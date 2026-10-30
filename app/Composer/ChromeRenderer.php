<?php

namespace App\Composer;

use App\Settings\Settings;
use Symfony\Component\Process\Process;

/**
 * Headless Chrome, screenshotting a page whose window is exactly the ad's
 * pixel size. Chrome must be installed on the server (docs/deploy.md).
 */
class ChromeRenderer implements Renderer
{
    public function __construct(private Settings $settings) {}

    public function render(string $html, int $width, int $height, string $directory): string
    {
        $page = "{$directory}/ad.html";
        $png = "{$directory}/ad.png";
        file_put_contents($page, $html);

        $process = new Process([
            (string) $this->settings->get('composer.chrome_path'),
            '--headless=new', '--disable-gpu', '--no-sandbox', '--hide-scrollbars', '--allow-file-access-from-files', '--force-device-scale-factor=1',
            '--default-background-color=00000000', "--window-size={$width},{$height}", '--virtual-time-budget=5000', "--screenshot={$png}", "--user-data-dir={$directory}/chrome",
            'file://'.$page,
        ], $directory, ['HOME' => $directory], null, (int) $this->settings->get('composer.timeout_seconds'));

        try {
            $process->mustRun();
        } catch (\Throwable $e) {
            $message = trim($process->getErrorOutput() ?: $e->getMessage());

            throw new ComposeException('Chrome couldn\'t render the ad: '.mb_substr($message, 0, 300));
        }

        if (! is_file($png) || ! ($bytes = file_get_contents($png))) {
            throw new ComposeException('Chrome made no picture. Check that it\'s installed and the path in Settings → Generation → Advanced is right.');
        }

        return $bytes;
    }
}
