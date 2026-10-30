<?php

namespace App\Requests;

use App\Models\CreativeRequest;
use App\Models\RequestReference;
use App\Settings\Settings;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The images uploaded with a request, on the app's disk under the request.
 */
class RequestReferences
{
    public const ROOT = 'requests';

    private const MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(private Settings $settings) {}

    public function disk(): Filesystem
    {
        return Storage::disk();
    }

    /**
     * @throws ValidationException
     */
    public function add(CreativeRequest $request, UploadedFile $file, string $kind): RequestReference
    {
        $maxMb = (int) $this->settings->get('requests.max_reference_mb');
        $mime = (string) $file->getMimeType();

        if (! isset(self::MIMES[$mime]) || ! ($info = @getimagesize($file->getRealPath()))) {
            throw ValidationException::withMessages(['file' => 'Reference images are JPG, PNG or WebP files.']);
        }
        if ($file->getSize() > $maxMb * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => "Files can be up to {$maxMb} MB."]);
        }

        $kind = in_array($kind, [RequestReference::STYLE, RequestReference::PRODUCT], true) ? $kind : RequestReference::STYLE;
        $path = self::ROOT."/{$request->id}/references/".sha1_file($file->getRealPath()).'.'.self::MIMES[$mime];
        $this->disk()->put($path, file_get_contents($file->getRealPath()));

        return $request->references()->create([
            'kind' => $kind, 'path' => $path, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 200), 'mime' => $mime,
            'size' => (int) $file->getSize(), 'width' => $info[0], 'height' => $info[1],
        ]);
    }

    public function remove(RequestReference $reference): void
    {
        $others = RequestReference::where('path', $reference->path)->where('id', '!=', $reference->id)->exists();
        $reference->delete();

        if (! $others) {
            $this->disk()->delete($reference->path);
        }
    }
}
