<?php

namespace Modules\Mailbox\Services;

use Illuminate\Support\Collection;
use Modules\Mailbox\Contracts\EmailReader as EmailReaderContract;
use Modules\Mailbox\Models\Email;

class EmailReader implements EmailReaderContract
{
    public function forCustomer(int $customerId, int $limit = 20): Collection
    {
        return Email::query()
            ->forCustomer($customerId)
            ->recent()
            ->limit($limit)
            ->get()
            ->map(fn (Email $email): array => [
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
            ]);
    }

    public function countForCustomer(int $customerId): int
    {
        return Email::query()->forCustomer($customerId)->count();
    }
}
