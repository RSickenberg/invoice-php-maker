<?php

declare(strict_types=1);

namespace RSickenberg\InvoicePhpMaker\Invoice;

/**
 * Swiss number formatting: apostrophe for thousands, dot for decimals (3'000.00).
 */
final class Money
{
    public static function format(float $amount): string
    {
        return number_format($amount, 2, '.', "'");
    }
}
