<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Enums\BuyerStatus;
use AmazScript\Einvoicing\Enums\RejectionReason;
use AmazScript\Einvoicing\Facades\Einvoicing;
use AmazScript\Einvoicing\Models\InboundInvoice;
use AmazScript\Einvoicing\Models\Tenant;
use Illuminate\Support\Facades\Http;

const REPONSE_API = 'https://api.example.test';

function dossierAcheteur(): Tenant
{
    return Tenant::query()->create([
        'tenantable_type' => 'App\\Models\\Company', 'tenantable_id' => '1',
        'customer_id' => 'cust-1', 'siren' => '384066650', 'siret' => null, 'active' => true,
    ]);
}

function factureAtraiter(Tenant $dossier): InboundInvoice
{
    return InboundInvoice::query()->create([
        'tenant_id' => $dossier->id, 'provider' => 'iopole',
        'provider_invoice_id' => 'inv-recue', 'invoice_number' => 'F-2026-001',
    ]);
}

/** @return array<string, mixed> le corps envoyé à la plateforme */
function corpsEnvoye(): array
{
    $corps = [];

    Http::assertSent(function ($request) use (&$corps): bool {
        if (str_contains($request->url(), '/status')) {
            $corps = $request->data();
        }

        return true;
    });

    return $corps;
}

beforeEach(function (): void {
    config()->set('einvoicing.drivers.iopole.base_url', REPONSE_API);
    config()->set('einvoicing.drivers.iopole.token_url', REPONSE_API.'/token');
    config()->set('einvoicing.drivers.iopole.client_id', 'client');
    config()->set('einvoicing.drivers.iopole.client_secret', 'secret');

    Http::fake([
        '*/token' => Http::response(['access_token' => 'jeton', 'expires_in' => 300]),
        REPONSE_API.'/v1/invoice/*/status' => Http::response(['type' => 'STATUS', 'id' => 'st-1'], 201),
    ]);
});

it('accuse réception d\'une facture', function (): void {
    $dossier = dossierAcheteur();
    factureAtraiter($dossier);

    Einvoicing::for($dossier)->invoice('inv-recue')->acknowledge();

    expect(corpsEnvoye()['code'])->toBe('IN_HAND');
});

it('approuve une facture pour paiement', function (): void {
    $dossier = dossierAcheteur();
    factureAtraiter($dossier);

    Einvoicing::for($dossier)->invoice('inv-recue')->approve('Bon pour paiement');

    expect(corpsEnvoye())->toMatchArray(['code' => 'APPROVED', 'message' => 'Bon pour paiement']);
});

it('refuse une facture en disant pourquoi', function (): void {
    $dossier = dossierAcheteur();
    factureAtraiter($dossier);

    Einvoicing::for($dossier)->invoice('inv-recue')
        ->refuse(RejectionReason::TotalAmountIncorrect, 'Le total ne correspond pas au bon de commande');

    $corps = corpsEnvoye();

    // « Refusée » sans motif laisse le fournisseur deviner, et la plateforme
    // rejette l'appel.
    expect($corps['code'])->toBe('REFUSED')
        ->and($corps['rejectionDetail']['reason'])->toBe('TOTAL_AMOUNT_INCORRECT')
        ->and($corps['rejectionDetail']['message'])->toContain('bon de commande');
});

it('accepte un motif de refus donné en clair', function (): void {
    $dossier = dossierAcheteur();
    factureAtraiter($dossier);

    // La liste est normative, mais elle peut évoluer : une chaîne reste permise.
    Einvoicing::for($dossier)->invoice('inv-recue')->refuse('MOTIF_FUTUR');

    expect(corpsEnvoye()['rejectionDetail']['reason'])->toBe('MOTIF_FUTUR');
});

it('signale un paiement avec son montant', function (): void {
    $dossier = dossierAcheteur();
    factureAtraiter($dossier);

    Einvoicing::for($dossier)->invoice('inv-recue')->reportPayment(1234.56, 'eur');

    $corps = corpsEnvoye();

    expect($corps['code'])->toBe('PAYMENT_SENT')
        ->and($corps['payment'][0])->toMatchArray(['amount' => 1234.56, 'currency' => 'EUR']);
});

it('interdit un refus sans motif', function (): void {
    $dossier = dossierAcheteur();
    factureAtraiter($dossier);

    Einvoicing::for($dossier)->invoice('inv-recue')->answer(BuyerStatus::Refused);
})->throws(InvalidArgumentException::class, 'requires a reason');

it('interdit un statut de paiement sans montant', function (): void {
    $dossier = dossierAcheteur();
    factureAtraiter($dossier);

    Einvoicing::for($dossier)->invoice('inv-recue')->answer(BuyerStatus::PaymentReceived);
})->throws(InvalidArgumentException::class, 'requires an amount');

it('laisse envoyer les statuts sans raccourci dédié', function (): void {
    $dossier = dossierAcheteur();
    factureAtraiter($dossier);

    Einvoicing::for($dossier)->invoice('inv-recue')->answer(BuyerStatus::Completed);

    expect(corpsEnvoye()['code'])->toBe('COMPLETED');
});

it('enchaîne les réponses sur la même facture', function (): void {
    $dossier = dossierAcheteur();
    factureAtraiter($dossier);

    Einvoicing::for($dossier)->invoice('inv-recue')->acknowledge()->approve();

    Http::assertSentCount(3); // le jeton, puis deux statuts
});

it('ne laisse pas un dossier répondre pour un autre', function (): void {
    $mien = dossierAcheteur();
    $autre = Tenant::query()->create([
        'tenantable_type' => 'App\\Models\\Company', 'tenantable_id' => '2',
        'customer_id' => 'cust-2', 'siren' => '111111111', 'siret' => null, 'active' => true,
    ]);
    factureAtraiter($autre);

    Einvoicing::for($mien)->invoice('inv-recue')->approve();
})->throws(RuntimeException::class, 'does not belong to this tenant');
