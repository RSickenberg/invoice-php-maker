<?php

declare(strict_types=1);

namespace RSickenberg\InvoicePhpMaker\Config;

use RuntimeException;

final readonly class AppConfigRepository
{
    public function __construct(private string $path) {}

    /**
     * @throws \JsonException
     */
    public function load(): AppConfig
    {
        if (!is_file($this->path)) {
            throw new RuntimeException(\sprintf(
                "Config file not found: %s\nCopy config/config.example.json to config/config.json and fill in your information.",
                $this->path
            ));
        }

        $data = json_decode((string) file_get_contents($this->path), true, flags: JSON_THROW_ON_ERROR);

        $creditor = Creditor::fromArray($data['creditor'] ?? throw new RuntimeException('config.json: missing "creditor" block.'));
        $defaults = Defaults::fromArray($data['defaults'] ?? throw new RuntimeException('config.json: missing "defaults" block.'));

        return new AppConfig(
            creditor: $creditor,
            iban: str_replace(' ', '', $data['iban']),
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
            website: $data['website'] ?? null,
            defaults: $defaults,
            vatEnabled: (bool) ($data['vatEnabled'] ?? false),
            categories: $data['categories'] ?? [],
        );
    }
}
