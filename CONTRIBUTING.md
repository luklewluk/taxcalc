# Jak współtworzyć

Dzięki za zainteresowanie projektem! Poniżej wszystko, czego potrzebujesz, żeby zacząć.

## Zasada numer jeden: żadnych prawdziwych danych

To narzędzie przetwarza zestawienia maklerskie. **Nigdy** nie dodawaj do repozytorium,
zgłoszenia ani opisu zmiany:

- prawdziwych numerów rachunków (np. `U12345678`, `UXXXXXXXX` to bezpieczny zastępnik),
- imion i nazwisk posiadaczy rachunków,
- prawdziwych kwot, sald czy historii transakcji.

Do testów i przykładów używaj danych fikcyjnych — wzorem są pliki z katalogu `examples/`
(symbole `AAA`, `BBB`, rachunek `UXXXXXXXX`, nazwisko `Jan Przykładowy`).

## Wymagania

- PHP **8.4+** (rozwijane i testowane na 8.5) z rozszerzeniami `ctype`, `dom`, `iconv`,
  `json`, `mbstring`
- [Composer](https://getcomposer.org/) 2.x

## Uruchomienie lokalnie

```bash
composer install
php -S 127.0.0.1:8000 -t public \
  -d upload_max_filesize=6M -d post_max_size=64M \
  -d max_file_uploads=12 -d max_input_vars=120000 -d memory_limit=256M
# http://127.0.0.1:8000
```

Bez tych `-d` domyślny PHP przyjmuje pliki tylko do 2 MB i ucina formularz weryfikacji
po 1000 polach. Pełną tabelę wymaganych dyrektyw ma README, a gotowy zestaw —
`docker/php.ini`.

## Kontrole jakości

Wszystkie poniższe muszą przechodzić przed zgłoszeniem zmiany:

```bash
composer validate --strict          # poprawność composer.json
vendor/bin/phpunit                  # cały zestaw testów
vendor/bin/phpstan analyse          # analiza statyczna, poziom 9
php bin/console lint:container      # spójność kontenera usług
php bin/console lint:twig templates # poprawność szablonów
composer audit                      # znane podatności w zależnościach
```

Skrót: `composer check` uruchamia PHPStan i testy.

## Test-Driven Development

Projekt jest pisany w TDD i oczekujemy tego samego od zmian:

1. Napisz test opisujący brakujące zachowanie.
2. **Uruchom go i zobacz, że pada** — z powodu, którego się spodziewasz.
3. Napisz najprostszą implementację, która go zazieleni.
4. Uruchom cały zestaw testów.

Testy dzielą się na dwa zestawy:

- `tests/Unit` — czysta logika domenowa, bez kontenera i bez wejścia/wyjścia.
- `tests/Functional` — kontroler HTTP i polecenia konsoli przez kernel testowy.

### Testy nigdy nie wychodzą do sieci

Kursy walut w środowisku testowym pochodzą z `App\Tests\Support\FixedNbpRateProvider`
(podmieniany w `config/services.yaml` w sekcji `when@test`). Stałe kursy:

| Waluta | Kurs |
| --- | --- |
| USD | 4.0000 |
| EUR | 4.3000 |
| GBP | 5.0000 |
| CHF | 4.5000 |
| CAD | 3.0000 |

Jeśli piszesz test warstwy HTTP klienta NBP, użyj `Symfony\Component\HttpClient\MockHttpClient`
(przykład: `tests/Unit/CurrencyRate/NbpApiRateProviderTest.php`).

Kernel testowy działa z `APP_DEBUG=0`, żeby strony błędów i logowanie zachowywały się
jak na produkcji. Dlatego `tests/bootstrap.php` przy każdym uruchomieniu kasuje
`var/cache/test` — kontener bez trybu debug nie unieważnia się sam po zmianie kodu.

## Zasady dotyczące kodu

- **Żadnych `float` w liczeniu pieniędzy i ilości.** Wszystko przechodzi przez
  `App\Money\Decimal` i `App\Money\Amount` (arytmetyka dziesiętna na `brick/math`).
  Zaokrąglanie jest zawsze jawne — dzielenie wymaga podania skali.
- **Domena nie wie o HTTP.** Klasy z `src/Tax`, `src/Fifo`, `src/Money`, `src/Import`
  i `src/CurrencyRate` nie mogą zależeć od `Request` ani `Response`.
- **Importery nie rzucają wyjątkami na złe dane.** Błędny wiersz zwraca
  `App\Import\ImportMessage`, żeby jeden zepsuty rekord nie wywracał całego importu.
- **Nic nie zapisujemy z danych użytkownika.** Baza MySQL przechowuje wyłącznie publiczne
  kursy NBP. Jeśli Twoja zmiana miałaby utrwalać cokolwiek pochodzącego z przesłanych
  plików (encja, plik, sesja, cache) — to zmiana modelu prywatności i wymaga osobnej
  dyskusji w zgłoszeniu. Zmiana encji to nowa migracja: `doctrine:migrations:diff`
  na MySQL z `docker compose up -d db`.
- **Zero zewnętrznych zasobów front-endu.** Bez CDN, czcionek Google, bibliotek JS.
  CSS i JS piszemy ręcznie w `public/`, bez kroku budowania.
- Deklaruj `declare(strict_types=1);` w każdym pliku PHP.

## Dodanie obsługi nowego formatu pliku

1. Dodaj wariant do `App\Import\CsvFormat` wraz z etykietą po polsku.
2. Dopisz rozpoznawanie w `App\Import\FormatDetector` (po nagłówkach albo znaczniku sekcji).
3. Napisz importer implementujący `App\Import\Importer\ImporterInterface` — zostanie
   automatycznie wykryty dzięki `#[AutoconfigureTag]`.
4. Dodaj testy: rozpoznanie formatu, poprawny import, błędny wiersz, brakująca kolumna.
5. Dodaj sanityzowany plik przykładowy do `examples/` i wpisz go w
   `App\Controller\ExampleFileController::FILES`.
6. Opisz format w `README.md`.

## Stawki podatkowe

Tabela stawek umownych w `App\Tax\TaxRates` służy wyłącznie do ograniczenia odliczenia
podatku zagranicznego. Jeśli poprawiasz stawkę, **podaj w opisie zmiany źródło**
(artykuł konkretnej umowy o unikaniu podwójnego opodatkowania). Nie dodawajmy krajów
„na wyczucie”.

## Zgłaszanie błędów

Opisz: co zrobiłeś, czego oczekiwałeś, co się stało, wersja PHP i sposób uruchomienia.
Jeśli problem dotyczy konkretnego pliku CSV, załącz **minimalny przykład z danymi
fikcyjnymi**, który odtwarza błąd.

Podatności zgłaszaj zgodnie z [SECURITY.md](SECURITY.md), nie przez publiczne issue.
