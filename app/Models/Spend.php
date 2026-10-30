<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * US dollars spent with a provider, as its usage figures priced by the
 * model list: who, which brand, which request, what kind of work.
 */
#[Fillable(['brand_id', 'user_id', 'request_id', 'provider', 'kind', 'amount', 'note', 'created_at'])]
class Spend extends Model
{
    protected $table = 'spend';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount' => 'float', 'created_at' => 'datetime'];
    }
}
