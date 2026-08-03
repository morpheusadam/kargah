<?php

namespace Modules\Mailbox\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Collection;
use Modules\Mailbox\Contracts\EmailReader as EmailReaderContract;
use Modules\Mailbox\Models\Email;
use Modules\Mailbox\Models\EmailThread;

class EmailReader implements EmailReaderContract
{
    public function forCustomer(int $customerId, int $limit = 20): Collection
    {
        return Email::query()
            ->forCustomer($customerId)
            ->recent()
            ->limit($limit)
            ->get()
            ->map(fn (Email $email): array => $this->summarise($email));
    }

    public function countForCustomer(int $customerId): int
    {
        return Email::query()->forCustomer($customerId)->count();
    }

    public function unreadCount(): int
    {
        return Email::query()->unread()->count();
    }

    public function find(int $emailId): ?array
    {
        $email = Email::query()
            ->with('customer')
            ->withCount('attachments')
            ->find($emailId);

        return $email === null ? null : $this->shape($email);
    }

    public function thread(int $threadId, int $limit = 50): array|null
    {
        $thread = EmailThread::query()->find($threadId);

        if ($thread === null) {
            return null;
        }

        $messages = Email::query()
            ->where('email_thread_id', $thread->id)
            ->with('customer')
            ->withCount('attachments')
            ->orderBy('received_at')
            ->orderBy('id')
            ->limit(max(1, min(200, $limit)))
            ->get()
            ->map(fn (Email $email): array => $this->shape($email))
            ->all();

        return [
            'id' => $thread->id,
            'subject' => $thread->subject,
            'participants' => array_values($thread->participants ?? []),
            // The stored counter, not `count($messages)`: the list above is
            // capped by `$limit`, and reporting the cap as the thread's length
            // would tell a caller a conversation is shorter than it is.
            'message_count' => (int) $thread->message_count,
            'last_message_at' => $thread->last_message_at?->toIso8601String(),
            'messages' => $messages,
        ];
    }

    public function paginate(
        ?string $folder = null,
        ?bool $unread = null,
        ?int $customerId = null,
        string $search = '',
        ?string $cursor = null,
        int $perPage = 20,
    ): array {
        $perPage = max(1, min(100, $perPage));

        $query = Email::query();

        if ($folder !== null && $folder !== '') {
            $query->inFolder($folder);
        }

        if ($unread === true) {
            $query->unread();
        } elseif ($unread === false) {
            $query->where('is_read', true);
        }

        if ($customerId !== null) {
            $query->forCustomer($customerId);
        }

        $term = trim($search);

        if ($term !== '') {
            $like = '%'.$term.'%';

            $query->where(fn (Builder $q) => $q
                ->where('subject', 'like', $like)
                ->orWhere('from_name', 'like', $like)
                ->orWhere('from_email', 'like', $like)
                ->orWhere('body_text', 'like', $like));
        }

        $decoded = $cursor === null || $cursor === ''
            ? null
            : rescue(fn (): ?Cursor => Cursor::fromEncoded($cursor), null, false);

        // By id, not by `received_at`: a cursor needs a column that is both
        // comparable and unique, and two messages synced in the same batch can
        // share a timestamp to the second. Descending id is the same
        // newest-first order `scopeRecent()` gives in practice.
        $paginator = $query->orderByDesc('id')->cursorPaginate($perPage, ['*'], 'cursor', $decoded);

        return [
            'items' => $paginator->getCollection()->map(fn (Email $email): array => $this->summarise($email))->all(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'prev_cursor' => $paginator->previousCursor()?->encode(),
            'per_page' => $perPage,
        ];
    }

    /** The listing shape: a preview, never a body. */
    private function summarise(Email $email): array
    {
        return [
            'id' => $email->id,
            'subject' => $email->subject ?: '(no subject)',
            'from_name' => $email->from_name,
            'from_email' => $email->from_email,
            'preview' => $email->preview(),
            'received_at' => $email->received_at?->toIso8601String(),
            'is_read' => $email->is_read,
            'is_starred' => $email->is_starred,
            'has_attachments' => $email->has_attachments,
            'folder' => $email->folder,
            // The inbox opens the message from a query parameter; there is
            // no per-message route to depend on, and inventing one here
            // would tie another module to a URL Mailbox has not committed
            // to yet.
            'url' => route('mail.inbox', ['email' => $email->id]),
        ];
    }

    /** The single-message shape: everything in `summarise()`, plus the body. */
    private function shape(Email $email): array
    {
        return [
            'id' => $email->id,
            'thread_id' => $email->email_thread_id,
            'subject' => $email->subject ?: '(no subject)',
            'from_name' => $email->from_name,
            'from_email' => $email->from_email,
            'to' => array_values(array_filter((array) ($email->to ?? []), 'is_string')),
            'cc' => array_values(array_filter((array) ($email->cc ?? []), 'is_string')),
            'body' => $this->body($email),
            'has_html' => trim((string) $email->body_html) !== '',
            'received_at' => $email->received_at?->toIso8601String(),
            'is_read' => $email->is_read,
            'is_starred' => $email->is_starred,
            'has_attachments' => $email->has_attachments,
            'attachment_count' => (int) ($email->attachments_count ?? 0),
            'folder' => $email->folder,
            'customer' => $email->customer === null ? null : [
                'id' => $email->customer->id,
                'name' => $email->customer->name,
            ],
            'url' => route('mail.inbox', ['email' => $email->id]),
        ];
    }

    /**
     * The message as plain text.
     *
     * The same fallback `Email::preview()` uses — `body_text` first, because it
     * is what the sender's client already flattened — but line breaks survive
     * here where a preview collapses them. A preview is read as one line; a
     * body is read as paragraphs, and gluing them together makes a quoted reply
     * chain unreadable.
     */
    private function body(Email $email): string
    {
        $text = (string) $email->body_text;

        if (trim($text) === '') {
            $html = (string) $email->body_html;
            $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
            $html = preg_replace('#<(br|/p|/div|/li|/tr|/h[1-6])\b[^>]*>#i', "\n", $html) ?? $html;

            $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
