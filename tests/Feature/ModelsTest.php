<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Enums\InvoiceFileKind;
use AmazScript\Einvoicing\Enums\InvoiceFormat;
use AmazScript\Einvoicing\Enums\WebhookEventStatus;
use AmazScript\Einvoicing\Models\InboundInvoice;
use AmazScript\Einvoicing\Models\InvoiceFile;
use AmazScript\Einvoicing\Models\Status;
use AmazScript\Einvoicing\Models\Tenant;
use AmazScript\Einvoicing\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;

function tenant(array $attributes = []): Tenant
{
    return Tenant::create(array_merge([
        'tenantable_type' => 'App\\Models\\Company',
        'tenantable_id' => '42',
        'customer_id' => 'CUST-SECRET-123',
        'siren' => '123456789',
        'siret' => '12345678900011',
    ], $attributes));
}

it('ne stocke jamais le customer-id en clair', function (): void {
    $tenant = tenant();

    $stored = DB::table('einvoicing_tenants')->where('id', $tenant->id)->value('customer_id');

    expect($stored)->not->toBe('CUST-SECRET-123')
        ->and($tenant->fresh()->customer_id)->toBe('CUST-SECRET-123');
});

it('génère un identifiant uuid', function (): void {
    expect(tenant()->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});

it('convertit le format de facture en énumération', function (): void {
    $invoice = InboundInvoice::create([
        'tenant_id' => tenant()->id,
        'provider' => 'iopole',
        'provider_invoice_id' => 'INV-001',
        'format' => InvoiceFormat::Facturx,
        'amount_total' => '1234.56',
        'raw_metadata' => ['source' => 'webhook'],
    ]);

    $fresh = $invoice->fresh();

    expect($fresh->format)->toBe(InvoiceFormat::Facturx)
        ->and($fresh->amount_total)->toBe('1234.56')
        ->and($fresh->raw_metadata)->toBe(['source' => 'webhook']);
});

it('met à jour la facture existante au lieu de la dupliquer', function (): void {
    $attributes = ['provider' => 'iopole', 'provider_invoice_id' => 'INV-002'];

    InboundInvoice::updateOrCreate($attributes, ['invoice_number' => 'F-2026-001']);
    InboundInvoice::updateOrCreate($attributes, ['invoice_number' => 'F-2026-001-corrigee']);

    expect(InboundInvoice::count())->toBe(1)
        ->and(InboundInvoice::first()->invoice_number)->toBe('F-2026-001-corrigee');
});

it('relie une facture à son tenant, ses fichiers et ses statuts', function (): void {
    $tenant = tenant();

    $invoice = InboundInvoice::create([
        'tenant_id' => $tenant->id,
        'provider' => 'iopole',
        'provider_invoice_id' => 'INV-003',
    ]);

    InvoiceFile::create([
        'invoice_id' => $invoice->id,
        'kind' => InvoiceFileKind::Xml,
        'disk' => 'local',
        'path' => 'einvoicing/inv-003.xml',
        'checksum' => str_repeat('b', 64),
    ]);

    Status::create([
        'invoice_id' => $invoice->id,
        'provider' => 'iopole',
        'provider_status_id' => 'STA-003',
        'code' => 'RECEIVED',
        'value' => '202',
    ]);

    expect($invoice->tenant->is($tenant))->toBeTrue()
        ->and($invoice->files)->toHaveCount(1)
        ->and($invoice->files->first()->kind)->toBe(InvoiceFileKind::Xml)
        ->and($invoice->statuses)->toHaveCount(1)
        ->and($tenant->invoices)->toHaveCount(1);
});

it('détache les factures d\'un tenant supprimé sans les perdre', function (): void {
    $tenant = tenant();

    InboundInvoice::create([
        'tenant_id' => $tenant->id,
        'provider' => 'iopole',
        'provider_invoice_id' => 'INV-004',
    ]);

    $tenant->delete();

    expect(InboundInvoice::count())->toBe(1)
        ->and(InboundInvoice::first()->tenant_id)->toBeNull();
});

it('supprime les fichiers avec la facture qui les porte', function (): void {
    $invoice = InboundInvoice::create([
        'provider' => 'iopole',
        'provider_invoice_id' => 'INV-005',
    ]);

    InvoiceFile::create([
        'invoice_id' => $invoice->id,
        'kind' => InvoiceFileKind::ReadablePdf,
        'disk' => 'local',
        'path' => 'einvoicing/inv-005.pdf',
        'checksum' => str_repeat('c', 64),
    ]);

    $invoice->delete();

    expect(InvoiceFile::count())->toBe(0);
});

it('convertit le statut d\'un événement webhook en énumération', function (): void {
    $event = WebhookEvent::create([
        'event_id' => 'EVT-100',
        'event_type' => 'INVOICE_INBOUND',
        'status' => WebhookEventStatus::Unrouted,
        'payload' => ['recipients' => [['siret' => '12345678900011']]],
        'received_at' => now(),
    ]);

    $fresh = $event->fresh();

    expect($fresh->status)->toBe(WebhookEventStatus::Unrouted)
        ->and($fresh->status->isRetryable())->toBeTrue()
        ->and($fresh->payload)->toBe(['recipients' => [['siret' => '12345678900011']]]);
});
