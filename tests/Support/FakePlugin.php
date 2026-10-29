<?php

namespace Tests\Support;

use App\Models\Connection;
use App\Plugins\ConnectionField;
use App\Plugins\ConnectionMethod;
use App\Plugins\HealthResult;
use App\Plugins\IntegrationPlugin;
use Illuminate\Http\Request;

/**
 * A configurable plugin for testing the framework without a real service.
 */
class FakePlugin extends IntegrationPlugin
{
    public bool $healthy = true;

    public function __construct(
        private string $key = 'fake',
        private ConnectionMethod $method = ConnectionMethod::Fields,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return ucfirst($this->key);
    }

    public function description(): string
    {
        return 'A test service.';
    }

    public function connectionMethod(): ConnectionMethod
    {
        return $this->method;
    }

    public function connectionFields(): array
    {
        return [
            new ConnectionField('account', 'Account'),
            new ConnectionField('api_key', 'API key', secret: true),
            new ConnectionField('client_secret', 'Client secret', secret: true, required: false, advanced: true),
        ];
    }

    public function healthCheck(Connection $connection): HealthResult
    {
        return $this->healthy ? HealthResult::ok('All good') : HealthResult::failed('Service says no');
    }

    public function accountLabel(Connection $connection): string
    {
        return (string) $connection->config('account');
    }

    public function authorizationUrl(Connection $connection, string $state, string $redirectUri, string $codeChallenge): string
    {
        return 'https://fake.example/authorize?'.http_build_query(['state' => $state, 'redirect_uri' => $redirectUri, 'code_challenge' => $codeChallenge]);
    }

    public function completeAuthorization(Connection $connection, Request $request, string $redirectUri, string $codeVerifier): array
    {
        return ['access_token' => 'token-for-'.$request->query('code')];
    }
}
