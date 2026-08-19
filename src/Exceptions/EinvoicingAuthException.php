<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Exceptions;

/**
 * 401: token missing, expired or invalid. 403: valid token, insufficient role.
 */
final class EinvoicingAuthException extends EinvoicingException {}
