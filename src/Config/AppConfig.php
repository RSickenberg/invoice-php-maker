<?php

declare(strict_types=1);

namespace RSickenberg\InvoicePhpMaker\Config;

/**
 * Your own billing information, loaded from config/config.json.
 */
final readonly class AppConfig
{
    /**
     * @param list<string> $categories
     */
    public function __construct(
        public string $creditorName,
        public string $creditorStreet,
        public string $creditorHouseNumber,
        public string $creditorPostalCode,
        public string $creditorCity,
        public string $creditorCountry,
        public string $iban,
        public ?string $email,
        public ?string $phone,
        public ?string $website,
        public float $defaultHourlyRate,
        public string $defaultCurrency,
        public int $defaultPaymentTermDays,
        public string $defaultLanguage,
        public bool $vatEnabled,
        public array $categories,
    ) {}
}
