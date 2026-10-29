<?php

namespace App\Livewire\Connections;

use App\Models\Brand;
use App\Models\Connection;
use App\Plugins\Connections;
use App\Plugins\PluginRegistry;
use App\Plugins\PluginStates;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Connections')]
class ConnectionsIndex extends Component
{
    /** A brand's ID, "shared", or blank for all. */
    #[Url]
    public string $brand = '';

    public function setPlugin(string $key, bool $enabled, PluginStates $states): void
    {
        $this->authorize('connections.manage');

        $states->set($key, $enabled, Auth::user());

        $name = app(PluginRegistry::class)->plugin($key)?->name();
        $this->dispatch('saved', message: $enabled
            ? "{$name} is switched on."
            : "{$name} is switched off. Nothing can use its connections until it's switched on again.");
    }

    public function render(PluginRegistry $plugins, Connections $connections)
    {
        $all = Connection::with('brand')->orderBy('name')->get();
        $brands = Brand::orderBy('name')->get();

        // Each brand's connections, then shared ones; a filter shows one brand.
        $groups = $brands->map(fn (Brand $b) => ['key' => (string) $b->id, 'title' => $b->name, 'list' => $all->where('brand_id', $b->id)])
            ->push(['key' => 'shared', 'title' => 'Shared by every brand', 'list' => $all->whereNull('brand_id')])
            ->filter(fn (array $g) => ($this->brand === '' || $g['key'] === $this->brand) && $g['list']->isNotEmpty())
            ->values();

        return view('livewire.connections.index', [
            'connections' => $all,
            'groups' => $groups,
            'brands' => $brands,
            'usesOf' => fn (Connection $c) => $connections->usesOf($c),
            'plugins' => $plugins->all(),
            'registry' => $plugins,
        ]);
    }
}
