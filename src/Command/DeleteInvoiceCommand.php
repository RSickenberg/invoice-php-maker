<?php

declare(strict_types=1);

namespace RSickenberg\InvoicePhpMaker\Command;

use RSickenberg\InvoicePhpMaker\Invoice\InvoiceLedger;
use RSickenberg\InvoicePhpMaker\Invoice\Money;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'invoice:delete', description: 'Deletes an invoice (ledger entry and PDF) after a mistake')]
final class DeleteInvoiceCommand extends Command
{
    public function __construct(
        private readonly string $projectDir,
        private readonly InvoiceLedger $ledger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('number', InputArgument::OPTIONAL, 'Invoice number, e.g. 2026-001 (prompts to choose one if omitted)');
    }

    /**
     * @throws \JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $number = $input->getArgument('number');

        if ($number === null) {
            $entries = $this->ledger->all();
            if ($entries === []) {
                $io->outlineWarning('No invoices.');

                return Command::SUCCESS;
            }

            $choices = [];
            foreach ($entries as $e) {
                $choices[$e->number] = \sprintf('%s — %s — %s %s', $e->number, $e->clientName, Money::format($e->totalAmount), $e->currency);
            }

            $number = (string) $io->choice('Which invoice to delete?', $choices);
        }

        $number = (string) $number;

        $entry = $this->ledger->findByNumber($number);
        if ($entry === null) {
            $io->outlineError(\sprintf('No invoice "%s" found in invoices.json.', $number));

            return Command::FAILURE;
        }

        if (!$io->confirm(\sprintf('Delete invoice %s (%s, %s %s)? This cannot be undone.', $number, $entry->clientName, $entry->totalAmount, $entry->currency), false)) {
            $io->outlineWarning('Deletion cancelled.');

            return Command::SUCCESS;
        }

        try {
            $rolledBack = $this->ledger->removeEntry($number);
        } catch (RuntimeException $e) {
            $io->outlineError($e->getMessage());

            return Command::FAILURE;
        }

        foreach (glob(\sprintf('%s/invoices/*/%s-*.pdf', rtrim($this->projectDir, '/'), $number)) ?: [] as $pdfPath) {
            unlink($pdfPath);
        }

        $message = \sprintf('Invoice %s deleted.', $number);
        if ($rolledBack) {
            $message .= ' Its number will be reused for the next invoice of that year.';
        }

        $io->outlineSuccess($message);

        return Command::SUCCESS;
    }
}
