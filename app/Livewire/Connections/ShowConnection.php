<?php

namespace App\Livewire\Connections;

use App\Models\Brand;
use App\Models\Connection;
use App\Plugins\ConnectionField;
use App\Plugins\Connections;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ShowConnection extends Component
{
    #[Locked]
    public Connection $connection;

    public string $name = '';

    /** The brand's ID, or blank for shared. */
    public string $brand = '';

    /** @var array<string, string> Secret fields always start blank. */
    public array $fields = [];

    /** A one-off message the plugin left for after sign-in. */
    #[Locked]
    public ?array $notice = null;

    public function mount(Connection $connection): void
    {
        $this->connection = $connection;
        $this->notice = session()->pull("connection_notice.{$connection->id}");
        $this->name = $connection->name;
        $this->brand = (string) ($connection->brand_id ?? '');
        $this->fields = collect($connection->plugin()?->connectionFields() ?? [])
            ->mapWithKeys(fn (ConnectionField $f) => [$f->key => $f->secret ? '' : (string) $connection->config($f->key)])
            ->all();
    }

    public function save(Connections $connections): void
    {
        $this->authorize('connections.manage');

        $brand = $this->brand === '' ? null : Brand::find((int) $this->brand);

        if ($this->brand !== '' && ! $brand) {
            $this->addError('brand', 'Choose one of the brands, or shared.');

            return;
        }

        try {
            $connections->update($this->connection, $this->name, $this->fields, Auth::user());
            $connections->setBrand($this->connection, $brand, Auth::user());
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        foreach ($this->connection->plugin()->connectionFields() as $field) {
            if ($field->secret) {
                $this->fields[$field->key] = '';
            }
        }

        $this->dispatch('saved', message: 'Saved.');
    }

    public function check(Connections $connections): void
    {
        $this->authorize('connections.manage');

        $result = $connections->check($this->connection);

        $this->dispatch($result->ok ? 'saved' : 'failed', message: $result->message);
    }

    public function disconnect(Connections $connections): void
    {
        $this->authorize('connections.manage');

        $connections->disconnect($this->connection, Auth::user());

        $this->dispatch('saved', message: 'Disconnected. Everything set up on it is kept, so reconnecting restores it.');
    }

    public function delete(Connections $connections)
    {
        $this->authorize('connections.manage');

        try {
            $connections->delete($this->connection, Auth::user());
        } catch (ValidationException $e) {
            $this->dispatch('failed', message: collect($e->errors())->flatten()->first());

            return;
        }

        session()->flash('status', "Removed \"{$this->connection->name}\".");

        return $this->redirectRoute('connections');
    }

    public function render(Connections $connections)
    {
        $this->connection->refresh();

        return view('livewire.connections.show', [
            'plugin' => $this->connection->plugin(),
            'uses' => $connections->usesOf($this->connection),
        ])->title($this->connection->name);
    }
}
