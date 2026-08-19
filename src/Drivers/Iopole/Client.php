<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Drivers\Iopole;

use AmazScript\Einvoicing\Drivers\Iopole\ResponseMappers\ErrorMapper;
use AmazScript\Einvoicing\Exceptions\EinvoicingServerException;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\LazyCollection;

/**
 * Client HTTP de la plateforme Iopole.
 *
 * Il ne fait que transporter : authentifier la requête, l'envoyer, et traduire
 * une réponse d'erreur en exception. Aucune décision métier ici — notamment,
 * un 409 DUPLICATE_RESOURCE est signalé, pas interprété : c'est à l'appelant
 * de décider qu'un doublon est un succès.
 */
final class Client
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly AccessTokenProvider $tokens,
        private readonly ErrorMapper $errors,
        private readonly string $baseUrl,
        private readonly string $customerId = '',
    ) {}

    /**
     * Retourne un client identique agissant pour le compte d'un autre customer-id.
     * Le parc étant multi-tenant, chaque appel doit porter celui du bon dossier.
     */
    public function forCustomer(string $customerId): self
    {
        return new self($this->http, $this->tokens, $this->errors, $this->baseUrl, $customerId);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->decode($this->send('get', $path, $query));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<mixed>
     */
    public function post(string $path, array $payload = []): array
    {
        return $this->decode($this->send('post', $path, $payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<mixed>
     */
    public function put(string $path, array $payload = []): array
    {
        return $this->decode($this->send('put', $path, $payload));
    }

    /**
     * @return array<mixed>
     */
    public function delete(string $path): array
    {
        return $this->decode($this->send('delete', $path, []));
    }

    /**
     * Parcourt un endpoint paginé sans jamais tout charger en mémoire.
     *
     * Les listes paginées répondent `{ data: [...], meta: { offset, limit, count } }`,
     * `count` donnant le total. On avance page par page, et l'itération s'arrête
     * dès qu'une page revient vide — une garantie contre la boucle infinie si le
     * total annoncé ne correspond pas à ce qui est servi.
     *
     * @param  array<string, mixed>  $query
     * @return LazyCollection<int, array<mixed>>
     */
    public function paginate(string $path, array $query = [], int $perPage = 50): LazyCollection
    {
        return LazyCollection::make(function () use ($path, $query, $perPage): Generator {
            $offset = 0;

            do {
                $reponse = $this->get($path, array_merge($query, [
                    'offset' => $offset,
                    'limit' => $perPage,
                ]));

                $lignes = is_array($reponse['data'] ?? null) ? $reponse['data'] : [];
                $meta = is_array($reponse['meta'] ?? null) ? $reponse['meta'] : [];
                $total = is_numeric($meta['count'] ?? null) ? (int) $meta['count'] : null;

                foreach ($lignes as $ligne) {
                    if (is_array($ligne)) {
                        yield $ligne;
                    }
                }

                $offset += $perPage;
            } while ($lignes !== [] && ($total === null || $offset < $total));
        });
    }

    /**
     * Récupère un corps sans tenter de le décoder.
     *
     * Nécessaire pour les fichiers (XML, PDF, pièces jointes) mais aussi pour
     * certains endpoints qui renvoient une valeur nue : /v1/config/customer/id
     * répond en text/html avec l'identifiant seul, malgré une documentation qui
     * annonce de l'application/json.
     */
    public function raw(string $path): string
    {
        return $this->send('get', $path, [])->body();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(string $method, string $path, array $data): Response
    {
        $response = $this->dispatch($method, $path, $data);

        // Un 401 peut simplement signifier que le jeton mémorisé a été révoqué :
        // on le jette et on retente une fois, avec un jeton neuf.
        if ($response->status() === 401) {
            $this->tokens->forget();
            $response = $this->dispatch($method, $path, $data);
        }

        if ($response->failed()) {
            throw $this->errors->map($response);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function dispatch(string $method, string $path, array $data): Response
    {
        try {
            return $this->request()->{$method}($path, $data);
        } catch (ConnectionException $e) {
            throw new EinvoicingServerException('Plateforme injoignable : '.$e->getMessage());
        }
    }

    private function request(): PendingRequest
    {
        $request = $this->http
            ->baseUrl(rtrim($this->baseUrl, '/'))
            ->withToken($this->tokens->token())
            ->acceptJson()
            ->asJson();

        return $this->customerId === ''
            ? $request
            : $request->withHeaders(['customer-id' => $this->customerId]);
    }

    /**
     * @return array<mixed>
     */
    private function decode(Response $response): array
    {
        if ($response->body() === '') {
            return [];
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new EinvoicingServerException('Réponse de la plateforme illisible : JSON attendu.');
        }

        return $decoded;
    }
}
