<?php

namespace App\Plugins;

use App\Models\Connection;
use App\Settings\SettingDefinition;
use Illuminate\Http\Request;

/**
 * An integration plugin wraps one outside service (Shopify, Gemini, OpenAI,
 * Higgsfield...). Each lives in plugins/<name> and registers itself with
 * PluginRegistry from its service provider; what it can do for the app is
 * declared by the capability interfaces it implements (App\Plugins\Contracts).
 * The core never needs editing to add one.
 */
abstract class IntegrationPlugin
{
    /** Stable key, also the prefix of every action alias ("shopify"). */
    abstract public function key(): string;

    abstract public function name(): string;

    abstract public function description(): string;

    abstract public function connectionMethod(): ConnectionMethod;

    /**
     * Fields asked for when connecting. For OAuth plugins, these are the
     * details needed before signing in (such as a subdomain).
     *
     * @return list<ConnectionField>
     */
    abstract public function connectionFields(): array;

    abstract public function healthCheck(Connection $connection): HealthResult;

    /**
     * Settings this plugin adds to the settings screens.
     *
     * @return list<SettingDefinition>
     */
    public function settings(): array
    {
        return [];
    }

    /**
     * A short line identifying the connected account, such as
     * "canadianprotein.zendesk.com". Never includes secrets.
     */
    public function accountLabel(Connection $connection): string
    {
        return '';
    }

    /**
     * A Livewire component to use instead of the generic connect form, for
     * services whose set-up needs more than saving fields.
     */
    public function connectComponent(): ?string
    {
        return null;
    }

    /**
     * Whether an existing connection must go through the connect component
     * again before it can sign in (for example, its OAuth client was deleted).
     */
    public function needsSetup(Connection $connection): bool
    {
        return false;
    }

    /**
     * Details to show on the connection's page, such as an address to give
     * the service (a webhook URL) before it will accept the key.
     *
     * @return list<array{label: string, value: string, help?: string}>
     */
    public function setupDetails(Connection $connection): array
    {
        return [];
    }

    /**
     * Why an existing connection needs the person's attention, such as new
     * permissions that need a fresh sign-in. Null when nothing is needed.
     */
    public function attentionNeeded(Connection $connection): ?string
    {
        return null;
    }

    /**
     * OAuth plugins: where to send the person to approve access. $codeChallenge
     * is a PKCE S256 challenge the plugin should include if the service supports it.
     */
    public function authorizationUrl(Connection $connection, string $state, string $redirectUri, string $codeChallenge): string
    {
        throw new \LogicException("{$this->name()} doesn't connect by sign-in.");
    }

    /**
     * OAuth plugins: turn the approval into stored tokens.
     *
     * @return array<string, mixed> Secrets to merge into the connection.
     */
    public function completeAuthorization(Connection $connection, Request $request, string $redirectUri, string $codeVerifier): array
    {
        throw new \LogicException("{$this->name()} doesn't connect by sign-in.");
    }
}
