<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Drivers\Iopole\AccessTokenProvider;
use AmazScript\Einvoicing\Exceptions\EinvoicingAuthException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const TOKEN_URL = 'https://auth.example.test/realms/iopole/protocol/openid-connect/token';

function tokenProvider(string $clientId = 'client-abc', string $secret = 'secret-xyz'): AccessTokenProvider
{
    return new AccessTokenProvider(
        app(HttpFactory::class),
        Cache::store(),
        TOKEN_URL,
        $clientId,
        $secret,
    );
}

it('échange les identifiants contre un jeton', function (): void {
    Http::fake([TOKEN_URL => Http::response(['access_token' => 'jeton-1', 'expires_in' => 300])]);

    expect(tokenProvider()->token())->toBe('jeton-1');

    Http::assertSent(function ($request): bool {
        return $request->url() === TOKEN_URL
            && $request['grant_type'] === 'client_credentials'
            && $request['client_id'] === 'client-abc'
            && $request['client_secret'] === 'secret-xyz';
    });
});

it('réutilise le jeton mis en cache au lieu de le redemander', function (): void {
    Http::fake([TOKEN_URL => Http::response(['access_token' => 'jeton-1', 'expires_in' => 300])]);

    $provider = tokenProvider();
    $provider->token();
    $provider->token();
    $provider->token();

    Http::assertSentCount(1);
});

it('redemande un jeton après oubli', function (): void {
    Http::fake([TOKEN_URL => Http::response(['access_token' => 'jeton-1', 'expires_in' => 300])]);

    $provider = tokenProvider();
    $provider->token();
    $provider->forget();
    $provider->token();

    Http::assertSentCount(2);
});

it('ne met pas en cache un jeton dont la durée de vie est plus courte que la marge', function (): void {
    Http::fake([TOKEN_URL => Http::response(['access_token' => 'jeton-court', 'expires_in' => 30])]);

    $provider = tokenProvider();
    $provider->token();
    $provider->token();

    Http::assertSentCount(2);
});

it('sépare les jetons de deux clients distincts', function (): void {
    Http::fake([TOKEN_URL => Http::sequence()
        ->push(['access_token' => 'jeton-a', 'expires_in' => 300])
        ->push(['access_token' => 'jeton-b', 'expires_in' => 300]),
    ]);

    expect(tokenProvider('client-a')->token())->toBe('jeton-a')
        ->and(tokenProvider('client-b')->token())->toBe('jeton-b');
});

it('ne divulgue ni identifiant ni secret quand l\'authentification échoue', function (): void {
    Http::fake([TOKEN_URL => Http::response(['error' => 'invalid_client', 'client_id' => 'client-abc'], 401)]);

    try {
        tokenProvider()->token();
        $this->fail('une exception était attendue');
    } catch (EinvoicingAuthException $e) {
        expect($e->getMessage())->not->toContain('client-abc')
            ->and($e->getMessage())->not->toContain('secret-xyz')
            ->and($e->statusCode())->toBe(401);
    }
});

it('refuse de partir sans identifiants configurés', function (): void {
    Http::fake();

    expect(fn () => tokenProvider('', '')->token())
        ->toThrow(EinvoicingAuthException::class);

    Http::assertNothingSent();
});

it('rejette une réponse d\'authentification sans access_token', function (): void {
    Http::fake([TOKEN_URL => Http::response(['expires_in' => 300])]);

    expect(fn () => tokenProvider()->token())->toThrow(EinvoicingAuthException::class);
});

it('n\'écrit jamais l\'identifiant client en clair dans la clé de cache', function (): void {
    Http::fake([TOKEN_URL => Http::response(['access_token' => 'jeton-1', 'expires_in' => 300])]);

    tokenProvider('client-abc')->token();

    $keys = array_keys(Cache::store()->getStore()->all());

    foreach ($keys as $key) {
        expect((string) $key)->not->toContain('client-abc');
    }
})->skip(fn () => ! method_exists(Cache::store()->getStore(), 'all'), 'magasin de cache non introspectable');
