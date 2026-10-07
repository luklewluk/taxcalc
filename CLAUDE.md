# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Kalkulator PIT-38** — a privacy-first, open-source (MIT) Polish stock and dividend tax
calculator. Symfony **8.1** on PHP **8.4+** (developed on 8.5), available both as a web
application and as CLI commands.

Reads Interactive Brokers and DEGIRO exports (legacy normalized CSVs remain deprecated),
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
- **Model** — `ClosedPosition`, `Dividend`, and `AccountFee`, the normalized settlement
  records. Editable records carry stable form IDs in addition to content fingerprints.
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
  per-language column aliases, `Isin` the shape check and the ISIN country inference, and
  `ExchangeCountry` the venue-code → country table. These are value objects/static helpers
  and are excluded from the container.
  **The country of a DEGIRO trade is proposed from the exchange, not from the ISIN.**
  `Giełda referencyjna` (the *listing* venue, DEGIRO's own `NDQ`/`EAM` vocabulary; bare
  `Beurs` in Dutch) is read first, `Miejsce wykonania` (the execution-venue MIC) is the
  fallback. Pan-European MTFs and off-book venues (`CEUX`, `TQEX`, `XOFF`, …) are rows in
  the table with a *blank* country, so an ambiguous venue can never contribute the
  platform's own jurisdiction; an unknown code proposes nothing and is reported verbatim.
  The proposal is resolved **once per ISIN over the whole batch**, never per row:
  `FifoMatch::instrument()` prefers the sell leg, so a per-row country would let a buy on
  one exchange and a sell on another settle under whichever leg happened to be later. When
  the legs disagree the proposal is withdrawn and both codes are named. Code-level messages
  (unknown code, no exchange column) come from `readSource()`, which sees every row -
  `matchTrades()` walks only closed positions, so an open lot would get no explanation.
  Dividends keep the ISIN-prefix proposal: the DEGIRO account statement has no venue column.
  Raw trades are batched **per trade-source importer**, not globally: `TradeIdScope` says
  whether the broker's ID names one execution (IBKR `TransactionID`) or one order that may
  be filled in several rows *and on several days* (DEGIRO `Order ID`, incl. GTC orders),
  which is what decides duplicate vs. conflict. For an order-scoped ID a conflict means the
  same ID on another instrument, another currency or the opposite side - never merely
  another day. After deduplication, DEGIRO fills with the same reported ID, ISIN, side,
  currency and exact timestamp are aggregated by summing quantity and Total; synthetic IDs
  are marked explicitly and never trigger this aggregation.
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
  `RowFormMapper` (domain ↔ form array), `WorkbenchCalculator` (re-runs FIFO),
  `CountryReview` (groups every editable row by instrument, across the Transakcje and
  Dywidendy tabs, and reports what is wrong with its country), `TaxFormMap` (year-specific
  PIT field numbers), `TaxYearProvider`.
- **Controller / Command / EventListener / Exception** — thin HTTP and console entry
  points, security headers, domain exceptions.

### Key invariants

- **No binary floats for money or quantities.** Use `Decimal`/`Amount`.
- **Records enforce their own invariants.** `ClosedPosition` and `Dividend` reject
  non-positive amounts/quantities, negative withholding and mismatched currencies in their
  constructors, so no importer or form post can produce a wrong-signed taxable figure.
  Sign normalisation (IBKR reports withholding as negative) belongs in the importer.
- **Country is optional on import, required before a result.** Flat IBKR exports carry
  none; `RowFormMapper` demands `/^[A-Z]{2}$/` before anything is calculated. DEGIRO trades
  get a *proposal* from the listing exchange and dividends from the ISIN prefix, both with a
  warning — never present a proposed country as settled, and leave it blank when the source
  names nothing usable (non-country ISIN prefixes `XS…`, `EU`, `QZ`; unknown or multi-venue
  exchange codes).
- **A dividend's country and a trade's country answer different questions, and may differ.**
  A dividend declares the residence of the payer, because that is what the treaty follows when
  it caps the credit; a disposal declares where the income arose. A Canadian issuer listed in
  the US correctly carries `CA` on the dividend and `US` on the trades.
  This must **never** be reported as a conflict — `CountryReview` therefore groups strictly per
  tab (`CountryScope`), and a bulk action writes only into its own tab. Within one tab a
  disagreement is still a real data problem and stays a review item.
- **The country group keys on `broker|symbol`, per tab.** The FIFO pool is deliberately not part
  of it: IBKR keys pools per currency and the country is a property of the paper. The broker
  *is*, because one broker's ticker says nothing about another's. Dividends group among
  themselves by normalized display name. The group id hashes the scope plus that key, so it does
  not move when a row is filled — an id derived from the first blank row would.
- **The listing exchange disagreeing with the ISIN is a setting, not a finding.** Both are
  accepted readings of where income from a disposal arose, so the choice lives in
  `CountrySource` and the workbench never reports the disagreement. Switching it re-derives the
  *proposal* for trade rows through `CountrySourceApplier`, which reads the round-tripped
  `trades[N][exchange]` and the ISIN-shaped symbol; a value matching neither proposal was typed
  by hand and is left alone. It applies to trades only — a dividend always follows its ISIN.
- **Dividends are art. 30a, not PIT/ZG.** They are taxed at a flat 19% and reported in part G
  of PIT-38; PIT/ZG covers art. 27/30b/30c/30e. A dividend's country is required only to pick
  the treaty withholding cap for the conservative credit scenario, and its messages must say
  so — `countryRequired()` takes `$forPitZg` for exactly this reason. Closed positions keep
  the PIT/ZG wording.
- **The country select offers more than the treaty table.** Options are
  `TaxRates::supportedCountries()` plus every country `ExchangeCountry` can propose plus every
  valid code already present in a row, the extras labelled *poza tabelą umów*. A valid code
  with no matching `<option>` renders as "nothing selected" and is silently lost on the next
  post — the option list is what decides whether a value survives a round trip.
- **A mis-click never destroys a result.** Pressing a bulk country button without choosing a
  country is a *review* diagnostic, not blocking: `$state['errors']` gates both `settle()` and
  `build()`, so blocking would take a computed PIT result off the screen.
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
- **Two identical rows without a reported ID are two trades.** `Trade::$fillOrdinal` is the occurrence of a row
  within the file it was read from, and it - not the file name - is what de-duplication
  uses. A file name is not an identity: browsers rename downloads and two uploads can share
  a name. DEGIRO rows without an `Order ID` get a synthetic ID derived from the row's own
  content, so lineage exists for them too. Rows with one reported Order ID at one exact
  timestamp are instead one logical transaction and are aggregated after deduplication.
- **The day the cash lands settles the record, not the issuer's payable date.** For the DEGIRO
  account statement the tax year and the D-1 rate come from the **booking date** (the first
  `Data`/`Date` column): income exists when it is received or placed at the taxpayer's disposal
  (art. 11 ust. 1) and the rate is the last business day before that (art. 11a). DEGIRO books a
  dividend only once the custodian confirms the cash, so the value date is the issuer's payable
  date and proves nothing about availability — it survives only as a fallback for an export
  without a booking column and as audit data on `DegiroCashRow::$valueDate`. IBKR already worked
  this way (`date/time`, `reportdate`). Where a group spans several bookings the *earliest* is
  the day the cash first landed.
- **Payment groups are keyed on instrument, currency, value date and the booking *year*.** Both
  halves are load-bearing. The value date says which payment a row describes, so a reversal and
  its same-year re-post net against the original — keying on the booking date alone settled both
  and counted the dividend twice. The booking year keeps a reversal posted in a later year from
  reaching back and emptying the year the original was settled in — keying on the value date
  alone netted the two to zero and made the original vanish from that year's return.
- **A negative payment group is a reversal, not a negative dividend.** `Dividend` refuses a
  non-positive gross, so building one would fail the whole batch on an ordinary statement — and
  subtracting it from the current year would be wrong anyway, because it corrects the year the
  original was settled in. Such a group is skipped and reported with the instrument and that
  year. The same goes for a withholding refund exceeding the group's tax.
- **`MessageLevel::Review` is how an importer reaches the attention panel.** Import warnings and
  notices are rendered nowhere in the web UI; `importDiagnostics()` promotes exactly the
  Review-level messages to `Diagnostic::review('import.review', …)`. Do not gate that on
  `targetTab` — `CsvImportService` stamps one onto every message of a file. `ImportResult::warnings()`
  includes Review so the CLI, which has no panel, still prints it.
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
- **The declared przychód is the gross amount due, not the settled cash.** `Total`/`NetCash` is
  the cash that moved and stays the record (and the position's identity), but the three cases
  are not symmetric and every surface must show the same split. The *buy* fee is already inside
  the acquisition cost — nothing to do. The *sell* commission and AutoFX have been taken out of
  the proceeds by the broker, so `StockTaxCalculator` adds them back into przychód (art. 17
  ust. 1 pkt 6 lit. a) and counts the same amount as a koszt odpłatnego zbycia (art. 22 /
  art. 23 ust. 1 pkt 38), converted at the **sell** date's rate — which is why
  `CalculatedPosition::$disposalCost` is a second `ExchangedAmount` and not part of `$cost`.
  Income and tax are unchanged; only the split between fields 22 and 23 moves. A position whose
  source reported no sell fee (flat IBKR, own format, blank fee cell, an aggregated order whose
  fills disagree) keeps a przychód equal to the settled cash, and the FIFO footnote says so
  rather than pretending the split was made. Never derive the fee from `Total − quantity × price`.
- **A prorated fee slice is never negative.** `OpenLot::takeOptional()` rounds each non-final
  slice half-up from the whole and lets the last one take the remainder, so the slices can
  overshoot: 0.02 over four one-share sells leaves -0.01. Non-final slices are therefore capped
  at the remaining balance. Now that the fee is a tax figure, a negative slice would declare a
  przychód *below* the settled cash.
- **DEGIRO amounts come from `Total`, never `Quantity * Price`.** That column is the settled
  cash and already carries the transaction fee; adding the fee column again would
  double-count it. A sign that contradicts the quantity is an error, not something to fix.
- **PIT field numbers are versioned, never hard-coded in templates.** `TaxFormMap` owns the
  2021–2026 PIT-38/PIT-ZG mapping; 2026 is explicitly provisional.
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

The public flow is `upload → work with the result`. After the first import,
`calculator/workbench` owns the entire editable state in four accessible tabs:
`PIT-38 / PIT-ZG`, `FIFO`, `Dywidendy`, and `Opłaty`. There is no review screen or stepper.

- **The workbench is stateless.** Every logical trade, dividend, standalone fee, stable ID,
  and tombstone travels in the current form. Never move it into session or browser storage.
- **The FIFO tab names its money columns.** `Przychód PLN` and `Koszt PLN` are the figures that
  reach PIT-38 — the per-leg conversions used to be headed just `PLN`, which said nothing about
  which was which and stopped being true once the sell fee joined the costs. The two `Total`
  cells read `position.buyAmount`/`position.sellAmount`, deliberately **not**
  `cost.original`/`revenue.original`: after the reallocation `revenue.original` is the gross
  amount, and printing it under a header named after the broker's own column would read as a
  double count. The footnote carries both formulas.
- **FIFO input is editable, matches are derived.** `POST /kalkulator/wynik` remaps the form,
  runs FIFO again, and fails closed before building PIT fields. AJAX returns versioned
  summary/FIFO/message/counter fragments; the full POST remains the no-JS fallback.
- **Incremental imports are atomic and independent.** A batch touching an existing
  broker/instrument FIFO pool, or requiring an earlier dividend/tax row, is rejected as a
  whole. Stable IDs and tombstones protect manual changes from re-upload revival.
- **What is a choice belongs in Ustawienia, not in "Wymaga uwagi".** The attention panel is only
  for things the user must *set* before the tax figure is right. Anything the law leaves open —
  which country a disposal declares, which reading of the foreign-tax credit fills the fields —
  is a `WorkbenchSettings` field with a default and an explanation next to it. Settings ride the
  form exactly like `tax_year` (posted field + a total `normalize()`), never the session, and the
  panel deliberately sits outside every `data-fragment` so the debounce cannot wipe a control
  mid-change.
- **The credit variant picks the headline, never the only figure.** `WorkbenchSettings::$creditMethod`
  decides which reading fills the PIT fields (`DividendTaxResult::scenarioFor()`,
  `TaxReport::totalTaxRoundedFor()`); the other stays visible on every surface — summary,
  Dywidendy, print, CSV, CLI — because the dispute is live. `dividend.treaty_rate_missing` is
  shown only under the conservative reading, where a missing treaty rate really means a zero
  credit; under NSA the cap plays no part and the item would be noise.
- **The message strip is one sentence, the tab is the list.** `workbench_messages` renders
  only "N punkty wymagają uwagi" plus the link to the panel. Import warnings and info notices
  are deliberately **not** rendered in the web UI at all — a strip that grows with every
  skipped row buries the only sentence that matters. Anything a user must act on has to be a
  `Diagnostic`, not an `ImportMessage` warning; `upload.nothing_added` is the pattern to
  follow, because an upload that changed nothing would otherwise return an identical page
  with no explanation. The CLI still prints warnings and infos — it has no panel to point at.
- **Seven tabs.** `PIT-38 / PIT-ZG`, `Wymaga uwagi`, `Transakcje`, `FIFO`, `Dywidendy`, `Opłaty`,
  `Ustawienia`. The Dywidendy tab carries its own summary of everything that feeds the PIT-38
  part G fields, inside the existing `dividendResults` fragment — the AJAX fragment list is a
  pinned contract, so a new block joins an existing fragment rather than adding one.
- **One "Wymaga uwagi" item per instrument and tab, with the fix attached.** A missing country is
  reported once per instrument, counting the blanks in both tabs, and the item carries a
  country select plus *Zastosuj do wszystkich (N)*. A group whose rows disagree gets a review
  item with *Ujednolić kraj we wszystkich (N)*, which is the only variant that overwrites an
  existing value — filling blanks never does.
- **The bulk country action is a real submit, on purpose.** It carries `formaction` and
  `formnovalidate`, and JavaScript only refuses an empty choice. It must not fill the rows
  client-side: the editor tables are not AJAX fragments, so a client-side fill plus a
  background recalculation would leave the server holding countries the visible form no
  longer carries, and would need a second implementation of the instrument identity in JS.
  The server writes through `CountryReview::groups()` on the posted rows, skipping removed
  ones — the raw post still carries a row the rendered form has already dropped.
- **`formnovalidate` is required on every workbench submit.** The form holds `required`
  country selects with blank values, including inside the hidden compatibility block, and
  interactive validation runs *before* the `submit` event — without it every bar button is
  dead in exactly the state these actions exist for.
- **A staged choice survives a fragment rewrite.** The country select in the panel carries
  `data-no-recalc`: it skips the 450 ms debounce *and* disarms a run armed by an earlier edit,
  and `recalculate()` snapshots and restores those selects (plus focus) around the
  `innerHTML` writes. Anything user state lives in a replaced fragment needs the same.
- **Tabs are progressive enhancement.** Without JavaScript all tab panels are sequentially
  visible. With JavaScript they implement `tablist/tab/tabpanel`, arrows, Home/End and hash.
- **Paper never loses content.** The dedicated report is read-only and opens disclosures;
  `print.css` plus the `beforeprint` handler also reveal every panel and disclosure.
- **Workbench and report panels are wide.** `.container--workbench` removes the old 48rem
  constraint, while `.table-wrapper` retains horizontal scrolling for audit tables.
- **No inline styles, ever.** `style-src 'self'` has no `unsafe-inline`, so a `style=`
  attribute is silently dropped — add a class instead. There is no `url()` anywhere in the
  CSS: the chevrons and the step connector are drawn with borders.
- **Theme: the system by default, a pick in `localStorage`.** Every colour token that differs
  between modes is one `light-dark(light, dark)` pair in `:root`; never add a second,
  media-gated copy of the palette. `:root` carries `color-scheme: light dark`,
  `:root[data-theme="light|dark"]` pins one, and `print.css` pins paper to light. The header's
  *Auto / Jasny / Ciemny* switch ships `hidden`; `public/js/theme.js` reveals it and stores
  only `pit38-theme` (`light`/`dark`, removed for Auto). It loads **without `defer` in
  `<head>`, before the stylesheet**, on every layout including the report and the error page —
  a deferred script flashes the system theme on each navigation. That one word is the only
  thing kept in the browser; workbench state never is.
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
