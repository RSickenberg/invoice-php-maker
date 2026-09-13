<?php

declare(strict_types=1);

namespace RSickenberg\InvoicePhpMaker\Config;

/**
 * Global default values for a new invoice: hourly rate, currency, payment
 * term and language. Each one is overridable per client.
 */
final readonly class Defaults
{
    public function __construct(
        public float $hourlyRate,
        public string $currency,
        public int $paymentTermDays,
        public string $language,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            hourlyRate: (float) $data['hourlyRate'],
            currency: $data['currency'] ?? 'CHF',
            paymentTermDays: (int) $data['paymentTermDays'],
            language: $data['language'] ?? 'fr',
        );
    }
}
