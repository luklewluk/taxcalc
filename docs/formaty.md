# Formaty plików

Szczegóły dla dociekliwych: jak kalkulator czyta każdy z obsługiwanych eksportów i dlaczego
właśnie tak. Krótki przewodnik „skąd wziąć pliki” jest w [README](../README.md#skąd-wziąć-pliki).

Obsługiwane są trzy eksporty:

- **Interactive Brokers — Activity Statement** (transakcje, opcje, dywidendy i podatek
  u źródła w jednym pliku),
- **DEGIRO — Transakcje** (kupna i sprzedaże),
- **DEGIRO — Zestawienie konta** (dywidendy, podatek u źródła, opłata za dostęp do giełd).

Publiczne przykłady wszystkich trzech są w katalogu [`examples/`](../examples/) i do pobrania
ze strony `/kalkulator`.

## Zasady wspólne

Format jest wykrywany automatycznie na podstawie nagłówków lub znacznika sekcji — nigdy na
podstawie nazwy pliku.
Akceptowane separatory: przecinek, średnik i tabulator. Liczby mogą używać kropki
albo przecinka dziesiętnego, również gdy wartość z przecinkiem jest cytowana w CSV
rozdzielanym przecinkami. Daty: `RRRR-MM-DD` (Activity Statement, także z godziną)
i `DD-MM-RRRR` (DEGIRO); przy ręcznej edycji w aplikacji także `DD.MM.RRRR`, `DD/MM/RRRR`
i `RRRRMMDD`. Rok musi być czterocyfrowy — `15-03-25` jest odrzucane jako niejednoznaczne.

Kwoty z obu notacji tysięcznych są rozpoznawane bez zgadywania: gdy w liczbie występują
oba separatory, ten **stojący dalej** jest separatorem dziesiętnym, więc `1,234.56`
i `1.431,00` czytane są poprawnie. Dla DEGIRO jednoznaczne wartości w całym pliku ustalają
konwencję dla niejednoznacznych zapisów typu `1,234`; sprzeczne konwencje przerywają import.

## Interactive Brokers — Activity Statement

```csv
Statement,Data,Title,Activity Statement
Account Information,Data,Account,UXXXXXXXX
Trades,Header,DataDiscriminator,Asset Category,Currency,Symbol,Date/Time,Quantity,T. Price,C. Price,Proceeds,Comm/Fee,Basis,Realized P/L,MTM P/L,Code
Trades,Data,Order,Stocks,USD,AAA,"2025-02-03, 10:15:00",10,100,101,-1000,-1,1001,0,10,O
Trades,Data,Order,Stocks,USD,AAA,"2025-09-15, 15:42:10",-4,130,131,520,-1,-400.4,118.6,-4,C;P
Dividends,Header,Currency,Date,Description,Amount
Dividends,Data,USD,2025-06-12,AAA(US000ALFA001) Cash Dividend USD 0.50 per Share (Ordinary Dividend),5
Withholding Tax,Header,Currency,Date,Description,Amount,Code
Withholding Tax,Data,USD,2025-06-12,AAA(US000ALFA001) Cash Dividend USD 0.50 per Share - US Tax,-0.75,
Financial Instrument Information,Header,Asset Category,Symbol,Description,Conid,Security ID,Underlying,Listing Exch,Multiplier,Type,Code
Financial Instrument Information,Data,Stocks,AAA,ALFA CORP,1001,US000ALFA001,AAA,NASDAQ,1,COMMON,
```

Standardowy wyciąg z aktywności — nie wymaga konfigurowania żadnych zapytań. Jeden plik
zawiera transakcje, dywidendy i podatek u źródła. Format jest rozpoznawany po wierszu
`Statement,Data,Title,Activity Statement` (raport musi być wygenerowany po angielsku).

- **Transakcje** — sekcja `Trades`, wiersze `Order` klasy `Stocks`. Kwoty są w walucie
  transakcji: rozliczona gotówka to `Proceeds + Comm/Fee`, a prowizja jest znana osobno, więc
  przy sprzedaży przychód i koszty zbycia są rozdzielane jak dla DEGIRO. Czas wykonania
  (`Date/Time`, z sekundami) ustala kolejność FIFO; kupno i sprzedaż jednej kolejki z tym
  samym czasem przerywają import.
- **ISIN i giełda** pochodzą z sekcji `Financial Instrument Information` na końcu pliku.
  Kolejka FIFO to `ISIN@WALUTA` (przeżywa zmianę tickera między rocznymi wyciągami), a kraj
  jest proponowany z giełdy notowania (`Listing Exch`, np. `NASDAQ` → US, `LSEETF` → GB);
  ustawienie „Kraj transakcji” przełącza go na prefiks ISIN.
- **Dywidendy** — sekcje `Dividends` i `Payment In Lieu Of Dividends`, podatek z
  `Withholding Tax`; wypłata jest łączona z podatkiem po ISIN, walucie i dniu, także między
  plikami. Storna i korekty podatku bez wypłaty z tego samego dnia trafiają do „Wymaga uwagi”.
  Podatek od odsetek jest pomijany.
- **Opcje** (`Equity and Index Options`) są rozliczane w dniu zamknięcia pozycji — zob.
  [Opcje](metodyka.md#opcje-pit-38-część-c).
- **Pomijane** są wymiany walut (`Forex`). Inne klasy aktywów (np. futures, obligacje) są
  pomijane z pozycją w „Wymaga uwagi”, bo pola PIT-38 ich nie obejmują.
- **Przerywają import:** operacje korporacyjne (sekcja `Corporate Actions` albo wiersz bez
  kwoty) oraz transakcje anulowane lub korygowane (kody `Ca`, `Co`).
- **Sprzedaż bez zakupu** w wgranych plikach (zwykle brakuje wcześniejszego roku) jest pomijana
  w wyniku z pozycją w „Wymaga uwagi”, która ją wskazuje; reszta importu działa.

Wyciąg obejmuje najwyżej rok, więc pobierz plik **za każdy rok** od pierwszego zakupu
papierów, które sprzedawałeś, i wgraj wszystkie naraz. Nakładające się wyciągi nie dublują
transakcji. Sekcja `Account Information` (nazwisko, numer rachunku) **nie jest w ogóle czytana**.

Gdzie znaleźć: *Performance & Reports → Statements → Activity*, okres *Annual* (lub
*Custom Date Range*), format *CSV*, język *English*.

## DEGIRO — Transakcje (Transactions)

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
Brakujące lub nadmiarowe puste pola na samym końcu są wyrównywane tylko wtedy, gdy
odpowiadają pustym końcowym nagłówkom; przesunięcie właściwych kolumn pozostaje błędem.

Jako kwota używana jest kolumna **`Total`** („Razem”) wraz z jej walutą — to gotówka,
która faktycznie wyszła z rachunku lub na niego weszła, więc zawiera opłatę transakcyjną
(kolumny `Transaction and/or third party costs` nie doliczamy powtórnie).
Dodatnia `Quantity` to zakup i wymaga ujemnego `Total`, ujemna — sprzedaży i dodatniego
`Total`. Niezgodność znaku jest **błędem**, nie jest „naprawiana”.

Jeżeli eksport zawiera jawne kolumny prowizji i AutoFX, wartości są zachowywane osobno
ze ścisłą kontrolą waluty. Puste pole oznacza brak danych, a jawne zero pozostaje zerem.
Opłaty nie są wyliczane jako różnica `Total − liczba × kurs` i nigdy nie są doliczane do
kwoty rozliczonej. Prowizja zakupu jest już w `Total` i tym samym w koszcie nabycia; prowizja
i AutoFX **sprzedaży** są doliczane z powrotem do przychodu (przychodem jest kwota należna)
i jednocześnie ujmowane jako koszt odpłatnego zbycia — dochód jest ten sam, zmienia się
tylko rozbicie na pola 22 i 23. Przy agregacji transz oraz częściowym FIFO są sumowane i dzielone
proporcjonalnie; ostatnia część lota przejmuje resztę zaokrąglenia.

Tożsamością FIFO jest **sam ISIN**, więc zmiana nazwy spółki nie dzieli pozycji na dwie,
a papier kupiony na jednej giełdzie i sprzedany na innej pozostaje jedną kolejką.
Nazwa z kolumny `Product` służy tylko do prezentacji (po zmianie nazwy pokazywana jest
ta z transakcji sprzedaży). Jeśli jednak lot został otwarty w innej walucie, niż rozliczono
sprzedaż, kalkulator **odmawia rozliczenia tej pozycji** — pozycja zamknięta ma jedną
walutę, a wybranie którejkolwiek byłoby zgadywaniem.

Kolumna `Time` jest czytana i służy **wyłącznie do ustawienia kolejki FIFO**: bez niej
zakup i sprzedaż z tego samego dnia dałoby się uszeregować tylko po kolejności wgrywania
plików. W rozliczeniu zostaje sama data — kurs NBP i rok podatkowy są dzienne. Godzina
musi mieć postać `GG:MM` lub `GG:MM:SS`; błędna jest **błędem wiersza**, a nie cichą
północą. DEGIRO podaje ją z dokładnością do minuty, dlatego zakup i sprzedaż w tej samej
minucie przerywają import. Tak samo kilka zakupów w tej samej minucie o różnych kosztach
jednostkowych — kolejność plików nie może decydować o koszcie FIFO. Identyczne kosztowo
transze mogą pozostać, bo ich zamiana nie zmienia wyniku podatkowego.

`Order ID` jest identyfikatorem zewnętrznym (czytanym także z sąsiedniego, nienazwanego
pola końcowego w aktualnym polskim eksporcie). Jedno zlecenie bywa wykonane w kilku
transzach, **także w kilku dniach** (zlecenie GTC). Po usunięciu duplikatów wykonania
z tym samym zgłoszonym ID, ISIN-em, kierunkiem, walutą i dokładnym czasem są agregowane:
sumowane są liczba sztuk i `Total`. Wykonania z różnych chwil pozostają oddzielne.
Ten sam identyfikator przy innym instrumencie, innej walucie albo po przeciwnej stronie
transakcji przerywa import w całości. Wiersze **bez** `Order ID` dostają identyfikator
wyliczony z treści wiersza i jego kolejności w pliku, dzięki czemu ten sam eksport wgrany
dwa razy (także pod tą samą nazwą) nie dubluje pozycji. Identyfikator syntetyczny jest
oznaczony jawnie i nigdy nie uruchamia agregacji brokerowych transz; dwie naprawdę
identyczne transakcje bez ID w jednym pliku pozostają dwiema.

Neutralna zmiana nazwy produktu jest pomijana tylko jako kompletna para: ten sam plik,
ISIN, północ, waluta i kurs, przeciwne równe ilości oraz kwoty, puste miejsce wykonania,
brak brokerowego `Order ID` i różne nazwy produktu. Niepełna korekta oraz zwykły zakup
i sprzedaż w tej samej minucie nadal zatrzymują import.

Wiersze z zerową liczbą sztuk są pomijane z informacją. Wiersz z **niezerową** liczbą
sztuk, ale zerowym kursem lub zerową kwotą, to zwykle operacja korporacyjna (split,
scalenie, przydział) — taki wiersz **przerywa import**. Pominięcie go zmieniłoby koszt
nabycia wszystkich późniejszych sprzedaży tego papieru, a rozliczenie wyglądałoby
kompletnie.

Ten format **nie zawiera kraju źródła dochodu** — kalkulator proponuje go z giełdy
notowania (kolumna `Giełda referencyjna`, a gdy jej brak `Miejsce wykonania`) i wypisuje
ostrzeżenie; sprawdź go w zakładce Transakcje (zob. [kraj transakcji](metodyka.md#kraj-transakcji-i-kraj-dywidendy)).
Gdy giełda i prefiks ISIN wskazują różne kraje — np. irlandzki ETF notowany w Amsterdamie —
w „Wymaga uwagi” pojawia się punkt do weryfikacji wymieniający oba kody.

Gdzie znaleźć: portfel DEGIRO → *Aktywność (Activity)* → *Transakcje (Transactions)* →
zakres dat obejmujący także lata zakupów → *Eksport → CSV*. Instrukcja brokera:
<https://www.degiro.com/uk/helpdesk/tax/tax-treaties/which-reports-are-there-and-where-can-i-find-them>.

## DEGIRO — Zestawienie konta (Account statement)

```csv
Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id
13-02-2025,06:32,13-02-2025,ALFA CORP,US000ALFA001,Dividend,,USD,82.00,USD,82.00,
13-02-2025,06:32,13-02-2025,ALFA CORP,US000ALFA001,Dividend Tax,,USD,-12.30,USD,69.70,
```

Stąd wczytywane są **dywidendy i podatek u źródła**. Ten plik ma 12 kolumn, w tym
powtórzone puste nagłówki, więc jest czytany wyłącznie pozycyjnie: kolumna
`Change`/`Mutatie`/`Zmiana` zawiera **walutę**, a kwota stoi w następnej kolumnie
(w starszym układzie z nagłówkiem `Amount`/`Kwota` waluta stoi przed kwotą).

Rokiem podatkowym rządzi **data zaksięgowania na rachunku** (pierwsza kolumna `Data`
/ `Date`), a nie data waluty: dywidenda z datą waluty 29 grudnia zaksięgowana 2 stycznia
należy do nowego roku. Przychód powstaje w dniu otrzymania lub postawienia środków do
dyspozycji (art. 11 ust. 1 ustawy o PIT), a kurs bierze się z ostatniego dnia roboczego
**przed** tym dniem (art. 11a). DEGIRO księguje dywidendę po potwierdzeniu jej otrzymania
przez powiernika, zwykle do dwóch dni roboczych po dacie płatności, więc „data waluty” to
termin płatności po stronie emitenta i sama nie dowodzi, że pieniądze były dla Ciebie
dostępne. Data waluty służy więc tylko jako awaryjna, gdy eksport nie podaje księgowania,
oraz jako informacja przy stornach. Gdyby wyciąg dowodził bezwarunkowej dostępności środków
już w dacie waluty, popraw datę w zakładce Dywidendy — pole jest edytowalne.

Wypłaty są grupowane po ISIN, walucie, dacie waluty **oraz roku daty księgowania**. Data
waluty mówi, **której wypłaty** dotyczy wiersz, więc korekty nadal znoszą się z wypłatą,
którą korygują — DEGIRO potrafi wycofać wypłatę jednego dnia i wystawić ją ponownie
nazajutrz, a grupowanie po samej dacie księgowania policzyłoby ją dwa razy. Rok księgowania
jest w kluczu, bo storno zaksięgowane w późniejszym roku nie może sięgnąć wstecz i wyzerować
roku, w którym rozliczono oryginał. W obrębie jednego roku korekty się znoszą, między latami
części pozostają rozdzielne. Gdy grupa obejmuje kilka księgowań, datą uzyskania jest
**najwcześniejsze** — wtedy pieniądze pierwszy raz trafiły na rachunek.

Samotne storno (ujemne brutto) nie jest przychodem bieżącego roku: rekord nie powstaje,
a w „Wymaga uwagi” pojawia się punkt wskazujący instrument, datę księgowania i rok, którego
korekta dotyczy.

Opisy są dopasowywane wielojęzycznie, a wzorce podatkowe **przed** dywidendowymi — inaczej
niderlandzkie `Dividendbelasting` albo polski `Podatek od dywidendy` zostałyby zaksięgowane
jako przychód. Rozpoznawane są m.in. `Dividend`, `Dividende`, `Dividendo`, `Dywidenda`
oraz `Dividend Tax`, `Withholding Tax`, `Dividendbelasting`, `Impôts sur dividende`,
`Retención del dividendo`, `Quellensteuer`, `Podatek od dywidendy` i `Podatek Dywidendowy`.
Kupno, sprzedaż i zmiana produktu są rozpoznawane przed ogólnym rdzeniem `dywidend`, więc
słowo w nazwie instrumentu nie tworzy fałszywej wypłaty. Brokerowe skróty walut `NO` i `SG`
są normalizowane odpowiednio do `NOK` i `SGD`; inne kody przechodzą zwykłą walidację.

Ujemne wiersze podatku to pobranie, dodatnie — zwrot; są sumowane ze znakiem, więc zwrot
zmniejsza podatek. Zwrot większy od pobrania jest **błędem** dla tej wypłaty, a nie
sztucznym zwiększeniem odliczenia.
Wypłata i jej pełne odwrócenie są sumowane. Grupa, w której po korektach brutto i podatek
wynoszą zero, jest pomijana z informacją; zerowe brutto z niezerowym podatkiem oraz inne
niespójności pozostają błędami.

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
są tylko policzone w komunikacie. Spośród opłat aplikacja automatycznie importuje wyłącznie
ścisłe, wielojęzyczne warianty **DEGIRO Exchange Connection Fee** i domyślnie uwzględnia
je w kosztach PIT-38. Wiersze opłat transakcyjnych są pomijane, bo są już zawarte w `Total`.
Pozostałe operacje (wpłaty, przewalutowania, odsetki i inne opłaty) są pomijane z podsumowaniem.

Dodatnia korekta connection fee zmniejsza koszt. Grupa całkowicie wyzerowana jest pomijana
z informacją, a zwrot przewyższający opłaty zatrzymuje rozliczenie do ręcznej poprawy.
Rok oraz kurs NBP D-1 wynikają z daty zaksięgowania na rachunku. Koszty rachunkowe zwiększają koszt PIT-38,
ale nie są przypisywane do konkretnego kraju PIT/ZG. Zobacz
[broszurę MF do PIT-38 za 2025 r.](https://www.podatki.gov.pl/media/11079/broszura-do-pit-38-za-2025-r.pdf),
która wymienia wydatki związane z obsługą rachunku maklerskiego wśród możliwych kosztów.
`Capital Return` i `QIE Distribution Capital Gain` nie są automatycznie doliczane do
dywidend; aplikacja pokazuje wyraźne ostrzeżenie, aby zweryfikować je i rozliczyć ręcznie.

Ten format również **nie zawiera kraju** i nie podaje giełdy, więc dla dywidend kraj
proponowany jest z prefiksu ISIN, z ostrzeżeniem. Kraj dywidendy nie jest potrzebny do
PIT/ZG (dywidendy rozlicza się ryczałtem z art. 30a w części G PIT-38), ale bez niego nie
da się ustalić limitu stawki umownej w wariancie zachowawczym.

Gdzie znaleźć: portfel DEGIRO → *Aktywność (Activity)* → *Zestawienie konta
(Account statement)* → *Eksport → CSV*.

> **Wgranie obu plików DEGIRO naraz jest właściwym sposobem użycia.** Transakcje dają
> pozycje zamknięte, zestawienie konta — dywidendy. Można też dograć zestawienia z kilku
> lat: zakupy z lat wcześniejszych posłużą jako koszt dla sprzedaży w rozliczanym roku,
> a powtórzone wiersze zostaną rozpoznane po numerze zlecenia.

> **DEGIRO i IBKR można wgrać w jednym imporcie.** Surowe transakcje są zbierane i
> dopasowywane **osobno dla każdego brokera**: identyfikatory są unikalne tylko u wystawcy,
> a kolejki znaczą co innego — DEGIRO kluczuje je samym ISIN-em, IBKR ISIN-em z walutą.

## Dogrywanie kolejnych plików

Po pierwszym imporcie kolejne pliki można dogrywać z paska ekranu roboczego. Nowa paczka
jest dodawana **atomowo i tylko wtedy, gdy jest niezależna** od dotychczasowych danych:
jeśli dotyka istniejącej kolejki FIFO albo potrzebuje wcześniejszej wypłaty lub podatku,
cały upload jest odrzucany — wtedy wgraj od nowa wszystkie pliki razem. Stabilne
identyfikatory wierszy i znaczniki usunięcia sprawiają, że ręczne poprawki i usunięte
wiersze nie odżywają po ponownym imporcie tego samego pliku.

Limit 5 000 rekordów na jeden import działa jak bezpiecznik: po przekroczeniu import jest
odrzucany w całości, bo częściowy zestaw danych podatkowych jest groźniejszy niż brak
wyniku. Podziel wtedy dane, np. po jednym roku podatkowym.
