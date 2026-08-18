<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Exceptions;

/**
 * 5xx : panne côté plateforme. Toujours rejouable.
 */
final class EinvoicingServerException extends EinvoicingException {}
