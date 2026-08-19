<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Facades;

use AmazScript\Einvoicing\Api\DirectoryQuery;
use AmazScript\Einvoicing\Api\TenantScope;
use AmazScript\Einvoicing\Models\Tenant;
use Illuminate\Support\Facades\Facade;

/**
 * Façade de l'API publique.
 *
 * Seule façade du package : le code interne reçoit ses dépendances par
 * constructeur, ce qui le laisse testable sans conteneur.
 *
 * @method static TenantScope for(Tenant $tenant)
 * @method static TenantScope operator()
 * @method static DirectoryQuery directory()
 *
 * @see \AmazScript\Einvoicing\Einvoicing
 */
final class Einvoicing extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \AmazScript\Einvoicing\Einvoicing::class;
    }
}
