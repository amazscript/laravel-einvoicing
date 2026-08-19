<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Exceptions;

/**
 * 5xx: the platform is down. Always worth retrying.
 */
final class EinvoicingServerException extends EinvoicingException {}
