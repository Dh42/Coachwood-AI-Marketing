<?php

namespace App\Http\Controllers;

use App\Brands\CurrentBrand;
use App\Navigation\Navigation;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * Sends people to the first screen they can open in their current group.
     * Explains what to do when there's no group yet or no access yet.
     */
    public function __invoke(Request $request, Navigation $nav, CurrentBrand $current)
    {
        $user = $request->user();
        $brand = $current->get();

        if ($brand && ($item = $nav->firstVisibleTo($user, $brand))) {
            return redirect($item->url($brand));
        }

        if (! $brand && $user->platformRole() !== null) {
            return view('no-brands');
        }

        return view('no-access');
    }
}
