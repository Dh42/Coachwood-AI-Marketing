<?php

namespace Tests\Support;

use App\Catalogue\Cutout;
use App\Models\Connection;
use App\Plugins\Contracts\BackgroundRemover;
use Throwable;

/**
 * A background remover that sends back whatever PNG a test gives it (a
 * see-through one by default) and remembers what it was asked.
 */
class FakeRemover extends FakePlugin implements BackgroundRemover
{
    /** @var list<array{connection: int, mime: string, bytes: string}> */
    public array $calls = [];

    public ?string $png = null;

    public float $cost = 0.03;

    public ?Throwable $failure = null;

    public function __construct(string $key = 'remover')
    {
        parent::__construct($key);
    }

    public function removeBackground(Connection $connection, string $bytes, string $mime): Cutout
    {
        $this->calls[] = ['connection' => $connection->id, 'mime' => $mime, 'bytes' => $bytes];

        if ($this->failure) {
            throw $this->failure;
        }

        return new Cutout($this->png ?? self::transparentPng(), $this->cost);
    }

    /**
     * A PNG with a see-through background and an opaque shape in the middle, or a flat one.
     */
    public static function transparentPng(bool $transparent = true, int $size = 40): string
    {
        $image = imagecreatetruecolor($size, $size);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, $transparent ? imagecolorallocatealpha($image, 0, 0, 0, 127) : imagecolorallocate($image, 255, 255, 255));
        imagefilledellipse($image, intdiv($size, 2), intdiv($size, 2), intdiv($size, 2), intdiv($size, 2), imagecolorallocate($image, 200, 0, 40));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
