<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Drivers\Iopole;

use AmazScript\Einvoicing\Exceptions\EinvoicingAuthException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Obtains and keeps the platform's OAuth2 access token.
 *
 * The flow is client_credentials: an id and a secret are exchanged for a
 * short-lived access token. The token is cached until shortly before it expires,
 * so that not every API call costs a round trip.
 *
 * Neither the secret nor the token appears in any exception message.
 */
final class AccessTokenProvider
{
    /**
     * Safety margin subtracted from the announced lifetime, so that a token
     * never expires mid-flight.
     */
    private const EXPIRY_MARGIN_SECONDS = 60;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly Cache $cache,
        private readonly string $tokenUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {}

    public function token(): string
    {
        $cached = $this->cache->get($this->cacheKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->fetchAndStore();
    }

    /**
     * Forgets the cached token. Called on a 401 to force a renewal rather than
     * replaying a revoked token indefinitely.
     */
    public function forget(): void
    {
        $this->cache->forget($this->cacheKey());
    }

    private function fetchAndStore(): string
    {
        if ($this->clientId === '' || $this->clientSecret === '' || $this->tokenUrl === '') {
            throw new EinvoicingAuthException(
                'Missing OAuth2 credentials: set IOPOLE_TOKEN_URL, IOPOLE_CLIENT_ID and IOPOLE_CLIENT_SECRET.'
            );
        }

        try {
            $response = $this->http
                ->asForm()
                ->post($this->tokenUrl, [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ]);
        } catch (ConnectionException $e) {
            throw new EinvoicingAuthException('Authentication server unreachable.');
        }

        if ($response->failed()) {
            // The response body is not quoted: it may carry the client id.
            throw new EinvoicingAuthException(
                'Authentication refused by the platform.',
                $response->status()
            );
        }

        $payload = $response->json();

        if (! is_array($payload) || ! isset($payload['access_token']) || ! is_string($payload['access_token'])) {
            throw new EinvoicingAuthException('Unusable authentication response: access_token missing.');
        }

        $token = $payload['access_token'];
        $lifetime = isset($payload['expires_in']) && is_numeric($payload['expires_in'])
            ? (int) $payload['expires_in']
            : 0;

        $ttl = $lifetime - self::EXPIRY_MARGIN_SECONDS;

        if ($ttl > 0) {
            $this->cache->put($this->cacheKey(), $token, $ttl);
        }

        return $token;
    }

    /**
     * The key is derived by hashing: the client id must not end up in clear in
     * the cache store or its logs.
     */
    private function cacheKey(): string
    {
        return 'einvoicing:iopole:token:'.hash('sha256', $this->tokenUrl.'|'.$this->clientId);
    }
}
