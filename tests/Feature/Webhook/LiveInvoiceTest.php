<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use AmazScript\Einvoicing\Events\InboundInvoiceReceived;
use AmazScript\Einvoicing\Models\InboundInvoice;
use AmazScript\Einvoicing\Models\Status;
use AmazScript\Einvoicing\Models\Tenant;
use AmazScript\Einvoicing\Models\WebhookEvent;
use AmazScript\Einvoicing\Webhook\HmacSignatureVerifier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/**
 * Vecteur issu d'une facture entrante réellement livrée par la plateforme :
 * multipart, fichier PDF, champs annexes invoiceId et senderAcceptStatus.
 */
function factureLivree(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/iopole-live-invoice-webhook.json'), true);
}

function envoyerFactureLivree(?string $adresse = null): TestResponse
{
    $v = factureLivree();

    // Le checksum porte sur le contenu du fichier seul, jamais sur les champs.
    $timestamp = (string) (time() * 1000);
    $checksum = hash('sha256', $v['file']['content']);
    $canonique = $timestamp."\nPOST\n".$v['pathWithQuery']."\n".$checksum;

    return test()->call(
        'POST',
        $v['pathWithQuery'],
        $v['formFields'],
        [],
        ['file' => UploadedFile::fake()->createWithContent($v['file']['originalName'], $v['file']['content'])],
        [
            'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $canonique, $v['secret']),
            'HTTP_X_CHECKSUM' => $checksum,
            'HTTP_X_IDEMPOTENCY_KEY' => $v['headers']['x-idempotency-key'],
            'HTTP_X_TARGET_ELECTRONIC_ADDRESS' => $adresse ?? $v['headers']['x-target-electronic-address'],
        ],
    );
}

beforeEach(function (): void {
    config()->set('einvoicing.webhook.secret', factureLivree()['secret']);

    Tenant::query()->create([
        'tenantable_type' => 'App\\Models\\Company', 'tenantable_id' => '1',
        'customer_id' => 'cust-1', 'siren' => '111111111', 'siret' => null, 'active' => true,
    ]);
});

it('valide la signature d\'une facture réellement livrée', function (): void {
    $v = factureLivree();

    // Contrôle direct sur les octets figés : le checksum porte sur le fichier.
    expect((new HmacSignatureVerifier($v['secret'], PHP_INT_MAX))
        ->verify($v['headers'], $v['method'], $v['pathWithQuery'], $v['file']['content']))->toBeTrue();
});

it('rejette la même facture signée sur les champs annexes', function (): void {
    $v = factureLivree();
    $mauvaise = $v['file']['content'].http_build_query($v['formFields']);

    expect((new HmacSignatureVerifier($v['secret'], PHP_INT_MAX))
        ->verify($v['headers'], $v['method'], $v['pathWithQuery'], $mauvaise))->toBeFalse();
});

it('accepte une facture entrante multipart', function (): void {
    expect(envoyerFactureLivree()->status())->toBe(202);
});

it('consigne la facture reçue', function (): void {
    envoyerFactureLivree();

    $facture = InboundInvoice::query()->first();

    expect(InboundInvoice::query()->count())->toBe(1)
        ->and($facture->provider_invoice_id)->toBe(factureLivree()['formFields']['invoiceId'])
        ->and($facture->provider)->toBe('iopole')
        ->and($facture->tenant_id)->not->toBeNull()
        ->and($facture->raw_metadata)->toHaveKey('senderAcceptStatus');
});

it('ne duplique pas une facture livrée plusieurs fois', function (): void {
    envoyerFactureLivree();
    envoyerFactureLivree();
    envoyerFactureLivree();

    expect(InboundInvoice::query()->count())->toBe(1)
        ->and(WebhookEvent::query()->count())->toBe(1);
});

it('émet l\'événement attendu par l\'application', function (): void {
    Event::fake([InboundInvoiceReceived::class]);

    envoyerFactureLivree();

    Event::assertDispatched(InboundInvoiceReceived::class);
});

it('raccroche un statut arrivé avant sa facture', function (): void {
    $invoiceId = factureLivree()['formFields']['invoiceId'];

    // Cas réel : les statuts de cycle de vie précèdent parfois la facture.
    Status::query()->create([
        'provider' => 'iopole', 'provider_status_id' => 'sta-avance',
        'code' => 'RECEIVED', 'payload' => ['invoiceId' => $invoiceId],
    ]);

    envoyerFactureLivree();

    expect(Status::query()->first()->invoice_id)->toBe(InboundInvoice::query()->first()->id);
});

it('conserve sans traiter une facture destinée à un tenant inconnu', function (): void {
    // Un second dossier actif désactive le repli « tenant unique », sans quoi
    // toute livraison serait routée vers le seul tenant existant.
    Tenant::query()->create([
        'tenantable_type' => 'App\\Models\\Company', 'tenantable_id' => '2',
        'customer_id' => 'cust-2', 'siren' => '222222222', 'siret' => null, 'active' => true,
    ]);

    $reponse = envoyerFactureLivree('0225:999999999');

    expect($reponse->status())->toBe(202)
        ->and(InboundInvoice::query()->count())->toBe(0)
        ->and(WebhookEvent::query()->first()->status)->toBe(WebhookEventStatus::Unrouted);
});
