<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'scope', 'scope_id', 'value', 'updated_by'])]
class Setting extends Model
{
    protected function casts(): array
    {
        return [
            'value' => 'json',
            'scope_id' => 'integer',
        ];
    }
}
