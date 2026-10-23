<?php

declare(strict_types=1);

namespace RSickenberg\InvoicePhpMaker\Command;

use RSickenberg\InvoicePhpMaker\Invoice\InvoiceLedger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'invoice:list', description: 'Lists all invoices from the ledger')]
final class ListInvoicesCommand extends Command
{
    public function __construct(private readonly InvoiceLedger $ledger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('unpaid', 'u', InputOption::VALUE_NONE, 'Only show unpaid invoices');
    }

    /**
     * @throws \JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $entries = $this->ledger->all();
        if ($input->getOption('unpaid')) {
            $entries = array_filter($entries, static fn($entry) => !$entry->paid);
        }

        if ($entries === []) {
            $io->outlineWarning('No invoices found.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Number', 'Client', 'Amount', 'Issued', 'Due', 'Status'],
            array_map(static fn($entry) => [
                $entry->number,
                $entry->clientName,
                \sprintf('%.2f %s', $entry->totalAmount, $entry->currency),
                $entry->issueDate,
                $entry->dueDate,
                $entry->paid ? \sprintf('Paid on %s', $entry->paidAt) : 'Open',
            ], $entries),
        );

        return Command::SUCCESS;
    }
}
