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

    public function __construct(string $message, ?int $apiCode = null, array $raw = [], \Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->apiCode = $apiCode;
        $this->raw = $raw;
    }

    public function getApiCode(): ?int
    {
        return $this->apiCode;
    }

    public function getRaw(): array
    {
        return $this->raw;
    }
}


