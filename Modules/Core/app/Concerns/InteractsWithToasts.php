<?php

namespace Modules\Core\Concerns;

/**
 * Toast notifications from any Livewire component.
 *
 *     $this->toastSuccess('Invoice sent', 'INV-0041 went to Northwind Ltd.');
 *     $this->toastError('Could not send', $e->getMessage());
 *
 * The browser event is dispatched immediately, so it fires on the same
 * round-trip as the action. For a toast that must survive a redirect, use
 * `flashToast()` instead — the layout replays it on the next page load.
 */
trait InteractsWithToasts
{
    public function toast(string $type, string $message, ?string $description = null, ?int $duration = null): void
    {
        $this->dispatch('toast', [
            'type' => $type,
            'message' => $message,
            'description' => $description,
            'duration' => $duration,
        ]);
    }

    public function toastSuccess(string $message, ?string $description = null): void
    {
        $this->toast('success', $message, $description);
    }

    public function toastError(string $message, ?string $description = null): void
    {
        // Errors stay longer: the user has to read them, not just notice them.
        $this->toast('error', $message, $description, 9000);
    }

    public function toastWarning(string $message, ?string $description = null): void
    {
        $this->toast('warning', $message, $description, 7000);
    }

    public function toastInfo(string $message, ?string $description = null): void
    {
        $this->toast('info', $message, $description);
    }

    /**
     * Queue a toast to appear after a redirect. Use when the action navigates
     * away — a dispatched event would be lost with the old page.
     */
    public function flashToast(string $type, string $message, ?string $description = null): void
    {
        session()->flash('toast', [
            'type' => $type,
            'message' => $message,
            'description' => $description,
        ]);
    }
}
