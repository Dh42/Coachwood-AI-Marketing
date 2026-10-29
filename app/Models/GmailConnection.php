<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Gmail account the platform sends email from (invitations, password
 * resets, alerts). Same approach as CompanyTools.
 */
#[Fillable(['email', 'refresh_token', 'connected_by'])]
#[Hidden(['refresh_token'])]
class GmailConnection extends Model
{
    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public static function current(): ?self
    {
        return static::query()->latest('id')->first();
    }
}
