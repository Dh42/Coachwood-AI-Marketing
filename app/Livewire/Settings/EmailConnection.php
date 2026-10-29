<?php

namespace App\Livewire\Settings;

use App\Models\GmailConnection;
use App\Notifications\TestEmailNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Throwable;

class EmailConnection extends Component
{
    public function sendTest(): void
    {
        $this->authorize('settings.manage');

        try {
            Auth::user()->notify(new TestEmailNotification);
        } catch (Throwable $e) {
            Log::error('Test email failed', ['exception' => $e]);
            $this->dispatch('failed', message: 'The test email couldn\'t be sent: '.$e->getMessage());

            return;
        }

        $this->dispatch('saved', message: 'Test email sent to '.Auth::user()->email.'. Check the inbox.');
    }

    public function render()
    {
        return view('livewire.settings.email-connection', [
            'connection' => GmailConnection::current()?->load('connectedBy'),
            'mailer' => config('mail.default'),
        ]);
    }
}
