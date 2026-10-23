<?php

declare(strict_types=1);

namespace RSickenberg\InvoicePhpMaker\Command;

use Carbon\CarbonImmutable;
use RSickenberg\InvoicePhpMaker\Config\AppConfigRepository;
use RSickenberg\InvoicePhpMaker\Config\Client;
use RSickenberg\InvoicePhpMaker\Config\ClientRepository;
use RSickenberg\InvoicePhpMaker\Invoice\Invoice;
use RSickenberg\InvoicePhpMaker\Invoice\InvoiceLedger;
use RSickenberg\InvoicePhpMaker\Invoice\InvoiceLedgerEntry;
use RSickenberg\InvoicePhpMaker\Invoice\Money;
use RSickenberg\InvoicePhpMaker\Invoice\PaymentTerm;
use RSickenberg\InvoicePhpMaker\Invoice\Slug;
use RSickenberg\InvoicePhpMaker\Pdf\InvoicePdfGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'invoice:generate', description: 'Generates a PDF invoice with a Swiss QR-bill by answering a few questions')]
final class GenerateInvoiceCommand extends Command
{
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

    /**
     * @throws \JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('New invoice');

        $config = $this->configRepository->load();
        $client = $this->resolveClient($io);

        $issueDate = $this->prompter->askIssueDate($io, CarbonImmutable::today());
        $language = $this->prompter->askLanguage($io, $client->language ?? $config->defaults->language);
        $tasks = $this->prompter->askTasks($io, $config, $client);

        if ($tasks === []) {
            $io->outlineError('No task entered, invoice cancelled.');

            return Command::FAILURE;
        }

        $paymentTerm = $this->prompter->askPaymentTerm($io, $client->paymentTermDays ?? $config->defaults->paymentTermDays, $language);

        $number = $this->ledger->nextInvoiceNumber((int) $issueDate->format('Y'));

        $invoice = new Invoice(
            number: $number,
            issueDate: $issueDate,
            client: $client,
            language: $language,
            currency: $config->defaults->currency,
            paymentTerm: $paymentTerm,
            tasks: $tasks,
        );

        $outputPath = \sprintf(
            '%s/invoices/%s/%s-%s.pdf',
            rtrim($this->projectDir, '/'),
            $issueDate->format('Y'),
            $number,
            Slug::of($client->name)
        );

        $this->pdfGenerator->generate($invoice, $config, $outputPath);

        $this->ledger->addEntry(new InvoiceLedgerEntry(
            number: $number,
            clientId: $client->id,
            clientName: $client->name,
            totalAmount: $invoice->totalAmount(),
            currency: $invoice->currency,
            issueDate: $issueDate->format('Y-m-d'),
            dueDate: $invoice->dueDate()->format('Y-m-d'),
            language: $language,
            tasks: $tasks,
            paymentTermDays: $paymentTerm->value,
        ));

        $io->outlineSuccess(\sprintf(
            "Invoice %s generated: %s\nTotal: %s %s, due on %s",
            $number,
            $outputPath,
            Money::format($invoice->totalAmount()),
            $invoice->currency,
            $invoice->dueDate()->format('d.m.Y')
        ));

        return Command::SUCCESS;
    }

    /**
     * @throws \JsonException
     */
    private function resolveClient(SymfonyStyle $io): Client
    {
        $clients = $this->clientRepository->all();
        $newClientLabel = '+ New client';

        if ($clients !== []) {
            $labels = array_map(
                static fn(Client $c) => \sprintf('%s (%s)', $c->name, $c->city),
                $clients
            );
            $labels[] = $newClientLabel;

            $choice = $io->choice('Client', $labels, $labels[0]);
            $index = array_search($choice, $labels, true);

            if ($index !== false && $choice !== $newClientLabel) {
                return $clients[$index];
            }
        }

        return $this->createClient($io);
    }

    /**
     * @throws \JsonException
     */
    private function createClient(SymfonyStyle $io): Client
    {
        $io->section('New client');

        $name = $io->ask('Client / company name', validator: $this->prompter->requiredValidator());
        $street = $io->ask('Street', validator: $this->prompter->requiredValidator());
        $houseNumber = $io->ask('Building number', '');
        $postalCode = $io->ask('Postal code', validator: $this->prompter->requiredValidator());
        $city = $io->ask('City', validator: $this->prompter->requiredValidator());
        $country = $io->ask('Country (ISO code)', 'CH');

        $hourlyRate = null;
        if ($this->prompter->confirmYesNo($io, 'Does this client have a different hourly rate than the default?', false)) {
            $hourlyRate = (float) $io->ask('Hourly rate for this client', validator: $this->prompter->positiveFloatValidator());
        }

        $paymentTermDays = null;
        if ($this->prompter->confirmYesNo($io, 'Does this client have a different payment term than the default?', false)) {
            $paymentTermDays = (int) $io->choice(
                'Payment term for this client',
                array_map(static fn(PaymentTerm $t) => (string) $t->value, PaymentTerm::all())
            );
        }

        $language = null;
        if ($this->prompter->confirmYesNo($io, 'Does this client have a different invoice language than the default?', false)) {
            $language = $io->choice('Language for this client', ['fr', 'en']);
        }

        $client = new Client(
            id: Slug::of($name),
            name: $name,
            street: $street,
            houseNumber: (string) $houseNumber,
            postalCode: $postalCode,
            city: $city,
            country: $country,
            hourlyRate: $hourlyRate,
            paymentTermDays: $paymentTermDays,
            language: $language,
        );

        $this->clientRepository->save($client);

        return $client;
    }
}
