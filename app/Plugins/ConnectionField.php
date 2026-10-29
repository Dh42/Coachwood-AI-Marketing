<?php

namespace App\Plugins;

use Illuminate\Contracts\Validation\ValidationRule;

final readonly class ConnectionField
{
    /**
     * @param  bool  $secret  Stored encrypted and never shown again after saving.
     * @param  bool  $advanced  Shown only under "Advanced".
     * @param  list<string|ValidationRule>  $rules
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $help = '',
        public bool $secret = false,
        public bool $required = true,
        public bool $advanced = false,
        public string $placeholder = '',
        public array $rules = [],
        public array $options = [],
        public bool $multiline = false,
    ) {}
}
