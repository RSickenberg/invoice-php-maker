<?php

declare(strict_types=1);

namespace RSickenberg\InvoicePhpMaker\Pdf;

use Com\Tecnick\File\Exception as FileException;
use Com\Tecnick\Pdf\Encrypt\Exception as EncryptException;
use Com\Tecnick\Pdf\Exception as PdfException;
use Com\Tecnick\Pdf\Font\Exception as FontException;
use Com\Tecnick\Pdf\Page\Exception as PageException;
use Com\Tecnick\Unicode\Exception as UnicodeException;
use RSickenberg\InvoicePhpMaker\Config\AppConfig;
use RSickenberg\InvoicePhpMaker\Invoice\Invoice;
use RSickenberg\InvoicePhpMaker\Invoice\Task;
use RSickenberg\InvoicePhpMaker\QrBill\QrBillFactory;
use RuntimeException;
use Sprain\SwissQrBill\PaymentPart\Output\DisplayOptions;
use Sprain\SwissQrBill\PaymentPart\Output\TcLibPdfOutput\TcLibPdfOutput;

/**
 * Generates the invoice PDF: header, client info, tasks table grouped by
 * category with subtotals, grand total, then the Swiss QR-bill fixed at
 * the bottom of the last page. Manual pagination via InvoiceDocument (raw
 * tc-lib-pdf has no TCPDF-style automatic Cell()-triggered page breaks).
 */
final class InvoicePdfGenerator
{
    private const int|float CONTENT_WIDTH = InvoiceDocument::CONTENT_WIDTH;
    private const int MARGIN = InvoiceDocument::MARGIN;

    /** Y beyond which there is no longer enough room for the QR-bill (105mm) on an A4 page. */
    private const int QR_BILL_SAFE_LIMIT_Y = 188;

    public function __construct(private readonly QrBillFactory $qrBillFactory = new QrBillFactory()) {}

    /**
     * @throws \RuntimeException
     * @throws \Com\Tecnick\Pdf\Font\Exception
     * @throws \Com\Tecnick\Pdf\Page\Exception
     * @throws \Com\Tecnick\Unicode\Exception
     * @throws PdfException
     * @throws FileException
     * @throws UnicodeException
     * @throws EncryptException
     * @throws FontException
     * @throws PageException
     * @throws \Throwable
     */
    public function generate(Invoice $invoice, AppConfig $config, string $outputPath): void
    {
        $lang = $invoice->language;

        $doc = new InvoiceDocument($invoice->number, $invoice->client->name, $lang);

        $this->drawFirstPageHeader($doc, $invoice, $config, $lang);
        $this->drawClientBlock($doc, $invoice, $lang);
        $this->drawTasksTable($doc, $invoice, $lang);
        $this->drawPaymentTermNote($doc, $invoice, $lang);

        $doc->ensureQrBillRoom(self::QR_BILL_SAFE_LIMIT_Y);

        $this->drawQrBill($doc, $invoice, $config, $lang);

        $doc->outputTo($outputPath);
    }

    /**
     * @throws \Com\Tecnick\Pdf\Font\Exception
     * @throws \Com\Tecnick\Unicode\Exception
     * @throws \Com\Tecnick\Pdf\Page\Exception
     */
    private function drawFirstPageHeader(InvoiceDocument $doc, Invoice $invoice, AppConfig $config, string $lang): void
    {
        $halfWidth = self::CONTENT_WIDTH / 2;

        $doc->setFont('B', 14);
        $doc->cell(self::MARGIN, $halfWidth, 8, $config->creditor->name, 'L');
        $doc->setFont('B', 18);
        $doc->cell(self::MARGIN + $halfWidth, $halfWidth, 8, mb_strtoupper(Translations::get('invoice', $lang)), 'R');
        $doc->advanceY(8);

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

        $doc->setFont('', 9);
        $rows = max(\count($leftLines), \count($rightLines));
        $leftLines = array_values($leftLines);
        for ($i = 0; $i < $rows; $i++) {
            $doc->cell(self::MARGIN, $halfWidth, 5, $leftLines[$i] ?? '', 'L');
            $doc->cell(self::MARGIN + $halfWidth, $halfWidth, 5, $rightLines[$i] ?? '', 'R');
            $doc->advanceY(5);
        }

        $doc->advanceY(8);
    }

    /**
     * @throws \Com\Tecnick\Pdf\Font\Exception
     * @throws \Com\Tecnick\Unicode\Exception
     * @throws \Com\Tecnick\Pdf\Page\Exception
     */
    private function drawClientBlock(InvoiceDocument $doc, Invoice $invoice, string $lang): void
    {
        $client = $invoice->client;

        $doc->setFont('', 8);
        $doc->setTextColor(120, 120, 120);
        $doc->cell(self::MARGIN, self::CONTENT_WIDTH, 4, mb_strtoupper(Translations::get('billedTo', $lang)), 'L');
        $doc->advanceY(4);
        $doc->setTextColor(0, 0, 0);

        $doc->setFont('B', 10);
        $doc->cell(self::MARGIN, self::CONTENT_WIDTH, 5, $client->name, 'L');
        $doc->advanceY(5);
        $doc->setFont('', 9);
        $doc->cell(self::MARGIN, self::CONTENT_WIDTH, 5, \sprintf('%s %s', $client->street, $client->houseNumber), 'L');
        $doc->advanceY(5);
        $doc->cell(self::MARGIN, self::CONTENT_WIDTH, 5, \sprintf('%s %s', $client->postalCode, $client->city), 'L');
        $doc->advanceY(5);

        $doc->advanceY(6);
    }

    /**
     * @throws \Com\Tecnick\Pdf\Font\Exception
     * @throws \Com\Tecnick\Pdf\Page\Exception
     * @throws \Com\Tecnick\Unicode\Exception
     */
    private function drawTasksTable(InvoiceDocument $doc, Invoice $invoice, string $lang): void
    {
        // 'amount' gets 33mm rather than the original 25mm: unlike TCPDF's Cell(),
        // tc-lib-pdf wraps text that overflows its cell instead of letting it spill
        // into the neighboring column, so totals need enough width to render on
        // one line up to about six figures. Borrowed from 'category' (short labels).
        $widths = ['description' => 70, 'category' => 32, 'hours' => 20, 'rate' => 25, 'amount' => self::CONTENT_WIDTH - 147];
        $x = [
            'description' => self::MARGIN,
        ];
        $x['category'] = $x['description'] + $widths['description'];
        $x['hours'] = $x['category'] + $widths['category'];
        $x['rate'] = $x['hours'] + $widths['hours'];
        $x['amount'] = $x['rate'] + $widths['rate'];

        $doc->ensureRoom(7);
        $doc->setFont('B', 9);
        $doc->cell($x['description'], $widths['description'], 7, Translations::get('task', $lang), 'L', [235, 235, 235]);
        $doc->cell($x['category'], $widths['category'], 7, Translations::get('category', $lang), 'L', [235, 235, 235]);
        $doc->cell($x['hours'], $widths['hours'], 7, Translations::get('hours', $lang), 'R', [235, 235, 235]);
        $doc->cell($x['rate'], $widths['rate'], 7, Translations::get('hourlyRate', $lang), 'R', [235, 235, 235]);
        $doc->cell($x['amount'], $widths['amount'], 7, Translations::get('amount', $lang), 'R', [235, 235, 235]);
        $doc->advanceY(7);

        $tasksByCategory = [];
        foreach ($invoice->tasks as $task) {
            $tasksByCategory[$task->category][] = $task;
        }

        $doc->setFont('', 9);
        foreach ($tasksByCategory as $category => $tasks) {
            /** @var list<Task> $tasks */
            foreach ($tasks as $task) {
                $descriptionLines = null;
                $descriptionHeight = 0.0;
                if ($task->description !== null) {
                    $doc->setFont('I', 8);
                    $descriptionLines = $doc->wrapLines($task->description, $widths['description']);
                    $descriptionHeight = \count($descriptionLines) * 4;
                }

                $doc->ensureRoom(6 + $descriptionHeight);
                $doc->setFont('', 9);
                $doc->setTextColor(0, 0, 0);
                $doc->cell($x['description'], $widths['description'], 6, $task->title, 'L');
                $doc->cell($x['category'], $widths['category'], 6, $task->category, 'L');
                $doc->cell($x['hours'], $widths['hours'], 6, number_format($task->hours, 2, ',', ''), 'R');
                $doc->cell($x['rate'], $widths['rate'], 6, number_format($task->hourlyRate, 2, ',', ''), 'R');
                $doc->cell($x['amount'], $widths['amount'], 6, number_format($task->amount(), 2, ',', ''), 'R');
                $doc->advanceY(6);

                if ($task->description !== null) {
                    $doc->setFont('I', 8);
                    $doc->setTextColor(120, 120, 120);
                    $doc->advanceY($doc->multiCell($x['description'], $widths['description'], 4, $task->description, 'L', $descriptionLines));
                    $doc->setTextColor(0, 0, 0);
                }
            }

            $subtotal = array_sum(array_map(static fn(Task $t) => $t->amount(), $tasks));
            $doc->ensureRoom(6);
            $doc->setFont('I', 8);
            $doc->setTextColor(90, 90, 90);
            $labelWidth = $widths['description'] + $widths['category'] + $widths['hours'] + $widths['rate'];
            $doc->cell($x['description'], $labelWidth, 6, Translations::get('subtotal', $lang), 'R', null, true);
            $doc->cell($x['amount'], $widths['amount'], 6, number_format($subtotal, 2, ',', '') . ' ' . $invoice->currency, 'R', null, true);
            $doc->advanceY(6);
            $doc->setTextColor(0, 0, 0);
        }

        $doc->advanceY(2);
        $doc->ensureRoom(8);
        $doc->setFont('B', 11);
        $labelWidth = $widths['description'] + $widths['category'] + $widths['hours'] + $widths['rate'];
        $doc->cell($x['description'], $labelWidth, 8, Translations::get('total', $lang), 'R', null, true);
        $doc->cell($x['amount'], $widths['amount'], 8, number_format($invoice->totalAmount(), 2, ',', '') . ' ' . $invoice->currency, 'R', null, true);
        $doc->advanceY(8);
        $doc->advanceY(4);
    }

    /**
     * @throws \Com\Tecnick\Pdf\Font\Exception
     * @throws \Com\Tecnick\Unicode\Exception
     * @throws \Com\Tecnick\Pdf\Page\Exception
     */
    private function drawPaymentTermNote(InvoiceDocument $doc, Invoice $invoice, string $lang): void
    {
        $doc->ensureRoom(6);
        $doc->setFont('', 9);
        $doc->cell(self::MARGIN, self::CONTENT_WIDTH, 6, \sprintf(
            '%s %s (%s)',
            Translations::get('paymentTerm', $lang),
            $invoice->paymentTerm->label($lang),
            $invoice->dueDate()->format('d.m.Y')
        ), 'L');
        $doc->advanceY(6);
    }

    /**
     * @throws \RuntimeException If QR-Bill is invalid.
     */
    private function drawQrBill(InvoiceDocument $doc, Invoice $invoice, AppConfig $config, string $lang): void
    {
        $qrBill = $this->qrBillFactory->build($config, $invoice);

        if (!$qrBill->isValid()) {
            $messages = $qrBill->getViolations()
                |> iterator_to_array(...)
                |> (static fn($x) => array_map(static fn($v) => $v->getMessage(), $x));
            throw new RuntimeException('Invalid QR-bill: ' . implode(' / ', $messages));
        }

        $output = new TcLibPdfOutput($qrBill, $lang, $doc->engine);
        $output
            ->setDisplayOptions(new DisplayOptions()->setPrintable(false))
            ->getPaymentPart();
    }
}
