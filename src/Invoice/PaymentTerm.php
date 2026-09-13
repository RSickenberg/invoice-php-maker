<?php

declare(strict_types=1);

namespace RSickenberg\InvoicePhpMaker\Invoice;

/**
 * Payment terms offered to the client, in days from the invoice date.
 */
enum PaymentTerm: int
{
    case Days5 = 5;
    case Days10 = 10;
    case Days15 = 15;
    case Days30 = 30;

    public function label(string $language): string
    {
        return match ($language) {
            'en' => \sprintf('%d days', $this->value),
            default => \sprintf('%d jours', $this->value),
        };
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    public static function fromDays(int $days): self
    {
        return self::from($days);
    }
}
