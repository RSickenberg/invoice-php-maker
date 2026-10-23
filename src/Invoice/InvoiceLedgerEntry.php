<?php

declare(strict_types=1);

namespace RSickenberg\InvoicePhpMaker\Invoice;

final class InvoiceLedgerEntry
{
    /**
     * @param ?list<Task> $tasks Null for invoices issued before lines were stored (they cannot be regenerated).
     */
    public function __construct(
        public readonly string $number,
        public readonly string $clientId,
        public readonly string $clientName,
        public readonly float $totalAmount,
        public readonly string $currency,
        public readonly string $issueDate,
        public readonly string $dueDate,
        public bool $paid = false,
        public ?string $paidAt = null,
        public readonly string $language = 'fr',
        public readonly ?array $tasks = null,
        public readonly ?int $paymentTermDays = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'clientId' => $this->clientId,
            'clientName' => $this->clientName,
            'totalAmount' => $this->totalAmount,
            'currency' => $this->currency,
            'issueDate' => $this->issueDate,
            'dueDate' => $this->dueDate,
            'paid' => $this->paid,
            'paidAt' => $this->paidAt,
            'language' => $this->language,
            'paymentTermDays' => $this->paymentTermDays,
            'tasks' => $this->tasks === null ? null : array_map(static fn(Task $t) => $t->toArray(), $this->tasks),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            number: $data['number'],
            clientId: $data['clientId'],
            clientName: $data['clientName'],
            totalAmount: (float) $data['totalAmount'],
            currency: $data['currency'],
            issueDate: $data['issueDate'],
            dueDate: $data['dueDate'],
            paid: (bool) ($data['paid'] ?? false),
            paidAt: $data['paidAt'] ?? null,
            language: $data['language'] ?? 'fr',
            tasks: isset($data['tasks']) ? array_map(Task::fromArray(...), $data['tasks']) : null,
            paymentTermDays: $data['paymentTermDays'] ?? null,
        );
    }
}
