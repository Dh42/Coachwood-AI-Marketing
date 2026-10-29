<?php

namespace App\Livewire\Connections;

use App\Models\Brand;
use App\Plugins\ConnectionMethod;
use App\Plugins\Connections;
use App\Plugins\PluginRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Add connection')]
class CreateConnection extends Component
{
    #[Url]
    public string $plugin = '';

    public string $name = '';

    /** The brand's ID, or blank for shared. */
    public string $brand = '';

    /** @var array<string, string> */
    public array $fields = [];

    public function choose(string $plugin): void
    {
        $this->plugin = $plugin;
        $this->fields = [];
        $this->resetErrorBag();
    }

    public function save(Connections $connections, PluginRegistry $plugins)
    {
        $this->authorize('connections.manage');

        $brand = $this->brand === '' ? null : Brand::find((int) $this->brand);

        // Checked before anything is saved, so a bad brand never leaves a half-made connection.
        if ($this->brand !== '' && ! $brand) {
            $this->addError('brand', 'Choose one of the brands, or shared.');

            return;
        }

        try {
            $connection = $connections->create($this->plugin, $this->name, $this->fields, Auth::user(), $brand);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        // OAuth connections go straight on to sign in.
        if ($plugins->plugin($this->plugin)->connectionMethod() === ConnectionMethod::OAuth) {
            return $this->redirectRoute('connections.authorize', $connection);
        }

        session()->flash('status', $connection->isConnected()
            ? "\"{$connection->name}\" is connected."
            : "\"{$connection->name}\" is saved, but the check failed. See the message below.");

        return $this->redirectRoute('connections.show', $connection);
    }

    public function render(PluginRegistry $plugins)
    {
        return view('livewire.connections.create', [
            'available' => $plugins->enabled(),
            'selected' => $this->plugin && $plugins->isEnabled($this->plugin) ? $plugins->plugin($this->plugin) : null,
        ]);
    }
}
