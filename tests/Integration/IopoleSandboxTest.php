<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Drivers\Iopole\AccessTokenProvider;
use AmazScript\Einvoicing\Drivers\Iopole\Client;
use AmazScript\Einvoicing\Drivers\Iopole\Endpoints;
use AmazScript\Einvoicing\Drivers\Iopole\ResponseMappers\ErrorMapper;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;

/**
 * The only tests in this repository that touch the network.
 *
 * They skip themselves wherever the credentials are absent from the environment
 * — so in CI, and on any machine without a sandbox. No secret is ever written
 * into the repository.
 *
 * These tests run under Testbench, which builds its own minimal Laravel
 * application: it does not read the .env of any neighbouring project. The
 * credentials therefore have to be handed over explicitly.
 *
 *     make test-integration
 */
/**
 * Le message dit quoi faire : ces tests s'ignorent partout où les identifiants
 * manquent, et rien n'indiquait comment les fournir.
 */
const MESSAGE_IDENTIFIANTS_ABSENTS = 'identifiants sandbox absents — lancez « make test-integration », '
    .'ou exportez IOPOLE_TOKEN_URL, IOPOLE_CLIENT_ID, IOPOLE_CLIENT_SECRET et IOPOLE_BASE_URL';

function sandboxCredentialsMissing(): bool
{
    foreach (['IOPOLE_TOKEN_URL', 'IOPOLE_CLIENT_ID', 'IOPOLE_CLIENT_SECRET', 'IOPOLE_BASE_URL'] as $key) {
        if (! is_string(getenv($key)) || getenv($key) === '') {
            return true;
        }
    }

    return false;
}

function sandboxClient(): Client
{
    $http = app(HttpFactory::class);

    return new Client(
        $http,
        new AccessTokenProvider(
            $http,
            Cache::store(),
            (string) getenv('IOPOLE_TOKEN_URL'),
            (string) getenv('IOPOLE_CLIENT_ID'),
            (string) getenv('IOPOLE_CLIENT_SECRET'),
        ),
        new ErrorMapper,
        (string) getenv('IOPOLE_BASE_URL'),
        (string) (getenv('IOPOLE_CUSTOMER_ID') ?: ''),
    );
}

it('obtient un jeton auprès du serveur d\'authentification réel', function (): void {
    $token = new AccessTokenProvider(
        app(HttpFactory::class),
        Cache::store(),
        (string) getenv('IOPOLE_TOKEN_URL'),
        (string) getenv('IOPOLE_CLIENT_ID'),
        (string) getenv('IOPOLE_CLIENT_SECRET'),
    );

    expect($token->token())->toBeString()->not->toBeEmpty();
})->group('integration')->skip(sandboxCredentialsMissing(...), MESSAGE_IDENTIFIANTS_ABSENTS);

it('interroge l\'api réelle et retrouve le customer id', function (): void {
    // Réponse constatée : text/html contenant l'identifiant nu, sans json.
    $reponse = trim(sandboxClient()->raw(Endpoints::customerId()));

    expect($reponse)->toBe(getenv('IOPOLE_CUSTOMER_ID'));
})->group('integration')->skip(sandboxCredentialsMissing(...), MESSAGE_IDENTIFIANTS_ABSENTS);
