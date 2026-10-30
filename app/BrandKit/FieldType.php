<?php

namespace App\BrandKit;

enum FieldType: string
{
    case Text = 'text';

    /** One text per language: {"en": "...", "fr": "..."}. */
    case Translated = 'translated';

    case Select = 'select';

    case Toggle = 'toggle';
}
