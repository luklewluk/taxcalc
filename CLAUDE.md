# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**TaxCalc.pl** (https://taxcalc.pl) — a privacy-first, open-source (MIT) Polish stock, option
and dividend tax calculator. Symfony **8.1** on PHP **8.4+** (developed on 8.5), a web application only - there are no
CLI commands of its own (`bin/console` serves Doctrine and the linters).

Reads exactly three exports - the Interactive Brokers **Activity Statement**, DEGIRO
**Transactions** and the DEGIRO **Account statement** - converts amounts using NBP D-1 rates, matches sells to buys with FIFO, and produces
PIT-38 / PIT-ZG figures.

**No persistence of user data at all.** Uploaded files are read once from
PHP's temporary upload file, which is immediately unlinked; the content lives only in
request memory. Between requests, rows travel back to the browser as form fields. The
session holds nothing but a CSRF token. The one database (MySQL, Doctrine ORM + Migrations)
holds **public NBP exchange rates and nothing else**.

Never make an uploaded file, a row, or anything derived from one an entity; do not add a
queue, session storage of financial data, or any third-party frontend resource — those
would break the product's core promise.

## Commands

```bash
# Tests
vendor/bin/phpunit                              # full suite
vendor/bin/phpunit --testsuite unit             # domain logic only
vendor/bin/phpunit --testsuite functional       # HTTP + rate store via test kernel
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

# Database (public NBP rates only) - compose's MySQL matches DATABASE_URL in .env
docker compose up -d db
php bin/console doctrine:migrations:migrate
php bin/console doctrine:migrations:diff        # after changing an entity; review the SQL

# Local server
# Local server - the limits matter: with PHP's default max_input_vars (1000) the
# workbench form arrives short past ~40 trades and the calculation stops.
php -d max_input_vars=120000 -d upload_max_filesize=6M -d post_max_size=64M \
    -S 127.0.0.1:8000 -t public
```

User-facing docs: `README.md` (outside readers), `docs/formaty.md` (how each export is read),
`docs/metodyka.md` (tax methodology), `CONTRIBUTING.md` (dev setup). Keep them in step with a
behaviour change.

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
  (one closed position) and `UnmatchedSell` (reported, never thrown - a review item, not a
  failed import, see the invariants). Partially consumed lots are prorated exactly
  and the final slice of a lot receives the exact remaining balance, so prorated parts sum
  back to the lot total. Buy lots are never filtered by year. A pool of **options** goes
  through `matchOptionPool()` instead (stock matching is untouched): either side may open,
  the trade's declared `PositionEffect` decides, and what cannot be matched is a
  `FifoViolation` (a close with nothing to close is a review item, the rest are fatal). A pool
  mixing stocks and options is a violation. `FifoResult::$openPositions` lists what every
  queue still holds (`OpenPosition`: the trade, the quantity left, the direction) -
  informational, for the Transakcje tab; nothing is taxed before a position closes.
- **Model** — `ClosedPosition`, `Dividend`, and `AccountFee`, the normalized settlement
  records. Editable records carry stable form IDs in addition to content fingerprints.
  `ClosedPosition` carries `InstrumentKind` and `PositionDirection`; build it from a match
  with `ClosedPosition::fromMatch()` so neither can be dropped.
- **CurrencyRate** — `ExchangeInterface` → `NbpExchange` implements the D-1 rule and
  passes PLN through at rate 1 without any lookup. `NbpRateProviderInterface` is
  implemented by `DatabaseNbpRateProvider`, which serves the D-1 rate from published table A
  quotations stored in MySQL (`Entity\NbpTableRate`, one row per currency and day) and fills
  a missing quarter with one `NbpApiRateProvider::tablesBetween()` request - every currency
  of every day of that quarter. `NbpApiRateProvider` (scoped `symfony/http-client`) is the
  source; its single-day lookup walks back over weekends and holidays, bounded at 10 days.
- **Import** — `FormatDetector` identifies a file from its structure (never from its name);
  `CsvImportService` routes it to the matching importer and de-duplicates by fingerprint.
  Three importers implement `ImporterInterface` (auto-collected via `#[AutoconfigureTag]`).
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
  whether the broker's ID names one execution (the Activity Statement's synthetic row ID) or one order that may
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
  `Import/Ibkr/` holds the **IBKR Activity Statement** plumbing (excluded from the container
  like `Degiro/`). `ActivityStatementReader` reads the sectioned file through a real CSV
  parser (`"2026-06-22, 09:55:39"` has a comma) and keys each `Data` row by the header *in
  force* - Trades re-declares its header for Forex (`Comm in USD`), and Financial Instrument
  Information (ISIN, `Listing Exch`) comes *after* Trades, so the whole file is read before
  mapping. It keeps only the sections the importer names: Account Information (name, account
  number) is never held. `IbkrActivityStatementImporter` is both a trade source and a batch
  importer - one statement feeds FIFO and the cross-file dividend assembly, and
  `CsvImportService` routes such a file down both paths. Settled cash is `Proceeds + Comm/Fee`
  in the trade currency (the sell fee is known, so the przychód split applies); the queue is
  `ISIN@CURRENCY` with the ticker as `symbol`; IDs are synthetic (content + occurrence
  ordinal, `TradeIdScope::Fill`); `IbkrExchange` turns IBKR's exchange codes into MICs
  (IBKR's `TSE` is Toronto, `TSEJ` Tokyo) so `ExchangeCountry` and `CountrySourceApplier`
  work unchanged - the applier finds the ISIN in `pool` when `symbol` is a ticker. AS amounts
  get at least two decimals: FIFO prorates at the amount's own scale, and IBKR prints a round
  trade as `-1000`. Dividends join their withholding by ISIN, currency and day across files;
  reversals and withholding without a payment that day are Review items, interest
  withholding is ignored.
- **Tax** — `TaxRates` (19% Polish rate + treaty withholding caps), `StockTaxCalculator`,
  `DividendTaxCalculator`, `TaxYearFilter`, `CreditMethod`. Results are immutable DTOs in
  `Tax\Result`. The dividend calculator produces **two** credit scenarios, never one:
  `Conservative` (treaty-capped, KIS position) and `Nsa` (actual foreign tax up to the
  Polish 19%, per II FSK 1171/22 and II FSK 1302/22). Output surfaces show only the one
  chosen in `WorkbenchSettings::$creditMethod`.
- **Report** — `TaxReportBuilder` (filters to the year and pre-flights every exchange
  rate; one unavailable rate blocks the whole report rather than skipping a row), `CsvReportWriter`
  and `CsvCell` (formula-injection guard).
- **Web** — `UploadedCsvReader` (validation + immediate temp-file deletion),
  `RowFormMapper` (domain ↔ form array; a valid trade row posted without an id gets its
  `Trade::id()` echoed back, so it keeps one identity from then on), `WorkbenchCalculator`
  (re-runs FIFO; `SettlementResult` also carries `matchPositions` - the `ClosedPosition` each
  match became, keyed by match index - plus open positions, unmatched sells and violations),
  `CountryReview` (groups every editable row by instrument, across the Transakcje and
  Dywidendy tabs, and reports what is wrong with its country), `Ledger\TradeLedgerBuilder`
  (the Transakcje tab, see the Interface section), `TaxFormMap` (year-specific
  PIT field numbers), `TaxYearProvider`.
- **Controller / EventListener / Exception** — thin HTTP entry points, security headers,
  domain exceptions.

### Key invariants

- **No binary floats for money or quantities.** Use `Decimal`/`Amount`.
- **Records enforce their own invariants.** `ClosedPosition` and `Dividend` reject
  non-positive amounts/quantities, negative withholding and mismatched currencies in their
  constructors, so no importer or form post can produce a wrong-signed taxable figure.
  Sign normalisation (IBKR reports withholding as negative) belongs in the importer.
- **Country is optional on import, required before a result.** No export states it;
  `RowFormMapper` demands `/^[A-Z]{2}$/` for a dividend, and `CountryReview` blocks a trade
  with a blank country before anything is calculated. Trades get a *proposal* from the
  listing exchange (Activity Statement `Listing Exch`, DEGIRO `Giełda referencyjna`) and
  DEGIRO dividends from the ISIN prefix, both with a warning — never present a proposed country as settled, and leave it blank when the source
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
- **Nothing of the user's is persisted.** No user data in the database or `var/`, no
  financial data in the session.
- **Stored NBP rates never expire, and a missing row means something only under coverage.**
  Published tables do not change, so nothing is ever refreshed. `Entity\NbpCoverage` records
  per quarter how far the stored tables are complete; inside it a day without a row is a day
  NBP published nothing, which is what lets the D-1 walk (`[D-10, D-1]`, the same window as
  the API's) run as one SQL query. Coverage is written in the same transaction as the rates
  it vouches for, and only up to **yesterday**: today's table may not be out yet, and
  recording its absence would hide it for good - a rate from today on goes to NBP directly
  and is not stored. Two requests filling one quarter collide on the unique key; the loser
  resets the entity manager and inserts only what is still missing.
- **The rate store costs speed, never the result.** A database error (down, never migrated)
  makes `DatabaseNbpRateProvider` read NBP directly for the rest of the request and log a
  warning. NBP itself being unreachable still blocks the report, as before.
- **A rate is stored as the exact text NBP published.** `mid` is a `VARCHAR`, not a
  `DECIMAL`, which would pad zeros and change the scale every report prints. Table A quotes
  JPY, HUF, KRW, CLP and ISK with six decimals and IDR with eight, so the JSON float is
  formatted with `%.8F` and trimmed - `%.4F` turned the yen's `0.026287` into `0.0263`.
- **FIFO keeps old buys.** Year filtering happens on the *sale* date, after matching.
- **FIFO identity is not a display name.** The queue key (`Trade::$fifoPool`, else `$symbol`) is
  `ISIN@CURRENCY` for the Activity Statement and the **ISIN alone** for DEGIRO; `InstrumentDetails` carries the product
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
  includes Review, so a test asserting on warnings sees it too.
- **Time orders the queue, the day settles the record.** DEGIRO's `Time` column is parsed
  strictly (`HH:mm[:ss]`, blank allowed, malformed is a row error) so same-day trades order
  chronologically regardless of upload order; `ClosedPosition` dates are then set back to
  midnight, because the NBP rate and the tax year are per day. A buy and a sell in the same
  minute are ordered by file order and the ambiguity is reported.
- **A sale with no purchase is never silent, but it does not stop the import.** An unmatched
  sell has no cost basis, so it is left out of the result. Importers report it as
  `ImportMessage::review` with code `fifo.unmatched_sell` (`UnmatchedSell::describe()`, which
  points at the missing earlier statement); the workbench raises the same review item on every
  recalculation, linked to the row, and the controller drops the import's copy so it is listed
  once. It does not block the result: the user decided they want every other figure while
  they add the missing year. An option close with nothing to close (`UnmatchedClose`) is
  treated the same; the other FIFO violations contradict the data and still block.
- **Options settle when the position closes, never when it opens.** Art. 17 ust. 1 pkt 10 and
  ust. 1b, art. 23 ust. 1 pkt 38a; KIS 0113-KDIPT2-3.4011.645.2025.3.KKA of 2025-11-07 - no
  "premium on receipt" setting. Przychód is always the sell leg and koszt the buy leg; for a
  written option (`Short`) the buy closes, so `closeDate()`, `taxYear()` and `revenueDate()`
  move to the buy leg. Each amount uses the NBP rate before *its own* day: koszt the buy date,
  the disposal fee the sell date, przychód `revenueDate()` (art. 11a). `TaxReportBuilder`
  pre-flights `conversionDates()`.
- **Only an option's closing leg may be zero, and then without a fee.** Expiry (`Ep`) and
  assignment/exercise with physical delivery close at nothing. A stock leg is never zero and a
  stock is never short - an excess stock sell stays an `UnmatchedSell`.
- **An option trade declares whether it opens or closes.** IBKR's `O`/`C` codes become
  `Trade::$effect` (`C;O` = close then open with the rest); the form requires it for `OPT`.
  Inferring it from row order would let a buy-to-close whose writing sale was not uploaded
  open a long lot and lose the premium. A close with nothing to close is reported (review)
  and left out, never turned into an open.
- **The premium never enters the stock's cost basis.** Shares from an assignment enter FIFO
  at the strike; the option settles separately on the assignment day. An `A`/`Ex` option row
  at zero without a Stocks row of its underlying that day was cash-settled with the amount
  missing (index options) - fatal.
- **Option identity stays out of stock hashes.** `Trade::id()`, `ClosedPosition::fingerprint()`
  and the Activity Statement synthetic IDs append kind/effect/direction *only* for options, so
  every stock ID a workbench posted before options existed keeps matching.
- **One ISIN is one queue, and a currency change closes the door.** Keying the DEGIRO queue
  per currency would leave a cross-currency sale uncovered and drop its gain. So the queue
  is per ISIN, and a lot opened in a different currency than the sale closing it is a fatal
  error naming both currencies - `ClosedPosition` holds one currency and picking either
  would be a guess.
- **Trades from different brokers never share a pool.** One broker's IDs say nothing about
  another's, and the matching keys mean different things.
- **The declared przychód is the gross amount due, not the settled cash.** The settled cash
  (DEGIRO `Total`, Activity Statement `Proceeds + Comm/Fee`) is the cash that moved and stays the record (and the position's identity), but the three cases
  are not symmetric and every surface must show the same split. The *buy* fee is already inside
  the acquisition cost — nothing to do. The *sell* commission and AutoFX have been taken out of
  the proceeds by the broker, so `StockTaxCalculator` adds them back into przychód (art. 17
  ust. 1 pkt 6 lit. a) and counts the same amount as a koszt odpłatnego zbycia (art. 22 /
  art. 23 ust. 1 pkt 38), converted at the **sell** date's rate — which is why
  `CalculatedPosition::$disposalCost` is a second `ExchangedAmount` and not part of `$cost`.
  For stocks and bought options income and tax are unchanged; only the split between fields 22
  and 23 moves. For a *written* option it is not quite: the writing fee keeps the writing day's
  rate while the przychód takes the closing day's (see the options invariant). A position whose
  source reported no sell fee (a blank DEGIRO fee cell, an IBKR commission rebate, an
  aggregated order whose fills disagree) keeps a przychód equal to the settled cash, and the FIFO footnote says so
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
- **The chosen credit reading is the only one shown, and it is always named.** The dispute
  is live, so the user picks the reading in Ustawienia (default: conservative/KIS). The domain
  still computes both - switching must not need a re-import - but no surface prints the other
  one, an "alternative" figure or a difference. Every figure that depends on the reading
  carries its label (`CreditMethod::shortLabel()`); what the readings mean and their sources
  live in Ustawienia, where the choice is made.
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

- **"Symulacja" is the real import on fictional files.** `GET /kalkulator/symulacja` reads the
  three statements in `demo/` through `CsvImportService`, settles 2025 and renders the workbench
  with a notice; a hidden `demo=1` field carries the notice through recalculations, like
  `tax_year`. The data covers both brokers, USD/EUR/GBP/CAD/CHF/PLN (the currencies of the test
  rate fake), stocks and ETFs on several exchanges, a call and a put each bought and written,
  and dividends where the KIS and NSA credits differ. `DemoFlowTest` pins that it opens a
  complete result with nothing in "Wymaga uwagi" - change `demo/` only with it green.
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
- **Transakcje is a read-only ledger.** `TradeLedgerBuilder` splits it into *Akcje i ETF-y* and
  *Opcje* (only when there are options), one group per FIFO queue (`broker|pool ?: symbol`,
  exactly the matcher's key), rows stably sorted by date and time - the matcher's own sort.
  The rows post back in that order, and it cannot move a figure: no queue is split, ties keep
  their relative order, and match ordinals are counted per queue (a test proves the
  fingerprints identical). A pool mixing stocks and options stays whole and is flagged.
  - Every row is a `<tbody data-trade id="row-<id>">`: a summary row, then a row with two
    `<details>` - *Szczegóły* and *Edytuj*. Every `trades[N][...]` field lives in the closed
    *Edytuj*; a closed `<details>` still posts, so the round trip is unchanged. **No new field
    per row** (a test pins the names): 5,000 rows × 21 fields already sit close to
    `max_input_vars`. Without JS the `<summary>` elements toggle; with JS buttons do.
  - **A change counts only after *Zapisz*.** *Zapisz* is a real submit
    (`formaction=…/wynik#row-<id>`, `formnovalidate`); *Anuluj* restores the rendered values.
    The `formdata` handler makes every other request - the debounced recalculation, a year
    switch, an export, an upload, a panel action - carry the values the server rendered for
    every row except the one being saved, and drops unsaved new rows (the form states how many
    rows it rendered, so that is never read as truncation). One editor with changes at a time;
    `[data-manual-commit]` keeps keystrokes in the ledger from arming the debounce. *Usuń* is
    the old `trades[N][remove]` checkbox inside the editor; the server turns it into a
    tombstone on *Zapisz*.
  - **Details cover every year.** Each trade shows the lots it closed or the trades that closed
    it, with NBP rates, the same przychód/koszt split as everywhere
    (`StockTaxCalculator::calculate([$position])`), the income, the tax year and an
    informational exact 19% - labelled as such, because the return taxes a whole year rounded
    once. They are built only on full renders, never in the AJAX JSON, and do not depend on
    the tax year. Positions the report already converted are reused; the rest go latest close
    first under a time budget (`app.ledger.rate_budget_seconds`, 10 s) with a per-currency
    breaker (`MAX_RATE_FAILURES_PER_CURRENCY`); what is left says so, with *Dociągnij brakujące
    kursy*. When another tab blocks the result but every trade row is valid, the controller
    settles FIFO anyway for the ledger (`tradesComplete`) without merging its findings.
  - A row the server rejected (`trade.*`, or a blank id) renders with its editor open. Attention
    links carry `data-row-intent`: what FIFO could not match (`Diagnostic::opensDetails()`)
    opens the details, everything else the editor.
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
- **The credit variant decides every figure.** `WorkbenchSettings::$creditMethod` picks the
  reading (`DividendTaxResult::scenarioFor()`, `TaxReport::totalTaxRoundedFor()`,
  `CalculatedDividend::creditFor()`, `CountryDividendIncome::creditFor()`), and summary,
  Dywidendy, print and CSV (`CsvReportWriter::write(..., $chosen)`) show that one only.
  `CreditMethodOutputTest` pins it. `dividend.treaty_rate_missing` is
  shown only under the conservative reading, where a missing treaty rate really means a zero
  credit; under NSA the cap plays no part and the item would be noise.
- **The message strip is one sentence, the tab is the list.** `workbench_messages` renders
  only "N punkty wymagają uwagi" plus the link to the panel. Import warnings and info notices
  are deliberately **not** rendered in the web UI at all — a strip that grows with every
  skipped row buries the only sentence that matters. Anything a user must act on has to be a
  `Diagnostic`, not an `ImportMessage` warning; `upload.nothing_added` is the pattern to
  follow, because an upload that changed nothing would otherwise return an identical page
  with no explanation.
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
  ones — the raw post still carries a row the rendered form has already dropped. It lives in
  the attention panel only: the old in-row *Ustaw w pozostałych pustych transakcjach*
  (`bulk_country`) filled rows client-side and is gone; a ledger group with blank countries
  links to its panel item instead.
- **`formnovalidate` is required on every workbench submit.** The form holds `required`
  country selects with blank values, and interactive validation runs *before* the `submit` event — without it every bar button is
  dead in exactly the state these actions exist for.
- **A staged choice survives a fragment rewrite.** The country select in the panel carries
  `data-no-recalc`: it skips the 450 ms debounce *and* disarms a run armed by an earlier edit,
  and `recalculate()` snapshots and restores those selects (plus focus) around the
  `innerHTML` writes. Anything user state lives in a replaced fragment needs the same.
- **Tabs are progressive enhancement.** Without JavaScript all tab panels are sequentially
  visible. With JavaScript they implement `tablist/tab/tabpanel`, arrows, Home/End and hash.
  A hash that is not a tab name (`#row-…`, `#attention-…`, `#panel-…`) opens the panel that
  contains its element - a trade row also opens its details - instead of leaving every panel
  visible.
- **Paper never loses content.** The dedicated report is read-only and opens disclosures;
  `print.css` plus the `beforeprint` handler also reveal every panel and disclosure - except a
  trade's *Edytuj*, which only repeats its summary row as form controls.
- **Workbench and report panels are wide.** `.container--workbench` removes the old 48rem
  constraint, while `.table-wrapper` retains horizontal scrolling for audit tables.
- **Static files are versioned by content.** `ContentHashVersionStrategy` (`framework.assets`)
  appends `?v=<12 hex of xxh128>` to every `asset()` URL. The web server caches CSS, JS and
  images for a year as `immutable`; without the version a returning visitor would get new
  markup with yesterday's stylesheet and script. An unchanged file keeps its URL across deploys.
  Every static file a page loads goes through `asset()` - never a hard-coded path.
- **No inline styles, ever.** `style-src 'self'` has no `unsafe-inline`, so a `style=`
  attribute is silently dropped — add a class instead. There is no `url()` anywhere in the
  CSS: the chevrons and the step connector are drawn with borders.
- **Theme: the system by default, a pick in `localStorage`.** Every colour token that differs
  between modes is one `light-dark(light, dark)` pair in `:root`; never add a second,
  media-gated copy of the palette. `:root` carries `color-scheme: light dark`,
  `:root[data-theme="light|dark"]` pins one, and `print.css` pins paper to light. The header's
  theme control is **one round button** (`data-role="theme-toggle"`), shipped `hidden`;
  `public/js/theme.js` reveals it. It starts on *auto* (nothing stored, half-circle icon); a
  click pins the opposite of what is on screen, and from then on it flips light ↔ dark (sun /
  moon, via `data-theme-state`). It stores only `pit38-theme` (`light`/`dark`; auto is the
  absence of the entry). It loads **without `defer` in
  `<head>`, before the stylesheet**, on every layout including the report and the error page —
  a deferred script flashes the system theme on each navigation. That one word is the only
  thing kept in the browser; workbench state never is.
- **The brand is local and the name is text.** `app_name` is `TaxCalc.pl`; the header shows
  the calculator icon (`public/img/brand/taxcalc-icon.png`, `alt=""`) next to the name as
  HTML text, because the wordmark's dark lettering would vanish in the dark theme. The
  wordmark lives in `.github/` (light + dark variant) for the README. Favicons and the Open
  Graph image are files in `public/`; `APP_PUBLIC_URL` (empty on self-hosted copies) gates
  the canonical link and OG tags. The palette is the logo's teal (`--brand*`); `--brand`
  carries white text, so it is darkened to `#087F7C` (4.8:1) - never use the logo's raw
  `#029F9D` under text.
- **Broker logos are someone else's marks.** `templates/_partials/logos/` holds the IBKR and
  DEGIRO logos as inline SVG, shapes and brand colours unchanged (`<style>` replaced by `fill`
  attributes, so nothing depends on CSP; DEGIRO's white counters are real evenodd holes).
  Only the lettering is `currentColor`, set from `--logo-ink-*`: as shipped on the light
  canvas, white on the dark one - the inverse IBKR publishes itself. No plates, no borders.
  README's *Znaki towarowe* names the sources. The header
  links *Zgłoś problem* to `APP_REPOSITORY_URL/issues`; the code stays linked in the footer.
- **`--ink-3` is for rules and dots, not words.** It is a 3.3:1 grey on the light canvas;
  text uses `--ink` or `--ink-2`.

## Deployment

- `.github/workflows/ci.yml` tests every push and pull request (PHP 8.4 and 8.5) and, on
  `main` only, after the tests and only when the repository variable `DEPLOY_ENABLED` is
  `true`, deploys with Deployer (`deploy.php`, `recipe/symfony.php`) through the GitHub
  Environment `deployment`.
- **Migrations run and are validated on every deploy**, before `current` switches:
  `database:migrate`, then `database:validate` (`doctrine:schema:validate` against the real,
  freshly migrated database), both before `deploy:publish`. A mismatch fails the deploy and
  the release in service keeps running - without it, an entity changed without a migration
  would only make the rate store fall back to NBP, silently. The Docker image migrates in
  `docker/entrypoint.sh`. They are
  generated for MySQL 8.4 but their platform check accepts `AbstractMySQLPlatform`, so
  MariaDB works with its own `serverVersion` in `DATABASE_URL`. `transactional: false`,
  because MySQL commits implicitly on DDL. Keep migrations additive: the old release keeps
  serving while they run.
- **No server detail in the repository.** Host, user, path, SSH key and known hosts are
  `deployment` secrets read from the environment by `deploy.php`; the examples in `deploy/`
  use placeholders. Never add `pull_request_target`, and keep third-party actions pinned to
  a commit SHA - the deploy job holds the SSH key.
- The PHP-FPM pool in `deploy/php-fpm/` mirrors `docker/php.ini` - change both together,
  above all `max_input_vars`, which PHP enforces silently. `public/.user.ini` carries the same
  limits (`max_input_vars`, `post_max_size`, `upload_max_filesize`) with every deploy, so a
  server whose pool was never configured still gets them - PHP-FPM re-reads it within
  `user_ini.cache_ttl`, no reload needed; a `php_admin_value` in the pool wins. Nginx's dotfile
  rule keeps it from being served. `PhpLimitsTest` keeps the three in step.

## Testing

- `tests/Unit` — pure domain, no container.
- `tests/Functional` — `WebTestCase` / `KernelTestCase`.
- `tests/Support` — shared fakes.

**Tests never touch the network.** `config/services.yaml` (`when@test`) swaps
`NbpRateProviderInterface` for `App\Tests\Support\FixedNbpRateProvider`, with fixed rates
USD 4.0, EUR 4.3, GBP 5.0, CHF 4.5, CAD 3.0. For HTTP-layer tests use `MockHttpClient`.

The test database is in-memory SQLite (`.env.test`), fresh on every kernel boot; a test that
needs it builds the schema from the mapping with `SchemaTool` and constructs the
repositories from the public `doctrine` registry (unused private services are removed from
the test container). The migrations themselves meet MySQL only on deploy, where
`database:validate` checks them against the mapping.

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
- `doctrine/orm`, `doctrine/doctrine-bundle`, `doctrine/doctrine-migrations-bundle` — the
  NBP rate store; `symfony/clock` — "yesterday" for the coverage rule, `MockClock` in tests.
  No Symfony Flex: bundles and `config/packages/doctrine*.yaml` are maintained by hand.
- No frontend dependencies, no Node.js.

## Data hygiene

Never commit real account numbers, holder names, balances or transaction history.
Sanitized fixtures live in `examples/` (symbols `AAA`/`BBB`, invented DEGIRO products
`ALFA CORP`/`BETA ETF`/`GAMMA SA` with placeholder ISINs `US000ALFA001`/`IE000BETA002`/
`PL000GAMMA01`, account `UXXXXXXXX`, holder `Jan Przykładowy`). They are test fixtures and a
reference for contributors only - the site does not serve them, and the Docker image leaves
them out. The statements in `demo/` (behind "Symulacja") are fictional in the same way and are
deployed with the app. `input/` and `output/` are gitignored and must not be
re-added to the repository.
