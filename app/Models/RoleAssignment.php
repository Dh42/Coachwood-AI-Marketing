<?php

namespace App\Models;

use App\Auth\Role;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'role', 'brand_id'])]
class RoleAssignment extends Model
{
    /** brand_id value for a platform-wide role. */
    public const PLATFORM = 0;

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'brand_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
