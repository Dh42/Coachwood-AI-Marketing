<?php

namespace App\Platforms;

use App\Models\Placement;

/**
 * The library's starting rows: the brief's table, with safe zones, file
 * rules and copy limits as commonly published. Written from general
 * knowledge, not checked against each platform's current docs, so every
 * platform starts "not checked" until someone marks it checked.
 */
final class Starters
{
    private const IMAGE_ONLY = [Placement::IMAGE];

    private const VIDEO_ONLY = [Placement::VIDEO];

    private const BOTH = [Placement::IMAGE, Placement::VIDEO];

    /** The band of a 9:16 story or reel that platform UI covers: caption and buttons at the bottom, the account at the top. */
    private const STORY_SAFE = ['safe_top' => 250, 'safe_bottom' => 340, 'safe_left' => 60, 'safe_right' => 60];

    private const REEL_SAFE = ['safe_top' => 250, 'safe_bottom' => 420, 'safe_left' => 60, 'safe_right' => 150];

    private const META_IMAGE = ['image_types' => ['jpg', 'png'], 'max_image_kb' => 30720];

    private const META_VIDEO = ['video_types' => ['mp4', 'mov'], 'max_video_mb' => 4096];

    private const ORGANIC_COPY = ['caption' => 2200, 'hashtags' => 30];

    private const META_AD_COPY = ['primary_text' => 125, 'headline' => 40, 'description' => 30];

    /**
     * @return list<array<string, mixed>> Platforms, each with its placements.
     */
    public static function platforms(): array
    {
        $feeds = fn (array $copy) => [
            self::placement('feed-portrait', 'Feed (4:5)', 1080, 1350, self::BOTH, [...self::META_IMAGE, ...self::META_VIDEO, 'video_min_seconds' => 1, 'video_max_seconds' => 60, 'copy_limits' => $copy, 'notes' => 'Shown with the caption below it; nothing covers the image.']),
            self::placement('feed-square', 'Feed (1:1)', 1080, 1080, self::BOTH, [...self::META_IMAGE, ...self::META_VIDEO, 'video_min_seconds' => 1, 'video_max_seconds' => 60, 'copy_limits' => $copy]),
        ];
        $stories = fn (array $copy, int $maxSeconds) => self::placement('stories', 'Stories', 1080, 1920, self::BOTH, [...self::META_IMAGE, ...self::META_VIDEO, ...self::STORY_SAFE, 'video_min_seconds' => 1, 'video_max_seconds' => $maxSeconds, 'copy_limits' => $copy, 'notes' => 'The account name sits at the top and the reply bar at the bottom; keep text and the product between them.']);
        $reels = fn (array $copy, int $maxSeconds) => self::placement('reels', 'Reels', 1080, 1920, self::VIDEO_ONLY, [...self::META_VIDEO, ...self::REEL_SAFE, 'video_min_seconds' => 3, 'video_max_seconds' => $maxSeconds, 'copy_limits' => $copy, 'notes' => 'The caption covers the bottom and the buttons the right edge. The hook is in the first second.']);

        return [
            self::platform('instagram', 'Instagram', 'Instagram: polished but real. The feed rewards one strong image; Stories and Reels want motion and a hook in the first second.', [
                ...$feeds(self::ORGANIC_COPY), $stories([], 60), $reels(self::ORGANIC_COPY, 90),
            ]),
            self::platform('facebook', 'Facebook', 'Facebook: broader, older audience than Instagram; clear offer, readable at a glance.', [
                ...$feeds(self::ORGANIC_COPY), $stories([], 60), $reels(self::ORGANIC_COPY, 90),
            ]),
            self::platform('meta-ads', 'Meta ads', 'Meta ads: the Instagram and Facebook placements as paid ads, with primary text, a headline and a description. Little text in the image; the offer in the copy.', [
                ...$feeds(self::META_AD_COPY), $stories(['primary_text' => 125, 'headline' => 40], 120), $reels(['primary_text' => 125, 'headline' => 40], 90),
            ]),
            self::platform('tiktok', 'TikTok', 'TikTok: native, unpolished feel. It should look like a creator made it, not an ad.', [
                self::placement('in-feed', 'In-feed and ads', 1080, 1920, self::BOTH, ['image_types' => ['jpg', 'png'], 'max_image_kb' => 10240, 'video_types' => ['mp4', 'mov'], 'max_video_mb' => 500, 'safe_top' => 220, 'safe_bottom' => 480, 'safe_left' => 60, 'safe_right' => 150, 'video_min_seconds' => 5, 'video_max_seconds' => 60, 'copy_limits' => ['primary_text' => 100], 'notes' => 'The caption and sound cover the bottom, the buttons the right edge. TikTok publishes safe-zone templates per ratio; check them.']),
            ]),
            self::platform('youtube', 'YouTube', 'YouTube: Shorts are vertical and fast; in-stream ads need the brand and the point in the first five seconds, before the skip.', [
                self::placement('shorts', 'Shorts', 1080, 1920, self::VIDEO_ONLY, ['video_types' => ['mp4', 'mov'], 'max_video_mb' => 4096, 'safe_top' => 200, 'safe_bottom' => 400, 'safe_left' => 60, 'safe_right' => 150, 'video_min_seconds' => 5, 'video_max_seconds' => 60, 'copy_limits' => ['headline' => 100], 'notes' => 'The headline is the video title.']),
                self::placement('in-stream', 'In-stream', 1920, 1080, self::VIDEO_ONLY, ['video_types' => ['mp4', 'mov'], 'max_video_mb' => 4096, 'video_min_seconds' => 6, 'video_max_seconds' => 180, 'copy_limits' => ['headline' => 100]]),
                self::placement('thumbnail', 'Thumbnail', 1280, 720, self::IMAGE_ONLY, ['image_types' => ['jpg', 'png'], 'max_image_kb' => 2048, 'notes' => 'Shown small: one subject, few words.']),
            ]),
            self::platform('google-ads', 'Google Ads', 'Google Ads: clean, product-forward images with no text on them; Google lays its own headline and description over the image.', [
                self::placement('display-landscape', 'Display and Performance Max (1.91:1)', 1200, 628, self::IMAGE_ONLY, ['image_types' => ['jpg', 'png'], 'max_image_kb' => 5120, 'copy_limits' => ['headline' => 30, 'description' => 90], 'notes' => 'Responsive and Performance Max assets take up to 5 MB; a static uploaded display ad must be under 150 KB. Keep text, logos and buttons out of the image.']),
                self::placement('display-square', 'Display and Performance Max (1:1)', 1200, 1200, self::IMAGE_ONLY, ['image_types' => ['jpg', 'png'], 'max_image_kb' => 5120, 'copy_limits' => ['headline' => 30, 'description' => 90]]),
                self::placement('display-portrait', 'Display and Performance Max (4:5)', 960, 1200, self::IMAGE_ONLY, ['image_types' => ['jpg', 'png'], 'max_image_kb' => 5120, 'copy_limits' => ['headline' => 30, 'description' => 90]]),
            ]),
            self::platform('pinterest', 'Pinterest', 'Pinterest: tall, bright, useful; people save ideas here, so show the product in use.', [
                self::placement('standard-pin', 'Standard pin', 1000, 1500, self::BOTH, ['image_types' => ['jpg', 'png'], 'max_image_kb' => 20480, 'video_types' => ['mp4', 'mov'], 'max_video_mb' => 2048, 'video_min_seconds' => 4, 'video_max_seconds' => 900, 'copy_limits' => ['headline' => 100, 'description' => 500], 'notes' => 'The headline is the pin title.']),
            ]),
            self::platform('linkedin', 'LinkedIn', 'LinkedIn: professional and plain-spoken; the product as part of a routine, not a lifestyle.', [
                self::placement('feed-landscape', 'Feed (1.91:1)', 1200, 627, self::IMAGE_ONLY, ['image_types' => ['jpg', 'png'], 'max_image_kb' => 5120, 'copy_limits' => ['primary_text' => 150, 'headline' => 70], 'notes' => 'Primary text is cut after about 150 characters until "see more".']),
                self::placement('feed-square', 'Feed (1:1)', 1080, 1080, self::IMAGE_ONLY, ['image_types' => ['jpg', 'png'], 'max_image_kb' => 5120, 'copy_limits' => ['primary_text' => 150, 'headline' => 70]]),
            ]),
            self::platform('x', 'X', 'X: quick and direct; one line and one image.', [
                self::placement('feed-landscape', 'Feed (16:9)', 1600, 900, self::BOTH, ['image_types' => ['jpg', 'png'], 'max_image_kb' => 5120, 'video_types' => ['mp4', 'mov'], 'max_video_mb' => 512, 'video_min_seconds' => 2, 'video_max_seconds' => 140, 'copy_limits' => ['primary_text' => 280]]),
                self::placement('feed-square', 'Feed (1:1)', 1080, 1080, self::BOTH, ['image_types' => ['jpg', 'png'], 'max_image_kb' => 5120, 'video_types' => ['mp4', 'mov'], 'max_video_mb' => 512, 'video_min_seconds' => 2, 'video_max_seconds' => 140, 'copy_limits' => ['primary_text' => 280]]),
            ]),
            self::platform('snapchat', 'Snapchat', 'Snapchat: vertical, playful, fast; made for a young audience on a phone.', [
                self::placement('ads', 'Ads', 1080, 1920, self::BOTH, ['image_types' => ['jpg', 'png'], 'max_image_kb' => 5120, 'video_types' => ['mp4', 'mov'], 'max_video_mb' => 1024, 'safe_top' => 150, 'safe_bottom' => 220, 'video_min_seconds' => 3, 'video_max_seconds' => 180, 'copy_limits' => ['headline' => 34], 'notes' => 'Keep the top 150 and bottom 220 pixels clear of anything important.']),
            ]),
            self::platform('amazon-ads', 'Amazon Ads', 'Amazon Ads: the product and its use, nothing else; Amazon rejects custom images with text, logos or buttons on them.', [
                self::placement('sponsored-brands', 'Sponsored Brands and Display custom image', 1200, 628, self::IMAGE_ONLY, ['image_types' => ['jpg', 'png'], 'max_image_kb' => 5120, 'copy_limits' => ['headline' => 50], 'notes' => 'Clean images only: no text, logos, prices or buttons on the image.']),
            ]),
            self::platform('email', 'Email (Klaviyo)', 'Email: the hero is seen on a phone first; large, simple, product-forward, with the offer in the headline.', [
                self::placement('hero', 'Hero', 1200, 600, self::IMAGE_ONLY, ['image_types' => ['jpg', 'png'], 'max_image_kb' => 1024, 'copy_limits' => ['headline' => 60, 'primary_text' => 300], 'notes' => 'Keep the file small so the email loads; the headline is the hero headline, the primary text the paragraph under it.']),
            ]),
        ];
    }

    /**
     * Sets the team ticks as one, as platform key => placement keys.
     *
     * @return array<string, array<string, list<string>>>
     */
    public static function presets(): array
    {
        $vertical = ['stories', 'reels'];

        return [
            'Meta full set' => ['instagram' => ['feed-portrait', 'feed-square', ...$vertical], 'facebook' => ['feed-portrait', 'feed-square', ...$vertical], 'meta-ads' => ['feed-portrait', 'feed-square', ...$vertical]],
            'All vertical video' => ['instagram' => $vertical, 'facebook' => $vertical, 'meta-ads' => $vertical, 'tiktok' => ['in-feed'], 'youtube' => ['shorts'], 'snapchat' => ['ads']],
            'Square and portrait feeds' => ['instagram' => ['feed-portrait', 'feed-square'], 'facebook' => ['feed-portrait', 'feed-square'], 'meta-ads' => ['feed-portrait', 'feed-square'], 'linkedin' => ['feed-square'], 'x' => ['feed-square'], 'google-ads' => ['display-square', 'display-portrait']],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $placements
     * @return array<string, mixed>
     */
    private static function platform(string $key, string $name, string $promptNote, array $placements): array
    {
        return ['key' => $key, 'name' => $name, 'prompt_note' => $promptNote, 'placements' => $placements];
    }

    /**
     * @param  list<string>  $formats
     * @param  array<string, mixed>  $more
     * @return array<string, mixed>
     */
    private static function placement(string $key, string $name, int $width, int $height, array $formats, array $more = []): array
    {
        return [
            'key' => $key, 'name' => $name, 'width' => $width, 'height' => $height, 'formats' => $formats,
            'safe_top' => 0, 'safe_bottom' => 0, 'safe_left' => 0, 'safe_right' => 0,
            'image_types' => in_array(Placement::IMAGE, $formats, true) ? ['jpg', 'png'] : [], 'max_image_kb' => null,
            'video_types' => in_array(Placement::VIDEO, $formats, true) ? ['mp4', 'mov'] : [], 'max_video_mb' => null,
            'video_min_seconds' => null, 'video_max_seconds' => null, 'copy_limits' => [], 'notes' => null,
            ...$more,
        ];
    }
}
