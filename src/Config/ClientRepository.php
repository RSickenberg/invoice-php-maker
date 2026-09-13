<?php

declare(strict_types=1);

namespace RSickenberg\InvoicePhpMaker\Config;

final class ClientRepository
{
    /** @var array<string, Client>|null */
    private ?array $clients = null;

    public function __construct(private readonly string $path) {}

    /**
     * @return list<Client>
     * @throws \JsonException
     */
    public function all(): array
    {
        return array_values($this->load());
    }

    /**
     * @throws \JsonException
     */
    public function find(string $id): ?Client
    {
        return $this->load()[$id] ?? null;
    }

    /**
     * @throws \JsonException
     */
    public function save(Client $client): void
    {
        $clients = $this->load();
        $clients[$client->id] = $client;
        $this->clients = $clients;
        $this->persist();
    }

    /**
     * @return array<string, Client>
     * @throws \JsonException
     */
    private function load(): array
    {
        if ($this->clients !== null) {
            return $this->clients;
        }

        if (!is_file($this->path)) {
            return $this->clients = [];
        }

        $data = json_decode((string) file_get_contents($this->path), true, flags: JSON_THROW_ON_ERROR);
        $clients = [];
        foreach ($data['clients'] ?? [] as $clientData) {
            $client = Client::fromArray($clientData);
            $clients[$client->id] = $client;
        }

        return $this->clients = $clients;
    }

    /**
     * @throws \JsonException
     */
    private function persist(): void
    {
        $dir = \dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, recursive: true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Directory "%s" was not created', $dir));
        }

        $payload = ['clients' => array_map(static fn(Client $c) => $c->toArray(), array_values($this->clients ?? []))];
        file_put_contents(
            $this->path,
            json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ) . "\n"
        );
    }
}
