<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An image uploaded with a request: the look wanted (style), or a pack
 * shot to use instead of or beside the store's (product).
 */
#[Fillable(['request_id', 'kind', 'path', 'original_name', 'mime', 'size', 'width', 'height'])]
class RequestReference extends Model
{
    public const STYLE = 'style';

    public const PRODUCT = 'product';

    public const KINDS = [self::STYLE => 'Style: the look and mood wanted', self::PRODUCT => 'Product: a pack shot to use'];

    /**
     * @return BelongsTo<CreativeRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(CreativeRequest::class, 'request_id');
    }

    public function kindLabel(): string
    {
        return ucfirst($this->kind);
    }

    public function url(): string
    {
        return route('requests.reference', [$this->request->brand, $this->request, $this]);
    }
}
