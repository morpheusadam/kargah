<?php

namespace Modules\Data\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Data\Models\Repository;

/**
 * Mirror the GitHub repositories into the local table.
 *
 * The only place in Kargah that talks to GitHub. Nothing fetches during a
 * request, because a page render must not depend on someone else's API being
 * up; this runs from the scheduler and the repositories page reads the table.
 *
 * **Running it twice writes nothing the second time.** Not "writes the same
 * values again" — writes nothing at all: an unchanged payload leaves
 * `updated_at` and `synced_at` exactly where they were. That matters on cron,
 * where a doubled run is normal, and it is what makes "nothing changed" a thing
 * a test can actually assert.
 *
 * **No token means no run, and that is not an error.** There is no GitHub token
 * configured for this application, and a scheduled command that fails every
 * night teaches whoever reads the cron mail to ignore it. It says why it did
 * nothing and exits successfully.
 */
class SyncRepos extends Command
{
    protected $signature = 'data:sync-repos {--pages=5 : How many pages of 100 repositories to walk at most}';

    protected $description = 'Mirror the GitHub repositories into the local repositories table';

    private const PER_PAGE = 100;

    public function handle(): int
    {
        $token = config('data.github.token');

        if (! is_string($token) || trim($token) === '') {
            $this->components->warn(
                'data:sync-repos was skipped. No GITHUB_TOKEN is configured, so there is nothing to authenticate with. '
                .'Add a token with `repo` scope to .env and the next scheduled run will pick it up.'
            );

            return self::SUCCESS;
        }

        $maxPages = max(1, (int) $this->option('pages'));
        $created = 0;
        $updated = 0;
        $unchanged = 0;

        for ($page = 1; $page <= $maxPages; $page++) {
            try {
                $response = Http::withToken($token)
                    ->withHeaders([
                        'Accept' => 'application/vnd.github+json',
                        'X-GitHub-Api-Version' => '2022-11-28',
                    ])
                    ->timeout(20)
                    ->get(rtrim((string) config('data.github.api'), '/').'/user/repos', [
                        'per_page' => self::PER_PAGE,
                        'page' => $page,
                        'sort' => 'pushed',
                        'affiliation' => 'owner,collaborator,organization_member',
                    ]);
            } catch (ConnectionException $e) {
                $this->components->error('GitHub could not be reached: '.$e->getMessage());
                Log::warning('data:sync-repos could not reach GitHub. '.$e->getMessage());

                return self::FAILURE;
            }

            if ($response->status() === 401) {
                $this->components->error('GitHub rejected the token. Check that GITHUB_TOKEN is current and carries `repo` scope.');

                return self::FAILURE;
            }

            if ($response->failed()) {
                $this->components->error('GitHub answered '.$response->status().' on page '.$page.'.');
                Log::warning('data:sync-repos: GitHub answered '.$response->status().'.');

                return self::FAILURE;
            }

            /** @var list<array<string, mixed>> $payload */
            $payload = $response->json() ?? [];

            foreach ($payload as $item) {
                match ($this->record($item)) {
                    'created' => $created++,
                    'updated' => $updated++,
                    default => $unchanged++,
                };
            }

            // A short page is the last page. Asking for the next one would cost
            // a request that can only ever come back empty.
            if (count($payload) < self::PER_PAGE) {
                break;
            }
        }

        $this->components->info(
            'Synced '.($created + $updated + $unchanged).' '.str('repository')->plural($created + $updated + $unchanged)
            .': '.$created.' new, '.$updated.' changed, '.$unchanged.' already current.'
        );

        return self::SUCCESS;
    }

    /**
     * Write one repository, or leave it alone.
     *
     * `synced_at` is set only when something else changed. It reads as "when
     * this row last needed writing", which is the honest thing for a cache to
     * say, and it keeps a re-run genuinely free of database writes.
     *
     * @param  array<string, mixed>  $item
     * @return 'created'|'updated'|'unchanged'
     */
    private function record(array $item): string
    {
        $fullName = (string) ($item['full_name'] ?? '');

        if ($fullName === '') {
            return 'unchanged';
        }

        $repository = Repository::query()->firstOrNew([
            'provider' => 'github',
            'full_name' => $fullName,
        ]);

        $repository->fill([
            'description' => $this->trim($item['description'] ?? null, 500),
            'language' => $this->trim($item['language'] ?? null, 60),
            'default_branch' => $this->trim($item['default_branch'] ?? null, 120),
            'stars' => (int) ($item['stargazers_count'] ?? 0),
            'forks' => (int) ($item['forks_count'] ?? 0),
            'open_issues' => (int) ($item['open_issues_count'] ?? 0),
            'is_private' => (bool) ($item['private'] ?? false),
            'is_archived' => (bool) ($item['archived'] ?? false),
            'html_url' => $this->trim($item['html_url'] ?? null, 500),
            'pushed_at' => $item['pushed_at'] ?? null,
        ]);

        $existed = $repository->exists;

        if ($existed && ! $repository->isDirty()) {
            return 'unchanged';
        }

        $repository->synced_at = now();
        $repository->save();

        return $existed ? 'updated' : 'created';
    }

    private function trim(mixed $value, int $length): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $length);
    }
}
