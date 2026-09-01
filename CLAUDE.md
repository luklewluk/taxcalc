# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Kalkulator PIT-38** — a privacy-first, open-source (MIT) Polish stock and dividend tax
calculator. Symfony **8.1** on PHP **8.4+** (developed on 8.5), available both as a web
application and as CLI commands.

Reads Interactive Brokers exports, DEGIRO exports, or the project's own normalized CSVs,
converts amounts using NBP D-1 rates, matches sells to buys with FIFO, and produces
PIT-38 / PIT-ZG figures.

**No database. No persistence of user data at all.** Uploaded files are read once from
PHP's temporary upload file, which is immediately unlinked; the content lives only in
request memory. Between requests, rows travel back to the browser as form fields. The
session holds nothing but a CSRF token. The only cached data is public NBP exchange rates.

Do not introduce a database, a queue, session storage of financial data, or any
third-party frontend resource — those would break the product's core promise.

## Commands

```bash
# Tests
vendor/bin/phpunit                              # full suite
vendor/bin/phpunit --testsuite unit             # domain logic only
vendor/bin/phpunit --testsuite functional       # HTTP + console via test kernel
vendor/bin/phpunit --filter testMethodName

# Static analysis (level 9, src only)
vendor/bin/phpstan analyse

# Lints
composer validate --strict
php bin/console lint:container
php bin/console lint:twig templates
composer audit

# Shortcut
composer check                                  # phpstan + phpunit

# Local server
php -S 127.0.0.1:8000 -t public
```

### CLI commands (backwards compatible)

```bash
php bin/console app:calculate-from-file <csv>... [--rok=YEAR]
php bin/console app:calculate-dividends-from-file <csv>... [--rok=YEAR]
php bin/console app:convert-interactivebrokers <in.csv> <out.csv>
php bin/console app:convert-dividends-interactivebrokers <in.csv> <out.csv>
```

All four accept any supported input format (auto-detected) - including both DEGIRO
exports, despite the historical `-interactivebrokers` command names. Without `--rok`, the
most recent year present in the data is settled.

## Architecture

Nine modules under `src/`, ordered from the inside out:

- **Money** — `Decimal` (immutable arbitrary-precision decimal over `brick/math`) and
  `Amount` (decimal + ISO 4217 currency). **Every** monetary value and quantity in the
  domain flows through these. Rounding is always explicit; division requires a caller
  supplied scale; negative scales are rejected. `Decimal::multipliedByRatio()` /
  `Amount::proratedBy()` exist because a share of a lot is a *ratio*: they keep
  `part/whole` exact (`BigRational`) and round once, so proration does not lose money in
  proportion to the amount the way a fixed intermediate scale does.
- **Fifo** — `FifoMatcher` pairs sells against buys, oldest first, producing `FifoMatch`
  (one closed position) and `UnmatchedSell` (reported, never thrown - importers turn it
  into a *fatal* message, see the invariants). Partially consumed lots are prorated exactly
  and the final slice of a lot receives the exact remaining balance, so prorated parts sum
  back to the lot total. Buy lots are never filtered by year.
- **Model** — `ClosedPosition` and `Dividend`, the normalized records every importer
  produces and every calculator consumes. Both expose `taxYear()` (year of the *sale* /
  payment) and `fingerprint()` (content hash used for duplicate detection).
- **CurrencyRate** — `ExchangeInterface` → `NbpExchange` implements the D-1 rule and
  passes PLN through at rate 1 without any lookup. `NbpRateProviderInterface` is
  implemented by `NbpApiRateProvider` (scoped `symfony/http-client`, walks back over
  weekends and holidays, bounded at 10 days) and decorated by `CachedNbpRateProvider`.
- **Import** — `FormatDetector` identifies a file from its structure (never from its name);
  `CsvImportService` routes it to the matching importer and de-duplicates by fingerprint.
  Seven importers implement `ImporterInterface` (auto-collected via `#[AutoconfigureTag]`).
  `NumberParser` and `DateParser` handle the notations brokers and spreadsheets produce.
  **Importers never leak exceptions on bad data** — they return `ImportMessage`s. The
  batch then fails closed: one broken row/file blocks the whole calculation rather than
  returning a plausible partial tax result.
  `Import/Degiro/` holds the DEGIRO-specific plumbing, and it is *positional*:
  `DegiroCsvReader` returns rows as field lists rather than header-keyed maps, because both
  official DEGIRO CSVs repeat an unnamed header to carry the currency of each amount, and a
  header-keyed reader would pair amounts with the wrong currency. `DegiroHeader` holds the
  per-language column aliases, `Isin` the shape check and the country inference. These are
  value objects/static helpers and are excluded from the container.
  Raw trades are batched **per trade-source importer**, not globally: `TradeIdScope` says
  whether the broker's ID names one execution (IBKR `TransactionID`) or one order that may
  be filled in several rows *and on several days* (DEGIRO `Order ID`, incl. GTC orders),
  which is what decides duplicate vs. conflict. For an order-scoped ID a conflict means the
  same ID on another instrument, another currency or the opposite side - never merely
  another day.
  `BatchImporterInterface::importMany()` is the same idea for records that span files:
  `DegiroAccountImporter` parses each statement on its own (own header, language, notation)
  but assembles payments across the whole batch, because a dividend and the tax withheld on
  it are two rows that routinely land in two exports. Aggregating per file reported the
  withholding as zero.
- **Tax** — `TaxRates` (19% Polish rate + treaty withholding caps), `StockTaxCalculator`,
  `DividendTaxCalculator`, `TaxYearFilter`, `CreditMethod`. Results are immutable DTOs in
  `Tax\Result`. The dividend calculator produces **two** credit scenarios, never one:
  `Conservative` (treaty-capped, KIS position) and `Nsa` (actual foreign tax up to the
  Polish 19%, per II FSK 1171/22 and II FSK 1302/22). Every output surface must show both.
- **Report** — `TaxReportBuilder` (filters to the year and pre-flights every exchange
  rate; one unavailable rate blocks the whole report rather than skipping a row), `CsvReportWriter`,
  `NormalizedCsvWriter`, and `CsvCell` (formula-injection guard).
- **Web** — `UploadedCsvReader` (validation + immediate temp-file deletion),
  `RowFormMapper` (domain ↔ form array), `TaxYearProvider`.
- **Controller / Command / EventListener / Exception** — thin HTTP and console entry
  points, security headers, domain exceptions.

### Key invariants

- **No binary floats for money or quantities.** Use `Decimal`/`Amount`.
- **Records enforce their own invariants.** `ClosedPosition` and `Dividend` reject
  non-positive amounts/quantities, negative withholding and mismatched currencies in their
  constructors, so no importer or form post can produce a wrong-signed taxable figure.
  Sign normalisation (IBKR reports withholding as negative) belongs in the importer.
- **Country is optional on import, required before a result.** Flat IBKR exports carry
  none; `RowFormMapper` demands `/^[A-Z]{2}$/` before anything is calculated. DEGIRO rows
  get a *proposal* from the ISIN prefix plus a warning that it is the registration country,
  not necessarily the source country — never present an inferred country as settled, and
  leave it blank for non-country prefixes (`XS…`, `EU`, `QZ`).
- **Round once, from the exact value.** Per-record 19% stays exact and is summed before
  rounding; the grosze and full-zloty figures are both derived from the same exact amount,
  never chained.
- **Import limits fail closed.** Over the record or per-file row cap the whole import is
  rejected - a partial tax dataset is more dangerous than none.
- **Domain is HTTP-agnostic.** `Money`, `Fifo`, `Model`, `Tax`, `Import`, `CurrencyRate`
  must not reference `Request`/`Response`.
- **Nothing is persisted.** No writes to `var/`, no financial data in the session.
- **FIFO keeps old buys.** Year filtering happens on the *sale* date, after matching.
- **FIFO identity is not a display name.** `Trade::$symbol` is the matching key (ticker +
  currency for IBKR, the **ISIN alone** for DEGIRO); `InstrumentDetails` carries the product
  name and country alongside so a rename cannot split a position. `FifoMatch::$sequence`
  exists because two identical lots closed by one sell agree on every other field - without
  the ordinal their lineage keys would collide and one position would be dropped as a
  duplicate, halving the gain. It is counted **per symbol**, so adding an unrelated
  instrument to an upload cannot move another instrument's fingerprint.
- **Two identical rows are two trades.** `Trade::$fillOrdinal` is the occurrence of a row
  within the file it was read from, and it - not the file name - is what de-duplication
  uses. A file name is not an identity: browsers rename downloads and two uploads can share
  a name. DEGIRO rows without an `Order ID` get a synthetic ID derived from the row's own
  content, so lineage exists for them too.
- **Time orders the queue, the day settles the record.** DEGIRO's `Time` column is parsed
  strictly (`HH:mm[:ss]`, blank allowed, malformed is a row error) so same-day trades order
  chronologically regardless of upload order; `ClosedPosition` dates are then set back to
  midnight, because the NBP rate and the tax year are per day. A buy and a sell in the same
  minute are ordered by file order and the ambiguity is reported.
- **A sale with no purchase is fatal, not a warning.** An unmatched sell has no cost basis,
  so its whole proceeds would read as gain. Both trade importers emit
  `ImportMessage::error`, which empties the batch. Settling the other positions and quietly
  dropping that one produces a return that looks complete.
- **One ISIN is one queue, and a currency change closes the door.** Keying the DEGIRO queue
  per currency would leave a cross-currency sale uncovered and drop its gain. So the queue
  is per ISIN, and a lot opened in a different currency than the sale closing it is a fatal
  error naming both currencies - `ClosedPosition` holds one currency and picking either
  would be a guess.
- **Trades from different brokers never share a pool.** One broker's IDs say nothing about
  another's, and the matching keys mean different things.
- **DEGIRO amounts come from `Total`, never `Quantity * Price`.** That column is the settled
  cash and already carries the transaction fee; adding the fee column again would
  double-count it. A sign that contradicts the quantity is an error, not something to fix.
- **Tax output is semantic.** Print "Przychód", "Koszty uzyskania przychodu" and the tax
  year — never hard-coded PIT field numbers like "C.22", which change between form versions.
- **Never present one withholding-credit figure as "the" answer.** The dispute is live;
  show both scenarios with their labels and sources.
- **Corporate actions are never settled silently.** Splits, mergers and spin-offs are out
  of scope. A row with a non-zero quantity but no price or no cash is *fatal*: skipping it
  would change the cost basis of every later sale of that instrument, so the batch stops
  and the user is told to settle that instrument by hand. Zero-*quantity* placeholders
  carry no cost basis at all and stay an info-level skip.
- **Exports are formula-safe.** All CSV cells go through `CsvCell::safe()`.
- **UI is Polish**, self-contained (own CSS/JS in `public/`, no build step, no CDN).

## Interface

Four surfaces, each with one job: decide (`home/index`), configure (`calculator/index`),
correct (`calculator/review`), read the answer (`calculator/result`). `_partials/steps`
renders the shared `1 Wgraj / 2 Sprawdź / 3 Wynik` indicator.

- **Reference material lives in `<details>`, the next step does not.** Treaty rates, broker
  instructions, sample files, the legal dispute, the PIT/ZG split and the FIFO audit tables
  are all collapsed by default; the amount, the four PIT-38 figures and the primary action
  are not. Two functional tests assert that no table sits outside a disclosure on the
  landing and result pages.
- **`report_body` is `report_summary` + `report_details`.** The result page renders them
  with a download form in between, which is why they are separate includes. Pass
  `expanded: true` (the printable report does) to render every disclosure open.
- **Paper never loses content.** Print completeness has two independent mechanisms: the
  `expanded` flag on the report route, and — for printing any other page — `print.css`
  (`::details-content`, `display: block` fallbacks) plus a `beforeprint` handler that opens
  every `<details>`. Keep all three when touching disclosures.
- **The review screen may hide a field, never drop it.** Quantity and source render in
  `.cell--tech` columns that plain CSS hides; the reveal is a real `<input type=checkbox>`
  with no `name`, sibling to `.review-body`, so it works without JavaScript and is never
  submitted. `UiSurfacesTest` pins the exact field set of every row.
- **No inline styles, ever.** `style-src 'self'` has no `unsafe-inline`, so a `style=`
  attribute is silently dropped — add a class instead. There is no `url()` anywhere in the
  CSS: the chevrons and the step connector are drawn with borders.
- **`--ink-3` is for rules and dots, not words.** It is a 3.3:1 grey on the light canvas;
  text uses `--ink` or `--ink-2`.

## Testing

- `tests/Unit` — pure domain, no container.
- `tests/Functional` — `WebTestCase` / `KernelTestCase`.
- `tests/Support` — shared fakes.

**Tests never touch the network.** `config/services.yaml` (`when@test`) swaps
`NbpRateProviderInterface` for `App\Tests\Support\FixedNbpRateProvider`, with fixed rates
USD 4.0, EUR 4.3, GBP 5.0, CHF 4.5, CAD 3.0. For HTTP-layer tests use `MockHttpClient`.

The test kernel runs with `APP_DEBUG=0` so error pages and logging behave like production;
`tests/bootstrap.php` therefore deletes `var/cache/test` on every run, because a non-debug
container does not self-invalidate.

Follow TDD: write a failing test, run it and confirm the expected failure, then implement.

## Dependencies

- `brick/math` — arbitrary-precision decimals (replaced `moneyphp/money`, whose
  integer-minor-unit model could not represent 4-decimal broker prices such as `507.5782`
  or sub-cent withholding such as `0.2025`)
- `league/csv` — CSV reading/writing
- `symfony/http-client` — NBP API (replaced the unmaintained `maciej-sz/nbp-php`)
- No frontend dependencies, no Node.js, no database driver.

## Data hygiene

Never commit real account numbers, holder names, balances or transaction history.
Sanitized fixtures live in `examples/` (symbols `AAA`/`BBB`, invented DEGIRO products
`ALFA CORP`/`BETA ETF`/`GAMMA SA` with placeholder ISINs `US000ALFA001`/`IE000BETA002`/
`PL000GAMMA01`, account `UXXXXXXXX`, holder `Jan Przykładowy`). `input/` and `output/` are gitignored and must not be
re-added to the repository.
