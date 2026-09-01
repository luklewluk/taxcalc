# Kalkulator PIT-38 — akcje i dywidendy

Otwartoźródłowy, prywatny kalkulator podatku od zysków giełdowych i dywidend dla polskich
podatników. Wczytuje zestawienia z **Interactive Brokers**, z **DEGIRO** albo własne pliki CSV, przelicza
kwoty po kursach NBP z dnia poprzedzającego transakcję, dopasowuje sprzedaże do zakupów
metodą **FIFO** i pokazuje wartości potrzebne do formularzy **PIT-38** i **PIT/ZG**.

Działa jako aplikacja webowa i jako zestaw poleceń konsoli.

> ### ⚠️ Zastrzeżenie
>
> To narzędzie **edukacyjno-pomocnicze i nie stanowi porady podatkowej**. Wyniki należy
> samodzielnie zweryfikować z aktualnymi przepisami oraz obowiązującymi w danym roku
> formularzami PIT-38 i PIT/ZG. Autorzy nie ponoszą odpowiedzialności za rozliczenia
> wykonane na podstawie tych wyliczeń. W razie wątpliwości skonsultuj się z doradcą
> podatkowym.

---

## Spis treści

- [Dlaczego prywatnie](#dlaczego-prywatnie)
- [Wymagania](#wymagania)
- [Instalacja lokalna](#instalacja-lokalna)
- [Docker](#docker)
- [Korzystanie z aplikacji webowej](#korzystanie-z-aplikacji-webowej)
- [Korzystanie z konsoli (CLI)](#korzystanie-z-konsoli-cli)
- [Obsługiwane formaty plików](#obsługiwane-formaty-plików)
- [Metodyka podatkowa](#metodyka-podatkowa)
- [Model prywatności](#model-prywatności)
- [Ograniczenia](#ograniczenia)
- [Architektura](#architektura)
- [Testy i kontrola jakości](#testy-i-kontrola-jakości)
- [Licencja](#licencja)

---

## Dlaczego prywatnie

Zestawienie maklerskie to jedne z najbardziej wrażliwych danych, jakie posiadasz: pełna
historia transakcji, salda i identyfikator rachunku. Ten kalkulator jest zbudowany tak,
żeby **nigdzie ich nie zapisywać**:

- **brak bazy danych** — aplikacja nie ma warstwy trwałości,
- **brak zapisu na dysku** — tymczasowy plik tworzony przez PHP przy wysyłce jest
  odczytywany raz i natychmiast usuwany,
- **brak danych w sesji** — sesja przechowuje wyłącznie token CSRF,
- **brak śledzenia** — zero analityki, zewnętrznych czcionek, skryptów i obrazów,
- **możesz uruchomić u siebie** — jedno polecenie i liczysz na własnym komputerze.

Między żądaniami dane wracają jako pola formularza w Twojej przeglądarce. Serwer jest
bezstanowy — odświeżenie strony kasuje wszystko.

## Wymagania

| Składnik | Wersja |
| --- | --- |
| PHP | **8.4+** (rozwijane i testowane na 8.5) |
| Rozszerzenia PHP | `ctype`, `dom`, `iconv`, `json`, `mbstring` |
| Composer | 2.x |
| Baza danych | **niepotrzebna** |
| Node.js / npm | **niepotrzebne** (brak kroku budowania front-endu) |

Dostęp do internetu jest potrzebny wyłącznie do pobrania kursów walut z publicznego API
NBP. Kursy są zapisywane w pamięci podręcznej, a transakcje w PLN nie wymagają połączenia.

## Instalacja lokalna

```bash
composer install

# opcjonalnie: własne ustawienia
cp .env.example .env.local
# uzupełnij APP_SECRET, np.:
php -r 'echo bin2hex(random_bytes(16)), PHP_EOL;'

# wbudowany serwer PHP
php \
  -d upload_max_filesize=6M \
  -d post_max_size=64M \
  -d max_file_uploads=12 \
  -d max_input_vars=120000 \
  -d memory_limit=256M \
  -S 127.0.0.1:8000 -t public
```

Aplikacja jest dostępna pod `http://127.0.0.1:8000`.

### Wymagane ustawienia PHP

Domyślna konfiguracja PHP jest **za ciasna** dla tej aplikacji: `upload_max_filesize`
wynosi zwykle 2 MB (mniej niż limit 5 MB na plik), a `max_input_vars` — 1000, co przy
większym imporcie ucięłoby formularz weryfikacji. Ustaw w `php.ini` (albo przekaż przez
`-d`, jak wyżej):

| Dyrektywa | Wartość | Dlaczego |
| --- | --- | --- |
| `upload_max_filesize` | `6M` | limit aplikacji to 5 MB na plik |
| `post_max_size` | `64M` | 10 plików × 5 MB + narzut formularza |
| `max_file_uploads` | `12` | limit aplikacji to 10 plików |
| `max_input_vars` | `120000` | ekran weryfikacji odsyła każdy wiersz jako pola formularza |
| `memory_limit` | `256M` | zapas przy dużych zestawieniach |

Gotowy zestaw znajdziesz w [`docker/php.ini`](docker/php.ini) — obraz Dockera ustawia
go automatycznie.

> Gdyby mimo wszystko doszło do ucięcia żądania, aplikacja to wykryje: strona podaje,
> ile wierszy wysłała, a serwer odrzuca niekompletny formularz z czytelnym błędem,
> zamiast policzyć zaniżony podatek.

Jeśli używasz [Symfony CLI](https://symfony.com/download):

```bash
symfony serve
```

## Docker

Obraz produkcyjny oparty na **PHP 8.5** (`php:8.5-fpm-alpine`) z nginx w jednym kontenerze,
uruchamiany jako użytkownik bez uprawnień roota. Obraz kompiluje wymagane rozszerzenia
(`dom`, `mbstring`, `intl`, `opcache`) i weryfikuje je w trakcie budowania przez
`composer check-platform-reqs`, więc brak rozszerzenia zatrzyma build, a nie pierwsze
żądanie:

```bash
docker compose up --build
# http://127.0.0.1:8080
```

Albo bez compose:

```bash
docker build -t pit-stock-calc .
docker run --rm -p 8080:8080 \
  -e APP_SECRET="$(php -r 'echo bin2hex(random_bytes(16));')" \
  pit-stock-calc
```

Zmienne środowiskowe opisano w [`.env.example`](.env.example). Na produkcji ustaw
`APP_ENV=prod`, `APP_DEBUG=0`, losowy `APP_SECRET` oraz `TRUSTED_HOSTS`.

Przy wystawieniu publicznie koniecznie przeczytaj [SECURITY.md](SECURITY.md) — opisuje
konfigurację `TRUSTED_HOSTS` oraz to, jak ustawić odwrotne proxy, żeby aplikacja
rozpoznała HTTPS i wysłała nagłówek HSTS.

## Korzystanie z aplikacji webowej

1. **Strona główna (`/`)** — opis narzędzia, modelu prywatności i obsługiwanych formatów.
2. **Kalkulator (`/kalkulator`)** — wybierz rok podatkowy i wgraj jeden lub kilka plików CSV
   (do 10 plików, każdy do 5 MB, kodowanie UTF-8). Format każdego pliku jest rozpoznawany
   automatycznie. Na tej stronie znajdziesz też przykładowe pliki do pobrania.
3. **Ekran weryfikacji** — po imporcie zobaczysz wszystkie wiersze w postaci edytowalnej:
   - popraw dowolną wartość, uzupełnij brakujący kraj albo zaznacz wiersz do usunięcia,
   - komunikaty wskazują problemy (sprzedaż bez pokrycia w zakupach, nieznany kraj,
     błędna data lub kwota, pominięte typy wierszy),
   - kolumna *Źródło* pokazuje, z którego pliku i w jakim formacie pochodzi wiersz,
   - identyczne rekordy z kilku plików są wykrywane i pomijane,
   - widać też, ile wierszy wypada poza wybrany rok podatkowy.
4. **Wynik** — podsumowanie w podziale na akcje (część C) i dywidendy (część G), rozbicie
   na kraje w układzie PIT/ZG oraz tabele szczegółowe z kwotą źródłową, kursem NBP, datą
   kursu i kwotą w złotych. Podatek łączny podajemy **w dwóch wariantach prawnych**
   (zachowawczym i wg orzecznictwa NSA) wraz z wyjaśnieniem i odnośnikami do źródeł —
   patrz [Metodyka podatkowa](#metodyka-podatkowa).
5. **Raport** — pobranie szczegółowego pliku CSV albo otwarcie wersji do wydruku.
   Oba są generowane w momencie kliknięcia i nie powstają jako pliki na serwerze.

### Trasy

| Metoda | Ścieżka | Opis |
| --- | --- | --- |
| GET | `/` | strona główna |
| GET | `/kalkulator` | formularz wgrywania plików |
| POST | `/kalkulator/import` | import i ekran weryfikacji |
| POST | `/kalkulator/wynik` | obliczenie podatku |
| POST | `/kalkulator/raport.csv` | pobranie raportu CSV |
| POST | `/kalkulator/raport` | wersja do wydruku |
| GET | `/przyklady/{plik}.csv` | przykładowe pliki |

Wszystkie operacje POST wymagają poprawnego tokenu CSRF.

## Korzystanie z konsoli (CLI)

Polecenia zachowują nazwy i zgodność z wcześniejszymi wersjami narzędzia.

### Obliczenie podatku

```bash
# pojedynczy plik w formacie własnym (jak dotychczas)
php bin/console app:calculate-from-file pozycje.csv

# kilka plików naraz, dowolny obsługiwany format, wybrany rok
php bin/console app:calculate-from-file transakcje.csv dywidendy.csv --rok=2024

# tylko dywidendy
php bin/console app:calculate-dividends-from-file dywidendy.csv --rok=2024
```

Bez opcji `--rok` rozliczany jest **najnowszy rok obecny w danych**; wybrany rok
jest zawsze wypisany w nagłówku, a liczba pominiętych wierszy w komunikacie.

> Płaski eksport transakcji IBKR nie zawiera kraju. CLI nie zgaduje tej wartości i
> zakończy obliczenie błędem. Najpierw użyj polecenia konwersji, uzupełnij kolumnę
> `country` w znormalizowanym CSV, a następnie uruchom kalkulację. W webie służy do tego
> ekran weryfikacji.

> Pliki DEGIRO liczą się w CLI od razu, bo kraj można zaproponować z prefiksu ISIN —
> ale to **propozycja**, nie ustalenie. Polecenie wypisuje wtedy ostrzeżenie z prośbą
> o sprawdzenie kraju. Żeby go poprawić, użyj ekranu weryfikacji w aplikacji webowej albo
> przekonwertuj dane do formatu własnego i popraw kolumnę `country`:
>
> ```bash
> php bin/console app:calculate-from-file degiro-transakcje.csv degiro-rachunek.csv --rok=2025
> php bin/console app:convert-interactivebrokers degiro-transakcje.csv pozycje.csv
> php bin/console app:convert-dividends-interactivebrokers degiro-rachunek.csv dywidendy.csv
> ```
>
> Polecenia konwersji noszą historyczne nazwy `*-interactivebrokers`, ale rozpoznają
> **każdy** obsługiwany format wejściowy, w tym oba eksporty DEGIRO.

### Konwersja zestawień brokerskich

```bash
# transakcje -> pozycje zamknięte (dopasowanie FIFO)
php bin/console app:convert-interactivebrokers transakcje-ibkr.csv pozycje.csv

# dywidendy (dowolny z formatów IBKR/DEGIRO) -> format własny
php bin/console app:convert-dividends-interactivebrokers dywidendy-ibkr.csv dywidendy.csv

# to samo dla DEGIRO (format wejściowy jest wykrywany automatycznie)
php bin/console app:convert-interactivebrokers degiro-transakcje.csv pozycje.csv
php bin/console app:convert-dividends-interactivebrokers degiro-rachunek.csv dywidendy.csv
```

Pliki wynikowe mają dokładnie te same kolumny co dotychczas, więc pasują do
istniejących skryptów i arkuszy. Nazwy poleceń pozostały historyczne ze względu na
zgodność wstecz — wejściem może być dowolny obsługiwany format.

### Kody wyjścia

`0` — sukces; `1` — plik nieczytelny, nierozpoznany format, brak danych do rozliczenia
albo nieudany zapis pliku wynikowego. Błędne pojedyncze wiersze są raportowane jako
ostrzeżenia i nie przerywają przetwarzania.

## Obsługiwane formaty plików

Format jest wykrywany automatycznie na podstawie nagłówków lub znacznika sekcji.
Akceptowane separatory: przecinek, średnik i tabulator. Liczby mogą używać kropki
(pliki brokerskie) lub przecinka dziesiętnego (pliki własne). Daty: `RRRR-MM-DD`,
`RRRRMMDD`, `RRRRMMDD;GGMMSS`, `DD.MM.RRRR`, `DD-MM-RRRR` (DEGIRO) i warianty z czasem.
Rok musi być czterocyfrowy — `15-03-25` jest odrzucane jako niejednoznaczne.

Kwoty z obu notacji tysięcznych są rozpoznawane bez zgadywania: gdy w liczbie występują
oba separatory, ten **stojący dalej** jest separatorem dziesiętnym, więc `1,234.56`
i `1.431,00` czytane są poprawnie. Gdy występuje tylko przecinek, decyduje separator pól:
w pliku rozdzielanym średnikiem (typowy eksport kontynentalny) przecinek jest dziesiętny.

### 1. Interactive Brokers — transakcje (Flex)

```csv
"AssetClass","Symbol","TradeDate","Quantity","TradePrice","NetCash","TransactionID","CurrencyPrimary"
"STK","CSPX","20240403","3","507.5782","-1523.98","1000000001","USD"
"STK","CSPX","20250227","-8","560.0000","4478.75","1000000003","USD"
```

Brane pod uwagę są wyłącznie wiersze `AssetClass = STK`. Dodatnia `Quantity` to zakup,
ujemna — sprzedaż. Jako kwota używany jest **`NetCash`**, a nie `TradePrice × Quantity`,
dzięki czemu prowizje trafiają do kosztów i przychodów. Ten format **nie zawiera kraju** —
uzupełnij go na ekranie weryfikacji.

Gdzie znaleźć: *Performance & Reports → Flex Queries → Trades*.

### 2. Interactive Brokers — dywidendy (zestawienie aktywności)

```csv
"CurrencyPrimary","Symbol","Multiplier","Date/Time","Amount","Type","TransactionID"
"USD","AAPL","1","20250213;202000","82.00","Dividends","2000000002"
"USD","AAPL","1","20250213;202000","-12.30","Withholding Tax","2000000003"
```

Wiersze typu `Dividends` i `Payment In Lieu Of Dividends` dają kwotę brutto. Wiersze
`Withholding Tax` są dopasowywane do właściwej dywidendy po symbolu, walucie i dacie.
Ujemne wiersze oznaczają pobranie, dodatnie — zwrot/korektę; aplikacja sumuje je ze
znakiem i dopiero ujemne saldo zamienia na dodatnią kwotę podatku. Zwrot większy od
pobrania jest zgłaszany jako błąd zamiast sztucznie zwiększać odliczenie.
Pozostałe typy (np. `Deposits/Withdrawals`) są pomijane z informacją. Ten format
**nie zawiera kraju**.

Gdzie znaleźć: *Flex Queries → Cash Transactions* (dodaj typy `Dividends`
oraz `Withholding Tax`).

### 3. Interactive Brokers — Dividend Detail (dokumenty podatkowe) — zalecany

```csv
Account,Header,AccountNumber,AccountAlias,Name,BaseCurrency,
Account,Data,UXXXXXXXX,,Jan Przykladowy,EUR,
DividendDetail,Header,DataDiscriminator,Currency,Symbol,Conid,Country,ReportDate,ExDate,Shares,RevenueComponent,QualifiedIndicator,Gross,GrossInBase,GrossInUSD,Withhold,WithholdInBase,WithholdInUSD
DividendDetail,Data,Summary,USD,AAPL,100000001,US,20250213,20250207,100,,,82.00,75.20,82.00,-12.30,-11.28,-12.30,
```

Plik sekcyjny. Czytana jest wyłącznie sekcja `DividendDetail`, a w niej tylko wiersze
`Summary` (wiersze `RevenueComponent` rozbijają tę samą wypłatę i podwajałyby kwoty).
`Withhold` jest ujemny w źródle i zapisywany jako wartość bezwzględna.
Nieoczekiwana dodatnia wartość `Withhold` oznacza zwrot i jest odrzucana do ręcznej
weryfikacji, a nie traktowana jak dodatkowo pobrany podatek.
**Ten format zawiera kraj** — nie trzeba go uzupełniać ręcznie.

Sekcja `Account` (nazwisko, numer rachunku) **nie jest w ogóle czytana**.

Gdzie znaleźć: *Performance & Reports → Tax Documents → Dividend Detail*.

> **Wgranie obu zestawień dywidendowych naraz jest bezpieczne.** Jeśli ta sama wypłata
> (ten sam symbol, waluta, data, kwota brutto i podatek) występuje w obu plikach, rekordy
> są scalane w jeden — zachowywany jest ten z krajem — a scalenie zgłaszane w komunikacie.
> Rekordy, które podają **różne** kraje, nie są scalane: to realny konflikt danych,
> który powinieneś rozstrzygnąć sam na ekranie weryfikacji.

### 4. DEGIRO — transakcje (Transactions)

```csv
Date,Time,Product,ISIN,Reference,Venue,Quantity,Price,,Local value,,Value,,Exchange rate,Transaction and/or third party costs,,Total,,Order ID
03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,3,507.5782,USD,-1522.73,USD,-1522.73,USD,,-1.25,USD,-1523.98,USD,aaa-0001
27-02-2025,15:41,ALFA CORP,US000ALFA001,NDQ,XNAS,-8,560.0000,USD,4480.00,USD,4480.00,USD,,-1.25,USD,4478.75,USD,aaa-0003
```

To **jedyne właściwe źródło transakcji** z DEGIRO. Plik występuje w kilku wariantach
(16 kolumn w starszym eksporcie, ~19 w nowszym) i w języku ustawionym na koncie —
rozpoznawane są m.in. nagłówki angielskie, niderlandzkie, polskie, niemieckie,
hiszpańskie i francuskie (`Date`/`Datum`/`Data`, `Quantity`/`Aantal`/`Liczba`,
`Price`/`Koers`/`Kurs`, `Total`/`Totaal`/`Razem`, `Order ID`/`Identyfikator zlecenia`).

Kolumny są dopasowywane po nazwie, ale wiersze czytane **pozycyjnie**: DEGIRO powtarza
puste nagłówki, bo waluta każdej kwoty stoi w nienazwanej kolumnie obok niej. Wariant,
w którym waluta jest w nagłówku (`Total EUR`, `Łącznie EUR`), też jest obsługiwany.

Jako kwota używana jest kolumna **`Total`** („Razem”) wraz z jej walutą — to gotówka,
która faktycznie wyszła z rachunku lub na niego weszła, więc zawiera opłatę transakcyjną
(kolumny `Transaction and/or third party costs` nie doliczamy powtórnie).
Dodatnia `Quantity` to zakup i wymaga ujemnego `Total`, ujemna — sprzedaży i dodatniego
`Total`. Niezgodność znaku jest **błędem**, nie jest „naprawiana”.

Tożsamością FIFO jest **sam ISIN**, więc zmiana nazwy spółki nie dzieli pozycji na dwie,
a papier kupiony na jednej giełdzie i sprzedany na innej pozostaje jedną kolejką.
Nazwa z kolumny `Product` służy tylko do prezentacji (po zmianie nazwy pokazywana jest
ta z transakcji sprzedaży). Jeśli jednak lot został otwarty w innej walucie, niż rozliczono
sprzedaż, kalkulator **odmawia rozliczenia tej pozycji** — pozycja zamknięta ma jedną
walutę, a wybranie którejkolwiek byłoby zgadywaniem (patrz [Ograniczenia](#ograniczenia)).

Kolumna `Time` jest czytana i służy **wyłącznie do ustawienia kolejki FIFO**: bez niej
zakup i sprzedaż z tego samego dnia dałoby się uszeregować tylko po kolejności wgrywania
plików. W rozliczeniu zostaje sama data — kurs NBP i rok podatkowy są dzienne. Godzina
musi mieć postać `GG:MM` lub `GG:MM:SS`; błędna jest **błędem wiersza**, a nie cichą
północą. DEGIRO podaje ją z dokładnością do minuty, dlatego zakup i sprzedaż w tej samej
minucie przerywają import. Tak samo kilka zakupów w tej samej minucie o różnych kosztach
jednostkowych — kolejność plików nie może decydować o koszcie FIFO. Identyczne kosztowo
transze mogą pozostać, bo ich zamiana nie zmienia wyniku podatkowego.

`Order ID` jest identyfikatorem zewnętrznym. Jedno zlecenie bywa wykonane w kilku
transzach, **także w kilku dniach** (zlecenie GTC), i wszystkie transze są rozliczane.
Ten sam identyfikator przy innym instrumencie, innej walucie albo po przeciwnej stronie
transakcji przerywa import w całości. Wiersze **bez** `Order ID` dostają identyfikator
wyliczony z treści wiersza i jego kolejności w pliku, dzięki czemu ten sam eksport wgrany
dwa razy (także pod tą samą nazwą) nie dubluje pozycji, a dwie naprawdę identyczne transze
w jednym pliku pozostają dwiema.

Wiersze z zerową liczbą sztuk są pomijane z informacją. Wiersz z **niezerową** liczbą
sztuk, ale zerowym kursem lub zerową kwotą, to zwykle operacja korporacyjna (split,
scalenie, przydział) — taki wiersz **przerywa import**. Pominięcie go zmieniłoby koszt
nabycia wszystkich późniejszych sprzedaży tego papieru, a rozliczenie wyglądałoby
kompletnie.

Ten format **nie zawiera kraju** — kalkulator proponuje go z dwóch pierwszych znaków ISIN
i wypisuje ostrzeżenie; sprawdź go na ekranie weryfikacji (patrz
[Ograniczenia](#ograniczenia)).

Gdzie znaleźć: portfel DEGIRO → *Aktywność (Activity)* → *Transakcje (Transactions)* →
zakres dat obejmujący także lata zakupów → *Eksport → CSV*. Instrukcja brokera:
<https://www.degiro.com/uk/helpdesk/tax/tax-treaties/which-reports-are-there-and-where-can-i-find-them>.

### 5. DEGIRO — zestawienie konta (Account statement)

```csv
Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id
13-02-2025,06:32,13-02-2025,ALFA CORP,US000ALFA001,Dividend,,USD,82.00,USD,82.00,
13-02-2025,06:32,13-02-2025,ALFA CORP,US000ALFA001,Dividend Tax,,USD,-12.30,USD,69.70,
```

Stąd wczytywane są **dywidendy i podatek u źródła**. Ten plik ma 12 kolumn, w tym
powtórzone puste nagłówki, więc jest czytany wyłącznie pozycyjnie: kolumna
`Change`/`Mutatie`/`Zmiana` zawiera **walutę**, a kwota stoi w następnej kolumnie
(w starszym układzie z nagłówkiem `Amount`/`Kwota` waluta stoi przed kwotą).

Rokiem podatkowym rządzi **data waluty** (`Value date`, `Valutadatum`, `Data waluty`),
a nie data księgowania: dywidenda zaksięgowana 2 stycznia z datą waluty 29 grudnia należy
do poprzedniego roku. Wypłaty są grupowane po ISIN, walucie i dacie waluty.

Opisy są dopasowywane wielojęzycznie, a wzorce podatkowe **przed** dywidendowymi — inaczej
niderlandzkie `Dividendbelasting` albo polski `Podatek od dywidendy` zostałyby zaksięgowane
jako przychód. Rozpoznawane są m.in. `Dividend`, `Dividende`, `Dividendo`, `Dywidenda`
oraz `Dividend Tax`, `Withholding Tax`, `Dividendbelasting`, `Impôts sur dividende`,
`Retención del dividendo`, `Quellensteuer`, `Podatek od dywidendy`.

Ujemne wiersze podatku to pobranie, dodatnie — zwrot; są sumowane ze znakiem, więc zwrot
zmniejsza podatek. Zwrot większy od pobrania jest **błędem** dla tej wypłaty, a nie
sztucznym zwiększeniem odliczenia.

Wypłaty są składane **ze wszystkich wgranych zestawień konta razem**, nie osobno w każdym
pliku. Dywidenda i pobrany od niej podatek to dwa wiersze i łatwo trafiają do dwóch
eksportów (np. przy pobieraniu miesiąc po miesiącu) — liczone per plik dałyby podatek zero
i zawyżony podatek do zapłaty. Każdy plik jest przy tym czytany osobno, więc mogą mieć
różne języki nagłówków i różne notacje liczb. Powtórzone wiersze z nachodzących na siebie
eksportów są odrzucane, ale dwie naprawdę identyczne wypłaty z jednego pliku zostają.
Podatek, który po przeczytaniu **wszystkich** plików nie ma pasującej dywidendy, przerywa
cały import: brak wypłaty brutto oznacza niepełne dane i nie wolno liczyć pozostałych
dywidend jako pozornie kompletnego zestawu.

**Transakcje z tego pliku nie są importowane.** Zestawienie konta nie podaje liczby sztuk
ani kursu, a wczytanie ich z obu plików podwoiłoby każdą pozycję; wiersze kupna/sprzedaży
są tylko policzone w komunikacie. Pozostałe operacje (wpłaty, opłaty, przewalutowania,
odsetki) są pomijane z podsumowaniem — nigdy jako błąd.

Ten format również **nie zawiera kraju**; obowiązuje ta sama propozycja z ISIN
i to samo ostrzeżenie.

Gdzie znaleźć: portfel DEGIRO → *Aktywność (Activity)* → *Zestawienie konta
(Account statement)* → *Eksport → CSV*.

> **Wgranie obu plików DEGIRO naraz jest właściwym sposobem użycia.** Transakcje dają
> pozycje zamknięte, zestawienie konta — dywidendy. Można też dograć zestawienia z kilku
> lat: zakupy z lat wcześniejszych posłużą jako koszt dla sprzedaży w rozliczanym roku,
> a powtórzone wiersze zostaną rozpoznane po numerze zlecenia.

> **DEGIRO i IBKR można wgrać w jednym imporcie.** Surowe transakcje są zbierane i
> dopasowywane **osobno dla każdego brokera**: identyfikatory są unikalne tylko u wystawcy,
> a kolejka FIFO jednego brokera jest kluczowana ISIN-em, drugiego symbolem.

### 6. Format własny — pozycje zamknięte

```csv
name,country,currency,buy_date,buy_total_amount,sell_date,sell_total_amount
AAPL,US,USD,2023-05-04,718.2750,2025-02-16,1274.0000
PKO,PL,PLN,2024-01-15,1000.00,2025-03-10,1180.50
```

Kwoty są **łączne dla pozycji**, nie za sztukę. Precyzja większa niż grosze jest zachowywana.

### 7. Format własny — dywidendy

```csv
name,country,currency,date,amount,tax_paid
AAPL,US,USD,2025-02-13,82.00,12.30
VUSD,IE,USD,2025-04-02,56.75,0
```

`tax_paid` to **nieujemna** kwota podatku pobranego u źródła; puste pole oznacza zero.
Ujemny znak jest normalizowany wyłącznie w brokerowych formatach IBKR, które go tak
definiują. W formacie własnym i formularzu wartość ujemna jest błędem.

Przykładowe pliki znajdziesz w katalogu [`examples/`](examples/) oraz do pobrania
ze strony `/kalkulator`.

## Metodyka podatkowa

### Kurs waluty

Każda kwota jest przeliczana **kursem średnim NBP z tabeli A z ostatniego dnia roboczego
poprzedzającego dzień transakcji** (zasada D-1, art. 11a ustawy o PIT). Jeśli NBP nie
publikował kursu w danym dniu (weekend, święto), sprawdzany jest kolejny wcześniejszy dzień
— maksymalnie 10 dni wstecz. W raporcie widać użyty kurs, jego datę i numer tabeli.
Kwoty w PLN są przyjmowane z kursem 1 i **nie wymagają połączenia z NBP**.

Jeżeli choć jednego wymaganego kursu nie uda się pobrać, aplikacja działa **fail closed**:
nie pokazuje ani nie eksportuje sum policzonych z pozostałych rekordów. Wraca do ekranu
weryfikacji z komunikatem, a CLI kończy się kodem `1`. Zapobiega to rozliczeniu na
niepełnym zbiorze danych podczas awarii NBP lub przy nieobsługiwanej walucie.

### Akcje i ETF-y (PIT-38 część C)

- Sprzedaże są dopasowywane do zakupów metodą **FIFO** — najstarsze zakupy najpierw.
- **Dopasowanie obejmuje wszystkie wgrane pliki naraz.** Broker wystawia zestawienia
  rocznie, więc zakup z pliku za 2024 r. jest poprawnie łączony ze sprzedażą z pliku
  za 2025 r. Wgraj po prostu oba pliki jednocześnie.
- **Powtórzone transakcje są rozpoznawane po `TransactionID`** jeszcze przed dopasowaniem,
  więc nakładające się zestawienia roczne (ten sam zakup w pliku za 2024 i 2025 r.)
  nie tworzą drugiej partii ani fikcyjnej pozycji otwartej.
- Dwa identyczne co do treści wypełnienia zlecenia z tego samego dnia **pozostają dwiema
  pozycjami**, bo rozróżnia je numer transakcji brokera — zwijanie ich zaniżyłoby podatek.
- **Zakupy z wcześniejszych lat pozostają dostępne** dla sprzedaży w rozliczanym roku;
  do rozliczenia trafiają tylko dochody **zrealizowane** w wybranym roku (czyli sprzedaże).
- Przy częściowym zamknięciu partii koszt jest dzielony proporcjonalnie, a ostatnia część
  dostaje dokładną resztę, więc sumy się zgadzają co do grosza.
- Przychód = suma kwot sprzedaży w PLN; koszt = suma kwot zakupu w PLN.
- Podatek = **19%** dochodu. Przy stracie podatek wynosi **zero** (nigdy wartości ujemnej).

### Dywidendy (PIT-38 część G)

Podatek polski liczymy tak samo w obu wariantach:

```
podatek polski = 19% × kwota brutto w PLN
```

**Wysokość odliczenia zagranicznego podatku u źródła jest natomiast prawnie sporna**,
dlatego kalkulator podaje **dwie kwoty** i nie rozstrzyga sporu za Ciebie.

#### Wariant zachowawczy (stanowisko KIS)

```
odliczenie         = min(podatek faktycznie pobrany, stawka umowna × brutto, podatek polski)
podatek do zapłaty = max(0, podatek polski − odliczenie)
```

Organy podatkowe (interpretacje Krajowej Informacji Skarbowej) ograniczają odliczenie do
stawki wynikającej z umowy o unikaniu podwójnego opodatkowania. Jeśli broker pobrał więcej —
np. 30% w USA bez formularza W-8BEN — nadwyżkę ponad stawkę umowną odzyskuje się w kraju
źródła, a nie odlicza w Polsce.

#### Wariant wg orzecznictwa NSA

```
odliczenie         = min(podatek faktycznie pobrany, podatek polski)
podatek do zapłaty = max(0, podatek polski − odliczenie)
```

Naczelny Sąd Administracyjny w prawomocnych wyrokach **II FSK 1171/22** (28.02.2023) oraz
**II FSK 1302/22** (24.06.2025) uznał, że art. 30a ust. 9 ustawy o PIT pozwala odliczyć
podatek *faktycznie pobrany* za granicą, ograniczony jedynie polskim podatkiem 19%,
bez dodatkowego limitu stawką umowną.

#### Co z tym zrobić

Stan na 2026 r. pozostaje rozbieżny: interpretacje i komentarze idą w obie strony, a wyroki
NSA wiążą formalnie w konkretnych sprawach. Kalkulator pokazuje obie kwoty w wyniku,
w wersji do wydruku, w raporcie CSV i w konsoli — **wybór wariantu i jego uzasadnienie
należą do podatnika**. Przy istotnej różnicy rozważ wniosek o interpretację indywidualną
albo konsultację z doradcą podatkowym. To narzędzie nie rozstrzyga Twojej indywidualnej
sytuacji.

Przykład (kurs 4,00; dywidenda z USA 100 USD brutto, 30 USD pobrane):

| Pozycja | Wariant zachowawczy | Wariant wg NSA |
| --- | --- | --- |
| Przychód brutto | 400,00 PLN | 400,00 PLN |
| Podatek polski 19% | 76,00 PLN | 76,00 PLN |
| Podatek pobrany | 120,00 PLN | 120,00 PLN |
| **Do odliczenia** | **60,00 PLN** (15% umowne) | **76,00 PLN** (do 19%) |
| **Do zapłaty** | **16,00 PLN** | **0,00 PLN** |

**Źródła:**

- [Ustawa o podatku dochodowym od osób fizycznych — tekst jednolity (ELI, Dz.U. 2025 poz. 163)](https://eli.gov.pl/eli/DU/2025/163/ogl) — art. 30a ust. 9
- [Informacje Ministerstwa Finansów o PIT](https://www.podatki.gov.pl/pit/) — formularze i broszury PIT-38 / PIT/ZG
- [Centralna Baza Orzeczeń Sądów Administracyjnych](https://orzeczenia.nsa.gov.pl/) — wyroki II FSK 1171/22 i II FSK 1302/22

Komentarze branżowe i publikacje doradców traktuj wyłącznie jako kontekst — źródłem prawa
są ustawa i orzeczenia.

### Domyślne stawki umowne

| Kod | Kraj | Stawka | | Kod | Kraj | Stawka |
| --- | --- | --- | --- | --- | --- | --- |
| AT | Austria | 15% | | JP | Japonia | 10% |
| AU | Australia | 15% | | LU | Luksemburg | 15% |
| BE | Belgia | 15% | | NL | Holandia | 15% |
| CA | Kanada | 15% | | NO | Norwegia | 15% |
| CH | Szwajcaria | 15% | | PL | Polska | 19% |
| CZ | Czechy | 5% | | PT | Portugalia | 15% |
| DE | Niemcy | 15% | | SE | Szwecja | 15% |
| DK | Dania | 15% | | SG | Singapur | 10% |
| ES | Hiszpania | 15% | | US | Stany Zjednoczone | 15% |
| FI | Finlandia | 15% | | GB | Wielka Brytania | 0% |
| FR | Francja | 15% | | HK | Hongkong | 10% |
| IE | Irlandia | 0% | | IT | Włochy | 10% |

**Zweryfikuj stawkę dla swojej sytuacji.** Umowy bywają renegocjowane, a właściwa stawka
potrafi zależeć od rodzaju instrumentu i statusu podatnika. Tabela to punkt wyjścia,
nie źródło prawa — jest zdefiniowana w [`src/Tax/TaxRates.php`](src/Tax/TaxRates.php).
Dla kraju spoza tabeli kalkulator **nie zgaduje stawki**: wariant zachowawczy przyjmuje
odliczenie `0 PLN`, wariant wg NSA pokazuje faktycznie pobrany podatek do limitu 19%,
a wynik zawiera wyraźne ostrzeżenie wymagające weryfikacji umowy.

### Zaokrąglanie

Wszystkie działania wykonuje arytmetyka dziesiętna (`brick/math`) — **żadnych liczb
zmiennoprzecinkowych**, więc grosze się nie gubią. Zasady:

1. **Przeliczenie na złote** zaokrąglamy do groszy przy każdej transakcji — kurs NBP dotyczy
   konkretnej wypłaty czy transakcji, więc to naturalna granica zaokrąglenia.
2. **Podatek 19% liczymy dokładnie** dla każdej pozycji i dywidendy i **sumujemy przed
   zaokrągleniem**. PIT-38 deklaruje kwoty zbiorcze, a nie sumę zaokrąglonych kwot
   cząstkowych. Gdybyśmy zaokrąglali każdą wypłatę osobno, sto dywidend po 0,03 PLN dałoby
   1,00 PLN podatku zamiast prawidłowych 0,57 PLN.
3. **Zaokrąglamy raz, od wartości dokładnej.** Kwota w groszach i kwota w pełnych złotych
   powstają obie z tej samej dokładnej liczby — nie zaokrąglamy najpierw do groszy,
   a potem do złotych. Skutek uboczny: przy dokładnym podatku 0,4997 PLN zobaczysz
   „0,50 PLN” obok „0 PLN” w pełnych złotych. To celowe; podwójne zaokrąglenie potrafi
   przesunąć deklarowaną kwotę o cały złoty.
4. **Podział kosztu częściowo sprzedanego lota liczymy ułamkiem, nie liczbą dziesiętną.**
   Udział „ile z tego lota” to `sprzedana ilość / ilość w locie` — na przykład 1/3, które
   nie ma skończonego rozwinięcia dziesiętnego. Kalkulator trzyma ten ułamek dokładnie
   (`Brick\Math\BigRational`) i zaokrągla **raz**, do liczby miejsc, jaką ma sama kwota.
   Skala pośrednia gubiłaby kwotę proporcjonalnie do jej wielkości: przy sześciu miejscach
   po przecinku jedna trzecia z 1 000 000 000,00 wychodziła o 333,33 za mało. Ostatnia
   transza lota dostaje dokładną resztę, więc części zawsze sumują się do całości.

> **Założenie do zweryfikowania.** Broszura PIT-38 mówi o zaokrąglaniu kwot **wykazywanych
> w zeznaniu** do pełnych złotych, ale nie rozstrzyga wprost, czy 19% liczy się od sumy,
> czy od każdej wypłaty osobno. Przyjęliśmy podejście zbiorcze (pkt 2 i 3), bo formularz
> deklaruje agregaty. Przy dużej liczbie mikropłatności różnica bywa realna — jeśli Twój
> doradca uzna inaczej, wartości cząstkowe w raporcie CSV pozwalają przeliczyć obie wersje.

### Pola formularzy

Celowo **nie podajemy numerów pól** (typu „C.22”). Numeracja zmienia się między wersjami
formularzy, a wpisanie kwoty w nieaktualne pole jest gorsze niż samodzielne dopasowanie.
Zamiast tego prezentujemy wartości semantyczne (przychód, koszty uzyskania przychodu,
dochód, podatek) wraz z sekcją formularza i wybranym rokiem.

## Model prywatności

| Obszar | Rozwiązanie |
| --- | --- |
| Baza danych | brak |
| Zapis przesłanych plików | brak — tymczasowy plik PHP jest usuwany zaraz po odczycie |
| Dane w sesji | tylko token CSRF |
| Stan między żądaniami | pola formularza w przeglądarce użytkownika |
| Pamięć podręczna | wyłącznie publiczne kursy NBP |
| Nagłówki | `Cache-Control: no-store` na każdej odpowiedzi |
| Analityka, czcionki, CDN | brak; CSP `default-src 'self'` |
| Ruch wychodzący | wyłącznie `api.nbp.pl` po kursy walut |

Szczegóły i mechanizmy obronne: [SECURITY.md](SECURITY.md).

## Ograniczenia

Rzeczy, których to narzędzie **nie robi** — warto wiedzieć przed użyciem:

- **Nie rozstrzyga sporu o odliczenie podatku u źródła.** Podaje obie kwoty (wariant
  zachowawczy KIS i wariant wg orzecznictwa NSA) — wybór należy do Ciebie.
- **Nie przyjmuje kwot ujemnych ani zerowych.** Kwota zakupu, sprzedaży i dywidendy brutto
  muszą być dodatnie, liczba sztuk dodatnia, a podatek u źródła nieujemny (po normalizacji
  znaku właściwej dla formatu). Wiersz łamiący te reguły jest odrzucany z komunikatem,
  a nie „naprawiany” zmianą znaku.
- **Wymaga kraju przed obliczeniem.** Płaskie zestawienia IBKR nie zawierają kraju; taki
  wiersz trafia na ekran weryfikacji, ale wynik i raport policzysz dopiero po uzupełnieniu
  dwuliterowego kodu ISO.
- **Kraj z pliku DEGIRO jest tylko propozycją.** Eksporty DEGIRO nie podają kraju źródła
  dochodu, więc kalkulator wpisuje dwa pierwsze znaki numeru ISIN i wypisuje ostrzeżenie.
  To **kraj rejestracji papieru**, nie zawsze kraj źródła dochodu — klasyczny przykład to
  irlandzki ETF na akcje amerykańskie (`IE…`) albo spółka notowana poza krajem rejestracji.
  Prefiksy, które nie są kodem kraju (`XS…` Euroclear/Clearstream, `EU`, `QZ`), zostawiają
  pole puste do ręcznego uzupełnienia. Cyfra kontrolna ISIN nie jest weryfikowana —
  sprawdzana jest tylko struktura numeru. **Sprawdź kolumnę „Kraj” przed obliczeniem.**
- **Starsze eksporty DEGIRO mogły nie zawierać prowizji AutoFX w kwocie `Total`.** Sam
  DEGIRO to w przeszłości potwierdzał. Kalkulator liczy dokładnie to, co jest w pliku, i
  informuje, gdy plik nie ma kolumny AutoFX — opłaty, której nie ma w eksporcie, nie da się
  wymyślić. Jeśli płaciłeś za przewalutowanie, zweryfikuj kwoty w zestawieniu konta.
- **Limit 5 000 rekordów na jeden import działa jak bezpiecznik.** Po przekroczeniu import
  jest odrzucany w całości — świadomie, bo częściowy zestaw danych podatkowych jest
  groźniejszy niż brak wyniku. Podziel dane, np. po jednym roku podatkowym.

- **Nie rozlicza strat z lat ubiegłych.** Strata daje podatek zero w danym roku,
  ale przeniesienie jej na kolejne lata musisz obsłużyć sam.
- **Obsługuje tylko akcje i ETF-y** (`AssetClass = STK`). Kontrakty, opcje, obligacje,
  waluty i kryptowaluty są pomijane.
- **Nie obsługuje zdarzeń korporacyjnych** — splitów, połączeń, spin-offów, przydziałów.
  Po takim zdarzeniu liczba sztuk i koszt wymagają ręcznej korekty. Nic nie dzieje się
  przy tym po cichu: wiersz DEGIRO z niezerową liczbą sztuk, ale bez kursu lub bez kwoty
  (typowy zapis takiej operacji) **przerywa import w całości**. Usuń ten instrument
  z pliku i rozlicz go osobno — pominięcie samego wiersza zmieniłoby koszt nabycia
  wszystkich późniejszych sprzedaży tego papieru.
- **Nie rozlicza pozycji zamkniętej w innej walucie, niż została otwarta.** Format pozycji
  zamkniętej ma jedną walutę i jeden kurs po każdej stronie, więc kupno w USD i sprzedaż
  w EUR nie da się w nim wyrazić. Taka pozycja jest **zgłaszana jako błąd** z nazwą papieru
  i obiema walutami — nie jest rozliczana w jednej z nich ani pomijana. Przelicz ją ręcznie
  i dopisz w formacie własnym.
- **Nie liczy różnic kursowych na rachunku walutowym** ani odsetek.
- **Nie obsługuje krótkiej sprzedaży, a sprzedaż bez pokrycia przerywa import.**
  Sprzedaż, do której w żadnym wgranym pliku nie ma zakupu, nie ma kosztu nabycia — cały
  jej przychód wyglądałby jak dochód. Taki wiersz jest **błędem**, więc żadna pozycja
  z tego importu nie trafia do wyniku: rozliczenie części pozycji z cicho pominiętą jedną
  jest groźniejsze niż brak wyniku. Dograj wcześniejsze zestawienie albo dopisz brakującą
  pozycję w formacie własnym. Dotyczy to zarówno DEGIRO, jak i Interactive Brokers.
- **Nie generuje pliku e-Deklaracji ani PDF-a formularza.** Dostajesz wartości do
  samodzielnego wpisania oraz raport CSV i wersję do wydruku.
- **Tabela stawek umownych jest uproszczona** i wymaga weryfikacji (patrz wyżej).
- **Prowizje** trafiają do rozliczenia tylko wtedy, gdy są zawarte w `NetCash` (IBKR),
  w kolumnie `Total` (DEGIRO) lub w kwotach formatu własnego. Osobnych wierszy
  prowizyjnych nie sumujemy.
- **Nie jest to porada podatkowa.**

## Architektura

Symfony 8.1 na PHP 8.5, bez bazy danych i bez kroku budowania front-endu.

```
src/
├─ Money/          Decimal, Amount — arytmetyka dziesiętna (brick/math)
├─ Fifo/           dopasowanie FIFO z proporcjonalnym podziałem kosztu
├─ Model/          ClosedPosition, Dividend — znormalizowane rekordy
├─ CurrencyRate/   kursy NBP: interfejs, klient HTTP, cache, przeliczanie D-1
├─ Import/         rozpoznawanie formatu, parsery liczb i dat, 7 importerów
│  └─ Degiro/      pozycyjny czytnik CSV, aliasy nagłówków, ISIN
├─ Tax/            stawki, kalkulator akcji, kalkulator dywidend, filtr roku
├─ Report/         budowanie raportu, eksport CSV odporny na formuły
├─ Web/            mapowanie formularza, walidacja i odczyt przesłanych plików
├─ Controller/     3 kontrolery (strona główna, kalkulator, pliki przykładowe)
├─ Command/        4 polecenia konsoli
├─ EventListener/  nagłówki bezpieczeństwa
└─ Exception/      wyjątki domenowe
```

Zasady: domena nie zna HTTP, kontrolery są cienkie, importery zgłaszają problemy
komunikatami zamiast wyjątków, a wszystkie kwoty przechodzą przez `Decimal`.

## Testy i kontrola jakości

```bash
vendor/bin/phpunit                    # cały zestaw testów
vendor/bin/phpunit --testsuite unit   # logika domenowa
vendor/bin/phpstan analyse            # poziom 9
composer validate --strict
php bin/console lint:container
php bin/console lint:twig templates
composer audit
```

Testy **nigdy nie łączą się z siecią** — kursy walut w środowisku testowym pochodzą
z deterministycznej atrapy `App\Tests\Support\FixedNbpRateProvider`.

Szczegóły w [CONTRIBUTING.md](CONTRIBUTING.md).

## Licencja

[MIT](LICENSE).
