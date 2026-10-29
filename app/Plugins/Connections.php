<?php

namespace App\Plugins;

use App\Models\Brand;
use App\Models\Connection;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Adding, changing, checking and removing connections. Secrets are never
 * logged and never sent back to the browser.
 */
class Connections
{
    /** @var list<Closure(Connection): list<string>> Features that say where a connection is in use, by name. */
    private array $uses = [];

    public function __construct(private PluginRegistry $plugins) {}

    /**
     * A feature that uses connections registers how to say where one is in
     * use, so it can't be removed from under it.
     *
     * @param  Closure(Connection): list<string>  $uses
     */
    public function usedBy(Closure $uses): void
    {
        $this->uses[] = $uses;
    }

    /**
     * @param  array<string, mixed>  $fields  Values for the plugin's connection fields.
     */
    public function create(string $pluginKey, string $name, array $fields, User $by, ?Brand $brand = null): Connection
    {
        $plugin = $this->enabledPlugin($pluginKey);
        [$config, $secrets] = $this->validateFields($plugin, $name, $fields);

        $connection = Connection::create([
            'plugin' => $plugin->key(),
            'brand_id' => $brand?->id,
            'name' => trim($name),
            'config' => $config,
            'secrets' => $secrets,
            'status' => Connection::STATUS_PENDING,
            'created_by' => $by->id,
        ]);

        $this->log($by, $connection, 'connection_added', "Added connection \"{$connection->name}\" ({$plugin->name()})");

        // Field-based connections can be checked straight away; OAuth ones wait for sign-in.
        if ($plugin->connectionMethod() === ConnectionMethod::Fields) {
            $this->check($connection);
        }

        return $connection;
    }

    /**
     * Keeps a connection to one brand, or shares it with every brand (null).
     */
    public function setBrand(Connection $connection, ?Brand $brand, User $by): void
    {
        if ($connection->brand_id === $brand?->id) {
            return;
        }

        $connection->update(['brand_id' => $brand?->id]);
        $this->log($by, $connection, 'connection_updated', "Set \"{$connection->name}\" to ".($brand ? "the {$brand->name} brand" : 'shared by every brand'));
    }

    /**
     * Blank secret fields keep their saved value, so they never need re-typing.
     *
     * @param  array<string, mixed>  $fields
     */
    public function update(Connection $connection, string $name, array $fields, User $by): void
    {
        $plugin = $connection->plugin() ?? throw ValidationException::withMessages(['name' => 'This connection\'s plugin isn\'t installed any more.']);
        [$config, $secrets] = $this->validateFields($plugin, $name, $fields, $connection);

        $connection->update([
            'name' => trim($name),
            'config' => $config,
            'secrets' => [...$connection->secrets, ...$secrets],
        ]);

        $this->log($by, $connection, 'connection_updated', "Changed connection \"{$connection->name}\"", [
            // Which secrets changed, never their values.
            'secrets_changed' => array_keys($secrets),
        ]);

        if ($plugin->connectionMethod() === ConnectionMethod::Fields) {
            $this->check($connection);
        }
    }

    /**
     * Merges tokens from a completed sign-in and marks the connection live.
     *
     * @param  array<string, mixed>  $secrets
     */
    public function authorized(Connection $connection, array $secrets, User $by): void
    {
        $connection->update(['secrets' => [...$connection->secrets, ...$secrets]]);

        $this->log($by, $connection, 'connection_authorized', "Signed in \"{$connection->name}\"");
        $this->check($connection);
    }

    /**
     * Forgets the sign-in or tokens but keeps the connection and everything
     * set up on it, so reconnecting restores it all.
     */
    public function disconnect(Connection $connection, User $by): void
    {
        $keep = collect($connection->plugin()?->connectionFields() ?? [])
            ->filter(fn (ConnectionField $f) => $f->secret && $f->advanced)
            ->pluck('key')->all();

        $connection->update([
            'secrets' => array_intersect_key($connection->secrets, array_flip($keep)),
            'status' => Connection::STATUS_PENDING,
            'last_error' => null,
        ]);

        $this->log($by, $connection, 'connection_disconnected', "Disconnected \"{$connection->name}\"");
    }

    /**
     * Where a connection is in use, in words (empty when nothing uses it).
     *
     * @return list<string>
     */
    public function usesOf(Connection $connection): array
    {
        return collect($this->uses)->flatMap(fn (Closure $uses) => $uses($connection))->unique()->values()->all();
    }

    public function delete(Connection $connection, User $by): void
    {
        if (($uses = $this->usesOf($connection)) !== []) {
            throw ValidationException::withMessages([
                'connection' => "\"{$connection->name}\" is still used by ".implode(', ', $uses).'. Change those first, or disconnect it instead.',
            ]);
        }

        $connection->delete();

        $this->log($by, $connection, 'connection_removed', "Removed connection \"{$connection->name}\"");
    }

    /**
     * Runs the plugin's health check and records the result.
     */
    public function check(Connection $connection): HealthResult
    {
        $plugin = $connection->plugin();

        try {
            $result = $plugin ? $plugin->healthCheck($connection) : HealthResult::failed('The plugin for this connection isn\'t installed.');
        } catch (Throwable $e) {
            $result = HealthResult::failed($e->getMessage());
        }

        $connection->update([
            'status' => $result->ok ? Connection::STATUS_CONNECTED : Connection::STATUS_ERROR,
            'checked_at' => now(),
            'last_error' => $result->ok ? null : $result->message,
        ]);

        return $result;
    }

    private function enabledPlugin(string $key): IntegrationPlugin
    {
        if (! $this->plugins->isEnabled($key)) {
            throw ValidationException::withMessages(['plugin' => 'That service isn\'t available. An admin may have switched its plugin off.']);
        }

        return $this->plugins->plugin($key);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} Config and secrets, split.
     */
    private function validateFields(IntegrationPlugin $plugin, string $name, array $fields, ?Connection $existing = null): array
    {
        $rules = ['name' => ['required', 'string', 'max:100', Rule::unique('connections', 'name')->ignore($existing?->id)]];
        $attributes = ['name' => 'name'];

        foreach ($plugin->connectionFields() as $field) {
            // On edit, a blank secret means "keep what's saved".
            $optional = ! $field->required || ($field->secret && $existing && filled($existing->secret($field->key)));
            $rules["fields.{$field->key}"] = [
                $optional ? 'nullable' : 'required', 'string', 'max:5000', ...$field->rules,
                ...($field->options ? [Rule::in(array_keys($field->options))] : []),
            ];
            $attributes["fields.{$field->key}"] = $field->label;
        }

        $data = Validator::make(['name' => $name, 'fields' => $fields], $rules, [
            'name.unique' => 'Another connection already has this name. Names need to be unique so people can tell them apart.',
        ], $attributes)->validate();

        $config = [];
        $secrets = [];

        foreach ($plugin->connectionFields() as $field) {
            $value = isset($data['fields'][$field->key]) ? trim((string) $data['fields'][$field->key]) : null;

            if ($field->secret) {
                if (filled($value)) {
                    $secrets[$field->key] = $value;
                }
            } else {
                $config[$field->key] = $value;
            }
        }

        return [$config, $secrets];
    }

    private function log(User $by, Connection $connection, string $event, string $description, array $properties = []): void
    {
        activity('connections')->causedBy($by)->performedOn($connection)->event($event)->withProperties($properties)->log($description);
    }
}
