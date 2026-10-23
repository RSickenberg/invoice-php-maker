# invoice-php-maker

A personal CLI tool that generates PDF invoices with a Swiss QR-bill. You answer a few interactive questions (client, tasks billed by category, hours, rate, payment term) and get a ready-to-send PDF. Invoices are tracked in a local ledger so you can list them and mark them as paid.

## Requirements

- PHP 8.5+
- [Composer](https://getcomposer.org)

## Installation

```bash
make install
```

Then create your configuration from the examples:

```bash
cp config/config.example.json config/config.json
cp config/clients.example.json config/clients.json
```

- `config/config.json`: your details (creditor address, IBAN, contact info), defaults (hourly rate, currency, payment term, language), VAT toggle and billing categories.
- `config/clients.json`: your saved clients. Per-client `hourlyRate`, `paymentTermDays` and `language` override the defaults when set. New clients can also be added interactively while generating an invoice.

Both files are git-ignored, as are `invoices.json` and the `invoices/` folder.

## Usage

| Task | Command |
|---|---|
| Generate an invoice (interactive) | `make generate` |
| List all invoices | `make list` |
| List open (unpaid) invoices only | `make list ARGS=--unpaid` |
| Mark an invoice as paid (interactive) | `make mark-paid` |
| Mark a given invoice as paid | `make mark-paid NUMBER=2026-001` |
| Edit an invoice (date, term, language, tasks) and regenerate its PDF | `make edit` (or `NUMBER=2026-001`) |
| Regenerate an invoice's PDF as stored | `make regenerate NUMBER=2026-001` |
| Delete an invoice (ledger entry and PDF, interactive) | `make delete` (or `NUMBER=2026-001`) |
| Edit an existing client (interactive) | `make edit-client` (or `ID=acme-sa`) |
| Fix code style | `make lint` |
| Check code style (no changes) | `make lint-check` |
| Show all commands | `make help` |

Each `make` target wraps a console command, so `bin/console invoice:generate`, `invoice:list [--unpaid]`, `invoice:mark-paid [number]`, `invoice:delete [number]` and `client:edit [id]`, `invoice:edit [number] [--regenerate]` work too.

### Generating an invoice

`make generate` walks you through choosing or creating a client, the invoice language, the tasks and hours per category, the rate and the payment term. The PDF is written under `invoices/` and the invoice is recorded in `invoices.json`.

Invoice numbers are sequential and reset every year (`2026-001`, `2026-002`, …). Deleting the latest invoice of a year frees its number for the next one.

### Dates, months and editing

`invoice:generate` asks for the invoice date (defaults to today) and, for each task, the month the work was done (`YYYY-MM`). The PDF groups tasks under a month heading (Mai 2026, Juin 2026, …) with a subtotal per month. Every invoice's tasks, language and payment term are stored in `invoices.json`, so `make edit` can change them later and `make regenerate` rebuilds the PDF as stored. Invoices generated before this was added have no stored tasks and cannot be edited.

### Marking an invoice as paid

Run `make mark-paid` and pick the invoice from the list of open ones, then confirm or enter the paid date (`YYYY-MM-DD`, defaults to today). Passing `NUMBER=` skips the menu but still asks for the date.

## Project docs

- [`DECISIONS.md`](DECISIONS.md): log of technical and architectural decisions.
- [`CHANGELOG.md`](CHANGELOG.md): generated from Conventional Commits on release.

## License

MIT
