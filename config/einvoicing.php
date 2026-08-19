<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Tenancy\SiretResolver;

return [

    /*
    |--------------------------------------------------------------------------
    | Default accredited platform
    |--------------------------------------------------------------------------
    |
    | The package acts as a dematerialisation operator: it consumes an accredited
    | platform's API, and neither transmits nor certifies anything itself.
    |
    */

    'default' => env('EINVOICING_DRIVER', 'iopole'),

    'drivers' => [

        'iopole' => [
            'base_url' => env('IOPOLE_BASE_URL', 'https://api.ppd.iopole.fr'),

            // OAuth2 client_credentials authentication: the platform issues no
            // permanent token. The id and secret are exchanged for a short-lived
            // access token, renewed automatically.
            'token_url' => env('IOPOLE_TOKEN_URL'),
            'client_id' => env('IOPOLE_CLIENT_ID'),
            'client_secret' => env('IOPOLE_CLIENT_SECRET'),

            // The operator's customer-id, sent as a header. In a multi-tenant
            // estate, the tenant's own value takes precedence over this default.
            'customer_id' => env('IOPOLE_CUSTOMER_ID'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound webhook
    |--------------------------------------------------------------------------
    |
    | The platform accepts a single callbackUrl per direction for the whole
    | estate: routing to the right tenant is therefore up to the package.
    |
    | Apply no rate limiting to this route. A 429 returned to the platform would
    | make it retry the delivery for nothing.
    |
    | canonical_path: the path used to rebuild the signature's canonical string.
    | Set it only when a proxy or a tunnel rewrites the incoming URI, in which
    | case the locally computed signature would no longer match the one the
    | platform produced over the public path. Null uses the request's own URI.
    |
    | tolerance: the largest accepted gap, in seconds, between X-Timestamp and
    | the local clock. Replay protection.
    |
    */

    'webhook' => [
        'path' => env('EINVOICING_WEBHOOK_PATH', 'einvoicing/webhook'),
        'middleware' => ['api'],
        'secret' => env('EINVOICING_WEBHOOK_SECRET'),
        'canonical_path' => env('EINVOICING_WEBHOOK_CANONICAL_PATH'),
        'tolerance' => 300,
        'direction' => 'INBOUND',
    ],

    /*
    |--------------------------------------------------------------------------
    | Invoice file storage
    |--------------------------------------------------------------------------
    |
    | Files are stored under {path}/{invoice id}/. A file is read back from the
    | disk recorded with it, not from the one configured today, so changing disk
    | does not make past invoices unreadable.
    |
    */

    'storage' => [
        'disk' => env('EINVOICING_DISK', 'local'),
        'path' => 'einvoicing',
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | The webhook controller performs no business processing: it verifies,
    | deduplicates, dispatches and answers. Everything else goes to the queue,
    | so a worker must be running or invoices are received but never used.
    |
    */

    'queue' => [
        'connection' => env('EINVOICING_QUEUE_CONNECTION'),
        'name' => 'einvoicing',
    ],

    /*
    |--------------------------------------------------------------------------
    | Event retention
    |--------------------------------------------------------------------------
    |
    | How long an already-processed webhook event is kept before
    | einvoicing:events:prune may remove it. Unrouted and failed events are never
    | pruned: they hold data nobody has acted upon yet.
    |
    */

    'events' => [
        'retention_days' => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant resolution
    |--------------------------------------------------------------------------
    |
    | Replaceable by any class implementing the TenantResolver contract.
    |
    */

    'tenant_resolver' => SiretResolver::class,

];
