<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Enums\VatCategory;
use AmazScript\Einvoicing\Enums\VatPointDate;
use AmazScript\Einvoicing\Facades\Einvoicing;
use AmazScript\Einvoicing\Models\Tenant;
use AmazScript\Einvoicing\Reporting\Transaction;
use Illuminate\Support\Facades\Http;

const REPORTING_API = 'https://api.example.test';

function dossierDeclarant(string $siren = '384066650'): Tenant
{
    return Tenant::query()->create([
        'tenantable_type' => 'App\\Models\\Company', 'tenantable_id' => '1',
        'customer_id' => 'cust-1', 'siren' => $siren, 'siret' => null, 'active' => true,
    ]);
}

/** @return array{url: string, corps: array<string, mixed>} */
function declarationEnvoyee(): array
{
    $vu = ['url' => '', 'corps' => []];

    Http::assertSent(function ($request) use (&$vu): bool {
        if (str_contains($request->url(), '/reporting/')) {
            $vu = ['url' => $request->url(), 'corps' => $request->data()];
        }

        return true;
    });

    return $vu;
}

beforeEach(function (): void {
    config()->set('einvoicing.drivers.iopole.base_url', REPORTING_API);
    config()->set('einvoicing.drivers.iopole.token_url', REPORTING_API.'/token');
    config()->set('einvoicing.drivers.iopole.client_id', 'client');
    config()->set('einvoicing.drivers.iopole.client_secret', 'secret');

    // Volontairement étroit : un motif large gagnerait sur les stubs posés
    // dans les tests, le premier enregistré l'emportant.
    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        REPORTING_API.'/v1/reporting/transaction/scheme/*' => Http::response(['type' => 'STREAM', 'id' => 'rep-1'], 201),
        REPORTING_API.'/v1/reporting/payment/transaction/scheme/*' => Http::response(['type' => 'STREAM', 'id' => 'rep-1'], 201),
    ]);
});

it('déclare une vente de biens', function (): void {
    $dossier = dossierDeclarant();

    $id = Einvoicing::for($dossier)->reporting()->reportTransactions(
        new DateTimeImmutable('2026-08-20'),
        [Transaction::goods(taxBasis: 1000.0, tax: 200.0)],
    );

    expect($id)->toBe('rep-1');

    $appel = declarationEnvoyee();

    expect($appel['url'])->toContain('/transaction/scheme/0002/value/384066650')
        ->and($appel['corps']['transactionDate'])->toBe('2026-08-20');

    $ligne = $appel['corps']['transactions'][0];

    expect($ligne['categoryCode'])->toBe('TLB1')
        ->and($ligne['currency'])->toBe('EUR')
        ->and($ligne['monetary']['taxBasisTotalAmount']['amount'])->toBe(1000.0)
        ->and($ligne['monetary']['taxTotalAmount']['amount'])->toBe(200.0)
        ->and($ligne['taxDetails'][0])->toMatchArray(['percent' => 20.0, 'code' => 'S'])
        ->and($ligne)->not->toHaveKey('taxPaymentOption');
});

it('exige une date d\'exigibilité sur une prestation de service', function (): void {
    $dossier = dossierDeclarant();

    Einvoicing::for($dossier)->reporting()->reportTransactions(
        new DateTimeImmutable('2026-08-20'),
        [Transaction::services(taxBasis: 500.0, tax: 100.0, vatPointDate: VatPointDate::PaymentDate)],
    );

    $ligne = declarationEnvoyee()['corps']['transactions'][0];

    // Sur un service, la TVA est due à l'encaissement : la plateforme refuse
    // la déclaration sans cette date.
    expect($ligne['categoryCode'])->toBe('TPS1')
        ->and($ligne['taxPaymentOption']['iopCode'])->toBe('PAYMENT_DATE');
});

it('déclare une opération hors champ de TVA sans taxe', function (): void {
    $dossier = dossierDeclarant();

    Einvoicing::for($dossier)->reporting()->reportTransactions(
        new DateTimeImmutable('2026-08-20'),
        [Transaction::nonTaxable(taxBasis: 300.0)],
    );

    $ligne = declarationEnvoyee()['corps']['transactions'][0];

    expect($ligne['categoryCode'])->toBe('TNT1')
        ->and($ligne['monetary']['taxTotalAmount']['amount'])->toBe(0.0)
        ->and($ligne['taxDetails'][0]['code'])->toBe('O');
});

it('déclare plusieurs transactions en un seul envoi', function (): void {
    $dossier = dossierDeclarant();

    Einvoicing::for($dossier)->reporting()->reportTransactions(
        new DateTimeImmutable('2026-08-20'),
        [
            Transaction::goods(taxBasis: 100.0, tax: 20.0),
            Transaction::goods(taxBasis: 50.0, tax: 2.75, rate: 5.5),
            Transaction::services(taxBasis: 80.0, tax: 16.0, vatPointDate: VatPointDate::PaymentDate),
        ],
        registerId: 'CAISSE-3',
        closureId: 'Z-2026-08-20',
    );

    $corps = declarationEnvoyee()['corps'];

    expect($corps['transactions'])->toHaveCount(3)
        ->and($corps['registerId'])->toBe('CAISSE-3')
        ->and($corps['closureId'])->toBe('Z-2026-08-20')
        ->and($corps['transactions'][1]['taxDetails'][0]['percent'])->toBe(5.5);

    Http::assertSentCount(2); // le jeton, puis un seul envoi
});

it('refuse une déclaration vide', function (): void {
    // Ne rien déclarer et déclarer zéro ne disent pas la même chose au fisc.
    Einvoicing::for(dossierDeclarant())->reporting()->reportTransactions(new DateTimeImmutable, []);
})->throws(InvalidArgumentException::class, 'at least one transaction');

it('ne recalcule aucun montant', function (): void {
    $dossier = dossierDeclarant();

    // Un total incohérent est une question comptable, pas quelque chose à
    // rattraper en silence : il part tel quel.
    Einvoicing::for($dossier)->reporting()->reportTransactions(
        new DateTimeImmutable('2026-08-20'),
        [Transaction::goods(taxBasis: 100.0, tax: 999.0, rate: 20.0)],
    );

    expect(declarationEnvoyee()['corps']['transactions'][0]['monetary']['taxTotalAmount']['amount'])->toBe(999.0);
});

it('déclare un encaissement', function (): void {
    $dossier = dossierDeclarant();

    Einvoicing::for($dossier)->reporting()->reportPayment(
        new DateTimeImmutable('2026-08-25'), 600.0, 'eur', 'VIR-2026-08-25-01'
    );

    $appel = declarationEnvoyee();

    expect($appel['url'])->toContain('/payment/transaction/scheme/0002/value/384066650')
        ->and($appel['corps']['transaction'])->toMatchArray([
            'paymentDate' => '2026-08-25',
            'reference' => 'VIR-2026-08-25-01',
        ])
        ->and($appel['corps']['transaction']['amount'])->toMatchArray(['amount' => 600.0, 'currency' => 'EUR']);
});

it('refuse de déclarer sous un SIREN inutilisable', function (): void {
    $dossier = dossierDeclarant('12345');

    Einvoicing::for($dossier)->reporting()->reportTransactions(
        new DateTimeImmutable, [Transaction::goods(taxBasis: 10.0, tax: 2.0)]
    );
})->throws(InvalidArgumentException::class, 'no usable SIREN');

it('accepte une devise étrangère et une catégorie de TVA choisie', function (): void {
    $dossier = dossierDeclarant();

    Einvoicing::for($dossier)->reporting()->reportTransactions(
        new DateTimeImmutable('2026-08-20'),
        [Transaction::goods(taxBasis: 1000.0, tax: 0.0, rate: 0.0, currency: 'chf', vatCategory: VatCategory::Export)],
    );

    $ligne = declarationEnvoyee()['corps']['transactions'][0];

    expect($ligne['currency'])->toBe('CHF')
        ->and($ligne['taxDetails'][0]['code'])->toBe('G');
});

it('exige un dossier pour déclarer', function (): void {
    Einvoicing::operator()->reporting();
})->throws(RuntimeException::class, 'Reporting requires a tenant');

it('retire une déclaration', function (): void {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        REPORTING_API.'/v1/reporting/transaction/*' => Http::response([], 204),
    ]);

    // Il n'existe aucun moyen de modifier : corriger, c'est retirer puis
    // redéclarer, les endpoints de mise à jour répondant 501.
    Einvoicing::for(dossierDeclarant())->reporting()->deleteTransaction('tr-1');

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/v1/reporting/transaction/tr-1'));
});

it('retire un encaissement', function (): void {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        REPORTING_API.'/v1/reporting/payment/*' => Http::response([], 204),
    ]);

    Einvoicing::for(dossierDeclarant())->reporting()->deletePayment('pay-1');

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/v1/reporting/payment/pay-1'));
});

it('lit les périodes de déclaration', function (): void {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        REPORTING_API.'/v1/reporting/report/scheme/*' => Http::response([
            'data' => [[
                'id' => 'rep-2026-08',
                'state' => 'OPEN',
                'status' => 'PENDING',
                'transactionType' => 'INITIAL',
                'vatRegime' => 'VAT_EXEMPTION_REGIME',
                'startDate' => '2026-08-01',
                'endDate' => '2026-08-31',
                'autoCloseDate' => '2026-09-05',
            ]],
            'meta' => ['offset' => 0, 'limit' => 50, 'count' => 1],
        ]),
    ]);

    $periode = Einvoicing::for(dossierDeclarant())->reporting()->reports(new DateTimeImmutable('2026-08-01'))->first();

    expect($periode->id)->toBe('rep-2026-08')
        ->and($periode->isOpen())->toBeTrue()
        ->and($periode->wasRejected())->toBeFalse()
        ->and($periode->vatRegime->value)->toBe('VAT_EXEMPTION_REGIME')
        ->and($periode->autoCloseDate->format('Y-m-d'))->toBe('2026-09-05');
});

it('reconnaît une période close et une déclaration refusée', function (): void {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        REPORTING_API.'/v1/reporting/report/scheme/*' => Http::response([
            'data' => [['id' => 'rep-1', 'state' => 'CLOSED', 'status' => 'REJECTED']],
            'meta' => ['offset' => 0, 'limit' => 50, 'count' => 1],
        ]),
    ]);

    $periode = Einvoicing::for(dossierDeclarant())->reporting()->reports(new DateTimeImmutable('2026-08-01'))->first();

    // Close : plus rien ne peut y être déclaré. Refusée : à reprendre.
    expect($periode->isOpen())->toBeFalse()
        ->and($periode->wasRejected())->toBeTrue()
        ->and($periode->startDate)->toBeNull();
});

it('borne la consultation à des mois, pas à des jours', function (): void {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        REPORTING_API.'/v1/reporting/report/scheme/*' => Http::response(['data' => [], 'meta' => ['count' => 0]]),
    ]);

    Einvoicing::for(dossierDeclarant())->reporting()
        ->reports(new DateTimeImmutable('2026-01-15'), new DateTimeImmutable('2026-12-31'))
        ->all();

    // Relevé en réel : « from must match YYYY-MM ». Une période de déclaration
    // n'est jamais plus fine qu'un mois.
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'from=2026-01')
        && ! str_contains($request->url(), 'from=2026-01-15'));
});
