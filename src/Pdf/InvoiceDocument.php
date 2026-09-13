<?php

declare(strict_types=1);

namespace RSickenberg\InvoicePhpMaker\Pdf;

use Com\Tecnick\File\Exception as FileException;
use Com\Tecnick\Pdf\Encrypt\Exception as EncryptException;
use Com\Tecnick\Pdf\Exception as PdfException;
use Com\Tecnick\Pdf\Font\Exception as FontException;
use Com\Tecnick\Pdf\Page\Exception as PageException;
use Com\Tecnick\Pdf\Page\Unit;
use Com\Tecnick\Pdf\Tcpdf;
use Com\Tecnick\Unicode\Exception as UnicodeException;

/**
 * Thin canvas around the raw tc-lib-pdf engine (Com\Tecnick\Pdf\Tcpdf).
 *
 * tc-lib-pdf has no Cell()/MultiCell()/Header()/Footer() convenience layer
 * like TCPDF did: every page break is a manual choice, and a page's content
 * region actively drops (not clips-and-moves) anything positioned past its
 * bottom edge. So this class:
 *  - keeps its own Y cursor and a Cell()-like helper (single-line only: the
 *    invoice body never wraps text, so no MultiCell equivalent is needed),
 *  - reserves 20mm at the bottom of every page during normal flow (matching
 *    the previous SetAutoPageBreak(true, 20)) via ensureRoom(),
 *  - draws the light "invoice N continued, Client" header on every page but
 *    the first, replacing TCPDF's automatic Header() hook,
 *  - gives every page a full-height content region (margin 'CB' = 0) so the
 *    QR-bill (which needs to draw anywhere in the bottom 105mm) is never
 *    silently dropped -- see DECISIONS.md D-013.
 */
final class InvoiceDocument
{
    private const int PAGE_WIDTH = 210;
    private const int PAGE_HEIGHT = 297;
    public const int MARGIN = 15;
    public const int|float CONTENT_WIDTH = self::PAGE_WIDTH - 2 * self::MARGIN;

    /** Bottom margin reserved during normal body flow (matches the former SetAutoPageBreak(true, 20)). */
    private const int BODY_BOTTOM_MARGIN = 277;

    private const int CONTINUATION_HEADER_Y = 10;
    private const int BODY_START_AFTER_CONTINUATION_HEADER = 18;
    private const int FOOTER_Y = 282;

    /** mm subtracted from a wrapLines() width to leave room for tc-lib-pdf's own cell padding. */
    private const float WRAP_SAFETY_MARGIN = 2.0;

    public readonly Tcpdf $engine;

    private int $pid;

    private float $y;

    /** @var list<int> Every page id created for this document, in order. */
    private array $pageIds = [];

    /**
     * @throws \Com\Tecnick\Pdf\Font\Exception
     * @throws \Com\Tecnick\Pdf\Page\Exception
     * @throws \Com\Tecnick\Unicode\Exception
     */
    public function __construct(
        private readonly string $invoiceNumber,
        private readonly string $clientName,
        private readonly string $language,
    ) {
        $this->engine = new Tcpdf(unit: Unit::Millimeter, isunicode: true);

        $page = $this->engine->addPage([
            'format' => 'A4',
            'orientation' => 'P',
            'autobreak' => false,
            'margin' => [
                'PT' => self::MARGIN,
                'PB' => self::MARGIN,
                'CT' => self::MARGIN,
                'CB' => 0,
            ],
        ]);

        $this->pid = (int) $page['pid'];
        $this->pageIds[] = $this->pid;
        $this->y = self::MARGIN;
    }

    public function y(): float
    {
        return $this->y;
    }

    public function setY(float $y): void
    {
        $this->y = $y;
    }

    public function advanceY(float $height): void
    {
        $this->y += $height;
    }

    public function pid(): int
    {
        return $this->pid;
    }

    /**
     * @throws \Com\Tecnick\Pdf\Font\Exception
     * @throws \Com\Tecnick\Pdf\Page\Exception
     */
    public function setFont(string $style, float $sizePt): void
    {
        $font = $this->engine->font->insert($this->engine->pon, 'helvetica', $style, $sizePt);
        $this->engine->page->addContent($font['out'], $this->pid);
    }

    /**
     * @throws \Com\Tecnick\Pdf\Page\Exception
     */
    public function setTextColor(int $r, int $g, int $b): void
    {
        $this->engine->page->addContent(
            $this->engine->graph->getStyleCmd(['fillColor' => \sprintf('rgb(%d,%d,%d)', $r, $g, $b)]),
            $this->pid,
        );
    }

    /**
     * Break to a fresh continuation page (with its own light header) if
     * $height mm of content would cross the 20mm bottom margin.
     */
    public function ensureRoom(float $height): void
    {
        if ($this->y + $height <= self::BODY_BOTTOM_MARGIN) {
            return;
        }

        $this->addContinuationPage();
    }

    /**
     * Force a fresh page if the current one no longer has the full 105mm the
     * QR-bill needs (mirrors the former "GetY() > QR_BILL_SAFE_LIMIT_Y" check).
     */
    public function ensureQrBillRoom(float $safeLimitY): void
    {
        if ($this->y <= $safeLimitY) {
            return;
        }

        $this->addContinuationPage();
    }

    /**
     * @throws \Com\Tecnick\Pdf\Font\Exception
     * @throws \Com\Tecnick\Unicode\Exception
     * @throws \Com\Tecnick\Pdf\Page\Exception
     */
    private function addContinuationPage(): void
    {
        // No data => tc-lib-pdf clones the previous page's format/margins (CB = 0 included).
        $page = $this->engine->addPage();
        $this->pid = (int) $page['pid'];
        $this->pageIds[] = $this->pid;

        $this->setFont('', 8);
        $this->setTextColor(120, 120, 120);
        $this->engine->addTextCellXY(
            txt: \sprintf(
                '%s %s %s, %s',
                Translations::get('invoice', $this->language),
                $this->invoiceNumber,
                Translations::get('continued', $this->language),
                $this->clientName,
            ),
            pid: $this->pid,
            posx: self::MARGIN,
            posy: self::CONTINUATION_HEADER_Y,
            width: self::CONTENT_WIDTH,
            height: 5,
            valign: 'C',
            halign: 'L',
            drawcell: false,
        );
        $this->setTextColor(0, 0, 0);

        $this->y = self::BODY_START_AFTER_CONTINUATION_HEADER;
    }

    /**
     * Splits $text into as many lines as needed to fit $width (in mm) at the
     * currently selected font, breaking on word boundaries.
     *
     * A small safety margin is subtracted from $width: tc-lib-pdf's own cell
     * padding otherwise leaves slightly less room than measured here, which
     * previously left the last word or two of a line clipped with "...".
     *
     * A single word wider than $width on its own (a long URL or token, with
     * no spaces to break on) is hard-split at the character level instead of
     * being left to overflow the cell and get truncated by cell()'s fit='T'.
     *
     * @return list<string>
     * @throws \Com\Tecnick\Pdf\Font\Exception
     */
    public function wrapLines(string $text, float $width): array
    {
        $safeWidth = $width - self::WRAP_SAFETY_MARGIN;
        $lines = [];

        foreach (explode("\n", $text) as $paragraph) {
            $current = '';
            foreach (explode(' ', $paragraph) as $word) {
                if ($this->textWidth($word) > $safeWidth) {
                    if ($current !== '') {
                        $lines[] = $current;
                    }
                    $pieces = $this->splitOverlongWord($word, $safeWidth);
                    $current = array_pop($pieces);
                    array_push($lines, ...$pieces);

                    continue;
                }

                $candidate = $current === '' ? $word : $current . ' ' . $word;
                if ($current !== '' && $this->textWidth($candidate) > $safeWidth) {
                    $lines[] = $current;
                    $current = $word;
                } else {
                    $current = $candidate;
                }
            }
            $lines[] = $current;
        }

        return $lines;
    }

    /**
     * Word-wraps $text to fit $width, drawing one cell() per line starting
     * at the current Y. Does not advance the cursor; call advanceY() with
     * the returned height explicitly.
     *
     * Pass $lines to reuse a wrapLines() result already computed by the
     * caller (e.g. to size a row before drawing it) instead of re-wrapping.
     *
     * @param ?list<string> $lines
     * @throws \Com\Tecnick\Pdf\Page\Exception
     * @throws \Com\Tecnick\Pdf\Font\Exception
     * @throws \Com\Tecnick\Unicode\Exception
     */
    public function multiCell(float $x, float $width, float $lineHeight, string $text, string $align = 'L', ?array $lines = null): float
    {
        $lines ??= $this->wrapLines($text, $width);
        $startY = $this->y;

        foreach ($lines as $line) {
            $this->cell($x, $width, $lineHeight, $line, $align);
            $this->y += $lineHeight;
        }

        $height = $this->y - $startY;
        $this->y = $startY;

        return $height;
    }

    /**
     * Splits a single word wider than $safeWidth into character-level chunks
     * that each fit, so it can be drawn across multiple lines instead of
     * overflowing one.
     *
     * @return list<string>
     * @throws \Com\Tecnick\Pdf\Font\Exception
     */
    private function splitOverlongWord(string $word, float $safeWidth): array
    {
        $pieces = [];
        $current = '';

        foreach (mb_str_split($word) as $char) {
            $candidate = $current . $char;
            if ($current !== '' && $this->textWidth($candidate) > $safeWidth) {
                $pieces[] = $current;
                $current = $char;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /**
     * @throws \Com\Tecnick\Pdf\Font\Exception
     */
    private function textWidth(string $text): float
    {
        return $this->engine->toUnit(
            $this->engine->font->getOrdArrWidth($this->engine->uniconv->strToOrdArr($text)),
        );
    }

    /**
     * Draws a single-line, TCPDF Cell()-like block at the current Y and the
     * given X, optionally with a background fill and/or a top border rule.
     * Does not advance the cursor; call advanceY() explicitly.
     *
     * Unlike TCPDF's Cell(), tc-lib-pdf wraps text that doesn't fit $width
     * onto extra lines by default, which would break this class's fixed
     * row heights. $fit defaults to 'T' (truncate instead of wrap) so a
     * too-long value is cut off rather than silently growing the row.
     * @throws \Com\Tecnick\Pdf\Page\Exception
     * @throws \Com\Tecnick\Pdf\Font\Exception
     * @throws \Com\Tecnick\Unicode\Exception
     */
    public function cell(
        float $x,
        float $width,
        float $height,
        string $text,
        string $align = 'L',
        ?array $fillRgb = null,
        bool $borderTop = false,
        string $fit = 'T',
    ): void {
        if ($fillRgb !== null) {
            $this->engine->page->addContent(
                $this->engine->graph->getRect(
                    $x,
                    $this->y,
                    $width,
                    $height,
                    'F',
                    ['all' => ['fillColor' => vsprintf('rgb(%d,%d,%d)', $fillRgb)]],
                ),
                $this->pid,
            );
        }

        if ($borderTop) {
            $this->engine->page->addContent(
                $this->engine->graph->getLine(
                    $x,
                    $this->y,
                    $x + $width,
                    $this->y,
                    ['lineWidth' => 0.2, 'lineColor' => 'rgb(180,180,180)'],
                ),
                $this->pid,
            );
        }

        if ($text === '') {
            return;
        }

        $this->engine->addTextCellXY(
            txt: $text,
            pid: $this->pid,
            posx: $x,
            posy: $this->y,
            width: $width,
            height: $height,
            valign: 'C',
            halign: $align,
            drawcell: false,
            fit: $fit,
        );
    }

    /**
     * Adds the "page N / total" footer to every page, then writes the raw
     * PDF bytes to $outputPath.
     * @throws \Com\Tecnick\Pdf\Page\Exception
     * @throws \Com\Tecnick\Pdf\Font\Exception
     * @throws \Com\Tecnick\Unicode\Exception
     * @throws PdfException
     * @throws FileException
     * @throws UnicodeException
     * @throws EncryptException
     * @throws FontException
     * @throws PageException
     * @throws \Throwable
     */
    public function outputTo(string $outputPath): void
    {
        $total = \count($this->pageIds);
        foreach ($this->pageIds as $index => $pid) {
            $font = $this->engine->font->insert($this->engine->pon, 'helvetica', '', 8);
            $this->engine->page->addContent($font['out'], $pid);
            $this->engine->page->addContent(
                $this->engine->graph->getStyleCmd(['fillColor' => 'rgb(150,150,150)']),
                $pid,
            );
            $this->engine->addTextCellXY(
                txt: ($index + 1) . ' / ' . $total,
                pid: $pid,
                posx: 0,
                posy: self::FOOTER_Y,
                width: self::PAGE_WIDTH,
                height: 10,
                valign: 'C',
                halign: 'C',
                drawcell: false,
            );
        }

        $rawpdf = $this->engine->getOutPDFString();

        $dir = \dirname($outputPath);
        if (!is_dir($dir) && !mkdir($dir, recursive: true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Directory "%s" was not created', $dir));
        }

        file_put_contents($outputPath, $rawpdf);
    }
}
