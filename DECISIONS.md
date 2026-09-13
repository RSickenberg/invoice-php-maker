<!--
Decision log for this project. Maintained by the `decisions` Claude Code
skill (.claude/skills/decisions/SKILL.md) — read that file for the rules.
Short version: entries are for decisions that are foundational, whose
reasoning wouldn't otherwise survive in the code/commit, or that you asked
to be tracked. Never delete an entry; supersede or reopen it instead.
-->

# Decisions

## Open questions

<!-- one line each; delete once ruled (git history keeps them) -->

## Log

<!--
## D-001 (YYYY-MM-DD) — short title

- **Status:** open | decided | reaffirmed (date) | reopened | superseded by D-NNN
- **Foundational:** yes | no
- **Decision:** what was decided (or the options, while open)
- **Why:** the reasoning, including alternatives rejected and why
- **Premises:** the facts and context this decision depends on
-->

## D-001 (2026-09-13) -- No FrankenPHP overlay, symfony/console for the CLI

- **Status:** decided
- **Foundational:** yes
- **Decision:** bootstrap without a stack overlay (just `common/`), then a hand-built Composer skeleton using the `symfony/console` component (not the full framework)
- **Why:** the template's two overlays (`php-frankenphp-symfony`, `php-frankenphp-laravel`) bundle Docker/FrankenPHP/Postgres for a web app, which doesn't fit a single-user local CLI tool. `symfony/console` provides the interactive prompts (QuestionHelper/SymfonyStyle) that were asked for without the weight of the full framework.
- **Premises:** personal tool, no web deployment, no database.

## D-002 (2026-09-13) -- TCPDF + sprain/swiss-qr-bill for the PDF and the QR-bill

- **Status:** superseded by D-009 (TCPDF version pin only; sprain/swiss-qr-bill ^5.3 still stands)
- **Foundational:** yes
- **Decision:** `tecnickcom/tcpdf` (^6.11, the classic API -- not v7, which depends on `tc-lib-pdf`) + `sprain/swiss-qr-bill` (^5.3) via `Sprain\SwissQrBill\PaymentPart\Output\TcPdfOutput\TcPdfOutput`, which draws the payment slip directly onto the invoice's own TCPDF instance
- **Why:** `sprain/swiss-qr-bill` is the best-maintained active library for the Swiss QR-bill and documents direct integration with TCPDF/FPDI (unlike dompdf, which "needs adjustments" per its own docs). Pinning TCPDF to ^6.11 rather than v7: v7 is a deprecated compatibility wrapper around `tc-lib-pdf` and no longer necessarily exposes the same classic API (Cell/MultiCell/SetFont) that `TcPdfOutput` expects; `sprain` itself tests against `tecnickcom/tcpdf ^6.3.2` in its require-dev.
- **Premises:** verified by cloning both GitHub repos and installing a full test vendor tree (`repo.packagist.org` being blocked by the session's network policy); an end-to-end smoke test (a 1-page invoice and a 45-task / 2-page invoice) confirms the QR-bill prints correctly at the bottom of the last page in both cases.

## D-003 (2026-09-13) -- Standard IBAN, NON payment reference

- **Status:** decided
- **Foundational:** yes
- **Decision:** creditor with a standard IBAN (no QR-IBAN) and `PaymentReference::TYPE_NON` (no structured reference)
- **Why:** per the SIX implementation guidelines, a QR-IBAN requires a QRR reference; a standard IBAN takes SCOR or NON. The user only has a standard IBAN and doesn't need a structured reference (no close per-invoice reconciliation by a third-party accounting tool).
- **Premises:** standard IBAN confirmed by the user; reopen if they get a QR-IBAN or adopt accounting software that relies on SCOR references.

## D-004 (2026-09-13) -- Config and client registry in gitignored JSON, with versioned `.example` files

- **Status:** decided
- **Foundational:** yes
- **Decision:** `config/config.json` (billing info + IBAN) and `config/clients.json` (client registry) are gitignored; `config/config.example.json` and `config/clients.example.json` are versioned in their place
- **Why:** IBAN and client details are personal/sensitive data that has no place in a Git repo, even a private one.
- **Premises:** the user explicitly asked for the client registry to stay off GitHub; extended for consistency to the config file.

## D-005 (2026-09-13) -- Yearly numbering + `invoices.json` ledger

- **Status:** decided
- **Foundational:** yes
- **Decision:** invoice number sequential, reset to 1 every calendar year, format `YYYY-NNN` (`2026-001`, 3 digits); `invoices.json` (gitignored) tracks every invoice (client, amount, dates, paid/unpaid status); status is updated via the dedicated `invoice:mark-paid <number>` command, never by hand-editing the JSON
- **Why:** a readable format that's common practice in Switzerland; a dedicated command avoids JSON syntax mistakes from manual edits and stays in the spirit of "everything happens in the CLI."
- **Premises:** freelance invoicing volume (3 digits is plenty; revisit if more than 999 invoices/year).

## D-006 (2026-09-13) -- Rate, payment term and language: global default + per-client override

- **Status:** decided
- **Foundational:** yes
- **Decision:** `config.json` carries default values (hourly rate, payment term, language); each client in `clients.json` can override any of the three; the resolved value stays editable on the fly at each question asked in the CLI
- **Why:** avoids re-entering the same values for a recurring client with special terms, without losing one-off flexibility.
- **Premises:** none.

## D-007 (2026-09-13) -- VAT not implemented, just a flag ready for later

- **Status:** decided
- **Foundational:** no
- **Decision:** `config.json` carries a `vatEnabled: false` field; no VAT calculation or display on the PDF for now
- **Why:** the user is not yet subject to VAT but wants to be able to turn it on later without reworking the data model.
- **Premises:** reopen as soon as the user becomes VAT-liable -- rate, VAT number, and the calculation/display lines on the PDF will need to be added then.

## D-008 (2026-09-13) -- Makefile + php-cs-fixer modeled on programmatic-resume, not the Docker overlays

- **Status:** decided
- **Foundational:** no
- **Decision:** `Makefile` and `.php-cs-fixer.dist.php` are copied from `programmatic-resume` (help banner/target style, `friendsofphp/php-cs-fixer ^3.95` with the `@auto`/`@auto:risky`/`@PhpCsFixer:risky` ruleset), not from the `php-frankenphp-symfony`/`php-frankenphp-laravel` template overlays (Docker-oriented, older `@PSR12`+`@Symfony` manual ruleset). Targets: `help`, `install`, `update`, `generate`, `mark-paid`, `lint`, `lint-check`.
- **Why:** `programmatic-resume` is the closer match in shape (plain Composer PHP CLI tool, no Docker, no web server, PHP 8.5), and its php-cs-fixer setup is already proven working on a real project.
- **Premises:** this sandbox has no Packagist access to verify the `friendsofphp/php-cs-fixer` install itself; run `composer update` once on a machine with network access to pull it in (the constraint is copied verbatim from `programmatic-resume`, already known-good there).

## D-009 (2026-09-13) -- TCPDF v7 adopted (revises D-002)

- **Status:** decided
- **Foundational:** yes
- **Decision:** move from `tecnickcom/tcpdf` ^6.11 to ^7.0.9, at the user's explicit, voluntary request.
- **Why:** the user chose this directly. Re-checked the risk D-002 was pinning against: TCPDF v7.0.9's own docblock describes itself as "a compatibility facade: it implements the legacy TCPDF public API as thin wrappers that delegate ... to the modern tc-lib-pdf engine", and its `MAPPING.md` lists every method our code and `sprain/swiss-qr-bill`'s `TcPdfOutput` call (`Cell`, `MultiCell`, `SetFont`, `SetTextColor`, `ImageSVG` including the `@`-prefixed inline-SVG form, `StartTransform`/`Rotate`/`StopTransform`, `Header`/`Footer` page hooks, etc.) as implemented ("adapter"/"shim"/"delegated"), not "blocked". PHP resolves method calls case-insensitively, so the v7 facade's lowerCamelCase methods (`setY`, `setFont`, ...) still satisfy the classic UpperCamelCase call sites unchanged. No source changes were needed in `InvoiceDocument`, `InvoicePdfGenerator` or `QrBillFactory`.
- **Premises:** verified by static API inspection (MAPPING.md + direct signature reading of TCPDF v7.0.9's `tcpdf.php`), not a full runtime install: `tecnickcom/tc-lib-pdf`'s own dependency tree (~15 further `tecnickcom/tc-lib-*` packages) was judged too large to clone package-by-package for this sandbox's blocked-Packagist workaround, proportionate to a version bump the user already owns. Run `bin/console invoice:generate` for real once on a machine with Packagist access (`make install` / `composer update`) to confirm end-to-end; if anything surfaces, the fix is almost certainly a v7-only method name from `MAPPING.md`'s "blocked" list (only `ImageEps` and `addPageRegion`, neither used here).

**Addendum (2026-09-13):** considered dropping TCPDF entirely for raw `tecnickcom/tc-lib-pdf`. Confirmed `sprain/swiss-qr-bill` ships exactly three output adapters (`TcPdfOutput`, `FpdfOutput`, `HtmlOutput`) and none for tc-lib-pdf, in current source and full git history. Since TCPDF v7 already delegates to tc-lib-pdf internally, staying on it *is* using tc-lib-pdf, just through the facade that keeps `TcPdfOutput` working. The alternative -- hand-drawing the payment slip against tc-lib-pdf's native API to the SIX Group spec's exact tolerances -- was rejected as unnecessary risk for no engine change. User confirmed: stay on TCPDF v7.

## D-010 (2026-09-13) -- Config DTOs (Creditor, Defaults) instead of raw array access

- **Status:** decided
- **Foundational:** no
- **Decision:** `AppConfig` now holds `Creditor $creditor` and `Defaults $defaults` (new `src/Config/Creditor.php` / `Defaults.php`, each with a `fromArray()` factory) instead of 10 flat `creditor*`/`default*` scalar properties. `AppConfigRepository::load()` builds these two DTOs first, then `AppConfig`. All call sites (`QrBillFactory`, `InvoicePdfGenerator`, `GenerateInvoiceCommand`) updated to `$config->creditor->name`, `$config->defaults->hourlyRate`, etc.
- **Why:** matches the pattern already used for `Client` and `InvoiceLedgerEntry` (`fromArray`/`toArray` DTOs) instead of raw `$creditor['name']`/`$defaults['hourlyRate']` array-key access, which was the one inconsistent spot left. `email`/`phone`/`website`/`iban`/`vatEnabled`/`categories` stay flat on `AppConfig`, matching how they sit in the JSON (siblings of `creditor`/`defaults`, not nested under either).
- **Premises:** none; this is a shape-only refactor, config.json's format is unchanged.

## D-011 (2026-09-13) -- Carbon for date handling

- **Status:** decided
- **Foundational:** no
- **Decision:** `nesbot/carbon` (^3.14) replaces native `DateTimeImmutable` everywhere a date is created or computed: `Invoice::$issueDate` is now `CarbonImmutable`, `Invoice::dueDate()` uses `->addDays()` instead of `->modify('+N days')`, `GenerateInvoiceCommand` uses `CarbonImmutable::today()`, `MarkPaidCommand` uses `CarbonImmutable::today()->toDateString()`. `InvoiceLedgerEntry` keeps plain `Y-m-d` strings for JSON storage, unchanged.
- **Why:** the user prefers Carbon's API for date arithmetic over raw `DateTimeImmutable::modify()` string manipulation.
- **Premises:** verified end-to-end (issue date, `+10 days` due date, ledger persistence, mark-paid) via a git-clone path-repo test build in this sandbox (Carbon's own dependency tree is small: `carbonphp/carbon-doctrine-types`, `psr/clock`, `symfony/clock`, `symfony/translation`) -- real PDF generated and visually checked, unchanged from before.

## D-012 (2026-09-13) -- Automate the TCPDF standard-font build via composer post-install/post-update hooks

- **Status:** decided
- **Foundational:** no
- **Decision:** added `bin/build-fonts.php` (idempotent: skips if `vendor/tecnickcom/tc-lib-pdf-font/target/fonts/core/helvetica.json` already exists, no-ops if the font package isn't installed at all) wired into `composer.json`'s `post-install-cmd` and `post-update-cmd`, plus a `make fonts` target that runs the same script directly.
- **Why:** `tecnickcom/tc-lib-pdf-font` (a dependency of TCPDF v7 / tc-lib-pdf) ships with no pre-built font metrics -- it expects a consuming project to run its own `util/bulk_convert.php` build step. `tecnickcom/tcpdf`'s own composer.json declares this as a post-install/post-update script, but Composer only ever executes the ROOT project's own lifecycle scripts, never a dependency's, so it silently never ran here, and the first font call blew up with "unable to read file: helvetica.json". Baking the build into our own composer.json's hooks means it just works on `composer install`/`composer update`, with no extra manual step for a fresh checkout or new machine.
- **Premises:** verified end-to-end with a full sandbox rebuild (fresh `vendor/`, `composer install` from scratch) confirming the fonts get built automatically and a real PDF renders correctly afterward.

## D-013 (2026-09-13) -- Drop TCPDF entirely; use tc-lib-pdf directly + sprain/swiss-qr-bill PR #299 (supersedes D-009)

- **Status:** decided
- **Foundational:** yes
- **Decision:** removed `tecnickcom/tcpdf` from composer.json entirely; require `tecnickcom/tc-lib-pdf` (^8.20, the modern engine TCPDF v7 only wrapped) directly, and pin `sprain/swiss-qr-bill` to the exact commit of its still-unmerged PR #299 (https://github.com/sprain/php-swiss-qr-bill/pull/299, commit `8e5df9287272feda2f9bc572a2fc16dd3b167796`), which adds `TcLibPdfOutput`, a payment-part renderer that draws straight onto a raw tc-lib-pdf `Tcpdf` engine instance instead of onto a classic-API TCPDF facade. `src/Pdf/InvoiceDocument.php` was rewritten from a `TCPDF` subclass into a thin canvas class driving `Com\Tecnick\Pdf\Tcpdf` directly, with its own manual pagination.
- **Why:** user's explicit request. tc-lib-pdf has no `Cell()`/`MultiCell()`/`Header()`/`Footer()`/automatic-page-break convenience layer like classic TCPDF, so `InvoiceDocument` now tracks its own Y cursor and reimplements the same two thresholds the old `SetAutoPageBreak(true, 20)`-based code relied on: `ensureRoom()` breaks to a new page past Y=277mm during normal body flow, `ensureQrBillRoom()` forces a break past Y=188mm before drawing the QR-bill (105mm needed). Every page is created with content-region `CB=0` (no bottom reservation at the tc-lib-pdf level): testing showed content positioned past a page's configured content-bottom boundary is *silently dropped*, not auto-paginated or clipped, so page-break decisions had to move entirely into our own code rather than relying on the library's region system.
  Also required: defining the `K_PATH_FONTS` constant explicitly in `bin/console` before any font lookup (previously handled implicitly somewhere in TCPDF v7's own facade bootstrap, which no longer runs since TCPDF is gone).
  Two minor layout differences from the previous TCPDF-based rendering, both in `InvoicePdfGenerator::drawTasksTable()`: `addTextCellXY` (tc-lib-pdf's low-level text-cell primitive, used by both the old TCPDF v7 facade and this PR's `TcLibPdfOutput`) wraps text that overflows its given width onto extra lines by default, rather than letting it overflow visually the way TCPDF's `Cell()` did -- fixed by passing `fit: 'T'` (truncate) on `InvoiceDocument::cell()`'s calls; and the `amount` column was widened from 25mm to 33mm (borrowed from `category`, narrowed 40mm -> 32mm) so six-figure bold totals still render on one line under truncation instead of getting cut off.
  This also makes the earlier `Output($path, 'F')`-for-a-full-path bug (found while fixing D-012) moot: the new `InvoiceDocument::outputTo()` calls `getOutPDFString()` + `file_put_contents()` directly and never touches the TCPDF facade's `Output()` method at all.
- **Premises:** verified with a single-task invoice, a 45-task/multi-page invoice, and a full `CommandTester`-driven smoke test, each visually checked via `pdftoppm` renders against the known-good prior TCPDF-based output. **Caveat: `sprain/swiss-qr-bill` is currently pinned to an *unmerged* PR via a custom Composer "package"-type repository referencing a GitHub commit-archive zip (not a normal Packagist version constraint) -- revisit once/if PR #299 merges upstream: drop the custom `repositories` entry in composer.json and switch back to a normal `^` version range.** The real `codeload.github.com` zip URL used in the pinned dist could not be exercised end-to-end in the sandbox that did this work (that host is blocked there); the packaging mechanics (folder-stripping, PSR-4 autoload resolution) were verified against a local `file://` substitute that mimics GitHub's real archive layout exactly, but running `composer update` once on this actual machine is the way to fully confirm it.
