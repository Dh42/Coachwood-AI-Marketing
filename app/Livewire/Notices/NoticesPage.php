<?php

namespace App\Livewire\Notices;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every notice the person has had, newest first.
 */
#[Title('Notices')]
class NoticesPage extends Component
{
    use WithPagination;

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

        return view('livewire.notices.notices-page', [
            'notices' => $user->notifications()->latest()->paginate(30),
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }
}
