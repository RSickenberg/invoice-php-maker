<?php

declare(strict_types=1);

namespace RSickenberg\InvoicePhpMaker\Pdf;

use RSickenberg\InvoicePhpMaker\Config\AppConfig;
use RSickenberg\InvoicePhpMaker\Invoice\Invoice;
use RSickenberg\InvoicePhpMaker\Invoice\Task;
use RSickenberg\InvoicePhpMaker\QrBill\QrBillFactory;
use RuntimeException;
use Sprain\SwissQrBill\PaymentPart\Output\DisplayOptions;
use Sprain\SwissQrBill\PaymentPart\Output\TcPdfOutput\TcPdfOutput;

/**
 * Generates the invoice PDF: header, client info, tasks table grouped by
 * category with subtotals, grand total, then the Swiss QR-bill fixed at
 * the bottom of the last page. Automatic pagination via TCPDF when the
 * table overflows onto several pages.
 */
final class InvoicePdfGenerator
{
    private const int PAGE_WIDTH = 210;
    private const int MARGIN = 15;
    private const int|float CONTENT_WIDTH = self::PAGE_WIDTH - 2 * self::MARGIN;

    /** Y beyond which there is no longer enough room for the QR-bill (105mm) on an A4 page. */
    private const int QR_BILL_SAFE_LIMIT_Y = 188;

    public function __construct(private readonly QrBillFactory $qrBillFactory = new QrBillFactory()) {}

    /**
     * @throws \RuntimeException
     */
    public function generate(Invoice $invoice, AppConfig $config, string $outputPath): void
    {
        $lang = $invoice->language;

        $pdf = new InvoiceDocument('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->invoiceNumber = $invoice->number;
        $pdf->clientName = $invoice->client->name;
        $pdf->language = $lang;
        $pdf->setPrintHeader();
        $pdf->setPrintFooter();
        $pdf->SetCreator('invoice-php-maker');
        $pdf->SetAuthor($config->creditor->name);
        $pdf->SetTitle(\sprintf('%s %s', Translations::get('invoice', $lang), $invoice->number));
        $pdf->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        $this->drawFirstPageHeader($pdf, $invoice, $config, $lang);
        $this->drawClientBlock($pdf, $invoice, $lang);
        $this->drawTasksTable($pdf, $invoice, $lang);
        $this->drawPaymentTermNote($pdf, $invoice, $lang);

        if ($pdf->GetY() > self::QR_BILL_SAFE_LIMIT_Y) {
            $pdf->AddPage();
        }

        $this->drawQrBill($pdf, $invoice, $config, $lang);

        $dir = \dirname($outputPath);
        if (!is_dir($dir) && !mkdir($dir, recursive: true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Directory "%s" was not created', $dir));
        }

        $pdf->Output($outputPath, 'F');
    }

    private function drawFirstPageHeader(InvoiceDocument $pdf, Invoice $invoice, AppConfig $config, string $lang): void
    {
        $halfWidth = self::CONTENT_WIDTH / 2;

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell($halfWidth, 8, $config->creditor->name, 0, 0, 'L');
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell($halfWidth, 8, mb_strtoupper(Translations::get('invoice', $lang)), 0, 1, 'R');

        $leftLines = array_filter([
            \sprintf('%s %s', $config->creditor->street, $config->creditor->houseNumber),
            \sprintf('%s %s', $config->creditor->postalCode, $config->creditor->city),
            $config->email,
            $config->phone,
            $config->website,
        ]);

        $rightLines = [
            \sprintf('%s %s', Translations::get('invoiceNumber', $lang), $invoice->number),
            \sprintf('%s : %s', Translations::get('issueDate', $lang), $invoice->issueDate->format('d.m.Y')),
            \sprintf('%s : %s', Translations::get('dueDate', $lang), $invoice->dueDate()->format('d.m.Y')),
        ];

        $pdf->SetFont('helvetica', '', 9);
        $rows = max(\count($leftLines), \count($rightLines));
        $leftLines = array_values($leftLines);
        for ($i = 0; $i < $rows; $i++) {
            $pdf->Cell($halfWidth, 5, $leftLines[$i] ?? '', 0, 0, 'L');
            $pdf->Cell($halfWidth, 5, $rightLines[$i] ?? '', 0, 1, 'R');
        }

        $pdf->Ln(8);
    }

    private function drawClientBlock(InvoiceDocument $pdf, Invoice $invoice, string $lang): void
    {
        $client = $invoice->client;

        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->Cell(0, 4, mb_strtoupper(Translations::get('billedTo', $lang)), 0, 1, 'L');
        $pdf->SetTextColor(0, 0, 0);

        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 5, $client->name, 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 5, \sprintf('%s %s', $client->street, $client->houseNumber), 0, 1, 'L');
        $pdf->Cell(0, 5, \sprintf('%s %s', $client->postalCode, $client->city), 0, 1, 'L');

        $pdf->Ln(6);
    }

    private function drawTasksTable(InvoiceDocument $pdf, Invoice $invoice, string $lang): void
    {
        $widths = ['description' => 70, 'category' => 40, 'hours' => 20, 'rate' => 25, 'amount' => self::CONTENT_WIDTH - 155];

        $pdf->SetFillColor(235, 235, 235);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell($widths['description'], 7, Translations::get('description', $lang), 0, 0, 'L', true);
        $pdf->Cell($widths['category'], 7, Translations::get('category', $lang), 0, 0, 'L', true);
        $pdf->Cell($widths['hours'], 7, Translations::get('hours', $lang), 0, 0, 'R', true);
        $pdf->Cell($widths['rate'], 7, Translations::get('hourlyRate', $lang), 0, 0, 'R', true);
        $pdf->Cell($widths['amount'], 7, Translations::get('amount', $lang), 0, 1, 'R', true);

        $tasksByCategory = [];
        foreach ($invoice->tasks as $task) {
            $tasksByCategory[$task->category][] = $task;
        }

        $pdf->SetFont('helvetica', '', 9);
        foreach ($tasksByCategory as $category => $tasks) {
            /** @var list<Task> $tasks */
            foreach ($tasks as $task) {
                $pdf->Cell($widths['description'], 6, $task->description, 0, 0, 'L');
                $pdf->Cell($widths['category'], 6, $task->category, 0, 0, 'L');
                $pdf->Cell($widths['hours'], 6, number_format($task->hours, 2, ',', ''), 0, 0, 'R');
                $pdf->Cell($widths['rate'], 6, number_format($task->hourlyRate, 2, ',', ''), 0, 0, 'R');
                $pdf->Cell($widths['amount'], 6, number_format($task->amount(), 2, ',', ''), 0, 1, 'R');
            }

            $subtotal = array_sum(array_map(static fn(Task $t) => $t->amount(), $tasks));
            $pdf->SetFont('helvetica', 'I', 8);
            $pdf->SetTextColor(90, 90, 90);
            $pdf->Cell($widths['description'] + $widths['category'] + $widths['hours'] + $widths['rate'], 6, \sprintf(
                '%s %s',
                Translations::get('subtotal', $lang),
                $category
            ), 'T', 0, 'R');
            $pdf->Cell($widths['amount'], 6, number_format($subtotal, 2, ',', '') . ' ' . $invoice->currency, 'T', 1, 'R');
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('helvetica', '', 9);
        }

        $pdf->Ln(2);
        $pdf->SetFont('helvetica', 'B', 11);
        $labelWidth = $widths['description'] + $widths['category'] + $widths['hours'] + $widths['rate'];
        $pdf->Cell($labelWidth, 8, Translations::get('total', $lang), 'T', 0, 'R');
        $pdf->Cell($widths['amount'], 8, number_format($invoice->totalAmount(), 2, ',', '') . ' ' . $invoice->currency, 'T', 1, 'R');
        $pdf->Ln(4);
    }

    private function drawPaymentTermNote(InvoiceDocument $pdf, Invoice $invoice, string $lang): void
    {
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 6, \sprintf(
            '%s %s (%s)',
            Translations::get('paymentTerm', $lang),
            $invoice->paymentTerm->label($lang),
            $invoice->dueDate()->format('d.m.Y')
        ), 0, 1, 'L');
    }

    /**
     * @throws \RuntimeException If QR-Bill is invalid.
     */
    private function drawQrBill(InvoiceDocument $pdf, Invoice $invoice, AppConfig $config, string $lang): void
    {
        $qrBill = $this->qrBillFactory->build($config, $invoice);

        if (!$qrBill->isValid()) {
            $messages = $qrBill->getViolations()
                    |> iterator_to_array(...)
                    |> (static fn($x) => array_map(static fn($v) => $v->getMessage(), $x));
            throw new RuntimeException('Invalid QR-bill: ' . implode(' / ', $messages));
        }

        $output = new TcPdfOutput($qrBill, $lang, $pdf);
        $output
            ->setDisplayOptions(new DisplayOptions()->setPrintable(false))
            ->getPaymentPart();
    }
}
