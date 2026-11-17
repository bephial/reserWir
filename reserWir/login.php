<?php
// login.php - Niederschwellige Anmeldung mit strengem PIN-Schutz und Brute-Force-Abwehr

require_once __DIR__ . '/config.php';

// Parameter vorab sicher parsen (verhindert 'undefined variable $mode' und Open-Redirect)
$mode = $_GET['mode'] ?? 'normal';
$redirect = safe_redirect($_GET['redirect'] ?? 'index.php');

// Wenn bereits angemeldet, direkt weiterleiten (außer wenn man als normaler Nutzer in den Admin-Modus wechseln möchte)
if (is_logged_in() && !isset($_GET['reauth'])) {
    if ($mode === 'admin' && !is_admin()) {
        // Erlaube Eingabe des Admin-Passworts zum Hochstufen
    } else {
        header('Location: ' . $redirect);
        exit;
    }
}

$error = '';
$enteredCode = trim($_POST['teacher_code'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = get_client_ip();
    $code = strtoupper(trim($_POST['teacher_code'] ?? ''));
    $password = trim($_POST['password'] ?? '');
    $rememberMe = !empty($_POST['remember_me']);

    // 1. IP-Basiertes Rate-Limiting gegen Brute-Force Angriffe prüfen
    $ipWait = check_ip_rate_limit($ip);
    if ($ipWait !== null) {
        $error = "🚨 Zu viele Fehlversuche von Ihrer IP-Adresse. Bitte warten Sie <strong>{$ipWait} Sekunden</strong>, bevor Sie es erneut versuchen.";
        log_activity('Login blockiert (IP-Sperre)', "Abgewiesener Versuch von IP {$ip} (noch {$ipWait}s Sperre)", $code ?: 'Unbekannt');
    } else {
        // 2. Validierung des Lehrerkürzels (Schutz vor DOM/Reflected-XSS und fehlerhaften Kürzeln)
        $isValidCode = true;
        if ($mode !== 'admin') {
            if ($code === '') {
                $error = 'Bitte geben Sie Ihr Lehrerkürzel ein (z. B. MÜL).';
                $isValidCode = false;
            } elseif (!preg_match('/^[A-Z0-9ÄÖÜ\-_]{1,10}$/u', $code)) {
                $error = 'Das eingegebene Lehrerkürzel ist ungültig. Bitte verwenden Sie nur Buchstaben und Ziffern (maximal 10 Zeichen).';
                $isValidCode = false;
            }
        } elseif ($code !== '' && !preg_match('/^[A-Z0-9ÄÖÜ\-_]{1,10}$/u', $code)) {
            $code = 'ADMIN';
        }

        if ($isValidCode) {
            $db = get_db();
            $adminHash = get_setting('admin_password_hash');
            $userHash = get_setting('user_password_hash');

            // 3. Prüfen auf Administrator-Passwort
            if (password_verify($password, $adminHash)) {
                reset_ip_failure($ip);
                $_SESSION['role'] = 'admin';
                $_SESSION['teacher_code'] = $code ?: ($_SESSION['teacher_code'] ?? 'ADMIN');

                if ($rememberMe) {
                    set_remember_token($_SESSION['teacher_code'], 'admin', 180);
                }

                log_activity('Login erfolgreich (Admin)', "Admin-Zugang via " . ($_SESSION['teacher_code'] ?: 'Admin'), $_SESSION['teacher_code']);
                header('Location: ' . ($mode === 'admin' ? 'admin.php' : $redirect));
                exit;
            }

            // 4. Admin-Modus-Versuch fehlgeschlagen
            if ($mode === 'admin') {
                $lockSeconds = record_ip_failure($ip);
                $error = 'Das eingegebene Administrator-Passwort ist ungültig.';
                if ($lockSeconds > 0) {
                    $error .= " 🚨 Wegen wiederholter Fehlversuche ist der Zugang für diese Verbindung für <strong>{$lockSeconds} Sekunden</strong> gesperrt.";
                }
                log_activity('Login fehlgeschlagen (Admin-Versuch)', 'Ungültiges Admin-Passwort' . ($lockSeconds ? " (IP-Sperre {$lockSeconds}s)" : ""), $code ?: 'Unbekannt');
            } else {
                // 5. Normaler Lehrer-Login
                $stmt = $db->prepare("SELECT * FROM teachers WHERE code = ?");
                $stmt->execute([$code]);
                $teacher = $stmt->fetch();

                $safeCode = h($code);

                // BRUTE-FORCE SCHUTZ PRO KÜRZEL: Prüfen, ob das Kürzel gesperrt ist
                if ($teacher && !empty($teacher['locked_until']) && (int)$teacher['locked_until'] > time()) {
                    $waitSec = (int)$teacher['locked_until'] - time();
                    $error = "🚨 Zu viele Fehlversuche! Das Kürzel <strong>{$safeCode}</strong> ist zum Schutz vor unbefugtem Zugriff für noch <strong>{$waitSec} Sekunden</strong> gesperrt.";
                    log_activity('Login blockiert (Brute-Force Sperre)', "Abgewiesener Versuch während Sperre für {$code} (noch {$waitSec}s)", $code);
                } else {
                    $loginSuccess = false;
                    $authMethod = '';

                    if ($teacher) {
                        $hasCustomPin = !empty($teacher['pin_hash']);

                        if ($hasCustomPin) {
                            // FALL A: PIN ist aktiv! -> NUR noch die 4-stellige PIN wird akzeptiert!
                            if (password_verify($password, $teacher['pin_hash'])) {
                                $loginSuccess = true;
                                $authMethod = 'Persönliche PIN';
                            } else {
                                // Fehlversuch für Kürzel registrieren
                                $failedCount = ((int)$teacher['failed_attempts']) + 1;
                                $teacherLockSeconds = 0;
                                
                                if ($failedCount === 3) {
                                    $teacherLockSeconds = 30; // 30 Sekunden
                                } elseif ($failedCount === 4) {
                                    $teacherLockSeconds = 60; // 1 Minute
                                } elseif ($failedCount === 5) {
                                    $teacherLockSeconds = 300; // 5 Minuten
                                } elseif ($failedCount >= 6) {
                                    $teacherLockSeconds = 900; // 15 Minuten
                                }

                                $lockedUntil = $teacherLockSeconds > 0 ? (time() + $teacherLockSeconds) : 0;
                                
                                // Server-Verzögerung gegen automatisierte Angriffe
                                if ($failedCount >= 3) {
                                    sleep(min(2, $failedCount - 2));
                                }

                                $db->prepare("UPDATE teachers SET failed_attempts = ?, locked_until = ?, last_failed_at = ? WHERE id = ?")
                                   ->execute([$failedCount, $lockedUntil, time(), $teacher['id']]);

                                // IP-Fehlversuch zählen
                                record_ip_failure($ip);

                                $enteredSchoolPass = password_verify($password, $userHash);

                                if ($lockedUntil > time()) {
                                    $error = "🚨 <strong>Zu viele Fehlversuche!</strong><br>Für das Kürzel <strong>{$safeCode}</strong> wurde die PIN 3× oder öfter falsch eingegeben. Das Konto ist für <strong>{$teacherLockSeconds} Sekunden</strong> gesperrt.";
                                } elseif ($enteredSchoolPass) {
                                    $error = "🔒 <strong>Für das Kürzel {$safeCode} ist eine persönliche PIN aktiv!</strong><br>Aus Sicherheitsgründen wird das allgemeine Schul-Passwort für dieses Kürzel nicht mehr akzeptiert. Bitte geben Sie Ihre 4-stellige PIN ein.";
                                } else {
                                    $remTries = max(0, 3 - $failedCount);
                                    $error = "Die PIN für das Kürzel <strong>{$safeCode}</strong> ist falsch. (Noch {$remTries} Versuch" . ($remTries === 1 ? '' : 'e') . " bis zur Sperre).";
                                }

                                log_activity('Login fehlgeschlagen (Falsche PIN)', "Fehlversuch #{$failedCount} für {$code}" . ($teacherLockSeconds ? " (Sperre {$teacherLockSeconds}s)" : ""), $code);
                            }
                        } else {
                            // FALL B: Noch KEINE PIN gesetzt -> Schul-Passwort wird akzeptiert
                            if (password_verify($password, $userHash)) {
                                $loginSuccess = true;
                                $authMethod = 'Schul-Passwort (Erstanmeldung)';
                            } else {
                                $failedCount = ((int)$teacher['failed_attempts']) + 1;
                                $teacherLockSeconds = ($failedCount >= 3) ? 60 : 0;
                                $lockedUntil = $teacherLockSeconds > 0 ? (time() + $teacherLockSeconds) : 0;

                                $db->prepare("UPDATE teachers SET failed_attempts = ?, locked_until = ?, last_failed_at = ? WHERE id = ?")
                                   ->execute([$failedCount, $lockedUntil, time(), $teacher['id']]);

                                $ipLockSec = record_ip_failure($ip);

                                $error = ($lockedUntil > time()) 
                                    ? "Zu viele Fehlversuche. Das Kürzel <strong>{$safeCode}</strong> ist für 60 Sekunden gesperrt."
                                    : "Das Schul-Passwort für das Kürzel <strong>{$safeCode}</strong> ist ungültig.";
                                if ($ipLockSec > 0) {
                                    $error .= " 🚨 Verbindung für {$ipLockSec} Sekunden gesperrt.";
                                }

                                log_activity('Login fehlgeschlagen', "Falsches Schul-Passwort für {$code}", $code);
                            }
                        }

                        if ($loginSuccess) {
                            $db->prepare("UPDATE teachers SET failed_attempts = 0, locked_until = 0, last_failed_at = 0 WHERE id = ?")
                               ->execute([$teacher['id']]);
                        }

                    } else {
                        // FALL C: Kürzel existiert noch nicht in der Datenbank
                        if (password_verify($password, $userHash)) {
                            $addStmt = $db->prepare("INSERT INTO teachers (code, name, created_at) VALUES (?, ?, ?)");
                            $addStmt->execute([$code, '', date('Y-m-d H:i:s')]);
                            $loginSuccess = true;
                            $authMethod = 'Schul-Passwort (Neuregistrierung)';
                        } else {
                            $ipLockSec = record_ip_failure($ip);
                            $error = "Das Kürzel <strong>{$safeCode}</strong> ist nicht bekannt oder das eingegebene Passwort ist falsch.";
                            if ($ipLockSec > 0) {
                                $error .= " 🚨 Wegen wiederholter Fehlversuche ist der Zugang für diese Verbindung für <strong>{$ipLockSec} Sekunden</strong> gesperrt.";
                            }
                            log_activity('Login fehlgeschlagen', "Unbekanntes Kürzel oder falsches Passwort: {$code}", $code);
                        }
                    }

                    if ($loginSuccess) {
                        reset_ip_failure($ip);
                        $_SESSION['role'] = 'user';
                        $_SESSION['teacher_code'] = $code;

                        if ($rememberMe) {
                            set_remember_token($code, 'user', 180);
                        }

                        log_activity('Login erfolgreich', "Erfolgreich angemeldet ({$authMethod})", $code);
                        header('Location: ' . $redirect);
                        exit;
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anmeldung - reserWir</title>
    <link rel="icon" type="image/png" href="<?= file_exists(__DIR__ . '/favicon.png') ? 'favicon.png' : 'logo_rw.png' ?>">
    <link rel="apple-touch-icon" href="<?= file_exists(__DIR__ . '/favicon.png') ? 'favicon.png' : 'logo_rw.png' ?>">
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-body">
    <div class="login-card">
        <div class="login-header">
            <img src="logo_rw.png" alt="reserWir Logo" class="login-logo-img">
            <h1>reserWir</h1>
            <p><?= $mode === 'admin' ? 'Administrator-Zugang' : 'Räume &amp; Ressourcen reservieren' ?></p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= $error ?></div>
        <?php endif; ?>

        <form method="POST" class="login-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

            <?php if ($mode !== 'admin'): ?>
                <div class="form-group">
                    <label for="teacher_code"><strong>Ihr Lehrerkürzel:</strong></label>
                    <input type="text" id="teacher_code" name="teacher_code" 
                           value="<?= h($enteredCode) ?>" 
                           required autofocus placeholder="z.B. MÜL oder SCH" 
                           class="form-input uppercase-input" 
                           autocomplete="username" maxlength="10">
                    <small class="form-hint">Groß- und Kleinschreibung ist egal.</small>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="password">
                    <strong><?= $mode === 'admin' ? 'Admin-Passwort:' : 'Passwort oder 4-stellige PIN:' ?></strong>
                </label>
                <input type="password" id="password" name="password" required 
                       <?= $mode === 'admin' ? 'autofocus' : '' ?>
                       placeholder="<?= $mode === 'admin' ? 'Admin-Passwort eingeben' : 'Schul-Passwort oder Ihre PIN' ?>" 
                       class="form-input" autocomplete="current-password">
                <small class="form-hint">
                    <?= $mode === 'admin' 
                        ? 'Geben Sie das Master-Passwort für die Schulverwaltung ein.' 
                        : 'Falls Sie eine persönliche PIN gesetzt haben, geben Sie diese hier ein. Ansonsten gilt das Schul-Passwort.' ?>
                </small>
            </div>

            <div class="form-group remember-checkbox-group">
                <label class="checkbox-container">
                    <input type="checkbox" name="remember_me" value="1" checked>
                    <span class="checkbox-text">
                        <strong>Auf diesem Gerät angemeldet bleiben</strong> (180 Tage)
                    </span>
                </label>
                <small class="form-hint text-muted">Empfohlen für Ihr Dienstgerät. Sie müssen das Passwort dann nicht ständig neu eingeben.</small>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Anmelden</button>
        </form>

        <div class="login-footer">
            <?php if ($mode === 'admin'): ?>
                <a href="login.php">← Zurück zur normalen Lehrer-Anmeldung</a>
            <?php else: ?>
                <a href="login.php?mode=admin">Als Administrator anmelden</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
