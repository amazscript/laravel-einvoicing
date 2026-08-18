<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Contracts;

/**
 * Vérifie qu'une requête entrante provient bien de la plateforme et n'a pas été
 * altérée en chemin.
 *
 * Remplaçable : une plateforme différente signe autrement (v0.4).
 */
interface SignatureVerifier
{
    /**
     * @param  array<string, string>  $headers  en-têtes en minuscules
     * @param  string  $checksumSource  contenu à hacher : le corps brut intégral en
     *                                  JSON, le contenu du champ fichier seul en multipart
     */
    public function verify(
        array $headers,
        string $method,
        string $pathWithQuery,
        string $checksumSource,
    ): bool;
}
