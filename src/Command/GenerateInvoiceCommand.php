<?php

declare(strict_types=1);

namespace RSickenberg\InvoicePhpMaker\Command;

use DateTimeImmutable;
use InvalidArgumentException;
use RSickenberg\InvoicePhpMaker\Config\AppConfig;
use RSickenberg\InvoicePhpMaker\Config\AppConfigRepository;
use RSickenberg\InvoicePhpMaker\Config\Client;
use RSickenberg\InvoicePhpMaker\Config\ClientRepository;
use RSickenberg\InvoicePhpMaker\Invoice\Invoice;
use RSickenberg\InvoicePhpMaker\Invoice\InvoiceLedger;
use RSickenberg\InvoicePhpMaker\Invoice\InvoiceLedgerEntry;
use RSickenberg\InvoicePhpMaker\Invoice\PaymentTerm;
use RSickenberg\InvoicePhpMaker\Invoice\Task;
use RSickenberg\InvoicePhpMaker\Pdf\InvoicePdfGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'invoice:generate', description: 'Generates a PDF invoice with a Swiss QR-bill by answering a few questions')]
final class GenerateInvoiceCommand extends Command
{
    private const string OTHER_CATEGORY_LABEL = 'Other';

    public function __construct(
        private readonly string $projectDir,
        private readonly AppConfigRepository $configRepository,
        private readonly ClientRepository $clientRepository,
        private readonly InvoiceLedger $ledger,
        private readonly InvoicePdfGenerator $pdfGenerator = new InvoicePdfGenerator(),
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

        $language = $this->askLanguage($io, $client, $config);
        $tasks = $this->askTasks($io, $config, $client);

        if ($tasks === []) {
            $io->outlineError('No task entered, invoice cancelled.');

            return Command::FAILURE;
        }

        $paymentTerm = $this->askPaymentTerm($io, $client, $config, $language);

        $issueDate = new DateTimeImmutable('today');
        $number = $this->ledger->nextInvoiceNumber((int) $issueDate->format('Y'));

        $invoice = new Invoice(
            number: $number,
            issueDate: $issueDate,
            client: $client,
            language: $language,
            currency: $config->defaultCurrency,
            paymentTerm: $paymentTerm,
            tasks: $tasks,
        );

        $outputPath = \sprintf(
            '%s/invoices/%s/%s-%s.pdf',
            rtrim($this->projectDir, '/'),
            $issueDate->format('Y'),
            $number,
            $this->slugify($client->name)
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
        ));

        $io->outlineSuccess(\sprintf(
            "Invoice %s generated: %s\nTotal: %s %s, due on %s",
            $number,
            $outputPath,
            number_format($invoice->totalAmount(), 2, ',', ' '),
            $invoice->currency,
            $invoice->dueDate()->format('d.m.Y')
        ));

        return Command::SUCCESS;
    }

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

    private function createClient(SymfonyStyle $io): Client
    {
        $io->section('New client');

        $name = $io->ask('Client / company name', validator: $this->requiredValidator());
        $street = $io->ask('Street', validator: $this->requiredValidator());
        $houseNumber = $io->ask('Building number', '');
        $postalCode = $io->ask('Postal code', validator: $this->requiredValidator());
        $city = $io->ask('City', validator: $this->requiredValidator());
        $country = $io->ask('Country (ISO code)', 'CH');

        $hourlyRate = null;
        if ($this->confirmYesNo($io, 'Does this client have a different hourly rate than the default?', false)) {
            $hourlyRate = (float) $io->ask('Hourly rate for this client', validator: $this->positiveFloatValidator());
        }

        $paymentTermDays = null;
        if ($this->confirmYesNo($io, 'Does this client have a different payment term than the default?', false)) {
            $paymentTermDays = (int) $io->choice(
                'Payment term for this client',
                array_map(static fn(PaymentTerm $t) => (string) $t->value, PaymentTerm::all())
            );
        }

        $language = null;
        if ($this->confirmYesNo($io, 'Does this client have a different invoice language than the default?', false)) {
            $language = $io->choice('Language for this client', ['fr', 'en']);
        }

        $client = new Client(
            id: $this->slugify($name),
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

    private function askLanguage(SymfonyStyle $io, Client $client, AppConfig $config): string
    {
        $default = $client->language ?? $config->defaultLanguage;

        return $io->choice('Invoice language', ['fr', 'en'], $default);
    }

    /**
     * @return list<Task>
     */
    private function askTasks(SymfonyStyle $io, AppConfig $config, Client $client): array
    {
        $categories = $config->categories;
        $categories[] = self::OTHER_CATEGORY_LABEL;
        $defaultRate = $client->hourlyRate ?? $config->defaultHourlyRate;

        $tasks = [];
        $io->section('Billed tasks');

        do {
            $description = $io->ask('Task description', validator: $this->requiredValidator());
            $category = $io->choice('Category', $categories);

            if ($category === self::OTHER_CATEGORY_LABEL) {
                $category = $io->ask('Category name', validator: $this->requiredValidator());
            }

            $hours = (float) $io->ask('Number of hours', validator: $this->positiveFloatValidator());
            $rate = (float) $io->ask('Hourly rate', (string) $defaultRate, $this->positiveFloatValidator());

            $tasks[] = new Task($description, $category, $hours, $rate);

            number_format($hours * $rate, 2, ',', '')
                |> (static fn($x) => \sprintf('  → %s: %s %s', $description, $x, $config->defaultCurrency))
                |> $io->text(...);
        } while ($this->confirmYesNo($io, 'Add another task?', true));

        return $tasks;
    }

    private function askPaymentTerm(SymfonyStyle $io, Client $client, AppConfig $config, string $language): PaymentTerm
    {
        $default = $client->paymentTermDays ?? $config->defaultPaymentTermDays;
        $choices = array_map(static fn(PaymentTerm $t) => $t->label($language), PaymentTerm::all());
        $defaultLabel = PaymentTerm::fromDays($default)->label($language);

        $selected = $io->choice('Payment term', $choices, $defaultLabel);
        $index = array_search($selected, $choices, true);

        return PaymentTerm::all()[$index];
    }

    private function confirmYesNo(SymfonyStyle $io, string $question, bool $default): bool
    {
        $q = new Question(\sprintf('%s (y/n)', $question), $default ? 'y' : 'n');
        $q->setNormalizer(static fn(?string $answer) => (string) $answer
                |> trim(...)
                |> mb_strtolower(...)
                |> (static fn($x) => str_starts_with($x, 'y')));

        return (bool) $io->askQuestion($q);
    }

    private function requiredValidator(): callable
    {
        return static function (?string $value): string {
            if ($value === null || trim($value) === '') {
                throw new InvalidArgumentException('This value cannot be empty.');
            }

            return $value;
        };
    }

    private function positiveFloatValidator(): callable
    {
        return static function (?string $value): float {
            $normalized = str_replace(',', '.', (string) $value);
            if (!is_numeric($normalized) || (float) $normalized <= 0) {
                throw new InvalidArgumentException('Please enter a positive number.');
            }

            return (float) $normalized;
        };
    }

    private function slugify(string $value): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $value));

        return trim($slug, '-') ?: 'client';
    }
}
