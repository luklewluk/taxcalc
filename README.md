<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset=".github/taxcalc-logo-dark.png">
    <img src=".github/taxcalc-logo.png" alt="TaxCalc.pl" width="420">
  </picture>
</p>

# TaxCalc.pl — kalkulator PIT-38 (akcje, opcje, dywidendy)

Darmowy, otwartoźródłowy kalkulator podatku od zysków giełdowych, opcji i dywidend dla
polskich podatników: **[taxcalc.pl](https://taxcalc.pl)**. Wgrywasz zestawienie od brokera,
a kalkulator przelicza kwoty po kursach NBP, dopasowuje sprzedaże do zakupów metodą FIFO
i podaje wartości do wpisania w **PIT-38** i **PIT/ZG**. Twoje dane nigdzie nie są zapisywane.

> ### ⚠️ Zastrzeżenie
>
> To narzędzie **edukacyjno-pomocnicze i nie stanowi porady podatkowej**. Wyniki należy
> samodzielnie zweryfikować z aktualnymi przepisami oraz obowiązującymi w danym roku
> formularzami PIT-38 i PIT/ZG. Autorzy nie ponoszą odpowiedzialności za rozliczenia
> wykonane na podstawie tych wyliczeń. W razie wątpliwości skonsultuj się z doradcą
> podatkowym.

- [Co potrafi](#co-potrafi)
- [Prywatność](#prywatność)
- [Jak zacząć](#jak-zacząć)
- [Skąd wziąć pliki](#skąd-wziąć-pliki)
- [Jak liczymy](#jak-liczymy)
- [Ograniczenia](#ograniczenia)
- [Uruchom u siebie](#uruchom-u-siebie)
- [Współtworzenie i licencja](#współtworzenie-i-licencja)

## Co potrafi

- Czyta eksporty **Interactive Brokers** (Activity Statement) i **DEGIRO** (Transakcje
  oraz Zestawienie konta) — także oba brokery naraz i pliki z wielu lat.
- Rozlicza **akcje i ETF-y**, **opcje** oraz **dywidendy** z podatkiem pobranym za granicą.
- Dopasowuje sprzedaże do zakupów metodą **FIFO** przez wszystkie wgrane lata — zakup
  z 2022 r. pokrywa sprzedaż z 2025 r.
- Przelicza każdą kwotę **kursem średnim NBP z dnia roboczego przed transakcją**.
- Podaje numery pól **PIT-38** i **PIT/ZG** właściwe dla wybranego roku (2021–2026).
- Pozwala wybrać **wariant odliczenia** podatku od dywidend — stanowisko urzędu (KIS)
  albo orzecznictwo NSA — bo kwestia jest sporna; wynik pokazuje wybrany wariant.
- Wynik pobierzesz jako **raport CSV** albo wydrukujesz.

## Prywatność

Zestawienie maklerskie to bardzo wrażliwe dane: pełna historia transakcji, salda i numer
rachunku. Kalkulator jest zbudowany tak, żeby **nigdzie ich nie zapisywać**:

- przesłany plik jest odczytywany raz i natychmiast usuwany,
- w bazie danych są wyłącznie publiczne kursy NBP — żadnych Twoich transakcji ani wyników,
- sesja przechowuje tylko token zabezpieczający formularz,
- między kolejnymi krokami dane wracają do Twojej przeglądarki jako pola formularza —
  zamknięcie karty je kasuje,
- zero analityki, zewnętrznych skryptów, czcionek i obrazów; jedyny ruch wychodzący to
  pobieranie kursów z `api.nbp.pl`,
- jedyna rzecz zapamiętana w przeglądarce to wybrany motyw kolorów.

Szczegóły i mechanizmy obronne: [SECURITY.md](SECURITY.md).

## Jak zacząć

1. **Pobierz pliki od brokera** — za każdy rok od pierwszego zakupu papierów, które
   sprzedawałeś (zob. [niżej](#skąd-wziąć-pliki)).
2. **Wgraj je naraz** na [taxcalc.pl/kalkulator](https://taxcalc.pl/kalkulator) i wybierz
   rok podatkowy. Format każdego pliku jest rozpoznawany automatycznie.
3. **Sprawdź zakładkę „Wymaga uwagi”.** Są tam rzeczy, które musisz uzupełnić przed
   wynikiem — najczęściej kraj dochodu, który kalkulator tylko proponuje.
4. **Przepisz wartości** z zakładki **PIT-38 / PIT-ZG** do formularzy.

Chcesz najpierw zobaczyć, jak to wygląda? Przycisk **Symulacja** na stronie głównej otwiera
ekran roboczy z fikcyjnymi danymi: akcje i ETF-y z kilku giełd i walut, opcje call i put
(kupione i wystawione) oraz dywidendy — bez wgrywania czegokolwiek.

Pozostałe zakładki pozwalają wszystko sprawdzić i poprawić: **Transakcje** (każda
transakcja z dopasowanymi partiami, kursami i dochodem; edycja), **FIFO** (pary
zakup–sprzedaż), **Dywidendy** (wypłaty i podsumowanie części G), **Opłaty** (koszty
rachunku) i **Ustawienia** (wybory, przy których przepisy dopuszczają więcej niż jedno
odczytanie).

## Skąd wziąć pliki

**Interactive Brokers — Activity Statement.** Portal IBKR → *Performance & Reports →
Statements* → *Activity Statement* → *Period: Annual*, wybierz rok, format *CSV*, język
*English*. Pobierz osobny plik za każdy rok. Jeden plik zawiera transakcje, opcje,
dywidendy i podatek u źródła.

**DEGIRO — dwa pliki.** Portfel → *Aktywność*:

- *Transakcje* → zakres dat obejmujący także lata zakupów → *Eksport → CSV*,
- *Zestawienie konta* → *Eksport → CSV* (dywidendy i podatek u źródła).

Eksporty brokerów nie podają kraju źródła dochodu. Kalkulator **proponuje** go z giełdy
notowania (dla dywidend z DEGIRO — z numeru ISIN), ale to tylko propozycja: sprawdź ją przed
przepisaniem wyniku.

Przykłady z fikcyjnymi danymi są w katalogu [`examples/`](examples/). Jak dokładnie czytany
jest każdy eksport: [docs/formaty.md](docs/formaty.md).

## Jak liczymy

- **Kurs:** średni NBP (tabela A) z ostatniego dnia roboczego przed transakcją lub wypłatą
  (art. 11a ustawy o PIT).
- **Akcje i ETF-y:** FIFO — najstarsze zakupy najpierw, przez wszystkie wgrane lata.
  Do rozliczenia trafiają sprzedaże z wybranego roku. Podatek to 19% dochodu; strata daje
  podatek zero.
- **Opcje:** rozliczane w dniu **zamknięcia** pozycji (odkup, sprzedaż, wygaśnięcie,
  przydział), nigdy w dniu otwarcia — zgodnie z interpretacją KIS
  0113-KDIPT2-3.4011.645.2025.3.KKA.
- **Dywidendy:** podatek polski to 19% kwoty brutto, od którego odlicza się podatek pobrany
  za granicą. Ile wolno odliczyć — jest sporne, więc wariant wybierasz sam w Ustawieniach
  (domyślnie KIS). Różnica na przykładzie:

  | Dywidenda z USA: 100 USD brutto, 30 USD pobrane, kurs 4,00 | Wariant KIS | Wariant NSA |
  | --- | --- | --- |
  | Podatek polski 19% | 76,00 PLN | 76,00 PLN |
  | Do odliczenia | 60,00 PLN (stawka umowna 15%) | 76,00 PLN (do 19%) |
  | **Do zapłaty** | **16,00 PLN** | **0,00 PLN** |

  Wariant KIS to stanowisko organów podatkowych; wariant NSA wynika z wyroków II FSK 1171/22
  i II FSK 1302/22. Pola PIT, podsumowanie, wydruk i raport CSV liczą się według wybranego
  wariantu i pokazują tylko jego.
- **Zaokrąglanie:** arytmetyka dziesiętna bez błędów zmiennoprzecinkowych; podatek jest
  liczony dokładnie, sumowany i zaokrąglany raz.

Wzory, źródła, tabela stawek umownych i numery pól formularzy: [docs/metodyka.md](docs/metodyka.md).

## Ograniczenia

- **To nie jest porada podatkowa** i nie zastępuje weryfikacji przepisów.
- **Nie rozlicza zdarzeń korporacyjnych** (splity, połączenia, spin-offy, przydziały).
  Plik z taką operacją jest odrzucany z nazwą instrumentu — rozlicz go osobno.
- **Nie rozlicza futures, obligacji, CFD, warrantów ani krótkiej sprzedaży akcji.** Takie
  wiersze są pomijane i zgłaszane w „Wymaga uwagi”.
- **Sprzedaż bez zakupu** w wgranych plikach (zwykle brakuje wcześniejszego roku) jest
  pomijana w wyniku i wskazana w „Wymaga uwagi” — dograj starszy wyciąg.
- **Nie rozlicza pozycji kupionej i sprzedanej w różnych walutach** — zgłasza ją jako błąd.
- **Nie przenosi strat z lat ubiegłych** i nie liczy różnic kursowych na rachunku walutowym
  ani odsetek.
- **Tabela stawek umownych jest uproszczona** — zweryfikuj stawkę dla swojej sytuacji.
- **Nie tworzy e-Deklaracji ani PDF-a formularza** — dostajesz wartości do wpisania,
  raport CSV i wersję do wydruku.
- Jeden import przyjmuje do 10 plików po 5 MB i do 5 000 rekordów.

## Uruchom u siebie

Kalkulator możesz uruchomić na własnym komputerze w Dockerze:

```bash
APP_SECRET=$(openssl rand -hex 16) docker compose up --build
# http://127.0.0.1:8080
```

Rozwój i uruchomienie bez Dockera: [CONTRIBUTING.md](CONTRIBUTING.md). Wdrożenie na
serwer: [DEPLOYMENT.md](DEPLOYMENT.md). Przed wystawieniem publicznie przeczytaj
[SECURITY.md](SECURITY.md).

## Współtworzenie i licencja

Zgłoszenia i poprawki są mile widziane — zacznij od [CONTRIBUTING.md](CONTRIBUTING.md).
Błędy zgłaszaj w [Issues](https://github.com/luklewluk/taxcalc/issues), podatności zgodnie
z [SECURITY.md](SECURITY.md).

Kod jest na licencji [MIT](LICENSE).

### Znaki towarowe

Loga Interactive Brokers i DEGIRO (`templates/_partials/logos/`) są znakami towarowymi
ich właścicieli i nie są objęte licencją MIT. Strona pokazuje je wyłącznie po to, by
wskazać, z eksportami których brokerów kalkulator współpracuje; projekt nie jest z nimi
powiązany. Źródła: logo IBKR ze strony
[interactivebrokers.com/design](https://www.interactivebrokers.com/design/assets-logos-ibkr.php),
logo DEGIRO z [Wikimedia Commons](https://commons.wikimedia.org/wiki/File:Degiro_logo.svg).
Kształty i kolory znaków pozostały bez zmian. Pliki osadzono bezpośrednio w stronie,
dlatego napisy przyjmują kolor motywu: w jasnym są takie jak w oryginale, w ciemnym białe.
Białe wypełnienia liter DEGIRO zastąpiono prawdziwymi otworami. Usunięto też arkusz
stylów (zastąpiony atrybutami `fill`) i metadane edytora.
