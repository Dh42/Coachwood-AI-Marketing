<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\CreativeRequest;
use App\Models\RequestReference;
use App\Requests\RequestReferences;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a request's reference images to people who may see the request.
 */
class RequestReferenceController extends Controller
{
    public function __invoke(Brand $brand, CreativeRequest $request, RequestReference $reference, RequestReferences $files): Response
    {
        abort_unless($request->brand_id === $brand->id && $reference->request_id === $request->id, 404);
        Gate::authorize('view', $request);
        abort_unless($files->disk()->exists($reference->path), 404);

        return $files->disk()->response($reference->path, $reference->original_name, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Type' => $reference->mime,
        ]);
    }
}
