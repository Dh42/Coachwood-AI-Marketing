<?php

namespace App\Plugins\Contracts;

use App\Images\ImageException;
use App\Images\ImageRequest;
use App\Images\ImageResult;
use App\Models\Brand;
use App\Models\Connection;
use App\Models\ImageModel;

/**
 * A plugin that makes images from a prompt, with or without reference
 * images (Gemini and OpenAI first). Its models are rows in image_models,
 * which admins switch on and off and price; the plugin only provides the
 * starting rows and the calls.
 */
interface ImageProvider
{
    /**
     * The starting rows for this provider's models, in the shape
     * ImageModels::addStarters() stores: model, label, aspect_ratios,
     * sizes, qualities, max_references, edits, transparent, renders_text,
     * prices (per image, by size or "quality size"), input_price,
     * image_input_price and output_price (US dollars per million tokens),
     * notes, enabled, position.
     *
     * @return list<array<string, mixed>>
     */
    public function imageModels(): array;

    /**
     * The quality used when a request doesn't name one (a setting for
     * providers that have qualities), for estimates and calls alike.
     */
    public function defaultQuality(ImageModel $model, ?Brand $brand = null): ?string;

    /**
     * Makes the images. The request has been checked against the model's
     * capabilities already, so the plugin can send it as it is.
     *
     * @throws ImageException
     */
    public function generateImages(Connection $connection, ImageModel $model, ImageRequest $request): ImageResult;
}
