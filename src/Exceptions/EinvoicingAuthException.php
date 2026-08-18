<?php

declare(strict_types=1);

namespace AmazScript\Einvoicing\Exceptions;

/**
 * 401 : jeton absent, expiré ou invalide. 403 : jeton valide, rôle insuffisant.
 */
final class EinvoicingAuthException extends EinvoicingException {}
