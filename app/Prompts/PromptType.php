<?php

namespace App\Prompts;

/**
 * The prompts a brand has: who reads each, and the placeholders the app
 * fills in it at run time.
 */
enum PromptType: string
{
    case Planner = 'planner';
    case Image = 'image';
    case Copy = 'copy';
    case ReviewImage = 'review_image';
    case ReviewAd = 'review_ad';
    case Change = 'change';

    public function label(): string
    {
        return match ($this) {
            self::Planner => 'Planner',
            self::Image => 'Images',
            self::Copy => 'Copy',
            self::ReviewImage => 'Review: images',
            self::ReviewAd => 'Review: finished ads',
            self::Change => 'Changes',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Planner => 'What the planner is told when it reads a brief: how to work, when to ask, how to write the image prompt and choose the model.',
            self::Image => 'Added to every image prompt the planner writes, for every shape: standing rules for the pictures themselves.',
            self::Copy => 'What the copywriter is told: the voice, the rules, the language. The placement and its limits are added by the app.',
            self::ReviewImage => 'What the reviewer scores a clean generated image on, and what rejects one outright.',
            self::ReviewAd => 'What the reviewer scores a finished ad on, and what rejects one outright.',
            self::Change => 'How a change someone asks for on an image ("darker background") is turned into one instruction for the image model: an edit of the original, or the prompt written again.',
        };
    }

    /**
     * Placeholders the app fills, with what each becomes.
     *
     * @return array<string, string>
     */
    public function placeholders(): array
    {
        $common = ['brand' => 'the brand\'s name', 'brand_kit' => 'the brand kit as text: colours, fonts, voice, visual direction, calls to action, legal lines'];

        return match ($this) {
            self::Planner => $common + ['asking' => 'the rule for asking questions, which changes once the rounds are used up'],
            self::Image => [],
            self::Copy => $common + ['language' => 'the language being written'],
            self::Change => $common,
            self::ReviewImage, self::ReviewAd => $common + ['criteria' => 'the criteria below, with their weights', 'hard_fails' => 'the hard fails below'],
        };
    }

    public function hasCriteria(): bool
    {
        return in_array($this, [self::ReviewImage, self::ReviewAd], true);
    }

    public function isReview(): bool
    {
        return $this->hasCriteria();
    }
}
