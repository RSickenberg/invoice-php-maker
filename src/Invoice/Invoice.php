<?php

declare(strict_types=1);

namespace RSickenberg\InvoicePhpMaker\Invoice;

use DateTimeImmutable;
use RSickenberg\InvoicePhpMaker\Config\Client;

final readonly class Invoice
{
    /**
     * @param list<Task> $tasks
     */
    public function __construct(
        public string $number,
        public DateTimeImmutable $issueDate,
        public Client $client,
        public string $language,
        public string $currency,
        public PaymentTerm $paymentTerm,
        public array $tasks,
    ) {}

    /**
     * @throws \DateMalformedStringException
     */
    public function dueDate(): DateTimeImmutable
    {
        return $this->issueDate->modify(\sprintf('+%d days', $this->paymentTerm->value));
    }

    public function totalAmount(): float
    {
        return array_map(static fn(Task $t) => $t->amount(), $this->tasks)
                |> array_sum(...)
                |> (static fn($x) => round($x, 2));
    }

    /**
     * Subtotals grouped by category, in order of first appearance.
     *
     * @return array<string, float>
     */
    public function totalsByCategory(): array
    {
        $totals = [];
        foreach ($this->tasks as $task) {
            $totals[$task->category] = round(($totals[$task->category] ?? 0.0) + $task->amount(), 2);
        }

        return $totals;
    }
}
