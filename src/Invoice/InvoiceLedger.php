<?php

declare(strict_types=1);

namespace RSickenberg\InvoicePhpMaker\Invoice;

use RuntimeException;

/**
 * Local invoice ledger (invoices.json): sequential numbering reset to 1
 * every year, and paid/unpaid status tracking.
 */
final class InvoiceLedger
{
    /** @var array<string, int>|null */
    private ?array $lastSequenceByYear = null;

    /** @var list<InvoiceLedgerEntry>|null */
    private ?array $entries = null;

    public function __construct(private readonly string $path) {}

    /**
     * @throws \JsonException
     */
    public function nextInvoiceNumber(int $year): string
    {
        $this->load();
        $sequence = ($this->lastSequenceByYear[(string) $year] ?? 0) + 1;
        $this->lastSequenceByYear[(string) $year] = $sequence;
        $this->persist();

        return \sprintf('%d-%03d', $year, $sequence);
    }

    /**
     * @throws \JsonException
     */
    public function addEntry(InvoiceLedgerEntry $entry): void
    {
        $this->load();
        $this->entries[] = $entry;
        $this->persist();
    }

    /**
     * @throws \JsonException
     */
    public function findByNumber(string $number): ?InvoiceLedgerEntry
    {
        $this->load();

        return array_find($this->entries, static fn($entry) => $entry->number === $number);
    }

    /**
     * @return bool Whether the year's sequence counter was rolled back.
     * @throws \JsonException
     */
    public function removeEntry(string $number): bool
    {
        $this->load();

        $index = array_find_key($this->entries, static fn($entry) => $entry->number === $number);
        if ($index === null) {
            throw new RuntimeException(\sprintf('No invoice "%s" found in the ledger.', $number));
        }

        unset($this->entries[$index]);
        $this->entries = array_values($this->entries);
        $rolledBack = $this->rollbackSequenceIfLast($number);
        $this->persist();

        return $rolledBack;
    }

    /**
     * Decrements the year's sequence counter when the deleted invoice was the
     * last one issued for that year, so the next invoice reuses its number.
     * Left untouched otherwise, to avoid a freed number colliding with an
     * invoice issued after it.
     */
    private function rollbackSequenceIfLast(string $number): bool
    {
        if (preg_match('/^(\d{4})-(\d+)$/', $number, $matches) !== 1) {
            return false;
        }

        [, $year, $sequence] = $matches;
        $sequence = (int) $sequence;

        if (($this->lastSequenceByYear[$year] ?? null) === $sequence) {
            $this->lastSequenceByYear[$year] = $sequence - 1;

            return true;
        }

        return false;
    }

    /**
     * @throws \JsonException
     */
    public function markPaid(string $number, string $paidAt): void
    {
        $this->load();
        foreach ($this->entries as $entry) {
            if ($entry->number === $number) {
                $entry->paid = true;
                $entry->paidAt = $paidAt;
                $this->persist();

                return;
            }
        }

        throw new RuntimeException(\sprintf('No invoice "%s" found in the ledger.', $number));
    }

    /**
     * @return list<InvoiceLedgerEntry>
     * @throws \JsonException
     */
    public function all(): array
    {
        $this->load();

        return $this->entries;
    }

    /**
     * @throws \JsonException
     */
    private function load(): void
    {
        if ($this->entries !== null) {
            return;
        }

        if (!is_file($this->path)) {
            $this->entries = [];
            $this->lastSequenceByYear = [];

            return;
        }

        $data = json_decode((string) file_get_contents($this->path), true, flags: JSON_THROW_ON_ERROR);
        $this->lastSequenceByYear = $data['lastSequenceByYear'] ?? [];
        $this->entries = array_map(
            static fn(array $e) => InvoiceLedgerEntry::fromArray($e),
            $data['invoices'] ?? []
        );
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

        $payload = [
            'lastSequenceByYear' => $this->lastSequenceByYear,
            'invoices' => array_map(static fn(InvoiceLedgerEntry $e) => $e->toArray(), $this->entries),
        ];

        file_put_contents(
            $this->path,
            json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ) . "\n"
        );
    }
}
