# Polityka bezpieczeństwa

## Zgłaszanie podatności

Jeśli znajdziesz lukę bezpieczeństwa, **nie zakładaj publicznego zgłoszenia (issue)**.
Napisz prywatnie przez funkcję *Security Advisory* w repozytorium GitHub
(zakładka **Security → Report a vulnerability**).

W zgłoszeniu opisz:

- na czym polega problem i jaki ma wpływ,
- kroki do odtworzenia (jeśli używasz pliku CSV, przygotuj wersję z danymi fikcyjnymi),
- wersję aplikacji, PHP i sposób uruchomienia (lokalnie / Docker).

Postaramy się potwierdzić otrzymanie zgłoszenia w ciągu 7 dni i opisać plan naprawy
w ciągu 30 dni. Poprawki wydajemy jako nowe wersje wraz z opisem w advisory.

**Nigdy nie dołączaj prawdziwych danych finansowych ani numerów rachunków maklerskich**
do zgłoszenia, załączników czy zrzutów ekranu.

## Zakres

W zakresie są w szczególności:

- obejście walidacji przesyłanych plików (typ, rozmiar, zawartość),
- odczyt lub zapis plików poza dozwolonymi ścieżkami,
- XSS, CSRF, wstrzyknięcie formuł do eksportu CSV,
- wyciek danych między żądaniami lub użytkownikami,
- ujawnienie danych wrażliwych w komunikatach błędów, logach lub nagłówkach.

Poza zakresem są zwykle:

- błędy rachunkowe i interpretacja przepisów podatkowych — to zgłoszenia funkcjonalne
  (zwykłe issue), nie podatności,
- brak nagłówków bezpieczeństwa w konfiguracji, którą wdrażający sam nadpisał,
- podatności w zależnościach bez wpływu na tę aplikację (zgłoś je u źródła).

## Model bezpieczeństwa i prywatności

Ta aplikacja została zaprojektowana tak, aby **nie przechowywać żadnych danych finansowych**:

| Obszar | Rozwiązanie |
| --- | --- |
| Baza danych | MySQL wyłącznie z publicznymi tabelami kursów NBP. Żaden przesłany plik, wiersz ani wynik nie jest encją i nie trafia do bazy. |
| Przesłane pliki | Odczytywane raz z tymczasowego pliku PHP, który jest natychmiast usuwany (`unlink`). Zawartość żyje wyłącznie w pamięci procesu obsługującego żądanie. |
| Katalogi aplikacji | Nic z danych użytkownika nie trafia do `var/`, `public/` ani żadnego innego katalogu projektu. |
| Sesja | Wyłącznie token CSRF. Żadnych kwot, symboli ani dat. |
| Stan między żądaniami | Wiersze wracają jako pola formularza w przeglądarce użytkownika — serwer jest bezstanowy. |
| Nagłówki odpowiedzi | `Cache-Control: no-store` na każdej odpowiedzi. |
| Analityka / zewnętrzne zasoby | Brak. CSP `default-src 'self'` bez wyjątków. |

### Mechanizmy obronne

- **CSRF** — każdy endpoint zmieniający stan (`/kalkulator/import`, `/kalkulator/wynik`,
  `/kalkulator/raport`, `/kalkulator/raport.csv`) jest metodą POST i wymaga poprawnego tokenu.
- **Walidacja przesyłanych plików** — limit liczby plików i rozmiaru, dozwolone rozszerzenia
  (`.csv`, `.txt`), dozwolone typy MIME, odrzucanie bajtów NUL, znaków sterujących,
  niepoprawnego UTF-8 oraz plików bez separatora w nagłówku.
- **Brak dowolnych ścieżek** — pliki przykładowe są serwowane z listy dozwolonych nazw,
  a nie przez sklejanie ścieżki. Nazwy przesłanych plików są sprowadzane do samej nazwy bazowej.
- **Bezpieczny eksport CSV** — komórki zaczynające się od `=`, `+`, `-`, `@`, tabulatora lub CR
  są poprzedzane apostrofem, żeby arkusz nie potraktował ich jako formuły. Zwykłe liczby
  (w tym ujemne) pozostają liczbami.
- **Szablony** — automatyczne escapowanie Twiga, bez `|raw` na danych użytkownika.
- **Nagłówki** — `Content-Security-Policy`, `X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-*` oraz `Strict-Transport-Security`
  wyłącznie na połączeniach HTTPS (patrz „HTTPS i odwrotne proxy”).
- **Walidacja wartości** — kwoty zakupu, sprzedaży i dywidendy brutto muszą być dodatnie,
  liczba sztuk dodatnia, podatek u źródła nieujemny, a kod kraju dwuliterowy. Reguły są
  wymuszane w modelu domenowym, więc obowiązują tak samo przy imporcie pliku, jak i przy
  spreparowanym POST z ekranu weryfikacji.
- **Limity wejścia** — maksymalnie 5 000 rekordów na import i 50 000 wierszy na plik.
  Po przekroczeniu import jest odrzucany w całości (fail closed), a nie obcinany.
- **Atomowy import wielu plików** — błąd, odrzucenie lub nieczytelność choć jednego pliku
  unieważnia cały batch. Aplikacja nie liczy podatku z „tych plików, które się udały”.
- **Walidacja odpowiedzi NBP** — kurs musi być poprawną dodatnią liczbą, a data notowania
  prawidłową datą dokładnie odpowiadającą żądanemu dniowi; każda rozbieżność blokuje cały raport.
- **Komunikaty błędów** — własne strony błędów bez śladów stosu i ścieżek serwera;
  na produkcji `APP_DEBUG=0`.

## Wdrożenie produkcyjne

- Ustaw `APP_ENV=prod` i `APP_DEBUG=0`.
- Ustaw losowy `APP_SECRET` (np. `php -r 'echo bin2hex(random_bytes(16)), PHP_EOL;'`).
- Nie zapisuj produkcyjnych sekretów w `.env` przed budowaniem obrazu. Plik `.env` jest
  wykluczony z kontekstu Dockera; ustawienia wdrożenia przekazuj jako zmienne środowiskowe
  lub przez mechanizm Docker/Kubernetes secrets.
- Nie loguj treści żądań POST ani ciał odpowiedzi.
- Limity PHP ustaw zgodnie z `docker/php.ini` (patrz README, sekcja „Wymagane ustawienia PHP”).

### `TRUSTED_HOSTS` — wymagane przy publicznym wystawieniu

Bez tego ustawienia aplikacja przyjmuje dowolny nagłówek `Host`, co pozwala na zatrucie
generowanych adresów URL (*Host header injection*) i podszycie się pod Twoją domenę
w treści odpowiedzi.

Ustaw wyrażenie regularne pasujące **wyłącznie** do hostów, pod którymi serwis działa:

```bash
TRUSTED_HOSTS='^kalkulator\.example\.com$'
# kilka hostów:
TRUSTED_HOSTS='^(kalkulator|www)\.example\.com$'
```

Lokalnie zostaw wartość pustą — domyślna konfiguracja nie wymaga zmian i nic nie psuje.

### HTTPS i odwrotne proxy

Nagłówek `Strict-Transport-Security` (HSTS, `max-age=31536000; includeSubDomains`) jest
wysyłany **tylko wtedy, gdy żądanie jest rozpoznane jako HTTPS**. To celowe: HSTS jest
zobowiązaniem jednokierunkowym — przeglądarka, która je zobaczy, przez rok odmówi
połączenia po HTTP. Wysłanie go z lokalnego serwera `php -S` zablokowałoby dostęp do
`localhost`.

Jeśli TLS kończy się na odwrotnym proxy (nginx, Traefik, Cloudflare, load balancer),
aplikacja widzi zwykłe HTTP i **HSTS nie zostanie wysłany**, dopóki nie zaufasz nagłówkom
przekazywanym przez proxy. Skonfiguruj oba końce:

1. **Proxy** musi ustawiać `X-Forwarded-Proto: https` (oraz `X-Forwarded-Host`,
   `X-Forwarded-Port`) i **usuwać** te nagłówki przychodzące z zewnątrz, żeby klient nie
   mógł ich podrobić. Dla nginx:

   ```nginx
   proxy_set_header X-Forwarded-Proto $scheme;
   proxy_set_header X-Forwarded-Host  $host;
   proxy_set_header X-Forwarded-Port  $server_port;
   ```

2. **Aplikacja** musi ufać adresowi proxy. W `config/packages/framework.yaml` (albo przez
   zmienną środowiskową) ustaw:

   ```yaml
   framework:
       trusted_proxies: '192.168.0.0/16'   # adres(y) Twojego proxy, nigdy 0.0.0.0/0
       trusted_headers: ['x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-port']
   ```

   Ufaj wyłącznie konkretnym adresom proxy. `trusted_proxies` ustawione na cały internet
   pozwala dowolnemu klientowi udawać HTTPS i podmienić host.

Alternatywnie HSTS możesz wysyłać z samego proxy — wtedy powyższa konfiguracja nie jest
potrzebna do tego celu (ale nadal bywa potrzebna do poprawnego generowania URL-i).
Nie ustawiamy dyrektywy `preload`: wpis na listę preload jest praktycznie nieodwracalny
i powinien być świadomą decyzją operatora, a nie domyślną aplikacji.
