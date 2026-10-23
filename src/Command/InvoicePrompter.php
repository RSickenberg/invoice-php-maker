<?php

declare(strict_types=1);

namespace RSickenberg\InvoicePhpMaker\Command;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use InvalidArgumentException;
use RSickenberg\InvoicePhpMaker\Config\AppConfig;
use RSickenberg\InvoicePhpMaker\Config\Client;
use RSickenberg\InvoicePhpMaker\Invoice\Money;
use RSickenberg\InvoicePhpMaker\Invoice\PaymentTerm;
use RSickenberg\InvoicePhpMaker\Invoice\Task;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Interactive questions shared by invoice:generate and invoice:edit.
 */
final class InvoicePrompter
{
    private const string OTHER_CATEGORY_LABEL = 'Other';

    public function askIssueDate(SymfonyStyle $io, CarbonImmutable $default): CarbonImmutable
    {
        $date = $io->ask('Invoice date (YYYY-MM-DD)', $default->toDateString(), static function (?string $value): string {
            try {
                return CarbonImmutable::createFromFormat('!Y-m-d', trim((string) $value))->toDateString();
            } catch (InvalidFormatException) {
                throw new InvalidArgumentException('Use the YYYY-MM-DD format.');
            }
        });

        return CarbonImmutable::createFromFormat('!Y-m-d', (string) $date);
    }

    public function askLanguage(SymfonyStyle $io, string $default): string
    {
        return $io->choice('Invoice language', ['fr', 'en'], $default);
    }

    public function askPaymentTerm(SymfonyStyle $io, int $defaultDays, string $language): PaymentTerm
    {
        $choices = array_map(static fn(PaymentTerm $t) => $t->label($language), PaymentTerm::all());
        $defaultLabel = PaymentTerm::fromDays($defaultDays)->label($language);

        $selected = $io->choice('Payment term', $choices, $defaultLabel);
        $index = array_search($selected, $choices, true);

        return PaymentTerm::all()[$index];
    }

    /**
     * @return list<Task>
     */
    public function askTasks(SymfonyStyle $io, AppConfig $config, Client $client): array
    {
        $tasks = [];
        $io->section('Billed tasks');

        do {
            $tasks[] = $this->askTask($io, $config, $client, defaultPeriod: $tasks === [] ? null : $tasks[array_key_last($tasks)]->period);
        } while ($this->confirmYesNo($io, 'Add another task?', true));

        return $tasks;
    }

    public function askTask(SymfonyStyle $io, AppConfig $config, Client $client, ?Task $existing = null, ?string $defaultPeriod = null): Task
    {
        $categories = $config->categories;
        $categories[] = self::OTHER_CATEGORY_LABEL;
        $defaultRate = $existing?->hourlyRate ?? $client->hourlyRate ?? $config->defaults->hourlyRate;

        $title = $io->ask('Task title', $existing?->title, $this->requiredValidator());
        $description = trim((string) $io->ask('Task description (optional)', $existing?->description ?? ''));

        $category = $io->choice('Category', $categories, $existing !== null && \in_array($existing->category, $categories, true) ? $existing->category : null);
        if ($category === self::OTHER_CATEGORY_LABEL) {
            $category = $io->ask('Category name', $existing?->category, $this->requiredValidator());
        }

        $period = $io->ask(
            'Month the work was done (YYYY-MM)',
            $existing?->period ?? $defaultPeriod ?? CarbonImmutable::today()->format('Y-m'),
            static function (?string $value): string {
                try {
                    return CarbonImmutable::createFromFormat('!Y-m', trim((string) $value))->format('Y-m');
                } catch (InvalidFormatException) {
                    throw new InvalidArgumentException('Use the YYYY-MM format.');
                }
            },
        );

        $hours = (float) $io->ask('Number of hours', $existing === null ? null : (string) $existing->hours, $this->positiveFloatValidator());
        $rate = (float) $io->ask('Hourly rate', (string) $defaultRate, $this->positiveFloatValidator());

        $io->text(\sprintf('  → %s: %s %s', $title, Money::format($hours * $rate), $config->defaults->currency));

        return new Task($title, $category, $hours, $rate, $description !== '' ? $description : null, $period);
    }

    public function confirmYesNo(SymfonyStyle $io, string $question, bool $default): bool
    {
        $q = new Question(\sprintf('%s (y/n)', $question), $default ? 'y' : 'n');
        $q->setNormalizer(static fn(?string $answer) => (string) $answer
                |> trim(...)
                |> mb_strtolower(...)
                |> (static fn($x) => str_starts_with($x, 'y')));

        return (bool) $io->askQuestion($q);
    }

    public function requiredValidator(): callable
    {
        return static function (?string $value): string {
            if ($value === null || trim($value) === '') {
                throw new InvalidArgumentException('This value cannot be empty.');
            }

            return $value;
        };
    }

    public function positiveFloatValidator(): callable
    {
        return static function (?string $value): float {
            $normalized = str_replace(',', '.', (string) $value);
            if (!is_numeric($normalized) || (float) $normalized <= 0) {
                throw new InvalidArgumentException('Please enter a positive number.');
            }

            return (float) $normalized;
        };
    }
}
