<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['plugin', 'enabled', 'updated_by'])]
class PluginState extends Model
{
    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
