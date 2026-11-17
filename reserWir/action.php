<?php
// action.php - Zentrale Verarbeitung aller Formularaktionen mit Berechtigungen, CSRF-Schutz und Logging

require_once __DIR__ . '/config.php';
require_login();

// Alle aktionsausführenden Operationen müssen per POST erfolgen
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Methode nicht erlaubt. Aktionen müssen per POST gesendet werden.');
}

// CSRF-Token für jede Anfrage validieren
verify_csrf();

$action = $_POST['action'] ?? '';
$db = get_db();

$currentTeacherCode = get_logged_in_teacher_code();
$isAdmin = is_admin();

// Helper zur Bereinigung von CSV-Feldern gegen Excel-Formel-Injection (=, +, -, @)
function sanitize_csv_cell($val): string {
    $str = (string)$val;
    if ($str !== '' && in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $str;
    }
    return $str;
}

// -------------------------------------------------------------
// 1. NEUE RESERVIERUNG ANLEGEN (mit Konfliktprüfung & Logging)
// -------------------------------------------------------------
if ($action === 'book') {
    $itemKey = trim($_POST['item_key'] ?? ''); // z.B. "room:1" oder "resource:2"
    $bookingDate = trim($_POST['booking_date'] ?? '');
    $periods = $_POST['periods'] ?? []; // Array aus gewählten Stunden z.B. ['1', '2']
    
    // Lehrerkürzel: Bei normalen Lehrern fest aus der Session; Admins dürfen für Kollegen buchen
    if ($isAdmin && !empty($_POST['teacher_code'])) {
        $teacherCode = strtoupper(trim($_POST['teacher_code']));
    } else {
        $teacherCode = $currentTeacherCode ?: strtoupper(trim($_POST['teacher_code'] ?? ''));
    }

    $className = trim($_POST['class_name'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $repeatType = trim($_POST['repeat_type'] ?? 'none');

    // Validierung
    if (!$itemKey || !$bookingDate || empty($periods) || !$teacherCode || !$className || !$subject) {
        set_flash('danger', 'Bitte füllen Sie alle Pflichtfelder aus und wählen Sie mindestens eine Schulstunde.');
        header('Location: ' . safe_redirect($_SERVER['HTTP_REFERER'] ?? 'index.php'));
        exit;
    }

    if (!preg_match('/^[A-Z0-9ÄÖÜ\-_]{1,10}$/u', $teacherCode)) {
        set_flash('danger', 'Ungültiges Lehrerkürzel. Bitte nur Buchstaben und Ziffern verwenden (max. 10 Zeichen).');
        header('Location: ' . safe_redirect($_SERVER['HTTP_REFERER'] ?? 'index.php'));
        exit;
    }

    [$itemType, $itemId] = explode(':', $itemKey);
    $itemId = (int)$itemId;

    if (!in_array($itemType, ['room', 'resource', 'divider'], true) || $itemId <= 0) {
        set_flash('danger', 'Ungültige Raum-, Ressourcen- oder Trennerauswahl.');
        header('Location: ' . safe_redirect($_SERVER['HTTP_REFERER'] ?? 'index.php'));
        exit;
    }

    // Prüfen, ob Objekt existiert
    if ($itemType === 'room') {
        $checkStmt = $db->prepare("SELECT id, name FROM rooms WHERE id = ?");
    } elseif ($itemType === 'resource') {
        $checkStmt = $db->prepare("SELECT id, name FROM resources WHERE id = ?");
    } else {
        $checkStmt = $db->prepare("SELECT id, name FROM dividers WHERE id = ?");
    }
    $checkStmt->execute([$itemId]);
    $itemObj = $checkStmt->fetch();
    if (!$itemObj) {
        set_flash('danger', 'Das ausgewählte Objekt wurde nicht gefunden.');
        header('Location: ' . safe_redirect($_SERVER['HTTP_REFERER'] ?? 'index.php'));
        exit;
    }

    // Alle Stunden in Integer umwandeln und filtern (1-11)
    $periods = array_unique(array_filter(array_map('intval', (array)$periods), fn($p) => $p >= 1 && $p <= 11));
    sort($periods);

    if (empty($periods)) {
        set_flash('danger', 'Bitte wählen Sie mindestens eine gültige Stunde (1-11).');
        header('Location: ' . safe_redirect($_SERVER['HTTP_REFERER'] ?? 'index.php'));
        exit;
    }

    // Datum prüfen
    $baseTs = strtotime($bookingDate);
    if (!$baseTs) {
        set_flash('danger', 'Ungültiges Datum.');
        header('Location: ' . safe_redirect($_SERVER['HTTP_REFERER'] ?? 'index.php'));
        exit;
    }

    // Wiederholungs-Termine berechnen
    $targetDates = [$bookingDate];
    $baseYear = (int)date('Y', $baseTs);
    $baseMonth = (int)date('n', $baseTs);

    if (in_array($repeatType, ['1', '2', '3', '4', '5'], true)) {
        $weeks = (int)$repeatType;
        for ($i = 1; $i <= $weeks; $i++) {
            $targetDates[] = date('Y-m-d', strtotime("+$i week", $baseTs));
        }
    } elseif ($repeatType === 'halbjahr') {
        // Bis zum 31. Januar
        $endYear = ($baseMonth === 1) ? $baseYear : ($baseYear + 1);
        $endDate = sprintf('%04d-01-31', $endYear);
        
        $week = 1;
        while (true) {
            $nextDate = date('Y-m-d', strtotime("+$week week", $baseTs));
            if ($nextDate > $endDate) break;
            $targetDates[] = $nextDate;
            $week++;
        }
    } elseif ($repeatType === 'schuljahr') {
        // Bis zum 31. Juli
        $endYear = ($baseMonth <= 7) ? $baseYear : ($baseYear + 1);
        $endDate = sprintf('%04d-07-31', $endYear);

        $week = 1;
        while (true) {
            $nextDate = date('Y-m-d', strtotime("+$week week", $baseTs));
            if ($nextDate > $endDate) break;
            $targetDates[] = $nextDate;
            $week++;
        }
    }

    // KONFLIKTPRÜFUNG
    $conflicts = [];
    $conflictStmt = $db->prepare("
        SELECT booking_date, period, teacher_code, class_name, subject 
        FROM bookings 
        WHERE item_type = ? AND item_id = ? AND booking_date = ? AND period = ?
    ");

    foreach ($targetDates as $d) {
        foreach ($periods as $p) {
            $conflictStmt->execute([$itemType, $itemId, $d, $p]);
            $existing = $conflictStmt->fetch();
            if ($existing) {
                $dFormatted = date('d.m.Y', strtotime($d));
                $conflicts[] = "• {$dFormatted}, {$p}. Stunde: Belegt von <strong>" . h($existing['teacher_code']) . "</strong> (" . h($existing['class_name']) . " / " . h($existing['subject']) . ")";
            }
        }
    }

    if (!empty($conflicts)) {
        $maxShow = 6;
        $conflictMsg = "<strong>Buchung fehlgeschlagen wegen Doppelbelegung!</strong><br>";
        $conflictMsg .= implode('<br>', array_slice($conflicts, 0, $maxShow));
        if (count($conflicts) > $maxShow) {
            $conflictMsg .= "<br>... und " . (count($conflicts) - $maxShow) . " weitere Konflikte.";
        }
        set_flash('danger', $conflictMsg);
        header('Location: ' . safe_redirect($_SERVER['HTTP_REFERER'] ?? 'index.php'));
        exit;
    }

    // KEINE KONFLIKTE -> EINTRAGEN
    $isMultiDay = count($targetDates) > 1;
    $isMultiPeriod = count($periods) > 1;

    if ($isMultiDay) {
        $seriesType = 'week'; // Wochenserie
        $seriesId = uniqid('ser_w_', true);
    } elseif ($isMultiPeriod) {
        $seriesType = 'day';  // Tagesserie (Mehrere Stunden am gleichen Tag)
        $seriesId = uniqid('ser_d_', true);
    } else {
        $seriesType = 'none';
        $seriesId = null;
    }

    $now = date('Y-m-d H:i:s');

    $insertStmt = $db->prepare("
        INSERT INTO bookings 
        (item_type, item_id, booking_date, period, teacher_code, class_name, subject, series_id, series_type, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $db->beginTransaction();
    try {
        $totalBookings = 0;
        foreach ($targetDates as $d) {
            foreach ($periods as $p) {
                $insertStmt->execute([
                    $itemType,
                    $itemId,
                    $d,
                    $p,
                    $teacherCode,
                    $className,
                    $subject,
                    $seriesId,
                    $seriesType,
                    $now
                ]);
                $totalBookings++;
            }
        }
        $db->commit();

        if ($seriesType === 'week') {
            $info = "Erfolgreich reserviert! Wochenserie mit insgesamt {$totalBookings} Terminen angelegt.";
        } elseif ($seriesType === 'day') {
            $info = "Erfolgreich reserviert! Tagesserie mit {$totalBookings} Stunden für " . h($itemObj['name']) . " angelegt.";
        } else {
            $info = 'Reservierung für ' . h($itemObj['name']) . ' erfolgreich gespeichert.';
        }
        set_flash('success', $info);

        // Im Logfile protokollieren
        $periodsStr = implode(', ', $periods) . '. Std.';
        $details = "{$itemObj['name']} am " . date('d.m.Y', strtotime($bookingDate)) . " ({$periodsStr}, {$className} {$subject})";
        if ($seriesType === 'week') {
            $details .= " [Wochenserie mit {$totalBookings} Terminen]";
        } elseif ($seriesType === 'day') {
            $details .= " [Tagesserie mit {$totalBookings} Stunden]";
        }
        log_activity('Termin reserviert', $details, $teacherCode);

    } catch (Exception $e) {
        $db->rollBack();
        error_log("Reservierungs-Fehler: " . $e->getMessage());
        set_flash('danger', 'Ein interner Datenbankfehler ist aufgetreten. Bitte versuchen Sie es erneut.');
    }

    $returnView = $_POST['return_view'] ?? 'week';
    $returnCat = $_POST['return_cat'] ?? '';
    if ($returnView === 'day') {
        if (!in_array($returnCat, ['rooms', 'resources', 'dividers'], true)) {
            $returnCat = ($itemType === 'resource') ? 'resources' : (($itemType === 'divider') ? 'dividers' : 'rooms');
        }
        $returnUrl = 'index.php?view=day&cat=' . urlencode($returnCat) . '&date=' . urlencode($bookingDate) . '&item=' . urlencode($itemKey);
    } else {
        $returnUrl = 'index.php?date=' . urlencode($bookingDate) . '&item=' . urlencode($itemKey);
    }
    header('Location: ' . $returnUrl);
    exit;
}

// -------------------------------------------------------------
// 2. RESERVIERUNG STORNIEREN (mit Besitz-Prüfung & Logging)
// -------------------------------------------------------------
if ($action === 'cancel') {
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $scope = $_POST['scope'] ?? 'single'; // 'single' oder 'series'

    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ?");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();

    if (!$booking) {
        set_flash('danger', 'Reservierung wurde nicht gefunden.');
        header('Location: ' . safe_redirect($_SERVER['HTTP_REFERER'] ?? 'index.php'));
        exit;
    }

    // Berechtigung prüfen: Nur eigene Buchungen stornieren (oder als Administrator)
    if (!$isAdmin && $booking['teacher_code'] !== $currentTeacherCode) {
        set_flash('danger', 'Sie können nur Ihre eigenen Buchungen stornieren. Dieser Termin wurde von ' . h($booking['teacher_code']) . ' reserviert.');
        log_activity('Stornierung abgelehnt', "Versuch, Buchung von {$booking['teacher_code']} durch {$currentTeacherCode} zu löschen", $currentTeacherCode);
        header('Location: ' . safe_redirect($_SERVER['HTTP_REFERER'] ?? 'index.php'));
        exit;
    }

    // Name des Objekts ermitteln für das Log
    $itemName = 'Objekt #' . $booking['item_id'];
    if ($booking['item_type'] === 'room') {
        $r = $db->query("SELECT name FROM rooms WHERE id = " . (int)$booking['item_id'])->fetch();
        if ($r) $itemName = $r['name'];
    } elseif ($booking['item_type'] === 'resource') {
        $r = $db->query("SELECT name FROM resources WHERE id = " . (int)$booking['item_id'])->fetch();
        if ($r) $itemName = $r['name'];
    } elseif ($booking['item_type'] === 'divider') {
        $r = $db->query("SELECT name FROM dividers WHERE id = " . (int)$booking['item_id'])->fetch();
        if ($r) $itemName = $r['name'];
    }

    if ($scope === 'series' && !empty($booking['series_id'])) {
        $delStmt = $db->prepare("DELETE FROM bookings WHERE series_id = ?");
        $delStmt->execute([$booking['series_id']]);
        $count = $delStmt->rowCount();

        $isWeekSeries = ($booking['series_type'] === 'week') || str_starts_with($booking['series_id'], 'ser_w_');
        $seriesLabel = $isWeekSeries ? 'Wochenserie' : 'Tagesserie';

        set_flash('success', "Die gesamte {$seriesLabel} mit {$count} Buchungen wurde erfolgreich storniert.");

        log_activity('Serie storniert', "Ganze {$seriesLabel} ({$count} Termine) von {$itemName} (Inhaber: {$booking['teacher_code']}) gelöscht", $currentTeacherCode ?: 'Admin');
    } else {
        $delStmt = $db->prepare("DELETE FROM bookings WHERE id = ?");
        $delStmt->execute([$bookingId]);
        set_flash('success', "Die Reservierung wurde erfolgreich storniert.");

        $dFormatted = date('d.m.Y', strtotime($booking['booking_date']));
        log_activity('Termin storniert', "Einzeltermin {$itemName} am {$dFormatted} ({$booking['period']}. Std., Inhaber: {$booking['teacher_code']}) gelöscht", $currentTeacherCode ?: 'Admin');
    }

    header('Location: ' . safe_redirect($_SERVER['HTTP_REFERER'] ?? 'index.php'));
    exit;
}

// -------------------------------------------------------------
// 3. EIGENE 4-STELLIGE PIN SETZEN / ÄNDERN (durch Lehrkraft)
// -------------------------------------------------------------
if ($action === 'set_my_pin') {
    $pin = trim($_POST['new_pin'] ?? '');
    
    if (!$currentTeacherCode) {
        set_flash('danger', 'Sie müssen als Lehrkraft angemeldet sein, um eine PIN zu vergeben.');
        header('Location: index.php');
        exit;
    }

    if ($pin === '') {
        // PIN entfernen -> Lehrkraft nutzt wieder das Schul-Passwort
        $stmt = $db->prepare("UPDATE teachers SET pin_hash = NULL WHERE code = ?");
        $stmt->execute([$currentTeacherCode]);
        set_flash('success', 'Ihre persönliche PIN wurde entfernt. Sie melden sich nun wieder mit dem allgemeinen Schul-Passwort an.');
        log_activity('PIN entfernt', 'Persönliche PIN gelöscht, zurück auf Schul-Passwort', $currentTeacherCode);
    } else {
        if (!preg_match('/^\d{4}$/', $pin)) {
            set_flash('danger', 'Die PIN muss genau 4 Ziffern lang sein (z.B. 1234).');
            header('Location: index.php');
            exit;
        }

        $pinHash = password_hash($pin, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE teachers SET pin_hash = ? WHERE code = ?");
        $stmt->execute([$pinHash, $currentTeacherCode]);
        set_flash('success', 'Ihre persönliche 4-stellige PIN wurde erfolgreich gespeichert!');
        log_activity('PIN gesetzt', 'Persönliche 4-stellige PIN aktualisiert', $currentTeacherCode);
    }

    header('Location: index.php');
    exit;
}

// -------------------------------------------------------------
// 3b. ADMIN-MODUS SCHNELL-FREISCHALTUNG (via Passwort mit IP-Rate-Limiting)
// -------------------------------------------------------------
if ($action === 'login_admin') {
    $ip = get_client_ip();
    $ipWait = check_ip_rate_limit($ip);
    if ($ipWait !== null) {
        set_flash('danger', "🚨 Zu viele Fehlversuche von Ihrer IP-Adresse. Bitte warten Sie <strong>{$ipWait} Sekunden</strong>.");
        header('Location: ' . safe_redirect($_SERVER['HTTP_REFERER'] ?? 'index.php'));
        exit;
    }

    $password = trim($_POST['admin_password'] ?? '');
    $adminHash = get_setting('admin_password_hash');

    if (password_verify($password, $adminHash)) {
        reset_ip_failure($ip);
        $_SESSION['role'] = 'admin';
        if (empty($_SESSION['teacher_code'])) {
            $_SESSION['teacher_code'] = 'ADMIN';
        }
        log_activity('Admin-Modus aktiviert', 'Wechsel in Admin-Modus via Passwort', $_SESSION['teacher_code']);
        set_flash('success', 'Admin-Modus erfolgreich aktiviert. Willkommen im Verwaltungsbereich!');
        header('Location: admin.php');
        exit;
    } else {
        $lockSeconds = record_ip_failure($ip);
        $msg = 'Das eingegebene Administrator-Passwort ist ungültig.';
        if ($lockSeconds > 0) {
            $msg .= " 🚨 Verbindung für {$lockSeconds} Sekunden gesperrt.";
        }
        set_flash('danger', $msg);
        log_activity('Admin-Versuch fehlgeschlagen', 'Falsches Admin-Passwort im Schnell-Login' . ($lockSeconds ? " (IP-Sperre {$lockSeconds}s)" : ""), $_SESSION['teacher_code'] ?? 'Unbekannt');
        header('Location: ' . safe_redirect($_SERVER['HTTP_REFERER'] ?? 'index.php'));
        exit;
    }
}

// -------------------------------------------------------------
// 4. ADMIN-AKTIONEN (Benötigen Admin-Rechte)
// -------------------------------------------------------------
if (str_starts_with($action, 'admin_')) {
    require_admin();

    // Raum speichern (Neu oder Bearbeiten)
    if ($action === 'admin_save_room') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $note = trim($_POST['note'] ?? '');

        if (!$name) {
            set_flash('danger', 'Der Raumname darf nicht leer sein.');
        } else {
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE rooms SET name = ?, note = ? WHERE id = ?");
                $stmt->execute([$name, $note, $id]);
                set_flash('success', 'Raum erfolgreich aktualisiert.');
                log_activity('Admin: Raum aktualisiert', "Raum #{$id} ({$name})");
            } else {
                $stmt = $db->prepare("INSERT INTO rooms (name, note) VALUES (?, ?)");
                $stmt->execute([$name, $note]);
                set_flash('success', 'Neuer Raum erfolgreich angelegt.');
                log_activity('Admin: Raum angelegt', "Raum: {$name}");
            }
        }
        header('Location: admin.php?tab=rooms');
        exit;
    }

    // Raum löschen
    if ($action === 'admin_delete_room') {
        $id = (int)($_POST['id'] ?? 0);
        $room = $db->query("SELECT name FROM rooms WHERE id = $id")->fetch();
        $rName = $room ? $room['name'] : "#$id";

        $db->prepare("DELETE FROM bookings WHERE item_type = 'room' AND item_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM rooms WHERE id = ?")->execute([$id]);
        set_flash('success', 'Raum und zugehörige Reservierungen gelöscht.');
        log_activity('Admin: Raum gelöscht', "Raum {$rName} und zugehörige Buchungen gelöscht");
        header('Location: admin.php?tab=rooms');
        exit;
    }

    // Ressource speichern (Neu oder Bearbeiten)
    if ($action === 'admin_save_resource') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $roomLocation = trim($_POST['room_location'] ?? '');
        $quantity = max(1, (int)($_POST['quantity'] ?? 1));
        $note = trim($_POST['note'] ?? '');

        if (!$name) {
            set_flash('danger', 'Der Name der Ressource darf nicht leer sein.');
        } else {
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE resources SET name = ?, room_location = ?, quantity = ?, note = ? WHERE id = ?");
                $stmt->execute([$name, $roomLocation, $quantity, $note, $id]);
                set_flash('success', 'Ressource erfolgreich aktualisiert.');
                log_activity('Admin: Ressource aktualisiert', "Ressource #{$id} ({$name})");
            } else {
                $stmt = $db->prepare("INSERT INTO resources (name, room_location, quantity, note) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $roomLocation, $quantity, $note]);
                set_flash('success', 'Neue Ressource erfolgreich angelegt.');
                log_activity('Admin: Ressource angelegt', "Ressource: {$name}");
            }
        }
        header('Location: admin.php?tab=resources');
        exit;
    }

    // Ressource löschen
    if ($action === 'admin_delete_resource') {
        $id = (int)($_POST['id'] ?? 0);
        $res = $db->query("SELECT name FROM resources WHERE id = $id")->fetch();
        $resName = $res ? $res['name'] : "#$id";

        $db->prepare("DELETE FROM bookings WHERE item_type = 'resource' AND item_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM resources WHERE id = ?")->execute([$id]);
        set_flash('success', 'Ressource und zugehörige Reservierungen gelöscht.');
        log_activity('Admin: Ressource gelöscht', "Ressource {$resName} und zugehörige Buchungen gelöscht");
        header('Location: admin.php?tab=resources');
        exit;
    }

    // Trenner speichern (Neu oder Bearbeiten)
    if ($action === 'admin_save_divider') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $roomLocation = trim($_POST['room_location'] ?? '');
        $quantity = max(1, (int)($_POST['quantity'] ?? 1));
        $note = trim($_POST['note'] ?? '');

        if (!$name) {
            set_flash('danger', 'Die Bezeichnung des Trenners darf nicht leer sein.');
        } else {
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE dividers SET name = ?, room_location = ?, quantity = ?, note = ? WHERE id = ?");
                $stmt->execute([$name, $roomLocation, $quantity, $note, $id]);
                set_flash('success', 'Trenner erfolgreich aktualisiert.');
                log_activity('Admin: Trenner aktualisiert', "Trenner #{$id} ({$name})");
            } else {
                $stmt = $db->prepare("INSERT INTO dividers (name, room_location, quantity, note) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $roomLocation, $quantity, $note]);
                set_flash('success', 'Neuer Trenner erfolgreich angelegt.');
                log_activity('Admin: Trenner angelegt', "Trenner: {$name}");
            }
        }
        header('Location: admin.php?tab=dividers');
        exit;
    }

    // Trenner löschen
    if ($action === 'admin_delete_divider') {
        $id = (int)($_POST['id'] ?? 0);
        $div = $db->query("SELECT name FROM dividers WHERE id = $id")->fetch();
        $divName = $div ? $div['name'] : "#$id";

        $db->prepare("DELETE FROM bookings WHERE item_type = 'divider' AND item_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM dividers WHERE id = ?")->execute([$id]);
        set_flash('success', 'Trenner und zugehörige Reservierungen gelöscht.');
        log_activity('Admin: Trenner gelöscht', "Trenner {$divName} und zugehörige Buchungen gelöscht");
        header('Location: admin.php?tab=dividers');
        exit;
    }

    // Kollegium verwalten: Kürzel per Massen-Import oder Einzeln anlegen
    if ($action === 'admin_add_teachers') {
        $rawText = trim($_POST['raw_codes'] ?? '');
        $tokens = preg_split('/[,\r\n\t]+/', $rawText);
        $added = 0;
        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare("INSERT OR IGNORE INTO teachers (code, name, created_at) VALUES (?, ?, ?)");

        foreach ($tokens as $t) {
            $c = strtoupper(trim($t));
            // Validierung: Nur erlaubte Zeichen im Lehrerkürzel [A-Z0-9ÄÖÜ\-_]
            if ($c !== '' && preg_match('/^[A-Z0-9ÄÖÜ\-_]{1,10}$/u', $c)) {
                $stmt->execute([$c, '', $now]);
                if ($stmt->rowCount() > 0) {
                    $added++;
                }
            }
        }

        set_flash('success', "Es wurden {$added} neue Lehrerkürzel angelegt.");
        log_activity('Admin: Kollegium erweitert', "{$added} Kürzel hinzugefügt: " . substr(strip_tags($rawText), 0, 80));
        header('Location: admin.php?tab=teachers');
        exit;
    }

    // PIN einer Lehrkraft durch Admin zurücksetzen
    if ($action === 'admin_reset_teacher_pin') {
        $id = (int)($_POST['teacher_id'] ?? 0);
        $t = $db->query("SELECT code FROM teachers WHERE id = $id")->fetch();
        if ($t) {
            $db->prepare("UPDATE teachers SET pin_hash = NULL, failed_attempts = 0, locked_until = 0 WHERE id = ?")->execute([$id]);
            set_flash('success', "PIN für " . h($t['code']) . " wurde zurückgesetzt. Die Lehrkraft nutzt nun wieder das Schul-Passwort.");
            log_activity('Admin: PIN zurückgesetzt', "PIN für {$t['code']} zurückgesetzt (und Sperren aufgehoben)");
        }
        header('Location: admin.php?tab=teachers');
        exit;
    }

    // Lehrkraft vorzeitig entsperren (nach zu vielen Fehlversuchen)
    if ($action === 'admin_unlock_teacher') {
        $id = (int)($_POST['teacher_id'] ?? 0);
        $t = $db->query("SELECT code FROM teachers WHERE id = $id")->fetch();
        if ($t) {
            $db->prepare("UPDATE teachers SET failed_attempts = 0, locked_until = 0 WHERE id = ?")->execute([$id]);
            set_flash('success', "Kürzel " . h($t['code']) . " wurde erfolgreich vorzeitig entsperrt.");
            log_activity('Admin: Entsperrung', "Kürzel {$t['code']} manuell entsperrt");
        }
        header('Location: admin.php?tab=teachers');
        exit;
    }

    // Lehrkraft löschen
    if ($action === 'admin_delete_teacher') {
        $id = (int)($_POST['teacher_id'] ?? 0);
        $t = $db->query("SELECT code FROM teachers WHERE id = $id")->fetch();
        if ($t) {
            $db->prepare("DELETE FROM teachers WHERE id = ?")->execute([$id]);
            set_flash('success', "Lehrkraft " . h($t['code']) . " wurde aus der Liste entfernt.");
            log_activity('Admin: Lehrkraft gelöscht', "Lehrkraft {$t['code']} gelöscht");
        }
        header('Location: admin.php?tab=teachers');
        exit;
    }

    // Passwörter ändern
    if ($action === 'admin_change_passwords') {
        $newUserPass = trim($_POST['new_user_password'] ?? '');
        $newAdminPass = trim($_POST['new_admin_password'] ?? '');

        if ($newUserPass !== '') {
            set_setting('user_password_hash', password_hash($newUserPass, PASSWORD_DEFAULT));
        }
        if ($newAdminPass !== '') {
            set_setting('admin_password_hash', password_hash($newAdminPass, PASSWORD_DEFAULT));
        }

        set_flash('success', 'Passwörter wurden erfolgreich aktualisiert.');
        log_activity('Admin: Passwörter geändert', 'System-Passwörter wurden aktualisiert');
        header('Location: admin.php?tab=settings');
        exit;
    }

    // iCal-Abo speichern (Neu oder Bearbeiten)
    if ($action === 'admin_save_ical_feed') {
        require_once __DIR__ . '/ical.php';
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $rawUrl = trim($_POST['url'] ?? '');
        $color = trim($_POST['color'] ?? 'blue');

        $allowedColors = array_keys(get_ical_color_options());
        if (!in_array($color, $allowedColors, true)) {
            $color = 'blue';
        }

        $validUrl = normalize_and_validate_ical_url($rawUrl);

        if ($name === '') {
            set_flash('danger', 'Bitte geben Sie eine Bezeichnung für das Kalender-Abo ein (z. B. Ferien).');
        } elseif ($validUrl === null) {
            set_flash('danger', 'Die eingegebene iCal-URL ist ungültig. Bitte geben Sie eine gültige https://, http:// oder webcal:// Adresse an.');
        } else {
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE ical_feeds SET name = ?, url = ?, color = ? WHERE id = ?");
                $stmt->execute([$name, $validUrl, $color, $id]);
                $feedId = $id;
                log_activity('Admin: iCal-Abo aktualisiert', "iCal-Feed #{$id} ({$name})");
            } else {
                $stmt = $db->prepare("INSERT INTO ical_feeds (name, url, color, created_at) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $validUrl, $color, date('Y-m-d H:i:s')]);
                $feedId = (int)$db->lastInsertId();
                log_activity('Admin: iCal-Abo angelegt', "Neuer iCal-Feed: {$name}");
            }

            // Direkt versuchen, den Feed abzurufen und in den Cache zu legen
            $ics = get_feed_ics_content('feed_' . $feedId, $validUrl, true);
            if ($ics !== '') {
                set_flash('success', 'Kalender-Abo „' . h($name) . '“ wurde gespeichert und erfolgreich synchronisiert.');
            } else {
                set_flash('warning', 'Kalender-Abo „' . h($name) . '“ wurde gespeichert, konnte aber aktuell noch keine gültigen ICS-Kalenderdaten abrufen. Bitte prüfen Sie ggf. die URL.');
            }
        }
        header('Location: admin.php?tab=ical');
        exit;
    }

    // iCal-Abo löschen
    if ($action === 'admin_delete_ical_feed') {
        require_once __DIR__ . '/ical.php';
        $id = (int)($_POST['id'] ?? 0);
        $feed = $db->query("SELECT name FROM ical_feeds WHERE id = $id")->fetch();
        if ($feed) {
            $db->prepare("DELETE FROM ical_feeds WHERE id = ?")->execute([$id]);
            delete_ical_feed_cache($id);
            set_flash('success', 'Kalender-Abo „' . h($feed['name']) . '“ wurde entfernt.');
            log_activity('Admin: iCal-Abo gelöscht', "iCal-Feed {$feed['name']} gelöscht");
        }
        header('Location: admin.php?tab=ical');
        exit;
    }

    // iCal Feeds manuell aktualisieren
    if ($action === 'admin_refresh_ical') {
        require_once __DIR__ . '/ical.php';
        get_calendar_events_for_range(date('Y-m-d'), date('Y-m-d', strtotime('+36 months')), true);
        set_flash('success', 'Alle hinterlegten iCal-Kalenderfeeds wurden neu synchronisiert.');
        log_activity('Admin: iCal Feeds aktualisiert', 'Manuelle Aktualisierung der iCal-Kalenderfeeds');
        header('Location: ' . safe_redirect($_SERVER['HTTP_REFERER'] ?? 'admin.php?tab=ical', 'admin.php?tab=ical'));
        exit;
    }

    // Datenbank herunterladen / Backup
    if ($action === 'admin_download_db') {
        if (file_exists(DB_FILE)) {
            log_activity('Admin: Backup-Download', 'SQLite-Datenbank heruntergeladen');
            header('Content-Type: application/x-sqlite3');
            header('Content-Disposition: attachment; filename="reserWir_' . date('Y-m-d_H-i') . '.sqlite"');
            header('Content-Length: ' . filesize(DB_FILE));
            readfile(DB_FILE);
            exit;
        } else {
            set_flash('danger', 'Datenbankdatei nicht gefunden.');
            header('Location: admin.php?tab=settings');
            exit;
        }
    }

    // 30-Tage Logfile als CSV exportieren / herunterladen (mit Schutz vor Excel-Formel-Injection)
    if ($action === 'admin_download_log') {
        $filterDate = trim($_POST['filter_date'] ?? '');
        $filterUser = trim($_POST['filter_user'] ?? '');
        $filterType = trim($_POST['filter_type'] ?? '');

        $whereClauses = [];
        $params = [];

        if ($filterDate !== '') {
            $whereClauses[] = "created_at LIKE ?";
            $params[] = $filterDate . '%';
        }
        if ($filterUser !== '') {
            $whereClauses[] = "user_name LIKE ?";
            $params[] = '%' . $filterUser . '%';
        }
        if ($filterType !== '') {
            if ($filterType === 'booking') {
                $whereClauses[] = "action LIKE '%reserviert%'";
            } elseif ($filterType === 'cancel') {
                $whereClauses[] = "(action LIKE '%storniert%' OR action LIKE '%gelöscht%')";
            } elseif ($filterType === 'login') {
                $whereClauses[] = "action LIKE '%Login%'";
            } elseif ($filterType === 'admin') {
                $whereClauses[] = "action LIKE '%Admin%'";
            }
        }

        $whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

        $stmt = $db->prepare("
            SELECT created_at, ip_address, user_name, action, details 
            FROM activity_logs 
            $whereSql
            ORDER BY id DESC
        ");
        $stmt->execute($params);
        $logs = $stmt->fetchAll();

        $filename = "reserWir_Aktivitaeten_" . date('Y-m-d') . ".csv";

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        // UTF-8 BOM für fehlerfreie Umlaute in Excel
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

        // Kopfzeile
        fputcsv($out, ['Zeitpunkt', 'IP-Adresse', 'Benutzer/Kürzel', 'Aktivität', 'Details'], ';');

        foreach ($logs as $row) {
            fputcsv($out, [
                sanitize_csv_cell($row['created_at']),
                sanitize_csv_cell($row['ip_address']),
                sanitize_csv_cell($row['user_name']),
                sanitize_csv_cell($row['action']),
                sanitize_csv_cell($row['details'])
            ], ';');
        }

        fclose($out);
        exit;
    }
}

// Fallback
header('Location: index.php');
exit;
