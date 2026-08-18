<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Drivers\Iopole\AccessTokenProvider;
use AmazScript\Einvoicing\Drivers\Iopole\Client;
use AmazScript\Einvoicing\Drivers\Iopole\Endpoints;
use AmazScript\Einvoicing\Drivers\Iopole\ResponseMappers\ErrorMapper;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;

/**
 * Seuls tests du dépôt qui touchent le réseau.
 *
 * Ils sont ignorés partout où les identifiants ne sont pas présents dans
 * l'environnement — donc en intégration continue, et sur toute machine qui n'a
 * pas de sandbox. Aucun secret ne doit être écrit dans le dépôt : ces variables
 * se renseignent dans un .env local, jamais versionné.
 *
 * Pour les exécuter :
 *
 *     IOPOLE_TOKEN_URL=... IOPOLE_CLIENT_ID=... IOPOLE_CLIENT_SECRET=... \
 *     IOPOLE_CUSTOMER_ID=... IOPOLE_BASE_URL=... vendor/bin/pest --group=integration
 */
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
})->group('integration')->skip(sandboxCredentialsMissing(...), 'identifiants sandbox absents de l\'environnement');

it('interroge l\'api réelle et retrouve le customer id', function (): void {
    $reponse = sandboxClient()->get(Endpoints::customerId());

    // Le contenu exact de la réponse est inconnu tant qu'on ne l'a pas vu :
    // on vérifie qu'elle arrive et qu'elle est exploitable, pas sa forme.
    expect($reponse)->toBeArray()->not->toBeEmpty();

    dump('réponse de '.Endpoints::customerId().' : '.json_encode($reponse));
})->group('integration')->skip(sandboxCredentialsMissing(...), 'identifiants sandbox absents de l\'environnement');
