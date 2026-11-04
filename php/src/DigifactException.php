<?php

declare(strict_types=1);

namespace Digifact\Fel;

/**
 * Base exception for all Digifact FEL SDK errors.
 */
class DigifactException extends \RuntimeException
{
    protected ?int $apiCode;
    protected array $raw;
    protected bool $retryable;

    public function __construct(
        string $message,
        ?int $apiCode = null,
        array $raw = [],
        ?\Throwable $previous = null,
        bool $retryable = false
    ) {
        parent::__construct($message, 0, $previous);
        $this->apiCode = $apiCode;
        $this->raw = $raw;
        $this->retryable = $retryable;
    }

    public function getApiCode(): ?int
    {
        return $this->apiCode;
    }

    public function getRaw(): array
    {
        return $this->raw;
    }

    /**
     * Whether the same request may succeed if sent again (HTTP 5xx).
     */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}


