<?php

namespace Modules\Mailbox\Console;

use Illuminate\Console\Command;
use Modules\Mailbox\Models\Email;
use Modules\Mailbox\Models\MailAccount;

/**
 * What the receiving side actually holds, printed where the owner can read it.
 *
 * The inbox answers one question — "what is in this folder" — and when a message
 * somebody knows they received is not on that page, the page cannot say which of
 * the several possible reasons applies. It may never have been stored; it may be
 * filed under a folder the rail is not showing; it may have been deleted; the
 * account it should belong to may be inactive, in which case the endpoint has
 * been answering 503 and senders have been queuing and eventually bouncing.
 * Each has a different fix and the difference is not visible from a page that
 * simply shows nothing.
 *
 * **Read-only, and deliberately so.** It writes nothing and repairs nothing:
 * every one of those states is a decision for a person, and a command that
 * quietly "fixed" a folder or undeleted a message would be destroying the
 * evidence of how it got there.
 *
 *     php artisan mailbox:inbound-report
 *     php artisan mailbox:inbound-report --messages=25
 */
class InboundReport extends Command
{
    protected $signature = 'mailbox:inbound-report
                            {--messages=10 : How many of the newest messages to list}';

    protected $description = 'Show what the mail store holds and whether the inbound path is configured to fill it';

    public function handle(): int
    {
        $this->accounts();
        $this->configuration();
        $this->folders();
        $this->newest();

        return self::SUCCESS;
    }

    /**
     * The accounts, both kinds.
     *
     * `kind` is the column that decides everything: `dueForSync` never opens a
     * socket to an `inbound` account, and `InboundMailController` will only file
     * a pushed message under one. An `imap` row where an `inbound` one was meant
     * is a mailbox that is polled for ever and never receives a push.
     */
    private function accounts(): void
    {
        $accounts = MailAccount::query()->orderBy('id')->get();

        if ($accounts->isEmpty()) {
            $this->components->error('There are no mail accounts at all, so nothing can be received.');

            return;
        }

        $this->components->info('Mail accounts');

        $this->table(
            ['id', 'address', 'kind', 'active', 'last synced', 'last error'],
            $accounts->map(fn (MailAccount $account): array => [
                $account->id,
                $account->email,
                $account->kind ?? '—',
                $account->is_active ? 'yes' : 'NO',
                $account->last_synced_at?->diffForHumans() ?? 'never',
                $account->last_error === null ? '—' : mb_strimwidth((string) $account->last_error, 0, 60, '…'),
            ])->all(),
        );

        $inbound = $accounts->where('kind', MailAccount::KIND_INBOUND)->where('is_active', true);

        if ($inbound->isEmpty()) {
            $this->components->error(
                'No active inbound account. /mail/inbound answers 503 to the Worker, which makes it reject the '
                .'message, which makes the sending server queue it and eventually bounce it. Nothing is being received.',
            );

            return;
        }

        $this->components->info(
            'Mail for an address with no account of its own is filed under the oldest active inbound account, '
            .'which is '.$inbound->sortBy('id')->first()->email.'.',
        );
    }

    /**
     * The two settings the Worker's half of the arrangement depends on.
     *
     * The secret is never printed. Whether one exists is the whole of what this
     * can usefully say — it has to equal the Worker's `INBOUND_SECRET`, and a
     * mismatch answers 404 to the Worker rather than anything that reads as a
     * problem, so 'set' here and 'still not arriving' means the two differ.
     */
    private function configuration(): void
    {
        $secret = (string) config('mailbox.inbound.secret');

        $this->components->twoColumnDetail('Inbound secret', $secret === ''
            ? '<fg=red>not set — the endpoint answers 404 to everything</>'
            : 'set ('.strlen($secret).' characters)');

        $this->components->twoColumnDetail('Inbound folder', (string) config('mailbox.inbound.folder', 'INBOX'));
        $this->components->twoColumnDetail('Size limit', config('mailbox.inbound.max_size_kb').' KB');
    }

    /**
     * Every folder in the store, including the ones the inbox rail would not
     * name and the deleted rows it would never show.
     *
     * This is the table that answers "I know I received it". A message counted
     * here under a folder other than INBOX was stored and filed elsewhere; a
     * message in the deleted column was stored and thrown away; a store with no
     * rows at all was never written to, which points at the Worker or the
     * secret rather than at anything in the panel.
     */
    private function folders(): void
    {
        $rows = Email::withTrashed()
            ->selectRaw('folder, count(*) as total')
            ->selectRaw('sum(case when is_read then 0 else 1 end) as unread')
            ->selectRaw('sum(case when deleted_at is null then 0 else 1 end) as deleted')
            ->groupBy('folder')
            ->orderBy('folder')
            ->get();

        $this->newLine();
        $this->components->info('Messages held, by folder');

        if ($rows->isEmpty()) {
            $this->components->warn('The store is empty. Nothing has ever been written by either the Worker or a sync.');

            return;
        }

        $this->table(
            ['folder', 'total', 'unread', 'deleted'],
            $rows->map(fn ($row): array => [
                $row->folder,
                (int) $row->total,
                (int) $row->unread,
                (int) $row->deleted,
            ])->all(),
        );

        $this->components->twoColumnDetail(
            'Shown on /mail/inbox',
            (string) Email::query()->inFolder('INBOX')->count().' in INBOX',
        );
    }

    /**
     * The newest messages, whatever folder they are in.
     *
     * Ordered by id rather than by date, because id is arrival order and the
     * date is whatever the sender's machine claimed — and a message that arrived
     * with a wrong or missing date is exactly the kind that goes missing from a
     * page ordered by date.
     */
    private function newest(): void
    {
        $limit = max(1, (int) $this->option('messages'));

        $messages = Email::withTrashed()->orderByDesc('id')->limit($limit)->get();

        $this->newLine();
        $this->components->info('The '.$messages->count().' most recently stored');

        if ($messages->isEmpty()) {
            return;
        }

        $this->table(
            ['id', 'folder', 'from', 'subject', 'received', 'state'],
            $messages->map(fn (Email $email): array => [
                $email->id,
                $email->folder,
                mb_strimwidth((string) ($email->from_email ?: '—'), 0, 30, '…'),
                mb_strimwidth((string) ($email->subject ?: '(no subject)'), 0, 40, '…'),
                $email->received_at?->format('j M H:i') ?? '<fg=yellow>no date</>',
                $email->trashed() ? '<fg=red>deleted</>' : ($email->is_read ? 'read' : 'unread'),
            ])->all(),
        );
    }
}
