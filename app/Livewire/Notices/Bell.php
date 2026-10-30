<?php

namespace App\Livewire\Notices;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The bell at the top of every page: how many notices are unread, and the
 * latest few when opened. Refreshes on a timer so a run finishing while
 * the person is elsewhere shows up.
 */
class Bell extends Component
{
    public const LATEST = 8;

    /** The list is loaded only once the bell has been opened. */
    public bool $opened = false;

    public function show(): void
    {
        $this->opened = true;
    }

    /**
     * Marks one notice read and goes where it points.
     */
    public function open(string $id): void
    {
        $notice = Auth::user()->notifications()->whereKey($id)->first();

        if (! $notice) {
            return;
        }

        $notice->markAsRead();
        $this->redirect((string) ($notice->data['url'] ?? route('notices')));
    }

    public function readAll(): void
    {
        Auth::user()->unreadNotifications()->update(['read_at' => now()]);
    }

    public function render()
    {
        $user = Auth::user();

        return view('livewire.notices.bell', [
            'unread' => $user->unreadNotifications()->count(),
            'notices' => $this->opened ? $user->notifications()->latest()->limit(self::LATEST)->get() : collect(),
        ]);
    }
}
