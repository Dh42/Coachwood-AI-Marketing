<?php

namespace App\Settings;

use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Every setting in the platform. Core features and plugins declare theirs
 * here with a scope, control, validation and default.
 */
class SettingsRegistry
{
    /** @var array<string, SettingDefinition> */
    private array $definitions = [];

    public function define(SettingDefinition $definition): void
    {
        if (isset($this->definitions[$definition->key])) {
            throw new InvalidArgumentException("Setting [{$definition->key}] is already defined.");
        }

        $this->definitions[$definition->key] = $definition;
    }

    public function get(string $key): SettingDefinition
    {
        return $this->definitions[$key] ?? throw new InvalidArgumentException("Setting [{$key}] is not defined.");
    }

    /**
     * @return Collection<int, SettingDefinition>
     */
    public function settableAt(SettingScope $scope): Collection
    {
        return collect($this->definitions)
            ->filter(fn (SettingDefinition $d) => $d->settableAt($scope))
            ->values();
    }
}
