<?php

declare(strict_types=1);

namespace RSickenberg\InvoicePhpMaker\Command;

use InvalidArgumentException;
use RSickenberg\InvoicePhpMaker\Config\Client;
use RSickenberg\InvoicePhpMaker\Config\ClientRepository;
use RSickenberg\InvoicePhpMaker\Invoice\PaymentTerm;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'client:edit', description: 'Edits an existing client')]
final class EditClientCommand extends Command
{
    private const string USE_DEFAULT = 'default';

    public function __construct(private readonly ClientRepository $clientRepository)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::OPTIONAL, 'Client id, e.g. acme-sa (prompts to choose a client if omitted)');
    }

    /**
     * @throws \JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = $input->getArgument('id');

        if ($id === null) {
            $clients = $this->clientRepository->all();
            if ($clients === []) {
                $io->outlineWarning('No clients yet.');

                return Command::SUCCESS;
            }

            $choices = [];
            foreach ($clients as $c) {
                $choices[$c->id] = \sprintf('%s (%s)', $c->name, $c->city);
            }

            $id = (string) $io->choice('Which client?', $choices);
        }

        $client = $this->clientRepository->find((string) $id);
        if ($client === null) {
            $io->outlineError(\sprintf('No client "%s" found in clients.json.', $id));

            return Command::FAILURE;
        }

        $io->section(\sprintf('Edit %s (press Enter to keep the current value)', $client->name));

        $edited = new Client(
            id: $client->id,
            name: $io->ask('Client / company name', $client->name, $this->requiredValidator()),
            street: $io->ask('Street', $client->street, $this->requiredValidator()),
            houseNumber: (string) $io->ask('Building number', $client->houseNumber),
            postalCode: $io->ask('Postal code', $client->postalCode, $this->requiredValidator()),
            city: $io->ask('City', $client->city, $this->requiredValidator()),
            country: $io->ask('Country (ISO code)', $client->country),
            hourlyRate: $this->askOverride($io, 'Hourly rate', $client->hourlyRate, static function (string $value): float {
                $normalized = str_replace(',', '.', $value);
                if (!is_numeric($normalized) || (float) $normalized <= 0) {
                    throw new InvalidArgumentException('Please enter a positive number.');
                }

                return (float) $normalized;
            }),
            paymentTermDays: $this->askOverride($io, 'Payment term (days)', $client->paymentTermDays, static function (string $value): int {
                $allowed = array_map(static fn(PaymentTerm $t) => (string) $t->value, PaymentTerm::all());
                if (!\in_array($value, $allowed, true)) {
                    throw new InvalidArgumentException(\sprintf('Choose one of: %s.', implode(', ', $allowed)));
                }

                return (int) $value;
            }),
            language: $this->askOverride($io, 'Invoice language', $client->language, static function (string $value): string {
                if (!\in_array($value, ['fr', 'en'], true)) {
                    throw new InvalidArgumentException('Choose fr or en.');
                }

                return $value;
            }),
        );

        $this->clientRepository->save($edited);
        $io->outlineSuccess(\sprintf('Client %s updated.', $edited->name));

        return Command::SUCCESS;
    }

    /**
     * Asks for an optional override; Enter keeps the current value, "default" clears it.
     *
     * @template T of float|int|string
     * @param callable(string): T $parse
     * @return T|null
     */
    private function askOverride(SymfonyStyle $io, string $label, float|int|string|null $current, callable $parse): float|int|string|null
    {
        return $io->ask(
            \sprintf('%s (type "%s" to use the global default)', $label, self::USE_DEFAULT),
            $current === null ? self::USE_DEFAULT : (string) $current,
            static function (?string $value) use ($parse): float|int|string|null {
                $value = trim((string) $value);

                return $value === self::USE_DEFAULT ? null : $parse($value);
            },
        );
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
}
