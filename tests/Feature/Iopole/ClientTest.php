<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Drivers\Iopole\AccessTokenProvider;
use AmazScript\Einvoicing\Drivers\Iopole\Client;
use AmazScript\Einvoicing\Drivers\Iopole\Endpoints;
use AmazScript\Einvoicing\Drivers\Iopole\ResponseMappers\ErrorMapper;
use AmazScript\Einvoicing\Exceptions\EinvoicingAuthException;
use AmazScript\Einvoicing\Exceptions\EinvoicingConflictException;
use AmazScript\Einvoicing\Exceptions\EinvoicingRateLimitException;
use AmazScript\Einvoicing\Exceptions\EinvoicingServerException;
use AmazScript\Einvoicing\Exceptions\EinvoicingValidationException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const AUTH_URL = 'https://auth.example.test/token';
const API_URL = 'https://api.example.test';

function client(string $customerId = 'cust-123'): Client
{
    $http = app(HttpFactory::class);

    return new Client(
        $http,
        new AccessTokenProvider($http, Cache::store(), AUTH_URL, 'client-abc', 'secret-xyz'),
        new ErrorMapper,
        API_URL,
        $customerId,
    );
}

function fakeApi(mixed $response): void
{
    Http::fake([
        AUTH_URL => Http::response(['access_token' => 'jeton-1', 'expires_in' => 300]),
        API_URL.'/*' => $response,
    ]);
}

it('authentifie chaque appel et transmet le customer-id', function (): void {
    fakeApi(Http::response(['id' => 'op-1']));

    expect(client()->get(Endpoints::customerId()))->toBe(['id' => 'op-1']);

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/v1/config/customer/id')) {
            return false;
        }

        return $request->header('Authorization') === ['Bearer jeton-1']
            && $request->header('customer-id') === ['cust-123'];
    });
});

it('agit pour le compte d\'un autre customer-id sans muter le client d\'origine', function (): void {
    fakeApi(Http::response([]));

    $base = client('cust-123');
    $autre = $base->forCustomer('cust-999');

    $autre->get(Endpoints::webhooks());
    $base->get(Endpoints::webhooks());

    $envoyes = [];
    Http::assertSent(function ($request) use (&$envoyes): bool {
        if (str_contains($request->url(), '/v1/config/webhook')) {
            $envoyes[] = $request->header('customer-id')[0];
        }

        return true;
    });

    expect($envoyes)->toBe(['cust-999', 'cust-123']);
});

it('retente une fois avec un jeton neuf après un 401', function (): void {
    Http::fake([
        AUTH_URL => Http::sequence()
            ->push(['access_token' => 'jeton-perime', 'expires_in' => 300])
            ->push(['access_token' => 'jeton-neuf', 'expires_in' => 300]),
        API_URL.'/*' => Http::sequence()
            ->push(['statusMessage' => 'expired'], 401)
            ->push(['ok' => true], 200),
    ]);

    expect(client()->get(Endpoints::customerId()))->toBe(['ok' => true]);

    Http::assertSent(fn ($request) => $request->header('Authorization') === ['Bearer jeton-neuf']);
});

it('abandonne si le second essai échoue lui aussi', function (): void {
    fakeApi(Http::response(['statusMessage' => 'Unauthorized'], 401));

    expect(fn () => client()->get(Endpoints::customerId()))
        ->toThrow(EinvoicingAuthException::class);
});

it('traduit un 400 en erreurs de validation exploitables', function (): void {
    fakeApi(Http::response([
        'statusMessage' => 'Request validation issues',
        'details' => [
            'validation' => 'uuid',
            'code' => 'invalid_string',
            'message' => 'Invalid uuid',
            'path' => ['businessEntityId'],
        ],
    ], 400));

    try {
        client()->get(Endpoints::customerId());
        $this->fail('une exception était attendue');
    } catch (EinvoicingValidationException $e) {
        expect($e->getMessage())->toBe('Request validation issues')
            ->and($e->errors())->toBe([
                ['path' => 'businessEntityId', 'code' => 'invalid_string', 'message' => 'Invalid uuid'],
            ]);
    }
});

it('accepte aussi une liste d\'erreurs de validation', function (): void {
    fakeApi(Http::response([
        'statusMessage' => 'Request validation issues',
        'details' => [
            ['code' => 'invalid_type', 'message' => 'Required', 'path' => ['recipient', 'siret']],
            ['code' => 'too_small', 'message' => 'Too short', 'path' => ['siren']],
        ],
    ], 400));

    try {
        client()->post(Endpoints::webhooks(), []);
        $this->fail('une exception était attendue');
    } catch (EinvoicingValidationException $e) {
        expect($e->errors())->toHaveCount(2)
            ->and($e->errors()[0]['path'])->toBe('recipient.siret')
            ->and($e->errors()[1]['path'])->toBe('siren');
    }
});

it('signale un doublon sans le confondre avec une autre forme de conflit', function (): void {
    fakeApi(Http::response([
        'code' => 'DUPLICATE_RESOURCE',
        'statusMessage' => 'A resource with the same unique identifier already exists.',
    ], 409));

    try {
        client()->post(Endpoints::webhooks(), []);
        $this->fail('une exception était attendue');
    } catch (EinvoicingConflictException $e) {
        // Un rejeu tombe ici : l'appelant peut le considérer comme un succès.
        expect($e->isDuplicateResource())->toBeTrue()
            ->and($e->code())->toBe('DUPLICATE_RESOURCE');
    }
});

it('ne prend pas tout conflit pour un doublon', function (): void {
    fakeApi(Http::response(['code' => 'WEBHOOK_ALREADY_CONFIGURED', 'statusMessage' => 'Conflict'], 409));

    try {
        client()->post(Endpoints::webhooks(), []);
        $this->fail('une exception était attendue');
    } catch (EinvoicingConflictException $e) {
        expect($e->isDuplicateResource())->toBeFalse();
    }
});

it('expose le délai d\'attente d\'un 429', function (): void {
    fakeApi(Http::response(['statusMessage' => 'Too Many Requests'], 429, ['Retry-After' => '120']));

    try {
        client()->get(Endpoints::invoicesNotSeen());
        $this->fail('une exception était attendue');
    } catch (EinvoicingRateLimitException $e) {
        expect($e->retryAfter())->toBe(120);
    }
});

it('traduit une panne serveur', function (): void {
    fakeApi(Http::response(['statusMessage' => 'Internal error'], 503));

    expect(fn () => client()->get(Endpoints::invoicesNotSeen()))
        ->toThrow(EinvoicingServerException::class);
});

it('ne fait fuiter ni jeton ni customer-id dans les messages d\'erreur', function (): void {
    fakeApi(Http::response(['statusMessage' => 'Internal error'], 500));

    try {
        client('cust-secret-999')->get(Endpoints::invoicesNotSeen());
        $this->fail('une exception était attendue');
    } catch (EinvoicingServerException $e) {
        expect($e->getMessage())->not->toContain('jeton-1')
            ->and($e->getMessage())->not->toContain('cust-secret-999');
    }
});

it('accepte une réponse vide sur une suppression', function (): void {
    fakeApi(Http::response('', 204));

    expect(client()->delete(Endpoints::webhook('wh-1')))->toBe([]);
});

it('récupère un corps binaire sans tenter de le décoder', function (): void {
    fakeApi(Http::response('%PDF-1.4 binaire', 200, ['Content-Type' => 'application/pdf']));

    expect(client()->download(Endpoints::downloadReadableInvoice('inv-1')))->toBe('%PDF-1.4 binaire');
});

it('refuse une réponse json illisible', function (): void {
    fakeApi(Http::response('<html>maintenance</html>', 200));

    expect(fn () => client()->get(Endpoints::invoicesNotSeen()))
        ->toThrow(EinvoicingServerException::class);
});
