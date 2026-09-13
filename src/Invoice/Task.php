<?php

declare(strict_types=1);

namespace RSickenberg\InvoicePhpMaker\Invoice;

final readonly class Task
{
    public function __construct(
        public string $title,
        public string $category,
        public float $hours,
        public float $hourlyRate,
        public ?string $description = null,
    ) {}

    public function amount(): float
    {
        return round($this->hours * $this->hourlyRate, 2);
    }
}
