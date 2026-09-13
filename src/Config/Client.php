<?php

declare(strict_types=1);

namespace RSickenberg\InvoicePhpMaker\Config;

/**
 * A client, with optional overrides (rate, payment term, language) that fall
 * back to AppConfig's defaults when they are null.
 */
final readonly class Client
{
    public function __construct(
        public string $id,
        public string $name,
        public string $street,
        public string $houseNumber,
        public string $postalCode,
        public string $city,
        public string $country,
        public ?float $hourlyRate = null,
        public ?int $paymentTermDays = null,
        public ?string $language = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'street' => $this->street,
            'houseNumber' => $this->houseNumber,
            'postalCode' => $this->postalCode,
            'city' => $this->city,
            'country' => $this->country,
            'hourlyRate' => $this->hourlyRate,
            'paymentTermDays' => $this->paymentTermDays,
            'language' => $this->language,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            name: $data['name'],
            street: $data['street'],
            houseNumber: (string) $data['houseNumber'],
            postalCode: (string) $data['postalCode'],
            city: $data['city'],
            country: $data['country'] ?? 'CH',
            hourlyRate: isset($data['hourlyRate']) ? (float) $data['hourlyRate'] : null,
            paymentTermDays: isset($data['paymentTermDays']) ? (int) $data['paymentTermDays'] : null,
            language: $data['language'] ?? null,
        );
    }
}
