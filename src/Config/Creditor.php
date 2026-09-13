<?php

declare(strict_types=1);

namespace RSickenberg\InvoicePhpMaker\Config;

/**
 * Your billing identity: the address block printed on the invoice and used
 * as the QR-bill creditor.
 */
final readonly class Creditor
{
    public function __construct(
        public string $name,
        public string $street,
        public string $houseNumber,
        public string $postalCode,
        public string $city,
        public string $country,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            street: $data['street'],
            houseNumber: (string) $data['houseNumber'],
            postalCode: (string) $data['postalCode'],
            city: $data['city'],
            country: $data['country'] ?? 'CH',
        );
    }
}
