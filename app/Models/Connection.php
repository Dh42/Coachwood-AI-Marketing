<?php

namespace App\Models;

use App\Plugins\IntegrationPlugin;
use App\Plugins\PluginRegistry;
use App\Settings\Settings;
use Database\Factories\ConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One connected account of an outside service, such as "BioSteel Shopify"
 * or the company's Gemini API key. It belongs to one brand, or to none,
 * which means every brand may use it.
 */
#[Fillable(['plugin', 'brand_id', 'name', 'config', 'secrets', 'status', 'checked_at', 'last_error', 'created_by'])]
#[Hidden(['secrets'])]
class Connection extends Model
{
    /** @use HasFactory<ConnectionFactory> */
    use HasFactory;

    public const STATUS_CONNECTED = 'connected';

    /** Saved but not signed in yet (OAuth), or signed out. */
    public const STATUS_PENDING = 'pending';

    public const STATUS_ERROR = 'error';

    /** The saved sign-in stopped working and couldn't be renewed; someone must sign in again. */
    public const STATUS_NEEDS_RECONNECT = 'reconnect';

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'secrets' => 'encrypted:array',
            'checked_at' => 'datetime',
            'brand_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Connections a brand can use: its own and shared ones.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function forBrand(Builder $query, Brand|int|null $brand): void
    {
        $id = $brand instanceof Brand ? $brand->id : $brand;

        if ($id !== null) {
            $query->where(fn ($q) => $q->whereNull('brand_id')->orWhere('brand_id', $id));
        }
    }

    public function isFor(Brand $brand): bool
    {
        return $this->brand_id === null || $this->brand_id === $brand->id;
    }

    public function isShared(): bool
    {
        return $this->brand_id === null;
    }

    /**
     * Options for a setting that picks one of a plugin's connections (or
     * any of several plugins'): the brand's own and shared ones, plus
     * whichever is chosen now, so saving the settings never drops it.
     *
     * @param  string|list<string>  $plugin
     * @return array<string, string>
     */
    public static function pickerOptions(string|array $plugin, int $brandId, string $settingKey): array
    {
        $brand = Brand::find($brandId);
        $current = $brand ? app(Settings::class)->get($settingKey, $brand->id) : null;

        return self::whereIn('plugin', (array) $plugin)->orderBy('name')->get()
            ->filter(fn (self $c) => ! $brand || $c->isFor($brand) || (string) $c->id === (string) $current)
            ->mapWithKeys(fn (self $c) => [(string) $c->id => $c->name])->all();
    }

    public function plugin(): ?IntegrationPlugin
    {
        return app(PluginRegistry::class)->plugin($this->plugin);
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function secret(string $key, mixed $default = null): mixed
    {
        return $this->secrets[$key] ?? $default;
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }
}
