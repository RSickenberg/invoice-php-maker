<?php

declare(strict_types=1);

namespace RSickenberg\InvoicePhpMaker\Pdf;

use TCPDF;

/**
 * TCPDF with a light, automatic header/footer on continuation pages, so an
 * invoice spread over several pages stays readable. The first page is
 * composed "by hand" by InvoicePdfGenerator; this class only handles
 * pages 2, 3, ...
 */
final class InvoiceDocument extends TCPDF
{
    public string $invoiceNumber = '';
    public string $clientName = '';
    public string $language = 'fr';

    public function Header(): void
    {
        if ($this->getPage() <= 1) {
            return;
        }

        $this->SetY(10);
        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 5, \sprintf(
            '%s %s %s, %s',
            Translations::get('invoice', $this->language),
            $this->invoiceNumber,
            Translations::get('continued', $this->language),
            $this->clientName
        ), 0, 1, 'L');
        $this->SetTextColor(0, 0, 0);
        $this->SetY(18);
    }

    public function Footer(): void
    {
        $this->SetY(-15);
        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 10, $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'C');
        $this->SetTextColor(0, 0, 0);
    }
}
