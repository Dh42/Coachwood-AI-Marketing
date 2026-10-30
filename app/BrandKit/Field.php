<?php

namespace App\BrandKit;

use App\Models\Brand;
use Closure;

/**
 * One thing the team fills in about an item of a kind (a colour's hex code,
 * a CTA's wording in each language). The kit page draws each field from
 * this, so a new field needs no view of its own.
 */
final readonly class Field
{
    /**
     * @param  list<string>  $rules  Extra validation rules beyond the type's own.
     * @param  array<string, string>|Closure(Brand): array<string, string>  $options  Select choices, by stored key.
     * @param  array<string, string>  $messages  Validation messages for the extra rules, by rule name.
     */
    public function __construct(
        public string $key,
        public string $label,
        public FieldType $type = FieldType::Text,
        public bool $required = false,
        public array $rules = [],
        public array|Closure $options = [],
        public mixed $default = null,
        public string $help = '',
        public string $placeholder = '',
        public array $messages = [],
    ) {}

    /**
     * @return array<string, string>
     */
    public function optionsFor(Brand $brand): array
    {
        return $this->options instanceof Closure ? ($this->options)($brand) : $this->options;
    }

    /**
     * What an empty item holds for this field.
     */
    public function blank(): mixed
    {
        return match ($this->type) {
            FieldType::Translated => ['en' => '', 'fr' => ''],
            FieldType::Toggle => (bool) $this->default,
            default => $this->default ?? '',
        };
    }

    /**
     * Validation rules for this field on one item, keyed by the field's path.
     *
     * @return array<string, list<mixed>>
     */
    public function rulesFor(Brand $brand): array
    {
        $presence = $this->required ? 'required' : 'nullable';

        return match ($this->type) {
            FieldType::Text => [$this->key => [$presence, 'string', 'max:1000', ...$this->rules]],
            FieldType::Translated => [
                $this->key => ['array'],
                "{$this->key}.en" => [$presence, 'string', 'max:1000', ...$this->rules],
                "{$this->key}.fr" => ['nullable', 'string', 'max:1000', ...$this->rules],
            ],
            FieldType::Select => [$this->key => [$presence, 'string', 'in:'.implode(',', array_keys($this->optionsFor($brand)))]],
            FieldType::Toggle => [$this->key => ['boolean']],
        };
    }

    /**
     * The value as it is stored, from what was typed.
     */
    public function clean(mixed $value): mixed
    {
        return match ($this->type) {
            FieldType::Translated => ['en' => trim((string) ($value['en'] ?? '')), 'fr' => trim((string) ($value['fr'] ?? ''))],
            FieldType::Toggle => (bool) $value,
            FieldType::Select => (string) ($value ?? $this->default ?? ''),
            FieldType::Text => trim((string) ($value ?? '')),
        };
    }
}
