<?php
// config.php - Konfiguration, Sicherheitsfunktionen & zentrale Datenbankverbindung

// 1. Sichere Session-Cookie Parameter setzen (HttpOnly, SameSite, Secure bei HTTPS)
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
               (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

// 2. Sicherheits-Header senden
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// Verzeichnis für SQLite Datenbank (kann bei Bedarf außerhalb des Web-Roots gelegt werden)
if (!defined('DATA_DIR')) {
    define('DATA_DIR', getenv('RESERWIR_DATA_DIR') ?: __DIR__ . '/data');
}
define('DB_FILE', DATA_DIR . '/reservierungen.sqlite');

// Schulstunden Definition (1. bis 11. Stunde)
define('SCHOOL_PERIODS', [
    1  => ['label' => '1. Stunde',  'time' => '07:45 - 08:30'],
    2  => ['label' => '2. Stunde',  'time' => '08:35 - 09:20'],
    3  => ['label' => '3. Stunde',  'time' => '09:40 - 10:25'],
    4  => ['label' => '4. Stunde',  'time' => '10:25 - 11:10'],
    5  => ['label' => '5. Stunde',  'time' => '11:20 - 12:05'],
    6  => ['label' => '6. Stunde',  'time' => '12:10 - 12:55'],
    7  => ['label' => '7. Stunde',  'time' => '13:30 - 14:10', 'is_lunch' => true],
    8  => ['label' => '8. Stunde',  'time' => '14:15 - 15:00'],
    9  => ['label' => '9. Stunde',  'time' => '15:00 - 15:45'],
    10 => ['label' => '10. Stunde', 'time' => '15:50 - 16:35'],
    11 => ['label' => '11. Stunde', 'time' => '16:35 - 17:20'],
]);

// Initial-Passwörter (können im Admin-Bereich geändert werden)
define('DEFAULT_USER_PASSWORD', 'schule');
define('DEFAULT_ADMIN_PASSWORD', 'admin123');

/**
 * Verbindung zur SQLite Datenbank herstellen und bei Bedarf Tabellen anlegen
 */
function get_db(): PDO {
    static $db = null;
    if ($db !== null) {
        return $db;
    }

    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0755, true);
    }

    // Schutz vor Web-Downloads der SQLite-Datenbank:
    // 1. .htaccess mit Apache 2.4 und 2.2 Syntax
    $htaccess = "# Apache 2.4\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n# Apache 2.2 Fallback\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n";
    file_put_contents(DATA_DIR . '/.htaccess', $htaccess);

    // 2. Leere index.php als Fallback (z.B. falls nginx/caddy ohne .htaccess läuft)
    if (!file_exists(DATA_DIR . '/index.php')) {
        file_put_contents(DATA_DIR . '/index.php', "<?php http_response_code(403); exit('Zugriff verweigert.');\n");
    }

    $isNew = !file_exists(DB_FILE);

    $db = new PDO('sqlite:' . DB_FILE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Tabellen initialisieren
    $db->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS rooms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            note TEXT DEFAULT ''
        );

        CREATE TABLE IF NOT EXISTS resources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            room_location TEXT DEFAULT '',
            quantity INTEGER DEFAULT 1,
            note TEXT DEFAULT ''
        );

        CREATE TABLE IF NOT EXISTS dividers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            room_location TEXT DEFAULT '',
            quantity INTEGER DEFAULT 1,
            note TEXT DEFAULT ''
        );

        CREATE TABLE IF NOT EXISTS bookings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_type TEXT NOT NULL, -- 'room', 'resource' oder 'divider'
            item_id INTEGER NOT NULL,
            booking_date TEXT NOT NULL, -- Format YYYY-MM-DD
            period INTEGER NOT NULL, -- 1 bis 11
            teacher_code TEXT NOT NULL, -- z.B. MÜL
            class_name TEXT NOT NULL, -- z.B. 9b
            subject TEXT NOT NULL, -- z.B. Physik
            series_id TEXT DEFAULT NULL,
            series_type TEXT DEFAULT 'none', -- 'none', 'day' (Tagesserie), 'week' (Wochenserie)
            created_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS teachers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT UNIQUE NOT NULL, -- Großbuchstaben, z.B. MÜL
            name TEXT DEFAULT '',
            pin_hash TEXT DEFAULT NULL, -- optionale 4-stellige PIN
            failed_attempts INTEGER DEFAULT 0,
            locked_until INTEGER DEFAULT 0,
            last_failed_at INTEGER DEFAULT 0,
            created_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS remember_tokens (
            token_hash TEXT PRIMARY KEY, -- SHA-256 Hash des Cookies (kein Klartext!)
            teacher_code TEXT NOT NULL,
            role TEXT NOT NULL,
            expires_at INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS ip_rate_limits (
            ip TEXT PRIMARY KEY,
            failed_count INTEGER DEFAULT 0,
            locked_until INTEGER DEFAULT 0,
            last_attempt INTEGER DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS activity_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            ip_address TEXT NOT NULL,
            user_name TEXT NOT NULL,
            action TEXT NOT NULL,
            details TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS ical_feeds (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            url TEXT NOT NULL,
            color TEXT DEFAULT 'blue',
            created_at TEXT NOT NULL
        );

        CREATE INDEX IF NOT EXISTS idx_bookings_lookup 
        ON bookings (item_type, item_id, booking_date, period);

        CREATE INDEX IF NOT EXISTS idx_activity_logs_created 
        ON activity_logs (created_at);
    ");

    // Migrationen für bestehende Tabellen
    $cols = $db->query("PRAGMA table_info(teachers)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('failed_attempts', $cols, true)) {
        $db->exec("ALTER TABLE teachers ADD COLUMN failed_attempts INTEGER DEFAULT 0");
    }
    if (!in_array('locked_until', $cols, true)) {
        $db->exec("ALTER TABLE teachers ADD COLUMN locked_until INTEGER DEFAULT 0");
    }
    if (!in_array('last_failed_at', $cols, true)) {
        $db->exec("ALTER TABLE teachers ADD COLUMN last_failed_at INTEGER DEFAULT 0");
    }

    $bookingCols = $db->query("PRAGMA table_info(bookings)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('series_type', $bookingCols, true)) {
        $db->exec("ALTER TABLE bookings ADD COLUMN series_type TEXT DEFAULT 'none'");
    }

    // Falls alte remember_tokens Tabelle ohne token_hash existiert: Tabelle neu anlegen
    $remCols = $db->query("PRAGMA table_info(remember_tokens)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('token_hash', $remCols, true)) {
        $db->exec("DROP TABLE IF EXISTS remember_tokens");
        $db->exec("CREATE TABLE remember_tokens (token_hash TEXT PRIMARY KEY, teacher_code TEXT NOT NULL, role TEXT NOT NULL, expires_at INTEGER NOT NULL)");
    }

    // Veraltete Logeinträge (> 30 Tage), abgelaufene Tokens und alte IP-Sperren bereinigen
    $db->exec("DELETE FROM activity_logs WHERE created_at < datetime('now', '-30 days')");
    $db->exec("DELETE FROM remember_tokens WHERE expires_at < " . time());
    $db->exec("DELETE FROM ip_rate_limits WHERE locked_until < " . time() . " AND last_attempt < " . (time() - 86400));

    // Standard-Einstellungen & Beispieldaten bei neuer Datenbank
    if ($isNew) {
        // Passwörter hashen und speichern
        $stmt = $db->prepare("INSERT INTO settings (key, value) VALUES (?, ?)");
        $stmt->execute(['user_password_hash', password_hash(DEFAULT_USER_PASSWORD, PASSWORD_DEFAULT)]);
        $stmt->execute(['admin_password_hash', password_hash(DEFAULT_ADMIN_PASSWORD, PASSWORD_DEFAULT)]);

        // Beispiel-Räume
        $roomStmt = $db->prepare("INSERT INTO rooms (name, note) VALUES (?, ?)");
        $roomStmt->execute(['Raum 101 (Computerraum)', '16 Schüler-PCs, 1 Lehrer-PC, Beamer']);
        $roomStmt->execute(['Raum 102 (Fachraum Biologie)', 'Mikroskope, Beamer, Dokumentenkamera']);
        $roomStmt->execute(['Musikraum', 'Klavier, Musikanlage, Orff-Instrumente']);

        // Beispiel-Ressourcen
        $resStmt = $db->prepare("INSERT INTO resources (name, room_location, quantity, note) VALUES (?, ?, ?, ?)");
        $resStmt->execute(['iPad-Koffer 1', 'Lehrerzimmer (Schrank A)', 16, '16 iPads mit Stiften & Ladekabel']);
        $resStmt->execute(['iPad-Koffer 2', 'Lehrerzimmer (Schrank A)', 16, '16 iPads mit Schutzhüllen']);
        $resStmt->execute(['Mobiler Beamer + Box', 'Verwaltung / Fr. Meier', 1, 'HDMI- und USB-C-Adapter enthalten']);

        // Beispiel-Trenner
        $divStmt = $db->prepare("INSERT INTO dividers (name, room_location, quantity, note) VALUES (?, ?, ?, ?)");
        $divStmt->execute(['Trenner-Satz 1', 'Lehrerzimmer (Schrank B)', 15, '15 Sichtschutz-Aufsteller für Klassenarbeiten']);
        $divStmt->execute(['Trenner-Satz 2', 'Lehrerzimmer (Schrank B)', 15, '15 Sichtschutz-Aufsteller für Klassenarbeiten']);

        // Beispiel-Lehrkräfte anlegen
        $teachStmt = $db->prepare("INSERT INTO teachers (code, name, created_at) VALUES (?, ?, ?)");
        $now = date('Y-m-d H:i:s');
        $teachStmt->execute(['MÜL', 'Fr. Müller', $now]);
        $teachStmt->execute(['SCH', 'Hr. Schmidt', $now]);
        $teachStmt->execute(['BEC', 'Fr. Becker', $now]);
        $teachStmt->execute(['WAG', 'Hr. Wagner', $now]);
    }

    return $db;
}

/**
 * Einstellung aus der DB abrufen
 */
function get_setting(string $key, string $default = ''): string {
    $db = get_db();
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['value'] : $default;
}

/**
 * Einstellung in der DB speichern
 */
function set_setting(string $key, string $value): void {
    $db = get_db();
    $stmt = $db->prepare("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value");
    $stmt->execute([$key, $value]);
}

/**
 * Authentifizierung prüfen
 */
function is_logged_in(): bool {
    if (!empty($_SESSION['role'])) {
        return true;
    }
    // Falls Session abgelaufen, aber Remember-Cookie vorhanden ist
    return check_remember_cookie();
}

function is_admin(): bool {
    if (!is_logged_in()) {
        return false;
    }
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function get_logged_in_teacher_code(): string {
    return $_SESSION['teacher_code'] ?? '';
}

/**
 * Remember-Token (Dauerhafter Login für z.B. 180 Tage)
 * SICHERHEIT: In der DB wird NUR der kryptografische Hash (SHA-256) gespeichert!
 */
function check_remember_cookie(): bool {
    if (empty($_COOKIE['reserwir_remember'])) {
        return false;
    }

    $rawToken = $_COOKIE['reserwir_remember'];
    $tokenHash = hash('sha256', $rawToken);

    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM remember_tokens WHERE token_hash = ? AND expires_at > ?");
    $stmt->execute([$tokenHash, time()]);
    $row = $stmt->fetch();

    if ($row) {
        $_SESSION['role'] = $row['role'];
        $_SESSION['teacher_code'] = $row['teacher_code'];
        return true;
    }

    // Ungültiges Cookie löschen
    setcookie('reserwir_remember', '', time() - 3600, '/');
    return false;
}

function set_remember_token(string $teacherCode, string $role, int $days = 180): void {
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expires = time() + ($days * 86400);

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
               (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    $db = get_db();
    $stmt = $db->prepare("INSERT INTO remember_tokens (token_hash, teacher_code, role, expires_at) VALUES (?, ?, ?, ?)");
    $stmt->execute([$tokenHash, $teacherCode, $role, $expires]);

    setcookie('reserwir_remember', $rawToken, [
        'expires' => $expires,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

function clear_remember_token(): void {
    if (!empty($_COOKIE['reserwir_remember'])) {
        $rawToken = $_COOKIE['reserwir_remember'];
        $tokenHash = hash('sha256', $rawToken);
        $db = get_db();
        $db->prepare("DELETE FROM remember_tokens WHERE token_hash = ?")->execute([$tokenHash]);
        setcookie('reserwir_remember', '', time() - 3600, '/');
    }
}

/**
 * CSRF-Schutz: Token erzeugen und prüfen
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        set_flash('danger', 'Sicherheitsprüfung fehlgeschlagen (ungültiges CSRF-Token). Bitte laden Sie die Seite neu.');
        header('Location: ' . safe_redirect($_SERVER['HTTP_REFERER'] ?? 'index.php'));
        exit;
    }
}

/**
 * Open-Redirect Schutz: Nur sichere interne PHP-Ziele erlauben
 */
function safe_redirect(?string $url, string $default = 'index.php'): string {
    if (empty($url)) {
        return $default;
    }
    $clean = trim($url);
    // Nur relative Dateinamen auf interne .php Skripte ohne Protokoll/Host erlauben
    if (preg_match('/^[a-zA-Z0-9_\-]+\.php(\?[a-zA-Z0-9_\-=&%.,]*)?$/', $clean)) {
        return $clean;
    }
    return $default;
}

/**
 * IP-basiertes Rate-Limiting gegen Brute-Force auf Admin- & Schulpasswort
 */
function get_client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

function check_ip_rate_limit(string $ip): ?int {
    $db = get_db();
    $stmt = $db->prepare("SELECT locked_until FROM ip_rate_limits WHERE ip = ?");
    $stmt->execute([$ip]);
    $row = $stmt->fetch();
    if ($row && (int)$row['locked_until'] > time()) {
        return (int)$row['locked_until'] - time();
    }
    return null;
}

function record_ip_failure(string $ip): int {
    $db = get_db();
    $stmt = $db->prepare("SELECT failed_count FROM ip_rate_limits WHERE ip = ?");
    $stmt->execute([$ip]);
    $row = $stmt->fetch();
    $count = ($row ? (int)$row['failed_count'] : 0) + 1;

    $lockSeconds = 0;
    if ($count >= 5) {
        $delays = [5 => 60, 6 => 300, 7 => 900, 8 => 1800];
        $lockSeconds = $delays[$count] ?? 3600; // Bis zu 1 Stunde Sperre
    }

    $lockedUntil = $lockSeconds > 0 ? (time() + $lockSeconds) : 0;
    $db->prepare("INSERT INTO ip_rate_limits (ip, failed_count, locked_until, last_attempt) 
                  VALUES (?, ?, ?, ?) 
                  ON CONFLICT(ip) DO UPDATE SET failed_count = excluded.failed_count, locked_until = excluded.locked_until, last_attempt = excluded.last_attempt")
       ->execute([$ip, $count, $lockedUntil, time()]);

    return $lockSeconds;
}

function reset_ip_failure(string $ip): void {
    $db = get_db();
    $db->prepare("DELETE FROM ip_rate_limits WHERE ip = ?")->execute([$ip]);
}

/**
 * Prüfen, ob noch das Standard-Adminpasswort aktiv ist
 */
function is_default_admin_password(): bool {
    $adminHash = get_setting('admin_password_hash');
    return password_verify(DEFAULT_ADMIN_PASSWORD, $adminHash);
}

/**
 * Protokolliert eine Aktion im 30-Tage-Aktivitätenlog
 */
function log_activity(string $action, string $details = '', ?string $userName = null): void {
    try {
        $db = get_db();
        // Echte IP-Adresse von der Netzwerkverbindung nutzen (nicht fälschbar über X-Forwarded-For)
        $ip = get_client_ip();

        if ($userName === null) {
            if (is_admin()) {
                $code = get_logged_in_teacher_code();
                $userName = 'Admin' . ($code ? " ({$code})" : '');
            } elseif (!empty($_SESSION['teacher_code'])) {
                $userName = $_SESSION['teacher_code'];
            } else {
                $userName = 'Gast / Nicht angemeldet';
            }
        }

        $stmt = $db->prepare("INSERT INTO activity_logs (created_at, ip_address, user_name, action, details) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            date('Y-m-d H:i:s'),
            $ip,
            $userName,
            $action,
            $details
        ]);
    } catch (Exception $e) {
        // Logging-Fehler dürfen den Betrieb nicht unterbrechen
    }
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function require_admin(): void {
    if (!is_admin()) {
        header('Location: login.php?mode=admin&redirect=' . urlencode(safe_redirect($_SERVER['REQUEST_URI'] ?? 'admin.php')));
        exit;
    }
}

/**
 * HTML-Escaping Helper
 */
function h(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Flash-Nachrichten für Benutzer-Feedback
 */
function set_flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Natürliche Sortierung (z.B. iPad 1, iPad 2, ..., iPad 9, iPad 10) nach dem Feld 'name'
 */
function sort_items_naturally(array &$items): void {
    usort($items, fn($a, $b) => strnatcasecmp($a['name'] ?? '', $b['name'] ?? ''));
}
