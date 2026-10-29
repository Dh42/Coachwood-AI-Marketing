<?php

namespace App\Settings;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Reads and writes settings. A value resolves the person's own first, then
 * the brand's, then the platform default saved in the app, then the code
 * default.
 */
class Settings
{
    /** @var array<string, ResolvedSetting> */
    private array $resolved = [];

    public function __construct(private SettingsRegistry $registry) {}

    public function get(string $key, ?int $brandId = null, ?int $userId = null): mixed
    {
        return $this->resolve($key, $brandId, $userId)->value;
    }

    public function resolve(string $key, ?int $brandId = null, ?int $userId = null): ResolvedSetting
    {
        $cacheKey = "{$key}|{$brandId}|{$userId}";

        if (isset($this->resolved[$cacheKey])) {
            return $this->resolved[$cacheKey];
        }

        $definition = $this->registry->get($key);

        $candidates = array_filter([
            [SettingScope::User, $userId],
            [SettingScope::Brand, $brandId],
            [SettingScope::Platform, 0],
        ], fn (array $c) => $c[1] !== null && $definition->settableAt($c[0]));

        $rows = Setting::where('key', $key)
            ->where(function ($query) use ($candidates) {
                foreach ($candidates as [$scope, $id]) {
                    $query->orWhere(fn ($q) => $q->where('scope', $scope->value)->where('scope_id', $id));
                }
            })
            ->get()
            ->keyBy('scope');

        foreach ($candidates as [$scope]) {
            if ($row = $rows->get($scope->value)) {
                $value = $definition->control === SettingControl::Secret ? Crypt::decryptString($row->value['encrypted']) : $row->value;

                return $this->resolved[$cacheKey] = new ResolvedSetting($definition, $value, $scope);
            }
        }

        return $this->resolved[$cacheKey] = new ResolvedSetting($definition, $definition->default, null);
    }

    /**
     * The value as it would be saved, checked against the setting's rules.
     *
     * @throws ValidationException
     */
    public function validate(string $key, mixed $value): mixed
    {
        $definition = $this->registry->get($key);

        if ($definition->normalize) {
            $value = ($definition->normalize)($value);
        }

        return Validator::make(['value' => $value], $definition->rules, $definition->messages, ['value' => $definition->label])
            ->validate()['value'];
    }

    /**
     * Validates and saves a value, and logs who changed it from what.
     *
     * @throws ValidationException
     */
    public function set(string $key, mixed $value, SettingScope $scope, int $scopeId, User $by): void
    {
        $definition = $this->registry->get($key);

        if (! $definition->settableAt($scope)) {
            throw new InvalidArgumentException("Setting [{$key}] can't be set at {$scope->value} level.");
        }

        $value = $this->validate($key, $value);
        $secret = $definition->control === SettingControl::Secret;

        // A blank secret field means "keep what's saved".
        if ($secret && blank($value)) {
            return;
        }

        DB::transaction(function () use ($definition, $key, $value, $scope, $scopeId, $by, $secret) {
            $existing = Setting::where(['key' => $key, 'scope' => $scope->value, 'scope_id' => $scopeId])->lockForUpdate()->first();
            $old = $existing?->value ?? $this->resolve($key, ...$this->scopeArgs($scope, $scopeId))->value;

            if ($existing && ! $secret && $existing->value === $value) {
                return;
            }

            Setting::updateOrCreate(
                ['key' => $key, 'scope' => $scope->value, 'scope_id' => $scopeId],
                ['value' => $secret ? ['encrypted' => Crypt::encryptString($value)] : $value, 'updated_by' => $by->id],
            );

            activity('settings')
                ->causedBy($by)
                ->event('updated')
                ->withProperties(['key' => $key, 'scope' => $scope->value, 'scope_id' => $scopeId, ...($secret ? ['changed' => 'secret replaced'] : ['old' => $old, 'new' => $value])])
                ->log("Changed \"{$definition->label}\"");
        });

        $this->resolved = [];
    }

    /**
     * Removes the value saved at this level, so the setting inherits again.
     */
    public function reset(string $key, SettingScope $scope, int $scopeId, User $by): void
    {
        $definition = $this->registry->get($key);
        $existing = Setting::where(['key' => $key, 'scope' => $scope->value, 'scope_id' => $scopeId])->first();

        if (! $existing) {
            return;
        }

        $existing->delete();
        $this->resolved = [];

        activity('settings')
            ->causedBy($by)
            ->event('reset')
            ->withProperties(['key' => $key, 'scope' => $scope->value, 'scope_id' => $scopeId, ...($definition->control === SettingControl::Secret ? ['changed' => 'secret removed'] : ['old' => $existing->value, 'new' => $this->resolve($key, ...$this->scopeArgs($scope, $scopeId))->value])])
            ->log("Reset \"{$definition->label}\" to inherit");
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function scopeArgs(SettingScope $scope, int $scopeId): array
    {
        return match ($scope) {
            SettingScope::Platform => [null, null],
            SettingScope::Brand => [$scopeId, null],
            SettingScope::User => [null, $scopeId],
        };
    }
}
