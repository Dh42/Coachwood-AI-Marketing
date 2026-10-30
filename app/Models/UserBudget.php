<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A person's own monthly budget, set by an admin, instead of the default.
 */
#[Fillable(['user_id', 'monthly_usd', 'set_by'])]
class UserBudget extends Model
{
    protected function casts(): array
    {
        return ['monthly_usd' => 'float'];
    }
}
