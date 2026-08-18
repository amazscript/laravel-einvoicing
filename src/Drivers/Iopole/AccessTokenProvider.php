<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Drivers\Iopole;

use AmazScript\Einvoicing\Exceptions\EinvoicingAuthException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Obtient et conserve le jeton d'accès OAuth2 de la plateforme.
 *
 * Le flux est client_credentials : on échange un identifiant et un secret contre
 * un access_token à durée de vie courte. Le jeton est mis en cache jusqu'à peu
 * avant son expiration pour éviter un aller-retour à chaque appel d'API.
 *
 * Ni le secret ni le jeton n'apparaissent dans un message d'exception.
 */
final class AccessTokenProvider
{
    /**
     * Marge de sécurité retirée à la durée de vie annoncée, pour ne jamais
     * présenter un jeton qui expirerait pendant le trajet réseau.
     */
    private const EXPIRY_MARGIN_SECONDS = 60;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly Cache $cache,
        private readonly string $tokenUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {}

    public function token(): string
    {
        $cached = $this->cache->get($this->cacheKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->fetchAndStore();
    }

    /**
     * Oublie le jeton mémorisé. À appeler sur un 401 pour forcer un renouvellement
     * plutôt que de rejouer indéfiniment un jeton révoqué.
     */
    public function forget(): void
    {
        $this->cache->forget($this->cacheKey());
    }

    private function fetchAndStore(): string
    {
        if ($this->clientId === '' || $this->clientSecret === '' || $this->tokenUrl === '') {
            throw new EinvoicingAuthException(
                'Identifiants OAuth2 absents : renseignez IOPOLE_TOKEN_URL, IOPOLE_CLIENT_ID et IOPOLE_CLIENT_SECRET.'
            );
        }

        try {
            $response = $this->http
                ->asForm()
                ->post($this->tokenUrl, [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ]);
        } catch (ConnectionException $e) {
            throw new EinvoicingAuthException('Serveur d\'authentification injoignable.');
        }

        if ($response->failed()) {
            // Le corps de la réponse n'est pas repris : il peut contenir l'identifiant client.
            throw new EinvoicingAuthException(
                'Authentification refusée par la plateforme.',
                $response->status()
            );
        }

        $payload = $response->json();

        if (! is_array($payload) || ! isset($payload['access_token']) || ! is_string($payload['access_token'])) {
            throw new EinvoicingAuthException('Réponse d\'authentification inexploitable : access_token absent.');
        }

        $token = $payload['access_token'];
        $lifetime = isset($payload['expires_in']) && is_numeric($payload['expires_in'])
            ? (int) $payload['expires_in']
            : 0;

        $ttl = $lifetime - self::EXPIRY_MARGIN_SECONDS;

        if ($ttl > 0) {
            $this->cache->put($this->cacheKey(), $token, $ttl);
        }

        return $token;
    }

    /**
     * La clé est dérivée par hachage : l'identifiant client ne doit pas se
     * retrouver en clair dans le magasin de cache ni dans ses logs.
     */
    private function cacheKey(): string
    {
        return 'einvoicing:iopole:token:'.hash('sha256', $this->tokenUrl.'|'.$this->clientId);
    }
}
