<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Webhook;

use AmazScript\Einvoicing\Contracts\SignatureVerifier;

/**
 * Vérification HMAC-SHA256 des requêtes entrantes de la plateforme.
 *
 * Chaîne canonique :
 *
 *     {X-Timestamp}\n{MÉTHODE}\n{chemin_avec_query}\n{checksum}
 *
 * Le checksum porte sur le corps brut intégral en `application/json`, mais
 * uniquement sur le contenu du champ fichier en `multipart/form-data` — les
 * autres champs du formulaire en sont exclus. C'est la source d'erreur
 * principale de cette intégration, et c'est à l'appelant de fournir la bonne
 * source : cette classe ne devine pas le type de contenu.
 *
 * Signature et checksum sont en hexadécimal.
 */
final class HmacSignatureVerifier implements SignatureVerifier
{
    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds = 300,
    ) {}

    /**
     * @param  array<string, string>  $headers
     */
    public function verify(
        array $headers,
        string $method,
        string $pathWithQuery,
        string $checksumSource,
    ): bool {
        // Un secret absent ne doit jamais valoir absence de contrôle.
        if ($this->secret === '') {
            return false;
        }

        $timestamp = $headers['x-timestamp'] ?? null;
        $signature = $headers['x-signature'] ?? null;

        if (! is_string($timestamp) || ! is_string($signature) || $timestamp === '' || $signature === '') {
            return false;
        }

        if (! $this->timestampIsFresh($timestamp)) {
            return false;
        }

        $checksum = hash('sha256', $checksumSource);

        // L'en-tête de checksum est optionnel, mais s'il est fourni il doit
        // concorder : on le vérifie avant la signature, comme le veut la doc.
        $received = $headers['x-checksum'] ?? null;

        if (is_string($received) && $received !== '' && ! hash_equals($checksum, $received)) {
            return false;
        }

        $canonical = $timestamp."\n".strtoupper($method)."\n".$pathWithQuery."\n".$checksum;
        $expected = hash_hmac('sha256', $canonical, $this->secret);

        // Comparaison en temps constant : une comparaison naïve laisserait fuiter
        // la signature attendue, octet par octet, par mesure du temps de réponse.
        return hash_equals($expected, $signature);
    }

    private function timestampIsFresh(string $timestamp): bool
    {
        if (! is_numeric($timestamp)) {
            return false;
        }

        // Le décalage est pris en valeur absolue : une horloge en avance est
        // aussi suspecte qu'une requête rejouée après coup.
        return abs(time() - $this->toSeconds((int) $timestamp)) <= $this->toleranceSeconds;
    }

    /**
     * Ramène l'horodatage en secondes.
     *
     * Constaté sur une livraison réelle : la plateforme envoie des millisecondes,
     * ce que sa documentation ne mentionne nulle part. Comparé tel quel à time(),
     * l'écart se compte en milliers d'années et toute livraison authentique est
     * rejetée. L'unité n'étant pas contractuelle, les deux sont acceptées.
     *
     * Le seuil correspond à l'an 5138 en secondes, soit novembre 1973 en
     * millisecondes : aucune valeur plausible ne peut être classée à tort.
     */
    private function toSeconds(int $timestamp): int
    {
        return $timestamp > 100_000_000_000 ? intdiv($timestamp, 1000) : $timestamp;
    }
}
