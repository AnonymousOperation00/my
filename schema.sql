-- ============================================================
-- „nasze zeszyty" — konfiguracja zewnętrznej bazy MariaDB/MySQL.
--
-- Uruchom raz jako administrator (np. root):
--     mariadb < schema.sql
-- (lub)  mysql -u root -p < schema.sql
--
-- Skrypt tworzy bazę, użytkownika aplikacji i tabele.
-- Aplikacja łączy się danymi z config.php (lub zmiennych środowiskowych),
-- które domyślnie odpowiadają wartościom ustawionym poniżej.
-- ============================================================

CREATE DATABASE IF NOT EXISTS nasze_zeszyty
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

-- Użytkownik aplikacji. Hasło 'nasze_zeszyty' — w produkcji zmień na mocne!
-- Poza 'localhost' i '127.0.0.1' dodany jest też '%' (połączenia zdalne,
-- np. z kontenera lub innego hosta). W razie potrzeby zawęź zakres.
CREATE USER IF NOT EXISTS 'nasze_zeszyty'@'localhost' IDENTIFIED BY 'nasze_zeszyty';
CREATE USER IF NOT EXISTS 'nasze_zeszyty'@'127.0.0.1' IDENTIFIED BY 'nasze_zeszyty';
CREATE USER IF NOT EXISTS 'nasze_zeszyty'@'%'         IDENTIFIED BY 'nasze_zeszyty';

GRANT ALL PRIVILEGES ON nasze_zeszyty.* TO 'nasze_zeszyty'@'localhost';
GRANT ALL PRIVILEGES ON nasze_zeszyty.* TO 'nasze_zeszyty'@'127.0.0.1';
GRANT ALL PRIVILEGES ON nasze_zeszyty.* TO 'nasze_zeszyty'@'%';

FLUSH PRIVILEGES;

USE nasze_zeszyty;

-- Notatki w zeszytach (moj / twoj).
CREATE TABLE IF NOT EXISTS notes (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    notebook   VARCHAR(16)  NOT NULL,
    title      VARCHAR(200) NOT NULL DEFAULT '',
    content    MEDIUMTEXT   NOT NULL,
    created_at DATETIME(3)  NOT NULL,
    updated_at DATETIME(3)  NOT NULL,
    PRIMARY KEY (id),
    KEY idx_notes_notebook (notebook, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ustawienia (data poznania, spotkanie, stan relacji…).
CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(64) NOT NULL,
    `value`     TEXT        NOT NULL,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historia nastroju.
CREATE TABLE IF NOT EXISTS moods (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    mood       VARCHAR(32)  NOT NULL,
    created_at DATETIME(3)  NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sekrety odblokowywane w dniu spotkania.
CREATE TABLE IF NOT EXISTS secrets (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    content    TEXT         NOT NULL,
    created_at DATETIME(3)  NOT NULL,
    reveal_at  DATETIME(3)  NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;