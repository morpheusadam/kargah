<?php

namespace Modules\Data\Providers;

use Modules\Core\Support\MorphMap;
use Modules\Data\Contracts\AttachmentService as AttachmentServiceContract;
use Modules\Data\Models\Attachment;
use Modules\Data\Models\Backup;
use Modules\Data\Models\Bookmark;
use Modules\Data\Models\Credential;
use Modules\Data\Models\Repository;
use Modules\Data\Services\AttachmentService;
use Nwidart\Modules\Support\ModuleServiceProvider;

class DataServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Data';

    protected string $nameLower = 'data';

    /**
     * Command classes to register.
     *
     * Empty on purpose: `data:sync-repos` and `data:backup` are resolved from
     * `routes/console.php` alongside their schedule entries, the same way
     * Accounting's are, so the command and the cron line that runs it are read
     * in one place.
     *
     * @var string[]
     */
    protected array $commands = [];

    /** @var string[] */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bind(AttachmentServiceContract::class, AttachmentService::class);

        $this->registerBackupDisk();
    }

    public function boot(): void
    {
        parent::boot();

        // Aliases, not class names. These rows outlive refactors — see
        // Modules\Core\Support\MorphMap. `credential_category` is absent on
        // purpose: a category is never linked to, logged against or attached to
        // on its own, only through the credential that carries it.
        MorphMap::register([
            'attachment' => Attachment::class,
            'credential' => Credential::class,
            'bookmark' => Bookmark::class,
            'repository' => Repository::class,
            'backup' => Backup::class,
        ]);
    }

    /**
     * Add the backups disk without editing the shared filesystems config.
     *
     * A module that needs a disk should be able to declare one; `config/` is
     * shared ground and five modules each appending to it is how that file
     * becomes nobody's. The root is `storage/app/backups`, outside `public/`,
     * because a database dump behind a guessable URL is the worst possible way
     * to lose a client list.
     *
     * Only set when absent, so a deployment that does define the disk itself —
     * pointing it at S3, say — keeps its own definition.
     */
    private function registerBackupDisk(): void
    {
        $name = (string) config('data.backups.disk', 'backups');

        if (config('filesystems.disks.'.$name) !== null) {
            return;
        }

        config([
            'filesystems.disks.'.$name => [
                'driver' => 'local',
                'root' => config('data.backups.root', storage_path('app/backups')),
                'throw' => false,
                'report' => false,
            ],
        ]);
    }
}
