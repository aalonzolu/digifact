<?php

declare(strict_types=1);

namespace Digifact\Fel;

/**
 * Result of a DTE emission.
 */
class DteResult
{
    public readonly string $authNumber;
    public readonly string $series;
    public readonly string $number;
    public readonly string $issueDateTime;
    public readonly array $raw;

    public function __construct(
        string $authNumber,
        string $series,
        string $number,
        string $issueDateTime,
        array $raw = []
    ) {
        $this->authNumber = $authNumber;
        $this->series = $series;
        $this->number = $number;
        $this->issueDateTime = $issueDateTime;
        $this->raw = $raw;
    }

    /**
     * Create a DteResult from the raw API response array.
     */
    public static function fromArray(array $data): self
    {
        $auth = $data['authNumber'] ?? $data['Autorizacion'] ?? '';
        $series = $data['batch'] ?? $data['Serie'] ?? '';
        $number = (string)($data['serial'] ?? $data['Numero'] ?? '');
        $tsRaw = $data['issuedTimeStamp'] ?? $data['FechaEmision'] ?? '';
        $issueDateTime = str_contains($tsRaw, 'T') ? str_replace('T', ' ', $tsRaw) : $tsRaw;

        return new self($auth, $series, $number, $issueDateTime, $data);
    }
}
