<?php

namespace App\Settings;

use Closure;
use Illuminate\Support\Str;

final readonly class SettingDefinition
{
    /**
     * @param  list<SettingScope>  $scopes  Where the value can be set. Resolution
     *                                      always runs the person's own, then the brand, then the platform.
     * @param  array<string, mixed>  $rules  Validation rules keyed "value" (and
     *                                       "value.*" for lists).
     * @param  Closure(mixed): mixed|null  $normalize  Tidies input before validation.
     * @param  array<string, string>|Closure(int $scopeId): array<string, string>  $options
     *                                                                                       Choices for a Select control, value => label. A closure
     *                                                                                       loads them when the screen opens, such as a list of agents.
     * @param  array<string, string>  $messages  Plain-language validation messages, keyed like Laravel's.
     * @param  bool  $advanced  Technical or rarely changed: shown under "Advanced" on settings screens.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public SettingControl $control,
        public mixed $default,
        public array $scopes = [SettingScope::Platform],
        public array $rules = [],
        public ?string $unit = null,
        public ?int $min = null,
        public ?int $max = null,
        public string $section = 'General',
        public ?Closure $normalize = null,
        public array|Closure $options = [],
        public array $messages = [],
        public bool $advanced = false,
    ) {}

    /**
     * @return array<string, string>
     */
    public function optionsFor(int $scopeId): array
    {
        return $this->options instanceof Closure ? ($this->options)($scopeId) : $this->options;
    }

    public function settableAt(SettingScope $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /**
     * Only set for the whole platform: a brand can't have its own. Such
     * settings show under "For every brand" in their feature's entry of a
     * brand's Settings, or on platform Settings when no feature has them.
     */
    public function isPlatformOnly(): bool
    {
        return $this->settableAt(SettingScope::Platform) && ! $this->settableAt(SettingScope::Brand);
    }

    /**
     * A value in a few words, for showing beside another one (such as the
     * default for all brands beside a brand's own).
     */
    public function describe(mixed $value, int $scopeId = 0): string
    {
        $none = 'not set';

        return match ($this->control) {
            SettingControl::Toggle => $value ? 'on' : 'off',
            SettingControl::Number => $value === null || $value === '' ? $none : match ($this->unit) {
                '%' => $value.'%',
                '$' => '$'.$value,
                default => trim($value.' '.$this->unit),
            },
            SettingControl::Select => $value === null || $value === '' ? $none : ($this->optionsFor($scopeId)[(string) $value] ?? (string) $value),
            SettingControl::Range => is_array($value) ? trim(($value['min'] ?? '?').' to '.($value['max'] ?? '?').' '.$this->unit) : $none,
            SettingControl::List => ($items = array_values((array) $value)) === [] ? 'none' : implode(', ', array_slice($items, 0, 3)).(count($items) > 3 ? ' and '.(count($items) - 3).' more' : ''),
            SettingControl::Checklist => ($on = (array) $value) === [] ? 'none' : count($on).' ticked',
            SettingControl::Switches => ($off = (array) $value) === [] ? 'all on' : count($off).' switched off',
            SettingControl::Translated => is_array($value) && filled($value['en'] ?? null) ? '“'.Str::limit((string) $value['en'], 60).'”' : $none,
            SettingControl::Secret => filled($value) ? 'saved' : $none,
            default => filled($value) ? '“'.Str::limit((string) $value, 60).'”' : $none,
        };
    }
}
