#!/usr/bin/env python3
"""Serwer "naszych zeszytów".

Co robi:
  * serwuje pliki statyczne (index.html, menu.html, css/, js/, photos/)
  * zapisuje notatki w bazie SQLite (data/app.db) przez REST API
  * obsługuje galerię: zdjęcia i nagrania (audio/wideo) z photos/wspomnienia

Uruchomienie:
    python3 server.py
    python3 server.py --port 9000
    python3 server.py --host 127.0.0.1      # tylko z tego komputera

Wystarczy biblioteka standardowa Pythona — bez instalowania zależności.
"""

import argparse
import base64
import json
import os
import re
import socket
import sqlite3
from contextlib import contextmanager
from datetime import datetime, timedelta, timezone
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, quote, urlparse

ROOT = Path(__file__).resolve().parent
DB_PATH = ROOT / "data" / "app.db"
PHOTO_DIR = ROOT / "photos"
MEMORIES_DIR = PHOTO_DIR / "wspomnienia"

NOTEBOOKS = ("moj", "twoj")

# nastroje (klucze ASCII, etykiety są po stronie przeglądarki)
MOOD_SLUGS = ("radosny", "zakochany", "spokojny", "zadumany",
              "zmeczony", "tesknie", "smutny", "zly")
MAX_SECRET_CHARS = 4000

# data poznania się (można zmienić przez PUT /api/since)
DEFAULT_SINCE = "2025-03-18"
IMAGE_EXT = {".jpg", ".jpeg", ".png", ".webp", ".gif", ".avif"}
AUDIO_EXT = {".mp3", ".m4a", ".aac", ".ogg", ".oga", ".opus", ".wav", ".weba"}
VIDEO_EXT = {".mp4", ".webm", ".mov", ".m4v", ".ogv"}
MEDIA_EXT = IMAGE_EXT | AUDIO_EXT | VIDEO_EXT

# typy MIME, żeby przeglądarka wiedziała, jak odtworzyć pliki
MEDIA_TYPES = {
    ".jpg": "image/jpeg", ".jpeg": "image/jpeg", ".png": "image/png",
    ".webp": "image/webp", ".gif": "image/gif", ".avif": "image/avif",
    ".mp3": "audio/mpeg", ".m4a": "audio/mp4", ".aac": "audio/aac",
    ".ogg": "audio/ogg", ".oga": "audio/ogg", ".opus": "audio/ogg",
    ".wav": "audio/wav", ".weba": "audio/webm",
    ".mp4": "video/mp4", ".webm": "video/webm", ".mov": "video/quicktime",
    ".m4v": "video/mp4", ".ogv": "video/ogg",
}

MAX_MEDIA_BYTES = 60 * 1024 * 1024
MAX_BODY_BYTES = 96 * 1024 * 1024


# ---------------------------------------------------------------- baza danych

@contextmanager
def connect():
    """Połączenie SQLite na jedno żądanie (serwer jest wielowątkowy)."""
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    try:
        yield conn
        conn.commit()
    finally:
        conn.close()


def init_storage():
    DB_PATH.parent.mkdir(parents=True, exist_ok=True)
    MEMORIES_DIR.mkdir(parents=True, exist_ok=True)

    with connect() as conn:
        conn.execute(
            """
            CREATE TABLE IF NOT EXISTS notes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                notebook TEXT NOT NULL,
                title TEXT NOT NULL DEFAULT '',
                content TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
            """
        )
        conn.execute("CREATE INDEX IF NOT EXISTS notes_notebook ON notes(notebook, id)")
        conn.execute(
            """
            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )
            """
        )
        conn.execute(
            """
            CREATE TABLE IF NOT EXISTS moods (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                mood TEXT NOT NULL,
                created_at TEXT NOT NULL
            )
            """
        )
        conn.execute(
            """
            CREATE TABLE IF NOT EXISTS secrets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                content TEXT NOT NULL,
                created_at TEXT NOT NULL,
                reveal_at TEXT NOT NULL
            )
            """
        )


def now_stamp():
    return datetime.now(timezone.utc).isoformat(timespec="milliseconds").replace("+00:00", "Z")


def is_revealed(reveal_at):
    """Czy sekret można już pokazać (data ukrycia minęła)."""
    try:
        moment = datetime.fromisoformat(str(reveal_at).replace("Z", "+00:00"))
    except ValueError:
        return True
    if moment.tzinfo is None:
        moment = moment.replace(tzinfo=timezone.utc)
    return moment <= datetime.now(timezone.utc)


def kind_for(suffix):
    """Rodzaj pliku po rozszerzeniu: photo / audio / video."""
    if suffix in IMAGE_EXT:
        return "photo"
    if suffix in AUDIO_EXT:
        return "audio"
    if suffix in VIDEO_EXT:
        return "video"
    return None


def media_kind(raw, suffix):
    """Rodzaj pliku po nagłówku — żeby nie wpuścić byle czego."""
    # zdjęcia
    if raw.startswith(b"\xff\xd8\xff") or raw.startswith(b"\x89PNG\r\n\x1a\n"):
        return "photo"
    if raw[:6] in (b"GIF87a", b"GIF89a"):
        return "photo"
    if raw[:4] == b"RIFF" and raw[8:12] == b"WEBP":
        return "photo"
    if raw[4:12] in (b"ftypavif", b"ftypavis"):
        return "photo"

    # dźwięk
    if raw[:3] == b"ID3" or raw[:2] in (b"\xff\xfb", b"\xff\xf3", b"\xff\xf2", b"\xff\xf1", b"\xff\xf9"):
        return "audio"
    if raw[:4] == b"RIFF" and raw[8:12] == b"WAVE":
        return "audio"
    if raw[:4] == b"OggS":
        return "video" if suffix == ".ogv" else "audio"
    if raw[:4] == b"\x1aE\xdf\xa3":          # kontener WebM/Matroska
        return "audio" if suffix in (".weba", ".opus") else "video"

    # wideo / m4a (wspólny kontener MP4)
    if raw[4:8] == b"ftyp":
        return "video" if suffix in (".mp4", ".mov", ".m4v") else "audio"

    return None


# ------------------------------------------------------------------- serwowanie

class Handler(SimpleHTTPRequestHandler):
    server_version = "NaszeZeszyty/1.0"
    extensions_map = {**SimpleHTTPRequestHandler.extensions_map, **MEDIA_TYPES}

    # ---------- pomocnicze ----------

    def send_json(self, payload, status=200):
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)

    def read_json(self):
        try:
            length = int(self.headers.get("Content-Length") or 0)
        except (TypeError, ValueError):
            length = 0
        if length <= 0:
            raise ValueError("brak treści żądania")
        if length > MAX_BODY_BYTES:
            raise ValueError("żądanie jest za duże")
        return json.loads(self.rfile.read(length).decode("utf-8"))

    @staticmethod
    def is_private(path):
        if path in ("/server.py", "/data"):
            return True
        return path.startswith(("/data/", "/.git/", "/__pycache__/"))

    # ---------- GET ----------

    def do_GET(self):
        path = urlparse(self.path).path

        if path == "/api/notes":
            return self.notes_list()
        if path == "/api/photos":
            return self.photos_list()
        if path == "/api/meeting":
            return self.meeting_get()
        if path == "/api/since":
            return self.since_get()
        if path == "/api/status":
            return self.status_get()
        if path == "/api/moods":
            return self.moods_list()
        if path == "/api/secrets":
            return self.secrets_list()
        if path.startswith("/api/"):
            return self.send_json({"error": "nie znaleziono"}, 404)

        if self.is_private(path):
            return self.send_error(404)
        if path != "/" and path.endswith("/"):
            return self.send_error(404)  # bez listowania katalogów

        return super().do_GET()

    # ---------- POST / PUT / DELETE ----------

    def do_POST(self):
        path = urlparse(self.path).path
        if path == "/api/notes":
            return self.notes_create()
        if path == "/api/photos":
            return self.photos_upload()
        if path == "/api/moods":
            return self.moods_create()
        if path == "/api/secrets":
            return self.secrets_create()
        self.send_json({"error": "nie znaleziono"}, 404)

    def do_PUT(self):
        path = urlparse(self.path).path
        if path == "/api/meeting":
            return self.meeting_put()
        if path == "/api/since":
            return self.since_put()
        if path == "/api/status":
            return self.status_put()

        match = re.match(r"^/api/notes/(\d+)$", path)
        if match:
            return self.notes_update(int(match.group(1)))
        self.send_json({"error": "nie znaleziono"}, 404)

    def do_DELETE(self):
        match = re.match(r"^/api/notes/(\d+)$", urlparse(self.path).path)
        if match:
            return self.notes_delete(int(match.group(1)))
        self.send_json({"error": "nie znaleziono"}, 404)

    # ---------- API notatek ----------

    def notes_list(self):
        query = parse_qs(urlparse(self.path).query)
        notebook = (query.get("notebook") or ["moj"])[0]
        if notebook not in NOTEBOOKS:
            return self.send_json({"error": "nieznany zeszyt"}, 400)

        with connect() as conn:
            rows = conn.execute(
                "SELECT id, notebook, title, content, created_at, updated_at "
                "FROM notes WHERE notebook = ? ORDER BY id ASC",
                (notebook,),
            ).fetchall()

        self.send_json([dict(row) for row in rows])

    def notes_create(self):
        try:
            data = self.read_json()
        except Exception:
            return self.send_json({"error": "nieprawidłowe dane"}, 400)

        notebook = data.get("notebook")
        if notebook not in NOTEBOOKS:
            return self.send_json({"error": "nieznany zeszyt"}, 400)

        title = str(data.get("title") or "")[:200]
        content = str(data.get("content") or "")
        stamp = now_stamp()

        with connect() as conn:
            cursor = conn.execute(
                "INSERT INTO notes (notebook, title, content, created_at, updated_at) "
                "VALUES (?, ?, ?, ?, ?)",
                (notebook, title, content, stamp, stamp),
            )
            row = conn.execute("SELECT * FROM notes WHERE id = ?", (cursor.lastrowid,)).fetchone()

        self.send_json(dict(row), 201)

    def notes_update(self, note_id):
        try:
            data = self.read_json()
        except Exception:
            return self.send_json({"error": "nieprawidłowe dane"}, 400)

        with connect() as conn:
            row = conn.execute("SELECT * FROM notes WHERE id = ?", (note_id,)).fetchone()
            if row is None:
                return self.send_json({"error": "nie ma takiej strony"}, 404)

            title = row["title"] if data.get("title") is None else str(data["title"])[:200]
            content = row["content"] if data.get("content") is None else str(data["content"])
            stamp = now_stamp()

            conn.execute(
                "UPDATE notes SET title = ?, content = ?, updated_at = ? WHERE id = ?",
                (title, content, stamp, note_id),
            )
            row = conn.execute("SELECT * FROM notes WHERE id = ?", (note_id,)).fetchone()

        self.send_json(dict(row))

    def notes_delete(self, note_id):
        with connect() as conn:
            conn.execute("DELETE FROM notes WHERE id = ?", (note_id,))
        self.send_json({"ok": True})

    # ---------- API odliczania (wspólna data spotkania) ----------

    def meeting_get(self):
        with connect() as conn:
            row = conn.execute("SELECT value FROM settings WHERE key = 'meeting'").fetchone()

            if row is None:
                # pierwsze uruchomienie: spotkanie za tydzień
                target = datetime.now(timezone.utc) + timedelta(days=7)
                value = target.isoformat(timespec="milliseconds").replace("+00:00", "Z")
                conn.execute("INSERT INTO settings (key, value) VALUES ('meeting', ?)", (value,))
            else:
                value = row["value"]

        self.send_json({"target": value})

    def meeting_put(self):
        try:
            data = self.read_json()
        except Exception:
            return self.send_json({"error": "nieprawidłowe dane"}, 400)

        raw = str(data.get("target") or "")
        try:
            parsed = datetime.fromisoformat(raw.replace("Z", "+00:00"))
        except ValueError:
            return self.send_json({"error": "nieprawidłowa data"}, 400)

        if parsed.tzinfo is None:
            parsed = parsed.replace(tzinfo=timezone.utc)

        value = parsed.astimezone(timezone.utc).isoformat(timespec="milliseconds").replace("+00:00", "Z")

        with connect() as conn:
            conn.execute(
                "INSERT INTO settings (key, value) VALUES ('meeting', ?) "
                "ON CONFLICT(key) DO UPDATE SET value = excluded.value",
                (value,),
            )

        self.send_json({"target": value})

    # ---------- API daty poznania się ----------

    def since_get(self):
        with connect() as conn:
            row = conn.execute("SELECT value FROM settings WHERE key = 'poznanie'").fetchone()

            if row is None:
                # data poznania ustawiona raz na zawsze (18.03.2025)
                conn.execute(
                    "INSERT INTO settings (key, value) VALUES ('poznanie', ?)",
                    (DEFAULT_SINCE,),
                )
                value = DEFAULT_SINCE
            else:
                value = row["value"]

        self.send_json({"since": value})

    def since_put(self):
        try:
            data = self.read_json()
        except Exception:
            return self.send_json({"error": "nieprawidłowe dane"}, 400)

        raw = str(data.get("since") or "").strip()
        try:
            moment = datetime.strptime(raw, "%Y-%m-%d")
        except ValueError:
            return self.send_json({"error": "nieprawidłowa data"}, 400)

        value = moment.strftime("%Y-%m-%d")

        with connect() as conn:
            conn.execute(
                "INSERT INTO settings (key, value) VALUES ('poznanie', ?) "
                "ON CONFLICT(key) DO UPDATE SET value = excluded.value",
                (value,),
            )

        self.send_json({"since": value})

    # ---------- API stanu (razem / po zerwaniu) ----------

    def status_get(self):
        with connect() as conn:
            state_row = conn.execute("SELECT value FROM settings WHERE key = 'status'").fetchone()
            at_row = conn.execute("SELECT value FROM settings WHERE key = 'status_at'").fetchone()

        state = state_row["value"] if state_row else "razem"
        if state not in ("razem", "rozstanie"):
            state = "razem"

        at = at_row["value"] if at_row else None
        self.send_json({"state": state, "at": at})

    def status_put(self):
        try:
            data = self.read_json()
        except Exception:
            return self.send_json({"error": "nieprawidłowe dane"}, 400)

        state = str(data.get("state") or "").strip()
        if state not in ("razem", "rozstanie"):
            return self.send_json({"error": "nieznany stan"}, 400)

        at = now_stamp() if state == "rozstanie" else None

        with connect() as conn:
            conn.execute(
                "INSERT INTO settings (key, value) VALUES ('status', ?) "
                "ON CONFLICT(key) DO UPDATE SET value = excluded.value",
                (state,),
            )
            conn.execute("DELETE FROM settings WHERE key = 'status_at'")
            if at:
                conn.execute("INSERT INTO settings (key, value) VALUES ('status_at', ?)", (at,))

        self.send_json({"state": state, "at": at})

    # ---------- API galerii ----------

    def photos_list(self):
        """Zdjęcia i nagrania z jednego folderu — nasze wspomnienia."""
        items = []

        if MEMORIES_DIR.is_dir():
            for entry in MEMORIES_DIR.iterdir():
                if not entry.is_file():
                    continue

                suffix = entry.suffix.lower()
                kind = kind_for(suffix)
                if kind is None:
                    continue

                stat = entry.stat()
                items.append(
                    {
                        "name": entry.name,
                        "url": "/photos/wspomnienia/%s" % quote(entry.name),
                        "kind": kind,
                        "size": stat.st_size,
                        "mtime": int(stat.st_mtime),
                    }
                )

        items.sort(key=lambda item: item["mtime"], reverse=True)
        self.send_json({"items": items})

    def photos_upload(self):
        try:
            data = self.read_json()
        except Exception:
            return self.send_json({"error": "nieprawidłowe dane"}, 400)

        category = data.get("category")
        if category is not None and category != "wspomnienia":
            return self.send_json({"error": "zdjęcia trafiają tylko do wspomnień"}, 400)

        name = os.path.basename(str(data.get("filename") or "zdjecie.jpg"))
        name = re.sub(r"[^A-Za-z0-9._-]+", "-", name).strip("-.") or "zdjecie.jpg"
        suffix = Path(name).suffix.lower()

        if suffix not in MEDIA_EXT:
            return self.send_json({"error": "nieobsługiwany format pliku"}, 400)

        try:
            raw = base64.b64decode(str(data.get("data") or ""), validate=True)
        except Exception:
            return self.send_json({"error": "nieprawidłowe dane pliku"}, 400)

        if not raw:
            return self.send_json({"error": "pusty plik"}, 400)
        if len(raw) > MAX_MEDIA_BYTES:
            return self.send_json(
                {"error": "plik jest za duży (limit %d MB)" % (MAX_MEDIA_BYTES // (1024 * 1024))},
                413,
            )

        kind = media_kind(raw, suffix)
        if kind is None:
            return self.send_json({"error": "to nie jest zdjęcie ani nagranie"}, 400)

        folder = MEMORIES_DIR
        folder.mkdir(parents=True, exist_ok=True)

        target = folder / name
        counter = 1
        while target.exists():
            target = folder / ("%s-%d%s" % (Path(name).stem, counter, suffix))
            counter += 1

        target.write_bytes(raw)

        self.send_json(
            {
                "ok": True,
                "name": target.name,
                "kind": kind,
                "url": "/photos/wspomnienia/%s" % quote(target.name),
            },
            201,
        )

    # ---------- API humoru ----------

    def moods_list(self):
        with connect() as conn:
            rows = conn.execute(
                "SELECT id, mood, created_at FROM moods ORDER BY id DESC LIMIT 8"
            ).fetchall()

        items = [dict(row) for row in rows]
        self.send_json({"current": items[0] if items else None, "history": items})

    def moods_create(self):
        try:
            data = self.read_json()
        except Exception:
            return self.send_json({"error": "nieprawidłowe dane"}, 400)

        mood = str(data.get("mood") or "").strip()
        if mood not in MOOD_SLUGS:
            return self.send_json({"error": "nieznany nastrój"}, 400)

        with connect() as conn:
            cursor = conn.execute(
                "INSERT INTO moods (mood, created_at) VALUES (?, ?)",
                (mood, now_stamp()),
            )
            row = conn.execute(
                "SELECT id, mood, created_at FROM moods WHERE id = ?",
                (cursor.lastrowid,),
            ).fetchone()

        self.send_json({"current": dict(row)}, 201)

    # ---------- API sekretów ----------

    def secrets_list(self):
        with connect() as conn:
            meeting = conn.execute("SELECT value FROM settings WHERE key = 'meeting'").fetchone()
            rows = conn.execute(
                "SELECT id, content, created_at, reveal_at FROM secrets ORDER BY id DESC LIMIT 50"
            ).fetchall()

        # sekrety otwierają się przy spotkaniu — jeśli data spotkania się zmieni,
        # otwarcie przesuwa się razem z nią
        reveal_at = meeting["value"] if meeting is not None else None

        items = []
        for row in rows:
            moment = reveal_at or row["reveal_at"]
            unlocked = is_revealed(moment)
            item = {
                "id": row["id"],
                "created_at": row["created_at"],
                "reveal_at": moment,
                "locked": not unlocked,
            }
            if unlocked:
                item["content"] = row["content"]
            items.append(item)

        self.send_json({"items": items})

    def secrets_create(self):
        try:
            data = self.read_json()
        except Exception:
            return self.send_json({"error": "nieprawidłowe dane"}, 400)

        content = str(data.get("content") or "").strip()
        if not content:
            return self.send_json({"error": "pusty sekret"}, 400)
        if len(content) > MAX_SECRET_CHARS:
            return self.send_json({"error": "sekret jest za długi"}, 400)

        with connect() as conn:
            row = conn.execute("SELECT value FROM settings WHERE key = 'meeting'").fetchone()

            if row is None:
                # brak daty spotkania: sekret otworzy się za tydzień
                reveal_at = (datetime.now(timezone.utc) + timedelta(days=7)).isoformat(
                    timespec="milliseconds"
                ).replace("+00:00", "Z")
                conn.execute("INSERT INTO settings (key, value) VALUES ('meeting', ?)", (reveal_at,))
            else:
                reveal_at = row["value"]

            cursor = conn.execute(
                "INSERT INTO secrets (content, created_at, reveal_at) VALUES (?, ?, ?)",
                (content, now_stamp(), reveal_at),
            )
            secret_id = cursor.lastrowid

        self.send_json({"ok": True, "id": secret_id, "reveal_at": reveal_at}, 201)


# ---------------------------------------------------------------------- start

def local_ip():
    """Adres w sieci lokalnej — żeby wygodnie otworzyć stronę na telefonie."""
    try:
        sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        try:
            sock.connect(("8.8.8.8", 80))
            return sock.getsockname()[0]
        finally:
            sock.close()
    except OSError:
        return "127.0.0.1"


def main():
    parser = argparse.ArgumentParser(description="Serwer naszych zeszytów")
    parser.add_argument("--host", default="0.0.0.0", help="adres nasłuchiwania (domyślnie 0.0.0.0)")
    parser.add_argument("--port", type=int, default=8000, help="port (domyślnie 8000)")
    args = parser.parse_args()

    init_storage()

    server = ThreadingHTTPServer((args.host, args.port), partial(Handler, directory=str(ROOT)))

    print("Zeszyty działają:")
    print("  lokalnie:  http://localhost:%d" % args.port)
    if args.host in ("0.0.0.0", ""):
        print("  w sieci:   http://%s:%d   (telefon/tablet w tej samej Wi-Fi)" % (local_ip(), args.port))
    print("  baza:      %s" % DB_PATH)
    print("  zdjęcia:   %s" % MEMORIES_DIR)
    print("Zatrzymanie: Ctrl+C")

    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nDo zobaczenia.")
    finally:
        server.server_close()


if __name__ == "__main__":
    main()


