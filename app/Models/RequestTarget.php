<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One placement in a request's plan: the shape it's generated at, what's
 * made for it, with which model, how many, and the estimate.
 */
#[Fillable(['request_id', 'placement_id', 'ratio', 'output', 'image_model', 'count', 'estimated_cost', 'status'])]
class RequestTarget extends Model
{
    protected function casts(): array
    {
        return ['estimated_cost' => 'float'];
    }

    /**
     * @return BelongsTo<CreativeRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(CreativeRequest::class, 'request_id');
    }

    /**
     * @return BelongsTo<Placement, $this>
     */
    public function placement(): BelongsTo
    {
        return $this->belongsTo(Placement::class);
    }

    public function model(): ?ImageModel
    {
        return ImageModel::findByKey($this->image_model);
    }
}
