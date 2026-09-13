<?php

declare(strict_types=1);

namespace RSickenberg\InvoicePhpMaker\Command;

use Carbon\CarbonImmutable;
use RSickenberg\InvoicePhpMaker\Invoice\InvoiceLedger;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'invoice:mark-paid', description: 'Marks an invoice from the ledger as paid')]
final class MarkPaidCommand extends Command
{
    public function __construct(private readonly InvoiceLedger $ledger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('number', InputArgument::REQUIRED, 'Invoice number, e.g. 2026-001');
    }

    /**
     * @throws \JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $number = (string) $input->getArgument('number');

        $entry = $this->ledger->findByNumber($number);
        if ($entry === null) {
            $io->outlineError(\sprintf('No invoice "%s" found in invoices.json.', $number));

            return Command::FAILURE;
        }

        if ($entry->paid) {
            $io->outlineWarning(\sprintf('Invoice %s is already marked as paid (on %s).', $number, $entry->paidAt));

            return Command::SUCCESS;
        }

        try {
            $this->ledger->markPaid($number, CarbonImmutable::today()->toDateString());
        } catch (RuntimeException|\JsonException $e) {
            $io->outlineError($e->getMessage());

            return Command::FAILURE;
        }

        $io->outlineSuccess(\sprintf('Invoice %s marked as paid.', $number));

        return Command::SUCCESS;
    }
}
