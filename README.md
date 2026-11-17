# nasze zeszyty ❤️

Aplikacja „naszych zeszytów": notatki (dwa zeszyty — „mój" i „twój"), wspólna
galeria zdjęć i nagrań, odliczanie do spotkania, data poznania się, nastrój dnia
oraz sekrety odblokowywane w dniu spotkania.

Backend jest w całości w **PHP** (brak Pythona), a dane trzymane są w zewnętrznej
bazie **MariaDB / MySQL**.

## Wymagania

- PHP **8.1+** z rozszerzeniem `pdo_mysql` (pakiet `php-mysql` / `php8.x-mysql`)
- serwer **MariaDB** (lub MySQL) — lokalny albo zewnętrzny

## 1. Baza danych

Utwórz bazę, użytkownika i tabele jednorazowo, uruchamiając jako administrator:

```bash
mariadb < schema.sql
# lub: mysql -u root -p < schema.sql
```

Skrypt tworzy bazę `nasze_zeszyty`, użytkownika `nasze_zeszyty` (hasło:
`nasze_zeszyty`) oraz tabele. **Zmodyfikuj hasło przed użyciem na serwerze.**

> Tabele są też tworzone automatycznie przy pierwszym zapytaniu (jeśli
> użytkownik ma do tego uprawnienia), ale zalecane jest importowanie `schema.sql`.

## 2. Konfiguracja połączenia

Dane bazy czyta się ze zmiennych środowiskowych (z domyślnymi wartościami
z `config.php`):

| Zmienna       | Domyślne        | Opis                       |
|---------------|-----------------|----------------------------|
| `DB_HOST`     | `127.0.0.1`     | adres serwera MariaDB      |
| `DB_PORT`     | `3306`          | port                       |
| `DB_NAME`     | `nasze_zeszyty` | nazwa bazy                 |
| `DB_USER`     | `nasze_zeszyty` | użytkownik                 |
| `DB_PASSWORD` | `nasze_zeszyty` | hasło                      |

## 3. Uruchomienie (wbudowany serwer PHP)

```bash
php -S 0.0.0.0:8000 router.php
```

Strona będzie dostępna pod `http://localhost:8000`, a z telefonu w tej samej
sieci Wi-Fi pod `http://<adres-komputera>:8000`.

Z własnymi danymi bazy:

```bash
DB_HOST=db.example.com DB_PASSWORD=tajne php -S 0.0.0.0:8000 router.php
```

### Apache (opcjonalnie)

Można też użyć Apache: ustaw `DocumentRoot` na katalog projektu i pozwól na
`.htaccess` (plik jest już przygotowany). Wtedy wystarczy `php-mysql` + Apache.

## Struktura

```
index.html    strona startowa (animowane serce)
menu.html     główna aplikacja (zeszyty, galeria, odliczanie…)
css/ js/      style i logika frontendu
photos/wspomnienia/   miejsce na zdjęcia i nagrania
config.php    konfiguracja (zmienne środowiskowe)
helpers.php   PDO, czas UTC, multimedia, obsługa plików i JSON
router.php    front controller (php -S), blokada prywatnych plików
api/index.php REST API
schema.sql    konfiguracja bazy MariaDB
.htaccess     opcjonalna konfiguracja dla Apache
```

## API

| Metoda | Ścieżka                              | Opis                                  |
|--------|--------------------------------------|---------------------------------------|
| GET    | `/api/notes?notebook=moj\|twoj`      | lista notatek                         |
| POST   | `/api/notes`                         | nowa notatka                          |
| PUT    | `/api/notes/{id}`                    | edycja notatki                        |
| DELETE | `/api/notes/{id}`                    | usunięcie notatki                     |
| GET    | `/api/photos`                        | lista zdjęć i nagrań                  |
| POST   | `/api/photos`                        | wgranie pliku (base64)                |
| GET    | `/api/meeting`                       | data spotkania                        |
| PUT    | `/api/meeting`                       | ustaw datę spotkania                  |
| GET    | `/api/since`                         | data poznania się                     |
| PUT    | `/api/since`                         | ustaw datę poznania                   |
| GET    | `/api/status`                        | stan relacji (`razem` / `rozstanie`)  |
| PUT    | `/api/status`                        | zmień stan relacji                    |
| GET    | `/api/moods`                         | nastrój (aktualny + historia)         |
| POST   | `/api/moods`                         | zapisz nastrój                        |
| GET    | `/api/secrets`                       | sekrety (część zablokowana do daty)   |
| POST   | `/api/secrets`                       | nowy sekret                           |

## Migracja ze starej wersji (Python + SQLite)

Stara wersja trzymała notatki w `data/app.db` (SQLite). Plik ten nie jest już
używany. Aby przenieść dane, wyeksportuj je ręcznie (np. `sqlite3 data/app.db
.dump`) i wstaw do nowej bazy MariaDB.