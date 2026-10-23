<?php

declare(strict_types=1);

namespace RSickenberg\InvoicePhpMaker\Command;

use Carbon\CarbonImmutable;
use RSickenberg\InvoicePhpMaker\Config\AppConfigRepository;
use RSickenberg\InvoicePhpMaker\Config\ClientRepository;
use RSickenberg\InvoicePhpMaker\Invoice\Invoice;
use RSickenberg\InvoicePhpMaker\Invoice\InvoiceLedger;
use RSickenberg\InvoicePhpMaker\Invoice\InvoiceLedgerEntry;
use RSickenberg\InvoicePhpMaker\Invoice\Money;
use RSickenberg\InvoicePhpMaker\Invoice\PaymentTerm;
use RSickenberg\InvoicePhpMaker\Invoice\Slug;
use RSickenberg\InvoicePhpMaker\Invoice\Task;
use RSickenberg\InvoicePhpMaker\Pdf\InvoicePdfGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'invoice:edit', description: 'Edits an existing invoice (date, term, language, tasks) and regenerates its PDF')]
final class EditInvoiceCommand extends Command
{
    private const string ACTION_DATE = 'Invoice date';
    private const string ACTION_LANGUAGE = 'Language';
    private const string ACTION_TERM = 'Payment term';
    private const string ACTION_EDIT_TASK = 'Edit a task';
    private const string ACTION_ADD_TASK = 'Add a task';
    private const string ACTION_REMOVE_TASK = 'Remove a task';
    private const string ACTION_SAVE = 'Save and regenerate the PDF';
    private const string ACTION_CANCEL = 'Cancel';

    public function __construct(
        private readonly string $projectDir,
        private readonly AppConfigRepository $configRepository,
        private readonly ClientRepository $clientRepository,
        private readonly InvoiceLedger $ledger,
        private readonly InvoicePdfGenerator $pdfGenerator = new InvoicePdfGenerator(),
        private readonly InvoicePrompter $prompter = new InvoicePrompter(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('number', InputArgument::OPTIONAL, 'Invoice number, e.g. 2026-001 (prompts to choose one if omitted)')
            ->addOption('regenerate', null, InputOption::VALUE_NONE, 'Regenerate the PDF from the stored data without changing anything');
    }

    /**
     * @throws \JsonException
     * @throws \Throwable
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $number = $input->getArgument('number');

        if ($number === null) {
            $entries = array_filter($this->ledger->all(), static fn(InvoiceLedgerEntry $e) => $e->tasks !== null);
            if ($entries === []) {
                $io->outlineWarning('No editable invoices (only invoices generated after lines were stored can be edited).');

                return Command::SUCCESS;
            }

            $choices = [];
            foreach ($entries as $e) {
                $choices[$e->number] = \sprintf('%s — %s — %s %s', $e->number, $e->clientName, Money::format($e->totalAmount), $e->currency);
            }

            $number = (string) $io->choice('Which invoice?', $choices);
        }

        $entry = $this->ledger->findByNumber((string) $number);
        if ($entry === null) {
            $io->outlineError(\sprintf('No invoice "%s" found in invoices.json.', $number));

            return Command::FAILURE;
        }

        if ($entry->tasks === null) {
            $io->outlineError(\sprintf('Invoice %s was generated before its lines were stored, so it cannot be edited or regenerated.', $entry->number));

            return Command::FAILURE;
        }

        $client = $this->clientRepository->find($entry->clientId);
        if ($client === null) {
            $io->outlineError(\sprintf('Client "%s" no longer exists in clients.json.', $entry->clientId));

            return Command::FAILURE;
        }

        $config = $this->configRepository->load();
        $issueDate = CarbonImmutable::createFromFormat('!Y-m-d', $entry->issueDate);
        $language = $entry->language;
        $paymentTerm = PaymentTerm::fromDays($entry->paymentTermDays ?? (int) $issueDate->diffInDays(CarbonImmutable::createFromFormat('!Y-m-d', $entry->dueDate)));
        $tasks = $entry->tasks;

        if (!$input->getOption('regenerate')) {
            do {
                $this->renderSummary($io, $issueDate, $language, $paymentTerm, $tasks, $entry->currency);
                $action = $io->choice('What do you want to change?', [
                    self::ACTION_DATE,
                    self::ACTION_LANGUAGE,
                    self::ACTION_TERM,
                    self::ACTION_EDIT_TASK,
                    self::ACTION_ADD_TASK,
                    self::ACTION_REMOVE_TASK,
                    self::ACTION_SAVE,
                    self::ACTION_CANCEL,
                ], self::ACTION_SAVE);

                switch ($action) {
                    case self::ACTION_DATE:
                        $issueDate = $this->prompter->askIssueDate($io, $issueDate);
                        break;
                    case self::ACTION_LANGUAGE:
                        $language = $this->prompter->askLanguage($io, $language);
                        break;
                    case self::ACTION_TERM:
                        $paymentTerm = $this->prompter->askPaymentTerm($io, $paymentTerm->value, $language);
                        break;
                    case self::ACTION_EDIT_TASK:
                        $index = $this->chooseTask($io, $tasks, 'Which task?');
                        $tasks[$index] = $this->prompter->askTask($io, $config, $client, $tasks[$index]);
                        break;
                    case self::ACTION_ADD_TASK:
                        $tasks[] = $this->prompter->askTask($io, $config, $client, defaultPeriod: $tasks === [] ? null : $tasks[array_key_last($tasks)]->period);
                        break;
                    case self::ACTION_REMOVE_TASK:
                        if (\count($tasks) === 1) {
                            $io->outlineWarning('An invoice needs at least one task.');
                            break;
                        }
                        unset($tasks[$this->chooseTask($io, $tasks, 'Which task to remove?')]);
                        $tasks = array_values($tasks);
                        break;
                }
            } while ($action !== self::ACTION_SAVE && $action !== self::ACTION_CANCEL);

            if ($action === self::ACTION_CANCEL) {
                $io->outlineWarning('Nothing changed.');

                return Command::SUCCESS;
            }
        }

        $invoice = new Invoice(
            number: $entry->number,
            issueDate: $issueDate,
            client: $client,
            language: $language,
            currency: $entry->currency,
            paymentTerm: $paymentTerm,
            tasks: $tasks,
        );

        $outputPath = \sprintf(
            '%s/invoices/%s/%s-%s.pdf',
            rtrim($this->projectDir, '/'),
            substr($entry->number, 0, 4),
            $entry->number,
            Slug::of($client->name),
        );

        foreach (glob(\sprintf('%s/invoices/*/%s-*.pdf', rtrim($this->projectDir, '/'), $entry->number)) ?: [] as $oldPath) {
            unlink($oldPath);
        }
        $this->pdfGenerator->generate($invoice, $config, $outputPath);

        $this->ledger->replaceEntry(new InvoiceLedgerEntry(
            number: $entry->number,
            clientId: $client->id,
            clientName: $client->name,
            totalAmount: $invoice->totalAmount(),
            currency: $entry->currency,
            issueDate: $issueDate->format('Y-m-d'),
            dueDate: $invoice->dueDate()->format('Y-m-d'),
            paid: $entry->paid,
            paidAt: $entry->paidAt,
            language: $language,
            tasks: $tasks,
            paymentTermDays: $paymentTerm->value,
        ));

        $io->outlineSuccess(\sprintf(
            "Invoice %s regenerated: %s\nTotal: %s %s, due on %s",
            $entry->number,
            $outputPath,
            Money::format($invoice->totalAmount()),
            $entry->currency,
            $invoice->dueDate()->format('d.m.Y'),
        ));

        return Command::SUCCESS;
    }

    /**
     * @param list<Task> $tasks
     */
    private function renderSummary(SymfonyStyle $io, CarbonImmutable $issueDate, string $language, PaymentTerm $paymentTerm, array $tasks, string $currency): void
    {
        $io->section(\sprintf('%s · %s · %s', $issueDate->format('d.m.Y'), $language, $paymentTerm->label($language)));
        $io->listing(array_map(
            static fn(Task $t) => \sprintf('[%s] %s — %s h × %s = %s %s', $t->period ?? '—', $t->title, $t->hours, Money::format($t->hourlyRate), Money::format($t->amount()), $currency),
            $tasks,
        ));
    }

    /**
     * @param list<Task> $tasks
     */
    private function chooseTask(SymfonyStyle $io, array $tasks, string $question): int
    {
        $choices = [];
        foreach ($tasks as $i => $t) {
            $choices[$i + 1] = \sprintf('[%s] %s', $t->period ?? '—', $t->title);
        }

        return (int) $io->choice($question, $choices) - 1;
    }
}
