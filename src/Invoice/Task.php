<?php

declare(strict_types=1);

namespace RSickenberg\InvoicePhpMaker\Invoice;

final readonly class Task
{
    /**
     * @param ?string $period Month the work was done in (YYYY-MM); tasks are grouped by it on the invoice.
     */
    public function __construct(
        public string $title,
        public string $category,
        public float $hours,
        public float $hourlyRate,
        public ?string $description = null,
        public ?string $period = null,
    ) {}

    public function amount(): float
    {
        return round($this->hours * $this->hourlyRate, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'category' => $this->category,
            'hours' => $this->hours,
            'hourlyRate' => $this->hourlyRate,
            'description' => $this->description,
            'period' => $this->period,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            title: $data['title'],
            category: $data['category'],
            hours: (float) $data['hours'],
            hourlyRate: (float) $data['hourlyRate'],
            description: $data['description'] ?? null,
            period: $data['period'] ?? null,
        );
    }
}
