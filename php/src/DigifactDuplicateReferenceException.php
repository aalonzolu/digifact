<?php

declare(strict_types=1);

namespace Digifact\Fel;

/**
 * The internal reference was already sent to certify (Digifact 9022 / BUS-003).
 *
 * The original DTE may already be certified: look it up with
 * {@see DigifactClient::findByInternalReference()} instead of issuing again.
 */
class DigifactDuplicateReferenceException extends DigifactApiException {}
