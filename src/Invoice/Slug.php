<?php

declare(strict_types=1);

namespace RSickenberg\InvoicePhpMaker\Invoice;

final class Slug
{
    public static function of(string $value): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $value));

        return trim($slug, '-') ?: 'client';
    }
}
