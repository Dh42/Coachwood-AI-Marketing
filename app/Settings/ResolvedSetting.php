<?php

namespace App\Settings;

final readonly class ResolvedSetting
{
    /**
     * @param  ?SettingScope  $source  Where the value came from; null means the code default.
     */
    public function __construct(
        public SettingDefinition $definition,
        public mixed $value,
        public ?SettingScope $source,
    ) {}

    public function isDefault(): bool
    {
        return $this->source === null;
    }
}
