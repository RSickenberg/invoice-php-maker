<?php

declare(strict_types=1);

namespace RSickenberg\InvoicePhpMaker\Pdf;

/**
 * Labels for the body of the invoice (distinct from the QR-bill's own
 * labels, which are fixed and handled by sprain/swiss-qr-bill per the
 * official standard).
 */
final class Translations
{
    private const array LABELS = [
        'invoice' => ['fr' => 'Facture', 'en' => 'Invoice'],
        'invoiceNumber' => ['fr' => 'Facture n°', 'en' => 'Invoice no.'],
        'issueDate' => ['fr' => 'Date', 'en' => 'Date'],
        'dueDate' => ['fr' => 'Échéance', 'en' => 'Due date'],
        'billedTo' => ['fr' => 'Facturé à', 'en' => 'Billed to'],
        'description' => ['fr' => 'Description', 'en' => 'Description'],
        'category' => ['fr' => 'Catégorie', 'en' => 'Category'],
        'hours' => ['fr' => 'Heures', 'en' => 'Hours'],
        'hourlyRate' => ['fr' => 'Taux/h', 'en' => 'Rate/h'],
        'amount' => ['fr' => 'Montant', 'en' => 'Amount'],
        'subtotal' => ['fr' => 'Sous-total', 'en' => 'Subtotal'],
        'total' => ['fr' => 'Total', 'en' => 'Total'],
        'paymentTerm' => ['fr' => 'Paiement sous', 'en' => 'Payment within'],
        'continued' => ['fr' => '(suite)', 'en' => '(continued)'],
    ];

    public static function get(string $key, string $language): string
    {
        return self::LABELS[$key][$language] ?? self::LABELS[$key]['en'] ?? $key;
    }
}
