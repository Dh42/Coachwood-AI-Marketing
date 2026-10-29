<?php

namespace App\Settings;

/**
 * How a setting is edited. The settings screens render each one
 * automatically, so a new setting needs no view of its own.
 */
enum SettingControl: string
{
    case Text = 'text';
    case Number = 'number';
    case Toggle = 'toggle';
    case List = 'list';
    case Select = 'select';

    /** Two values, {min, max}: a dual-handle slider with number inputs. */
    case Range = 'range';

    /**
     * A switch per option, all on by default. Stores the keys switched
     * off, so options added later start on.
     */
    case Switches = 'switches';

    /** Tick any of the options. Stores the keys ticked. */
    case Checklist = 'checklist';

    /** Stored encrypted, never shown again, never written to the activity log. */
    case Secret = 'secret';

    /** One text per language: {"en": "...", "fr": "..."}. */
    case Translated = 'translated';

    /**
     * Edited on its own screen (such as the chat widget's appearance), so
     * the generic settings forms leave it out.
     */
    case Custom = 'custom';
}
