<?php

declare(strict_types=1);

use AmazScript\Einvoicing\Enums\WebhookEventStatus;

it('considère comme rejouables les seuls états qui conservent une donnée non exploitée', function (
    WebhookEventStatus $status,
    bool $retryable,
): void {
    expect($status->isRetryable())->toBe($retryable);
})->with([
    'reçu, traitement en cours' => [WebhookEventStatus::Received, false],
    'traité' => [WebhookEventStatus::Processed, false],
    'tenant introuvable' => [WebhookEventStatus::Unrouted, true],
    'traitement en échec' => [WebhookEventStatus::Failed, true],
]);
