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
        public Creditor $creditor,
        public string $iban,
        public ?string $email,
        public ?string $phone,
        public ?string $website,
        public Defaults $defaults,
        public bool $vatEnabled,
        public array $categories,
    ) {}
}
