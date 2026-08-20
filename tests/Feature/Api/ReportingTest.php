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

    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        REPORTING_API.'/v1/reporting/*' => Http::response(['type' => 'STREAM', 'id' => 'rep-1'], 201),
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
