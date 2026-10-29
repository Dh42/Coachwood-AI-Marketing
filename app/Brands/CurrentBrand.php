<?php

namespace App\Brands;

use App\Models\Brand;
use Illuminate\Http\Request;

/**
 * The brand the person is working in: the one in the URL, otherwise the
 * last one they opened, otherwise the first they can see.
 */
class CurrentBrand
{
    private const SESSION_KEY = 'current_brand_id';

    private ?Brand $brand = null;

    private bool $resolved = false;

    public function __construct(private Request $request) {}

    public function get(): ?Brand
    {
        if ($this->resolved) {
            return $this->brand;
        }

        $this->resolved = true;
        $user = $this->request->user();

        if (! $user) {
            return null;
        }

        $fromRoute = $this->request->route('brand');

        if ($fromRoute instanceof Brand) {
            $this->request->session()->put(self::SESSION_KEY, $fromRoute->id);

            return $this->brand = $fromRoute;
        }

        $visible = $user->visibleBrands();

        return $this->brand = $visible->firstWhere('id', $this->request->session()->get(self::SESSION_KEY)) ?? $visible->first();
    }
}
