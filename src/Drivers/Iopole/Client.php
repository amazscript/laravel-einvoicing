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
 * HTTP client for the Iopole platform.
 *
 * It only carries: authenticate the request, send it, and turn an error response
 * into an exception. No business decision here — a 409 DUPLICATE_RESOURCE is
 * reported, not interpreted: deciding that a duplicate is a success belongs to
 * the caller.
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
     * Returns an identical client acting under another customer-id. The estate
     * being multi-tenant, every call must carry the right one.
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
     * Walks a paginated endpoint without ever loading everything into memory.
     *
     * Paginated lists answer `{ data: [...], meta: { offset, limit, count } }`,
     * where `count` is the total. Pages are fetched one at a time, and iteration
     * stops as soon as a page comes back empty — a guard against looping forever
     * should the announced total disagree with what is served.
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
     * Fetches a body without attempting to decode it.
     *
     * Needed for files (XML, PDF, attachments) but also for endpoints returning a
     * bare value: /v1/config/customer/id answers in text/html with the identifier
     * alone, despite documentation announcing application/json.
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

        // A 401 may simply mean the cached token was revoked: it is discarded
        // and the call retried once, with a fresh one.
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
            throw new EinvoicingServerException('Platform unreachable: '.$e->getMessage());
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
            throw new EinvoicingServerException('Unreadable platform response: JSON expected.');
        }

        return $decoded;
    }
}
