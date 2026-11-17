# 🏫 reserWir - Schul-Reservierungssystem

Ein schlankes, benutzerfreundliches Web-Reservierungssystem für Schulen. 
Entwickelt für minimale Server-Anforderungen (**PHP + SQLite**), ohne komplexe Datenbankserver oder Hintergrunddienste.

---

## 🎯 Funktionen im Überblick

* **Stundenplan-Raster (1.–11. Stunde):** Keine unübersichtlichen Zeitleisten, sondern passgenau für den Schulalltag bis zur 11. Stunde (16:35 - 17:20 Uhr).
* **Mittagspausen-Hervorhebung:** Die 7. Stunde (13:30 – 14:10 Uhr) ist als Mittagspause farblich hervorgehoben.
* **Frei konfigurierbare iCal-Kalenderabos (im Admin-Bereich):**
  * Beliebige eigene iCal-Feeds (`https://` oder `webcal://`) für Ferien, Feiertage, Konferenzen oder Schultermine direkt über die Administrationsoberfläche hinzufügen, bearbeiten und löschen.
  * 5 wählbare Darstellungsfarben im Plan (**Rot**, **Gelb**, **Grün**, **Blau**, **Lila**).
  * Automatische tägliche Aktualisierung mit lokalem Cache für blitzschnelle Ladezeiten und Offline-Sicherheit.
  * Eigene Kalender-Zeile direkt unter den Wochentagen.
* **Individuelles Schuldesign:** Titelleiste in hellem Orange mit klarem Branding **reserWir**.
* **Flexible Ansichten (3 Kategorien):**
  * **Wochenansicht:** Montag bis Freitag für einen ausgewählten Raum, eine Ressource oder einen Trenner.
  * **Tagesansicht (nach Kategorie getrennt):** Je eine eigene übersichtliche Tagesseite für **Räume**, **Ressourcen** und **Trenner** (z. B. Sichtschutz-Aufsteller für Klassenarbeiten).
* **Buchungsdetails:**
  * Lehrerkürzel (z. B. `MÜL`)
  * Klasse (z. B. `9b`)
  * Fach (z. B. `Physik`)
  * Mehrstundenauswahl über übersichtliche **Checkboxen**.
* **Wiederholungen wählbar:**
  * Einmalig (nur der gewählte Tag)
  * 1 bis 5 Wochen (z. B. für eine 4-wöchige Unterrichtsreihe)
  * Bis zum Halbjahr (automatisch bis 31. Januar)
  * Ganzes Schuljahr (automatisch bis 31. Juli)
* **Intelligente Konfliktprüfung:** Verhindert Doppelbelegungen über alle gewählten Stunden und Folgetermine hinweg.
* **Kompakte Serien-Kennzeichnung:** Klare farbliche Unterscheidung zwischen blauer **Tagesserie** (mehrere Stunden am selben Tag) und violetter **Wochenserie** (über mehrere Wochen wiederkehrend) – sowohl im Plan als auch im Stornierungsdialog.
* **Stornierung:** Einzelne Termine oder direkt die gesamte Tages- bzw. Wochenserie stornieren.
* **Niederschwellige & sichere Benutzerverwaltung:**
  * Login mit dem gewohnten **Lehrerkürzel** (Groß-/Kleinschreibung egal, z. B. `mül` ➔ `MÜL`).
  * **Schutz durch persönliche PIN:**
    * Bei der Erstanmeldung nutzt die Lehrkraft das allgemeine Schul-Passwort.
    * Sobald eine persönliche 4-stellige PIN gesetzt wurde, wird das Schul-Passwort für dieses Kürzel **strikt abgewiesen** – niemand anderes kann sich mehr unter diesem Namen anmelden.
    * PIN vergessen? Der Administrator kann die PIN mit einem Klick zurücksetzen.
  * **Brute-Force-Schutz (Progressiver Delay & Sperren):**
    * 1.–2. Fehlversuch: Normaler Hinweis.
    * Ab dem 3. Fehlversuch: Vorübergehende Sperre des Kürzels (30s, 60s, 5 Min., 15 Min.) und physischer Server-Delay gegen automatisiertes Durchprobieren.
    * Der Administrator sieht gesperrte Konten in der Kollegiums-Liste und kann sie bei Bedarf sofort vorzeitig entsperren.
  * Option: **„Auf diesem Gerät angemeldet bleiben (180 Tage)“** – auf Dienstgeräten entfällt das ständige Neueinloggen.
* **Schutz vor Fremdstornierung:** Lehrkräfte können nur ihre eigenen Buchungen stornieren (der Administrator kann alles verwalten).
* **30-Tage Aktivitäten-Logfile (DSGVO-konform):**
  * Protokolliert Zeitpunkt, IP-Adresse, Kürzel/Benutzer, Aktion (Reservierung, Stornierung, Login etc.) und Details.
  * **Hochperformant & paginiert:** Zeigt maximal 50 Einträge pro Seite, um den Server und den Browser auch bei tausenden Ereignissen blitzschnell zu halten.
  * **Umfangreiche Filter:** Filterung nach genauem Datum, Schnellfilter (*Heute / Gestern / Alle*), Filterung nach Lehrerkürzel und nach Aktivitäts-Typ (*Reservierungen, Stornierungen, Logins, Admin*).
  * Direkt im Admin-Bereich einsehbar und per 1-Klick als **CSV-Datei** für Excel exportierbar (auch gefiltert).
  * Automatische Löschung aller Einträge, die älter als 30 Tage sind.
* **Kollegiums-Verwaltung:** Kürzel können im Admin-Bereich bequem per Textliste (kommagetrennt/zeilenweise) importiert werden. Neue Kürzel werden beim ersten Login mit dem Schul-Passwort automatisch registriert.
* **Admin-Bereich:**
  * Räume anlegen/bearbeiten (Name, Notizfeld für Ausstattung)
  * Ressourcen anlegen/bearbeiten (Name, Standort/Raum, Stückzahl, Notizfeld)
  * Trenner anlegen/bearbeiten (Name, Standort/Raum, Stückzahl, Notizfeld)
  * iCal-Kalenderabos anlegen/bearbeiten/löschen (Bezeichnung, `.ics`-URL, Farbe)
  * Passwörter direkt im Webinterface ändern
  * SQLite-Datenbank per 1-Klick als Backup herunterladen

---

## 🔑 Standard-Passwörter

* **Kollegium (Nutzer):** `schule`
* **Administrator:** `admin123`

*(Beide Passwörter können im Admin-Bereich unter „Passwörter & Backup“ geändert werden).*

---

## 🚀 Installation auf einem Webserver

Die App benötigt lediglich einen Webserver mit **PHP 7.4+ oder 8.x** und aktiviertem **SQLite/PDO** (bei so gut wie allen Hostern wie BelWü, Strato, All-Inkl, Hetzner, Host Europe standardmäßig aktiv).

1. Kopieren Sie den gesamten Ordner `reserWir` auf Ihren Server (z. B. per FTP oder Git) in Ihr Webverzeichnis (z. B. `htdocs/reservierung` oder `/var/www/html/reservierung`).
2. Stellen Sie sicher, dass PHP Schreibrechte im Ordner hat, damit der Unterordner `data/` und die Datei `reservierungen.sqlite` angelegt werden können:
   ```bash
   chmod 755 .
   chmod 755 data 2>/dev/null || true
   ```
3. Rufen Sie die URL im Browser auf (z. B. `https://ihre-schule.de/reservierung/`).
4. Beim ersten Aufruf erstellt die App die SQLite-Datenbank sowie Beispieldaten (Computerraum, iPad-Koffer etc.) automatisch.

---

## 💻 Lokales Testen (z. B. auf dem Mac / PC)

Falls Sie PHP auf Ihrem Rechner installiert haben (z. B. via Homebrew mit `brew install php`):

```bash
cd reserWir
php -S localhost:8000
```
Öffnen Sie anschließend im Browser: **`http://localhost:8000`**

---

## 🛡️ Sicherheitsarchitektur & Härtung

Die Anwendung wurde umfassend nach modernen Sicherheitsstandards gehärtet:
* **Datenbankschutz:** Die SQLite-Datenbank liegt standardmäßig in `data/`, geschützt durch Apache 2.4/2.2 `.htaccess` (`Require all denied` / `Deny from all`) sowie eine `index.php` mit HTTP 403. Optional kann das Datenverzeichnis über die Umgebungsvariable `RESERWIR_DATA_DIR` vollständig außerhalb des Web-Roots platziert werden.
* **Gehashte Remember-Tokens:** Im Cookie-Token-System werden in der Datenbank ausschließlich kryptografische **SHA-256 Hashes** gespeichert – ein Datenbankabzug ermöglicht keine Session-Übernahme.
* **IP-basiertes Rate-Limiting & Lockout:** Brute-Force-Angriffe auf das Administrator- oder Kollegiumspasswort werden serverseitig pro IP-Adresse mit progressivem Delay und automatischen Sperren abgewehrt.
* **Vollständiger CSRF-Schutz:** Alle zustandsverändernden Aktionen werden per `POST` übertragen und durch session-gebundene kryptografische CSRF-Tokens (`verify_csrf()`) validiert.
* **XSS-Schutz & Zeichensatz-Validierung:** Lehrerkürzel werden strikt auf `[A-Z0-9ÄÖÜ\-_]` begrenzt und vor jeglicher Ausgabe im HTML oder in JS-Attributen kontextbezogen maskiert.
* **Cookie- & Transport-Härtung:** Sessions und Cookies sind mit `HttpOnly`, `SameSite=Lax` und bei HTTPS mit `Secure` geschützt. Standard-Header wie `X-Frame-Options`, `X-Content-Type-Options` und `Referrer-Policy` sind aktiv.
* **Schutz vor CSV-Formel-Injection:** Beim Log-Export werden Zellen mit führenden Zeichen wie `=`, `+`, `-` oder `@` automatisch maskiert (`'`), um Codeausführung beim Öffnen in Excel oder Calc zu verhindern.
* **Sichere Kalender-Feeds & Logs:** Die iCal-Feeds nutzen strenge TLS-Zertifikatsvalidierung und SSRF-Filterung; die Protokollierung nutzt die echte Netzwerk-IP (`REMOTE_ADDR`), um Fälschungen via Header zu unterbinden.

---

## 📁 Dateistruktur

```text
reserWir/
├── config.php          # Konfiguration, DB-Verbindung, CSRF, Rate-Limiting & Security-Header
├── index.php           # Hauptansicht (Wochen- & Tagesplan, Buchungsmodal, Schnell-Login)
├── login.php           # Gesicherte Anmeldung (PIN, Brute-Force-Abwehr, IP-Limiting)
├── logout.php          # Sichere Abmeldung & Cookie-Löschung
├── action.php          # CSRF-geschützte Verarbeitung aller Buchungs- & Admin-Aktionen
├── admin.php           # Verwaltung von Räumen, Ressourcen, Kollegium, Log & Einstellungen
├── ical.php            # Abruf externer Kalender-Feeds mit Cache & TLS-Validierung
├── style.css           # Modernes, responsives CSS (100% offline-fähig, Mobile/Tablet-optimiert)
├── script.js           # Modal-Steuerung, Checkboxen & Klick-Events
├── logo_rw.png         # Schul-/App-Logo
├── favicon.png         # Favicon für Browser-Tabs & Bookmarks
├── data/               # Wird automatisch erstellt (beinhaltet die SQLite-Datei & iCal-Cache)
│   ├── .htaccess       # Schützt vor direktem Web-Download (Apache 2.4 & 2.2)
│   ├── index.php       # HTTP 403 Fallback für Webserver ohne .htaccess
│   └── reservierungen.sqlite
└── README.md
```
---
Unter GPL-3.0-Lizenz
Die Software wird so angeboten, wie sie ist.
Entwickelt mit Hilfe von Antigravity & Claude Code
