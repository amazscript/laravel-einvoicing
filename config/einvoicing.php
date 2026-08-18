<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Tenancy\SiretResolver;

return [

    /*
    |--------------------------------------------------------------------------
    | Plateforme Agréée par défaut
    |--------------------------------------------------------------------------
    |
    | Le package est un Opérateur de Dématérialisation : il consomme l'API
    | d'une Plateforme Agréée, il ne transmet ni ne certifie rien lui-même.
    |
    */

    'default' => env('EINVOICING_DRIVER', 'iopole'),

    'drivers' => [

        'iopole' => [
            'base_url' => env('IOPOLE_BASE_URL', 'https://api.ppd.iopole.fr'),

            // Authentification OAuth2 client_credentials : la plateforme ne délivre
            // pas de jeton permanent. On échange l'identifiant et le secret contre
            // un access_token à durée de vie courte, renouvelé automatiquement.
            'token_url' => env('IOPOLE_TOKEN_URL'),
            'client_id' => env('IOPOLE_CLIENT_ID'),
            'client_secret' => env('IOPOLE_CLIENT_SECRET'),

            // Customer-id de l'opérateur, envoyé en en-tête. En multi-tenant, celui
            // du tenant concerné prime sur cette valeur par défaut.
            'customer_id' => env('IOPOLE_CUSTOMER_ID'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook entrant
    |--------------------------------------------------------------------------
    |
    | La Plateforme Agréée n'accepte qu'un seul callbackUrl par direction pour
    | tout le parc : le routage vers le bon tenant est à la charge du package.
    |
    | canonical_path : chemin utilisé pour reconstruire la chaîne canonique de
    | signature. À renseigner uniquement si un proxy ou un tunnel réécrit l'URI
    | reçue, auquel cas la signature calculée localement ne correspondrait plus
    | à celle produite par la plateforme. Null = on utilise l'URI de la requête.
    |
    | tolerance : écart maximal toléré, en secondes, entre X-Timestamp et
    | l'horloge locale. Protection anti-rejeu.
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
    | Stockage des fichiers de facture
    |--------------------------------------------------------------------------
    */

    'storage' => [
        'disk' => env('EINVOICING_DISK', 'local'),
        'path' => 'einvoicing',
    ],

    /*
    |--------------------------------------------------------------------------
    | File d'attente
    |--------------------------------------------------------------------------
    |
    | Le contrôleur webhook n'exécute aucun traitement métier : il vérifie,
    | déduplique, dispatche et répond 2xx. Tout le reste passe en queue.
    |
    */

    'queue' => [
        'connection' => env('EINVOICING_QUEUE_CONNECTION'),
        'name' => 'einvoicing',
    ],

    /*
    |--------------------------------------------------------------------------
    | Résolution du tenant
    |--------------------------------------------------------------------------
    |
    | Remplaçable par toute classe implémentant le contrat TenantResolver.
    | Implémentation fournie à partir de D07.
    |
    */

    'tenant_resolver' => SiretResolver::class,

];
