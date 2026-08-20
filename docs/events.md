# Events

Le package ne fait rien de vos factures : il les reçoit, les vérifie, les range, puis vous prévient.

## Les huit événements

### `InboundInvoiceReceived`

Une facture fournisseur est arrivée et a été consignée. C'est le point d'entrée principal.

```php
use AmazScript\Einvoicing\Events\InboundInvoiceReceived;

public function handle(InboundInvoiceReceived $event): void
{
    $facture = $event->invoice;   // AmazScript\Einvoicing\Models\InboundInvoice
}
```

Au moment où l'événement part, les métadonnées comptables et les fichiers ont déjà été récupérés.
Si la plateforme était indisponible, la facture existe tout de même : `invoice_number` et les
montants peuvent être `null`, et un `einvoicing:events:retry` les complétera.

### `InvoiceStatusUpdated`

Un statut de cycle de vie a été reçu. Codes observés en conditions réelles :

```
SUBMITTED → ISSUED → RECEIVED → MADE_AVAILABLE → IN_HAND
```

et `REJECTED` lorsque la remise échoue. Cette liste n'est pas exhaustive : les codes appartiennent
à la plateforme et évoluent sans préavis, c'est pourquoi le package ne les modélise pas en
énumération. Un code inconnu est consigné tel quel plutôt que rejeté.

```php
$event->status->code;        // RECEIVED
$event->status->value;       // 202, le code réseau
$event->status->invoice_id;  // null si la facture est inconnue du package
```

Un statut peut précéder sa facture, ou porter sur un document jamais reçu. Il est alors conservé
sans rattachement, puis raccroché dès que la facture arrive.

Le rapprochement s'appuie sur le numéro attribué par l'émetteur et son SIREN : les identifiants
techniques diffèrent de chaque côté de la chaîne, celui du statut désignant la facture émise. Les
deux critères sont exigés ensemble — deux fournisseurs peuvent numéroter à l'identique.

### `InboundInvoiceInvalid`

Une facture entrante a été refusée par la plateforme. Elle **n'arrivera jamais** : c'est au
fournisseur de la corriger et de la réémettre.

```php
$event->providerInvoiceId;
$event->invoiceNumber;
$event->validationErrors;   // [['code' => 'VAL001', 'message' => 'Invalid XML structure']]
```

### `OutboundInvoiceSent`

La plateforme a pris une facture émise et lui a donné un identifiant.

```php
$event->invoice;                        // OutboundInvoice
$event->invoice->provider_invoice_id;
```

Prise n'est pas livrée : la suite arrive sous forme de statuts. C'est le moment où le document
cesse d'être le problème de votre application.

### `OutboundInvoiceFailed`

La plateforme a refusé une facture d'emblée — **rien n'est parti**.

```php
$event->invoice->failure_reason;   // ce que la plateforme a répondu
```

À distinguer de `OutboundInvoiceNotDelivered`, qui survient plus tard dans le cycle : ici le
document n'a jamais quitté la plateforme, et il faut le corriger avant toute nouvelle tentative.
Renvoyer les mêmes octets rend le même refus, sans rappeler la plateforme.

### `OutboundInvoiceNotDelivered`

Une facture émise n'a pas atteint son destinataire.

```php
$event->reason;    // ROUTING_FAILURE
$event->message;   // No route found for given key (electronicAddress : 0225:…)
```

Cas le plus courant : le destinataire n'est pas enregistré dans l'annuaire — voir
[Entreprises](entreprises.md) pour le vérifier **avant** d'émettre.

Cet événement porte le `WebhookEvent` brut, pas le modèle : il existe depuis la v0.1, où l'émission
n'était pas du ressort du package. Pour retrouver la facture concernée :

```php
Einvoicing::for($tenant)->sent()->find($event->providerInvoiceId);
```

### `TenantResolutionFailed`

Aucun dossier ne correspond au destinataire. **À surveiller** : la donnée est conservée mais
personne ne la verra tant que le dossier n'existe pas.

```php
$event->keys;     // RoutingKeys : externalId, siret, siren
$event->reason;
```

### `WebhookSignatureRejected`

Une requête s'est présentée avec une signature invalide. **À surveiller de près** : en régime normal
cet événement ne se produit jamais. Une série soudaine signale soit une rotation de secret mal
propagée, soit une tentative d'injection de fausses factures.

## Déclaration

```php
// app/Providers/AppServiceProvider.php

use AmazScript\Einvoicing\Events\InboundInvoiceReceived;
use App\Listeners\EnregistrerFactureFournisseur;
use Illuminate\Support\Facades\Event;

public function boot(): void
{
    Event::listen(InboundInvoiceReceived::class, EnregistrerFactureFournisseur::class);
}
```

Les événements sont émis depuis la file d'attente, pas depuis la requête HTTP : un listener lent ne
retarde aucune livraison.
