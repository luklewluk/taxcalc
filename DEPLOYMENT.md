# Wdrożenie — GitHub Actions → Deployer → własny serwer

Każdy push do `main` uruchamia testy (`.github/workflows/ci.yml`). Gdy przejdą, a wdrożenie
jest włączone, ten sam workflow wdraża aplikację na serwer [Deployerem](https://deployer.org)
(`deploy.php`, recipe Symfony). Serwer pobiera kod z publicznego repozytorium, instaluje
zależności i przełącza symlink `current` na nowe wydanie — bez sudo i bez restartu PHP-FPM.

**Repozytorium nie zawiera żadnych danych serwera.** Adres, użytkownik, ścieżka i klucz SSH
są sekretami GitHub Environment `deployment`. Pull requesty, także z forków, ich nie
dostają, a GitHub maskuje je w logach.

Instrukcja dotyczy serwera z Ubuntu (np. droplet DigitalOcean) z nginx i PHP-FPM, bez
panelu. W przykładach `example.com`, użytkownik `taxcalc` i katalog `/var/www/taxcalc`
zastąp własnymi wartościami.

## 1. Pakiety

```bash
sudo add-apt-repository ppa:ondrej/php   # PHP 8.4, jeśli dystrybucja ma starsze
sudo apt update
sudo apt install nginx git unzip \
  php8.4-fpm php8.4-cli php8.4-mbstring php8.4-xml php8.4-opcache php8.4-mysql \
  mysql-server certbot python3-certbot-nginx
# Composer 2:
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw enable
```

Aplikacja nie potrzebuje kolejki ani Redisa. Baza MySQL (8.4 albo MariaDB — wystarczy ta,
którą serwer już ma) przechowuje **wyłącznie publiczne kursy NBP**, nigdy dane użytkowników.
Wymagane rozszerzenia (`ctype`, `dom`, `iconv`, `json`, `mbstring`, `pdo_mysql`) dają
pakiety `php8.4-cli`, `php8.4-xml`, `php8.4-mbstring` i `php8.4-mysql`.

Baza i użytkownik z prawami tylko do niej:

```bash
sudo mysql <<'SQL'
CREATE DATABASE taxcalc CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'taxcalc'@'localhost' IDENTIFIED BY 'wygeneruj-dlugie-haslo';
GRANT ALL PRIVILEGES ON taxcalc.* TO 'taxcalc'@'localhost';
SQL
```

Tabele zakłada Deployer przy każdym wdrożeniu (`database:migrate`, migracje Doctrine
z katalogu `migrations/`) i od razu sprawdza je `doctrine:schema:validate`
(`database:validate`), zanim `current` przełączy się na nowe wydanie. Gdy schemat nie zgadza
się z encjami, wdrożenie się zatrzymuje, a dotychczasowe wydanie działa dalej.

## 2. Użytkownik wdrożeniowy

```bash
sudo adduser --disabled-password --gecos '' taxcalc
sudo mkdir -p /var/www/taxcalc && sudo chown taxcalc:taxcalc /var/www/taxcalc

# Osobny klucz tylko do wdrożeń - wygeneruj go lokalnie, nie na serwerze:
#   ssh-keygen -t ed25519 -C taxcalc-deploy -f taxcalc_deploy -N ''
sudo -u taxcalc mkdir -m 700 -p /home/taxcalc/.ssh
sudo -u taxcalc tee /home/taxcalc/.ssh/authorized_keys < taxcalc_deploy.pub
sudo chmod 600 /home/taxcalc/.ssh/authorized_keys
```

Użytkownik wdrożeniowy **nie dostaje sudo**. Nie musi przeładowywać PHP-FPM: vhost
przekazuje `$realpath_root`, więc każde wydanie to nowa ścieżka i opcache kompiluje je od
razu. Wpisy starych wydań zostają w pamięci opcache do najbliższego restartu PHP-FPM (np.
przy aktualizacji PHP). Jeśli po wielu wdrożeniach zabraknie pamięci, administrator może
przeładować pulę ręcznie: `sudo systemctl reload php8.4-fpm`.

## 3. PHP-FPM i nginx

```bash
sudo cp deploy/php-fpm/taxcalc.conf.example /etc/php/8.4/fpm/pool.d/taxcalc.conf
sudo cp deploy/nginx/taxcalc.conf.example  /etc/nginx/sites-available/taxcalc
# popraw domenę, użytkownika i ścieżki w obu plikach, potem:
sudo ln -s /etc/nginx/sites-available/taxcalc /etc/nginx/sites-enabled/
sudo systemctl reload php8.4-fpm
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d example.com -d www.example.com
```

Na co zwrócić uwagę:

- **`max_input_vars = 120000` jest krytyczne.** Workbench przesyła cały stan w formularzu,
  bo serwer niczego nie przechowuje, a PHP po cichu ucina nadmiarowe pola. Przy domyślnym
  1000 formularz z ponad ~40 transakcjami dociera niekompletny i obliczenie się zatrzymuje.
  Pula FPM ustawia to sama; ustawienia odpowiadają `docker/php.ini`. Ten sam limit (oraz
  `post_max_size` i `upload_max_filesize`) wdraża też `public/.user.ini`, więc działa nawet
  bez konfiguracji puli — PHP-FPM czyta go sam, w ciągu 5 minut, bez przeładowania. Wartość
  ustawiona w puli przez `php_admin_value` ma pierwszeństwo, a reguła `location ~ /\.` w
  vhoście nie pozwala pobrać tego pliku.
- **`expires 1y` + `immutable` dla CSS, JS i obrazów jest bezpieczne:** aplikacja dokleja do
  każdego adresu hash treści pliku (`app.css?v=…`), więc zmieniony plik ma nowy adres, a
  przeglądarka nigdy nie łączy nowego HTML ze starym arkuszem stylów.
- **Logi nie zawierają query stringów ani ciał żądań** (`log_format taxcalc_minimal`),
  bo mogłyby nieść dane finansowe.
- `fastcgi_param SCRIPT_FILENAME $realpath_root…` sprawia, że PHP widzi ścieżkę nowego
  wydania zamiast symlinku.
- TLS kończy się na tym nginx, więc aplikacja sama wie o HTTPS i wysyła HSTS. Za
  Cloudflare w trybie proxy lub innym odwrotnym proxy skonfiguruj zaufane proxy wg
  [SECURITY.md](SECURITY.md).

## 4. Konfiguracja aplikacji

```bash
sudo -u taxcalc mkdir -p /var/www/taxcalc/shared
sudo -u taxcalc cp deploy/env.local.example /var/www/taxcalc/shared/.env.local
sudo -u taxcalc chmod 600 /var/www/taxcalc/shared/.env.local
# uzupełnij APP_SECRET (php -r 'echo bin2hex(random_bytes(16)), PHP_EOL;'),
# TRUSTED_HOSTS, DEFAULT_URI, APP_PUBLIC_URL i DATABASE_URL (hasło z kroku 1;
# na MariaDB serverVersion=mariadb-<wersja>, np. mariadb-11.4.2)
```

Deployer podlinkuje ten plik do każdego wydania (`shared_files` z recipe Symfony).

## 5. GitHub

W repozytorium: **Settings → Environments → New environment → `deployment`**.

| Rodzaj | Nazwa | Wartość |
| --- | --- | --- |
| Sekret | `DEPLOY_HOST` | IP lub nazwa hosta serwera |
| Sekret | `DEPLOY_USER` | `taxcalc` |
| Sekret | `DEPLOY_PATH` | `/var/www/taxcalc` |
| Sekret | `DEPLOY_SSH_KEY` | zawartość prywatnego `taxcalc_deploy` |
| Sekret | `DEPLOY_KNOWN_HOSTS` | wynik `ssh-keyscan -H <host>` (sprawdź odcisk z konsolą DigitalOcean) |
| Zmienna | `PUBLIC_URL` | `https://example.com` (smoke test po wdrożeniu) |
| Zmienna (opcj.) | `DEPLOY_PHP`, `DEPLOY_COMPOSER` | gdy PHP nie jest w `/usr/bin/php8.4` albo composer nie jest w `PATH` użytkownika (domyślnie wykrywany przez `which composer`) |

W ustawieniach Environment możesz ograniczyć wdrożenia do gałęzi `main`
(*Deployment branches and tags → Selected branches*).

Na koniec **Settings → Secrets and variables → Actions → Variables** dodaj zmienną
repozytorium **`DEPLOY_ENABLED` = `true`**. Dopóki jej nie ma, workflow tylko testuje.

## 6. Pierwsze wdrożenie

Wypchnij commit na `main` albo w zakładce **Actions → CI → Run workflow** uruchom workflow
ręcznie. Po wdrożeniu workflow sprawdza, czy `PUBLIC_URL/kalkulator` odpowiada i wysyła
nagłówek CSP.

Sprawdź ręcznie:

```bash
curl -I https://example.com        # Content-Security-Policy, Strict-Transport-Security
```

Potem wgraj na stronie plik z repozytorium, np. `examples/ibkr-activity-statement.csv`.

## Wdrożenie z własnego komputera i rollback

`deploy.php` czyta dane serwera ze zmiennych środowiskowych:

```bash
export DEPLOY_HOST=… DEPLOY_USER=taxcalc DEPLOY_PATH=/var/www/taxcalc
dep deploy prod      # wdrożenie
dep rollback prod    # powrót do poprzedniego wydania (Deployer trzyma 3)
```

## Co jest na serwerze

- `releases/` — ostatnie 3 wydania; `current` — symlink do aktywnego.
- `shared/.env.local` — konfiguracja.
- `var/` w wydaniu — cache Symfony; ginie z każdym wydaniem i nic w nim nie musi przetrwać.
- baza MySQL `taxcalc` — tabele A NBP pobrane kwartałami (`nbp_rate`, `nbp_coverage`)
  i tabela wersji migracji. Przetrwa każde wdrożenie, a w razie potrzeby można ją wyczyścić:
  aplikacja pobierze kursy ponownie.

Aplikacja nie zapisuje żadnych danych użytkowników — ani w bazie, ani na dysku: przesłane
pliki są czytane z pamięci, a tymczasowy plik PHP jest natychmiast usuwany.
