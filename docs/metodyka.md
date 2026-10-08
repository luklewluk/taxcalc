# Metodyka podatkowa

Jak kalkulator liczy każdą kwotę i na jakiej podstawie. Skrót jest w
[README](../README.md#jak-liczymy); tu są szczegóły, wzory i źródła.

### Kurs waluty

Każda kwota jest przeliczana **kursem średnim NBP z tabeli A z ostatniego dnia roboczego
poprzedzającego dzień transakcji** (zasada D-1, art. 11a ustawy o PIT). Jeśli NBP nie
publikował kursu w danym dniu (weekend, święto), sprawdzany jest kolejny wcześniejszy dzień
— maksymalnie 10 dni wstecz. W raporcie widać użyty kurs, jego datę i numer tabeli.
Kwoty w PLN są przyjmowane z kursem 1 i **nie wymagają połączenia z NBP**.

Opublikowane tabele A są zapisywane w bazie kwartałami: pierwszy kurs z danego kwartału
pobiera jednym zapytaniem wszystkie waluty ze wszystkich dni tego kwartału, kolejne
czytane są już z bazy. Kursów opublikowanych tabel się nie zmienia, więc nic nie wygasa
i wdrożenie niczego nie kasuje. Gdy baza jest niedostępna, kurs jest pobierany wprost
z NBP — wolniej, ale wynik się nie zmienia.

#### Dzień przeliczenia: data transakcji albo dzień rozliczenia

Art. 11a wiąże kurs z dniem uzyskania przychodu albo poniesienia kosztu. Przy papierach
zdematerializowanych za ten dzień przyjmuje się albo **dzień zawarcia transakcji**, albo
**dzień jej rozliczenia**, kiedy papiery przechodzą na kupującego, a pieniądze trafiają do
sprzedającego. Wybór jest w zakładce **Ustawienia** („Dzień przeliczenia transakcji”):

- **Data transakcji (D+0)** — domyślnie. Kurs z dnia roboczego przed zawarciem transakcji,
  rok podatkowy według daty zawarcia.
- **Dzień rozliczenia wg giełdy (D+1 / D+2)** — dzień rozliczenia liczony w dniach roboczych
  rynku, na którym zawarto transakcję (kraj giełdy z pliku, a gdy go brak — kraj wiersza):
  akcje i ETF-y dwa dni robocze; w USA od 28.05.2024 r. oraz w Kanadzie i Meksyku od
  27.05.2024 r. jeden dzień; w USA i Kanadzie przed 5.09.2017 r. oraz w Europie przed
  6.10.2014 r. trzy dni; w Europie (UE, EOG, Wielka Brytania, Szwajcaria) jeden dzień od
  zaplanowanego 11.10.2027 r. Opcje — jeden dzień.
- **Dzień rozliczenia, kalendarz polski (D+2)** — dwa polskie dni robocze dla akcji i ETF-ów,
  jeden dla opcji, bez względu na rynek.

Przy obu wariantach rozliczenia **rok podatkowy** wyznacza dzień rozliczenia strony
zamykającej pozycję: sprzedaż z 31 grudnia rozliczona w styczniu należy do kolejnego roku.
Koszt przeliczany jest kursem sprzed rozliczenia zakupu, a prowizja od sprzedaży — sprzed
rozliczenia sprzedaży. Dywidendy i opłaty rachunkowe zostają w dniu wypłaty lub pobrania;
wygaśnięcie i przydział opcji nie są transakcjami do rozliczenia, więc zostają w swoim dniu.
Kolejność FIFO zawsze wyznacza czas zawarcia transakcji.

Kalendarze dni wolnych są liczone regułami (stałe daty, święta ruchome od Wielkanocy,
n-ty dzień tygodnia miesiąca, przesunięcia z weekendu): Polska (w tym Wielki Piątek
i 31 grudnia na GPW), USA (święta giełdy i dni wolne systemu rozliczeń), Kanada, Meksyk,
Wielka Brytania, rynki strefy euro (kalendarz TARGET oraz stałe dni zamknięcia, np. 24 i 31
grudnia na Xetrze), Szwajcaria, Dania, Szwecja i Norwegia. Jednorazowe zamknięcia
(np. żałoba narodowa) są pominięte, a na pozostałych rynkach wolne są tylko weekendy — dzień
rozliczenia i kurs mogą wtedy wyjść o dzień wcześniej niż u brokera. Daty rozliczenia widać
w tabeli par i w raporcie CSV.

Jeżeli choć jednego wymaganego kursu nie uda się pobrać, aplikacja działa **fail closed**:
nie pokazuje ani nie eksportuje sum policzonych z pozostałych rekordów, tylko wraca do ekranu
roboczego z komunikatem. Zapobiega to rozliczeniu na
niepełnym zbiorze danych podczas awarii NBP lub przy nieobsługiwanej walucie.

### Akcje i ETF-y (PIT-38 część C)

- Sprzedaże są dopasowywane do zakupów metodą **FIFO** — najstarsze zakupy najpierw.
- **Dopasowanie obejmuje wszystkie wgrane pliki naraz.** Broker wystawia zestawienia
  rocznie, więc zakup z pliku za 2024 r. jest poprawnie łączony ze sprzedażą z pliku
  za 2025 r. Wgraj po prostu oba pliki jednocześnie.
- **Powtórzone transakcje są rozpoznawane** jeszcze przed dopasowaniem (DEGIRO po numerze
  zlecenia, Activity Statement po treści wiersza i jego kolejności w pliku), więc nakładające
  się zestawienia roczne (ten sam zakup w pliku za 2024 i 2025 r.) nie tworzą drugiej partii
  ani fikcyjnej pozycji otwartej.
- Dwie naprawdę identyczne transakcje w jednym pliku **pozostają dwiema pozycjami** —
  zwijanie ich zaniżyłoby koszt albo przychód.
- **Zakupy z wcześniejszych lat pozostają dostępne** dla sprzedaży w rozliczanym roku;
  do rozliczenia trafiają tylko dochody **zrealizowane** w wybranym roku (czyli sprzedaże).
- Przy częściowym zamknięciu partii koszt jest dzielony proporcjonalnie, a ostatnia część
  dostaje dokładną resztę, więc sumy się zgadzają co do grosza.
- Przychód = suma kwot sprzedaży w PLN; koszt = suma kwot zakupu w PLN (zob.
  [prowizje](#prowizje)).
- Uwzględnione samodzielne opłaty rachunkowe zwiększają koszt ogólny, ale pozostają osobną
  pozycją uzgadniającą i nie są przypisywane do kraju PIT/ZG.
- Podatek = **19%** dochodu. Przy stracie podatek wynosi **zero** (nigdy wartości ujemnej).
- PIT/ZG powstaje wyłącznie dla kraju z dodatnim dochodem z art. 30b. Kraj ze stratą
  pozostaje w audycie FIFO z ostrzeżeniem. Dywidendy nie tworzą PIT/ZG ani nie zwiększają
  liczby załączników.

#### Wskazanie partii zamiast FIFO

Art. 30b ust. 7 ustawy o PIT każe przyjąć, że zbyto papiery nabyte najwcześniej — ale tylko
wtedy, gdy nie da się ustalić, które papiery zbyto. Dyrektor KIS w interpretacji
indywidualnej z 13.02.2026 r. (sygn. 0112-KDIL2-1.4011.929.2025.1.TR) uznał, że jeśli broker
pozwala wskazać sprzedawaną partię, podatnik może rozliczyć koszt tej partii zamiast
najstarszej.

- **Domyślnie zawsze FIFO.** Wskazanie jest ręczne: w zakładce FIFO, w mapie partii, przy
  sprzedaży akcji lub ETF-u wybierasz „Zmień partie” i wpisujesz, ile sztuk pochodzi
  z każdej partii otwartej w dniu sprzedaży. Suma musi równać się liczbie sprzedanych sztuk;
  jedną sprzedaż można rozłożyć na kilka partii.
- Wskazana partia musi być w tej samej kolejce FIFO (ten sam broker i papier), kupiona przed
  sprzedażą i mieć w tym momencie dość sztuk — także po uwzględnieniu wcześniejszych
  sprzedaży. Późniejsze sprzedaże bez wskazania biorą najstarsze z tego, co zostało.
- **Wskazanie, które przestało pasować** (partia usunięta, zmieniona albo za mała), blokuje
  wynik z opisem problemu. Kalkulator nigdy nie wraca po cichu do FIFO — zmień partie albo
  przywróć FIFO.
- Opcje zawsze dopasowują się w obrębie serii; wskazanie partii ich nie dotyczy.
- Wskazanie widać w tabeli par („wskazana partia”), w szczegółach transakcji, na wydruku
  i w raporcie CSV (kolumna „Metoda doboru partii” i sekcja „AKTUALNY STAN - WSKAZANE
  PARTIE”).
- Interpretacja indywidualna chroni tylko wnioskodawcę. Korzystaj ze wskazania, gdy broker
  rzeczywiście pozwolił wybrać partię przy zleceniu, i **zachowaj potwierdzenie** tego
  wyboru.

### Opcje (PIT-38 część C)

Opcje z Activity Statement (klasa `Equity and Index Options`) trafiają do tych samych pól
co akcje (art. 30b), ale rozlicza się je **w dniu zamknięcia pozycji** — odkupu lub
sprzedaży zamykającej, wygaśnięcia albo przydziału lub wykonania — nigdy w dniu otwarcia
(art. 17 ust. 1 pkt 10 i ust. 1b, art. 23 ust. 1 pkt 38a ustawy o PIT; interpretacja
Dyrektora KIS 0113-KDIPT2-3.4011.645.2025.3.KKA z 7.11.2025).

| Kwota (kurs NBP z dnia roboczego przed…) | Akcje / opcja kupiona | Opcja wystawiona |
| --- | --- | --- |
| koszt (kupno + prowizja) | dniem kupna (otwarcia) | dniem odkupu lub wygaśnięcia (zamknięcia) |
| przychód (sprzedaż + prowizja sprzedaży) | dniem sprzedaży (zamknięcia) | **dniem zamknięcia** — premia jest przychodem dopiero wtedy (art. 11a ust. 1) |
| prowizja sprzedaży jako koszt zbycia | dniem sprzedaży | dniem wystawienia — dzień poniesienia (art. 11a ust. 2) |
| rok podatkowy | rok sprzedaży | rok zamknięcia |

- **Wygaśnięcie** (`Ep`) i **przydział lub wykonanie z dostawą akcji** (`A`, `Ex`) zamykają
  opcję kwotą 0. Akcje z przydziału wchodzą do FIFO **po cenie wykonania** — premia nie
  zmienia ich kosztu nabycia; rozlicza się ją osobno, w dniu przydziału.
- **Rozliczenie pieniężne** (np. opcje na indeks) z kwotą w wierszu zamyka pozycję jak
  sprzedaż lub odkup. Przydział bez wiersza akcji i bez kwoty przerywa import — kwoty
  rozliczenia nie ma w pliku.
- O tym, czy transakcja otwiera, czy zamyka pozycję, decydują kody IBKR `O`/`C` (`C;O` —
  zamknięcie i otwarcie przeciwnej pozycji resztą). Zamknięcie bez otwarcia w wgranych
  plikach jest pomijane z ostrzeżeniem, tak jak sprzedaż akcji bez zakupu.
- Kraj opcji wynika z giełdy notowania (`CBOE` i inne giełdy opcyjne USA → US).

> **Uwaga na zmianę metody.** Jeśli w latach ubiegłych rozliczałeś premię z wystawionej
> opcji w dniu jej otrzymania, nie rozliczaj tej samej premii ponownie przy zamknięciu.

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
NSA wiążą formalnie w konkretnych sprawach. **Wybór wariantu i jego uzasadnienie należą do
podatnika**: w zakładce **Ustawienia** wybierasz wariant (domyślnie zachowawczy), a wynik,
wersja do wydruku i raport CSV liczą się według niego i pokazują tylko jego. W razie
wątpliwości rozważ wniosek o interpretację indywidualną albo konsultację z doradcą
podatkowym. To narzędzie nie rozstrzyga Twojej indywidualnej
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
nie źródło prawa — jest zdefiniowana w [`src/Tax/TaxRates.php`](../src/Tax/TaxRates.php).
Dla kraju spoza tabeli kalkulator **nie zgaduje stawki**: wariant zachowawczy przyjmuje
odliczenie `0 PLN`, wariant wg NSA pokazuje faktycznie pobrany podatek do limitu 19%,
a wynik zawiera wyraźne ostrzeżenie wymagające weryfikacji umowy. Kraj spoza tabeli można
jednak wybrać — lista rozwijana zawiera dodatkowo każdy kraj, jaki potrafi zaproponować
tablica giełd, oraz każdy poprawny kod już obecny w danych, z etykietą
*poza tabelą umów*.

### Kraj transakcji i kraj dywidendy

- **Kraj transakcji to wybór wykładni, nie usterka.** Przy zbyciu akcji spotyka się dwie
  interpretacje kraju uzyskania dochodu: kraj giełdy, na której doszło do sprzedaży, albo kraj
  rejestracji instrumentu. Kalkulator domyślnie idzie za giełdą, a w zakładce **Ustawienia**
  można to przełączyć — zmiana przelicza propozycje dla transakcji i nie nadpisuje krajów
  wpisanych ręcznie.
- **Kraj dywidendy to kraj siedziby spółki lub funduszu, który ją wypłaca**, bo od niego
  zależy stawka z umowy o unikaniu podwójnego opodatkowania; ustawienie kraju transakcji
  dywidend nie dotyczy. Kalkulator proponuje kraj z prefiksu ISIN — zwykle trafnie, ale kwit
  depozytowy (ADR, GDR) ma ISIN kraju emisji kwitu, najczęściej `US`, choć dywidendę wypłaca
  spółka z innego kraju. Przy takich papierach sprawdź i popraw kraj w zakładce Dywidendy.
  Transakcje i dywidendy tego samego papieru mogą więc mieć **różne** kraje (kanadyjski
  emitent notowany w USA: sprzedaż w USA, dywidenda z Kanady) i nie jest to sprzeczność.
- **Kraj z pliku jest tylko propozycją.** Eksporty nie podają kraju źródła dochodu. Dla
  transakcji kalkulator bierze kraj **giełdy notowania** (Activity Statement: `Listing Exch`;
  DEGIRO: `Giełda referencyjna`, a gdy jej brak `Miejsce wykonania`); dla dywidend z DEGIRO —
  dwa pierwsze znaki numeru ISIN, bo zestawienie konta nie podaje giełdy. Kraj notowania też
  nie musi być krajem źródła dochodu: chiński emitent notowany w Hongkongu wypłaca z Chin.
  Platformy wielorynkowe (`CEUX`, `TQEX`, `XOFF`) i kody nierozpoznane nie proponują nic —
  pole zostaje puste. Gdy ten sam ISIN ma w pliku dwie różne giełdy, propozycja jest
  wycofywana: jeden papier ma jeden kraj. Prefiksy ISIN, które nie są kodem kraju (`XS…`,
  `EU`, `QZ`), zostawiają pole puste. Cyfra kontrolna ISIN nie jest weryfikowana.
- **Kraj jest wymagany przed wynikiem.** Pusty kraj zgłasza zakładka „Wymaga uwagi” — raz na
  instrument, z polem wyboru i przyciskiem „Zastosuj do wszystkich (N)”, który uzupełnia
  wszystkie puste wiersze tego papieru. Gdy wiersze jednego instrumentu mają różne kraje, ten
  sam panel proponuje „Ujednolić kraj we wszystkich”. Uzupełnianie pustych nigdy nie nadpisuje
  tego, co wpisałeś ręcznie.
- Kraj dywidendy nie jest potrzebny do PIT/ZG (dywidendy rozlicza się ryczałtem z art. 30a
  w części G PIT-38), ale bez niego nie da się ustalić limitu stawki umownej w wariancie
  zachowawczym.

### Prowizje

Kwotą transakcji jest gotówka, która faktycznie wyszła z rachunku lub na niego weszła
(Activity Statement: `Proceeds + Comm/Fee`, DEGIRO: `Total`), więc prowizja jest w niej
zawsze. Prowizja **zakupu** jest przez to w koszcie nabycia. Prowizję i AutoFX **sprzedaży**
broker już potrącił z wpływu, dlatego kalkulator dolicza je z powrotem do przychodu
(przychodem jest kwota należna, art. 17 ust. 1 pkt 6 lit. a) i jednocześnie ujmuje jako koszt
uzyskania przychodu (art. 22, art. 23 ust. 1 pkt 38) po kursie z dnia sprzedaży. Dochód
i podatek się nie zmieniają — zmienia się tylko rozbicie na pola przychodu i kosztów.

Gdy plik nie podaje opłaty sprzedaży osobno (puste pole opłaty w DEGIRO, zlecenie sklejone
z transz o niespójnych opłatach), przychód zostaje kwotą rozliczoną, a przypis w zakładce FIFO
to mówi. Opłata nigdy nie jest wyliczana jako różnica `kwota − liczba × kurs`. Osobnych
wierszy prowizyjnych nie sumujemy.

Starsze eksporty DEGIRO mogły nie zawierać prowizji AutoFX w kwocie `Total` (DEGIRO to
w przeszłości potwierdzał). Kalkulator liczy dokładnie to, co jest w pliku — jeśli płaciłeś
za przewalutowanie, zweryfikuj kwoty w zestawieniu konta.

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

Ekran roboczy dobiera wersję i numery pól do roku. Wyświetlane wartości są opisane jako
**wkład z zaimportowanych danych**, a nie kompletne zeznanie: straty z lat ubiegłych, inne
PIT-8C, krypto i zagraniczny podatek od zysków kapitałowych pozostają „brak danych”.

| Rok | Formularze | Źródło akcji | Suma / dochód-strata / podatek | Dywidendy | Podatek łącznie / PIT-ZG | PIT/ZG dochód / podatek |
| --- | --- | --- | --- | --- | --- | --- |
| 2021 | PIT-38(15), PIT/ZG(7) | 22/23 | 24–27, 29–33 | 45–47 | 49 / 69 | 32/33 |
| 2022 | PIT-38(16), PIT/ZG(7) | 22/23 | 24–27, 29–33 | 45–47 | 49 / 69 | 32/33 |
| 2023 | PIT-38(16), PIT/ZG(8) | 22/23 | 24–27, 29–33 | 45–47 | 49 / 69 | 29/30 |
| 2024 | PIT-38(17), PIT/ZG(8) | 22/23 | 24–27, 29–33 | 45–47 | 49 / 69 | 29/30 |
| 2025 | PIT-38(18), PIT/ZG(8) | 22/23 | 26–29, 31–35 | 47–49 | 51 / 72 | 29/30 |
| 2026 | prowizorycznie jak 2025 | jak 2025 | jak 2025 | jak 2025 | jak 2025 | jak 2025 |

Dla 2026 aplikacja stale ostrzega, że oficjalny wzór nie został jeszcze opublikowany i
numery mogą się zmienić. Interfejs oferuje wyłącznie lata 2021–2026. Aktualne wzory:
[PIT-38](https://www.gov.pl/web/finanse/pit-38) i [PIT/ZG](https://www.gov.pl/web/finanse/pitzg).
