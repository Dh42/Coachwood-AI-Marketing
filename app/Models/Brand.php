<?php

namespace App\Models;

use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * One brand's marketing (BioSteel, Canadian Protein): its store, brand kit,
 * prompts, templates, requests and settings. People hold roles per brand or
 * platform-wide (RoleAssignment).
 */
#[Fillable(['slug', 'name', 'color', 'created_by'])]
class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (Brand $brand) {
            if ($brand->isDirty('slug')) {
                throw new LogicException("A brand's ID can't change after it is created.");
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return HasMany<Connection, $this>
     */
    public function connections(): HasMany
    {
        return $this->hasMany(Connection::class);
    }

    /**
     * @return HasOne<BrandKit, $this>
     */
    public function kit(): HasOne
    {
        return $this->hasOne(BrandKit::class);
    }

    /**
     * @return HasMany<BrandKitItem, $this>
     */
    public function kitItems(): HasMany
    {
        return $this->hasMany(BrandKitItem::class);
    }

    /**
     * @return HasMany<RoleAssignment, $this>
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }
}
