<?php
// admin.php - Administrator-Bereich für Räume, Ressourcen, Kollegium, Logfile und Einstellungen

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ical.php';
require_admin();

$db = get_db();
$flash = get_flash();

$activeTab = $_GET['tab'] ?? 'rooms';
if (!in_array($activeTab, ['rooms', 'resources', 'dividers', 'ical', 'teachers', 'logs', 'settings'], true)) {
    $activeTab = 'rooms';
}

// Daten abrufen (und natürlich sortieren: 1, 2, ..., 9, 10)
$rooms = $db->query("SELECT * FROM rooms ORDER BY name ASC")->fetchAll();
$resources = $db->query("SELECT * FROM resources ORDER BY name ASC")->fetchAll();
$dividers = $db->query("SELECT * FROM dividers ORDER BY name ASC")->fetchAll();
sort_items_naturally($rooms);
sort_items_naturally($resources);
sort_items_naturally($dividers);
$teachers = $db->query("SELECT * FROM teachers ORDER BY code ASC")->fetchAll();
$icalFeeds = get_ical_sync_status();
$icalColorOptions = get_ical_color_options();
$icalColorShortNames = ['red' => 'Rot', 'yellow' => 'Gelb', 'green' => 'Grün', 'blue' => 'Blau', 'purple' => 'Lila'];

// Zähler für aktive Buchungen
$bookingCount = $db->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
$logCount = $db->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration - reserWir</title>
    <link rel="icon" type="image/png" href="<?= file_exists(__DIR__ . '/favicon.png') ? 'favicon.png' : 'logo_rw.png' ?>">
    <link rel="apple-touch-icon" href="<?= file_exists(__DIR__ . '/favicon.png') ? 'favicon.png' : 'logo_rw.png' ?>">
    <link rel="stylesheet" href="style.css">
</head>
<body class="admin-body">
    <header class="app-header">
        <div class="header-container">
            <div class="header-brand">
                <img src="logo_rw.png" alt="reserWir Logo" class="header-logo-img">
                <div>
                    <h1 class="header-title">reserWir <span style="font-weight: 500; font-size: 15px; color: #9a3412;">| Administration</span></h1>
                    <span class="header-subtitle">Verwaltung von Räumen, Ressourcen, Trennern &amp; System</span>
                </div>
            </div>

            <div class="header-actions">
                <a href="index.php" class="btn btn-secondary">← Zurück zum Plan</a>
                <a href="logout.php" class="btn btn-sm btn-subtle">Abmelden</a>
            </div>
        </div>
    </header>

    <main class="main-content">
        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible">
                <div><?= $flash['message'] ?></div>
                <button type="button" class="alert-close" onclick="this.parentElement.remove()">✕</button>
            </div>
        <?php endif; ?>

        <?php if (is_default_admin_password()): ?>
            <div class="alert alert-danger" style="border-left: 4px solid #b91c1c; margin-bottom: 20px;">
                <strong>⚠️ Wichtiger Sicherheitshinweis:</strong>
                Das Standard-Administrator-Passwort ist noch aktiv!
                Bitte ändern Sie das Master-Passwort umgehend im Reiter <a href="admin.php?tab=settings" style="text-decoration: underline; font-weight: bold;">Passwörter &amp; Backup</a>, um das System gegen unbefugte Zugriffe abzusichern.
            </div>
        <?php endif; ?>

        <!-- Admin Navigation Tabs -->
        <div class="admin-tabs">
            <a href="admin.php?tab=rooms" class="admin-tab <?= $activeTab === 'rooms' ? 'active' : '' ?>">
                📍 Räume (<?= count($rooms) ?>)
            </a>
            <a href="admin.php?tab=resources" class="admin-tab <?= $activeTab === 'resources' ? 'active' : '' ?>">
                📦 Ressourcen (<?= count($resources) ?>)
            </a>
            <a href="admin.php?tab=dividers" class="admin-tab <?= $activeTab === 'dividers' ? 'active' : '' ?>">
                🛡️ Trenner (<?= count($dividers) ?>)
            </a>
            <a href="admin.php?tab=ical" class="admin-tab <?= $activeTab === 'ical' ? 'active' : '' ?>">
                📅 Kalender-Abos (<?= count($icalFeeds) ?>)
            </a>
            <a href="admin.php?tab=teachers" class="admin-tab <?= $activeTab === 'teachers' ? 'active' : '' ?>">
                👥 Kollegium (<?= count($teachers) ?>)
            </a>
            <a href="admin.php?tab=logs" class="admin-tab <?= $activeTab === 'logs' ? 'active' : '' ?>">
                📜 Aktivitäten-Log (<?= (int)$logCount ?>)
            </a>
            <a href="admin.php?tab=settings" class="admin-tab <?= $activeTab === 'settings' ? 'active' : '' ?>">
                🔒 Passwörter &amp; Backup
            </a>
        </div>

        <!-- ================= TAB 1: RÄUME ================= -->
        <?php if ($activeTab === 'rooms'): ?>
            <div class="admin-layout">
                <div class="admin-list-pane">
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">Vorhandene Räume</h2>
                        </div>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Notiz / Ausstattung</th>
                                        <th class="text-right">Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($rooms)): ?>
                                        <tr>
                                            <td colspan="3" class="text-muted text-center">Bisher keine Räume angelegt.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($rooms as $r): ?>
                                        <tr>
                                            <td><strong><?= h($r['name']) ?></strong></td>
                                            <td><?= h($r['note'] ?: '–') ?></td>
                                            <td class="text-right table-actions">
                                                <button type="button" class="btn btn-xs btn-outline" 
                                                        onclick="editRoom(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)">
                                                    Bearbeiten
                                                </button>
                                                <form action="action.php" method="POST" style="display:inline;" 
                                                      onsubmit="return confirm('Möchten Sie diesen Raum wirklich löschen? Alle bestehenden Reservierungen für diesen Raum werden ebenfalls gelöscht!');">
                                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="admin_delete_room">
                                                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                                    <button type="submit" class="btn btn-xs btn-danger-outline">Löschen</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="admin-form-pane">
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title" id="roomFormTitle">Neuen Raum anlegen</h2>
                        </div>
                        <form action="action.php" method="POST" class="card-body">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="admin_save_room">
                            <input type="hidden" name="id" id="roomId" value="0">

                            <div class="form-group">
                                <label for="roomName"><strong>Raumbezeichnung / Name:</strong></label>
                                <input type="text" id="roomName" name="name" required placeholder="z.B. Raum 101 oder Computerraum" class="form-input">
                            </div>

                            <div class="form-group">
                                <label for="roomNote"><strong>Notizfeld (Ausstattung, Hinweise):</strong></label>
                                <textarea id="roomNote" name="note" rows="3" placeholder="z.B. 30 Schülerplätze, Beamer, Dokumentenkamera" class="form-input"></textarea>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary" id="btnSaveRoom">Raum anlegen</button>
                                <button type="button" class="btn btn-subtle" id="btnCancelEditRoom" style="display:none;" onclick="resetRoomForm()">Abbrechen</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        <!-- ================= TAB 2: RESSOURCEN ================= -->
        <?php elseif ($activeTab === 'resources'): ?>
            <div class="admin-layout">
                <div class="admin-list-pane">
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">Vorhandene Ressourcen</h2>
                        </div>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Ressource</th>
                                        <th>Standort (Raum)</th>
                                        <th>Anzahl</th>
                                        <th>Notiz</th>
                                        <th class="text-right">Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($resources)): ?>
                                        <tr>
                                            <td colspan="5" class="text-muted text-center">Bisher keine Ressourcen angelegt.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($resources as $res): ?>
                                        <tr>
                                            <td><strong><?= h($res['name']) ?></strong></td>
                                            <td><?= h($res['room_location'] ?: '–') ?></td>
                                            <td><span class="badge badge-neutral"><?= (int)$res['quantity'] ?>x</span></td>
                                            <td><?= h($res['note'] ?: '–') ?></td>
                                            <td class="text-right table-actions">
                                                <button type="button" class="btn btn-xs btn-outline" 
                                                        onclick="editResource(<?= htmlspecialchars(json_encode($res), ENT_QUOTES) ?>)">
                                                    Bearbeiten
                                                </button>
                                                <form action="action.php" method="POST" style="display:inline;" 
                                                      onsubmit="return confirm('Möchten Sie diese Ressource wirklich löschen? Alle bestehenden Reservierungen dafür werden ebenfalls gelöscht!');">
                                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="admin_delete_resource">
                                                    <input type="hidden" name="id" value="<?= $res['id'] ?>">
                                                    <button type="submit" class="btn btn-xs btn-danger-outline">Löschen</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="admin-form-pane">
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title" id="resFormTitle">Neue Ressource anlegen</h2>
                        </div>
                        <form action="action.php" method="POST" class="card-body">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="admin_save_resource">
                            <input type="hidden" name="id" id="resId" value="0">

                            <div class="form-group">
                                <label for="resName"><strong>Bezeichnung / Name:</strong></label>
                                <input type="text" id="resName" name="name" required placeholder="z.B. iPad-Koffer 1 oder Mobiler Beamer" class="form-input">
                            </div>

                            <div class="form-row">
                                <div class="form-col">
                                    <label for="resLocation"><strong>Standort (Raum / Schrank):</strong></label>
                                    <input type="text" id="resLocation" name="room_location" placeholder="z.B. Lehrerzimmer Schrank 3" class="form-input">
                                </div>
                                <div class="form-col-sm">
                                    <label for="resQuantity"><strong>Anzahl:</strong></label>
                                    <input type="number" id="resQuantity" name="quantity" min="1" value="1" class="form-input">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="resNote"><strong>Notizfeld:</strong></label>
                                <textarea id="resNote" name="note" rows="3" placeholder="z.B. Enthält 16 iPads, Ladegeräte, Adapter USB-C" class="form-input"></textarea>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary" id="btnSaveRes">Ressource anlegen</button>
                                <button type="button" class="btn btn-subtle" id="btnCancelEditRes" style="display:none;" onclick="resetResourceForm()">Abbrechen</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        <!-- ================= TAB 3: TRENNER ================= -->
        <?php elseif ($activeTab === 'dividers'): ?>
            <div class="admin-layout">
                <div class="admin-list-pane">
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">Vorhandene Trenner</h2>
                        </div>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Bezeichnung</th>
                                        <th>Standort (Raum)</th>
                                        <th>Anzahl</th>
                                        <th>Notiz</th>
                                        <th class="text-right">Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($dividers)): ?>
                                        <tr>
                                            <td colspan="5" class="text-muted text-center">Bisher keine Trenner angelegt.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($dividers as $div): ?>
                                        <tr>
                                            <td><strong><?= h($div['name']) ?></strong></td>
                                            <td><?= h($div['room_location'] ?: '–') ?></td>
                                            <td><span class="badge badge-neutral"><?= (int)$div['quantity'] ?>x</span></td>
                                            <td><?= h($div['note'] ?: '–') ?></td>
                                            <td class="text-right table-actions">
                                                <button type="button" class="btn btn-xs btn-outline" 
                                                        onclick="editDivider(<?= htmlspecialchars(json_encode($div), ENT_QUOTES) ?>)">
                                                    Bearbeiten
                                                </button>
                                                <form action="action.php" method="POST" style="display:inline;" 
                                                      onsubmit="return confirm('Möchten Sie diesen Trenner wirklich löschen? Alle bestehenden Reservierungen dafür werden ebenfalls gelöscht!');">
                                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="admin_delete_divider">
                                                    <input type="hidden" name="id" value="<?= $div['id'] ?>">
                                                    <button type="submit" class="btn btn-xs btn-danger-outline">Löschen</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="admin-form-pane">
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title" id="divFormTitle">Neuen Trenner anlegen</h2>
                        </div>
                        <form action="action.php" method="POST" class="card-body">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="admin_save_divider">
                            <input type="hidden" name="id" id="divId" value="0">

                            <div class="form-group">
                                <label for="divName"><strong>Bezeichnung / Name:</strong></label>
                                <input type="text" id="divName" name="name" required placeholder="z.B. Trenner-Satz 1 (Klassenarbeiten)" class="form-input">
                            </div>

                            <div class="form-row">
                                <div class="form-col">
                                    <label for="divLocation"><strong>Standort (Raum / Schrank):</strong></label>
                                    <input type="text" id="divLocation" name="room_location" placeholder="z.B. Lehrerzimmer Schrank B" class="form-input">
                                </div>
                                <div class="form-col-sm">
                                    <label for="divQuantity"><strong>Anzahl:</strong></label>
                                    <input type="number" id="divQuantity" name="quantity" min="1" value="15" class="form-input">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="divNote"><strong>Notizfeld:</strong></label>
                                <textarea id="divNote" name="note" rows="3" placeholder="z.B. 15 Sichtschutz-Aufsteller auf Rollwagen" class="form-input"></textarea>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary" id="btnSaveDiv">Trenner anlegen</button>
                                <button type="button" class="btn btn-subtle" id="btnCancelEditDiv" style="display:none;" onclick="resetDividerForm()">Abbrechen</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        <!-- ================= TAB 4: KALENDER-ABOS (iCal) ================= -->
        <?php elseif ($activeTab === 'ical'): ?>
            <div class="admin-layout">
                <div class="admin-list-pane">
                    <div class="card">
                        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                            <div>
                                <h2 class="card-title">Hinterlegte iCal-Kalenderabos</h2>
                                <small class="text-muted">Termine aus diesen Feeds werden automatisch in der Zeile „📅 Kalender“ unter dem Datum angezeigt.</small>
                            </div>
                            <?php if (!empty($icalFeeds)): ?>
                                <form action="action.php" method="POST" style="margin: 0;">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="admin_refresh_ical">
                                    <button type="submit" class="btn btn-sm btn-secondary">
                                        🔄 Alle Feeds jetzt synchronisieren
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Bezeichnung</th>
                                        <th>Farbe</th>
                                        <th>iCal-URL</th>
                                        <th>Status / Letzter Abruf</th>
                                        <th class="text-right">Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($icalFeeds)): ?>
                                        <tr>
                                            <td colspan="5" class="text-muted text-center" style="padding: 24px;">
                                                Noch keine iCal-Kalenderabos hinterlegt.<br>
                                                <small>Fügen Sie rechts ein neues Kalender-Abo (z. B. Ferien, Feiertage oder Schulkalender) hinzu.</small>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($icalFeeds as $fKey => $fStat): ?>
                                        <tr>
                                            <td><strong><?= h($fStat['name']) ?></strong></td>
                                            <td>
                                                <span class="cal-pill cal-pill-<?= h($fStat['color']) ?>" style="display: inline-flex;">
                                                    <span class="cal-pill-dot"></span>
                                                    <?= h($icalColorShortNames[$fStat['color']] ?? ucfirst($fStat['color'])) ?>
                                                </span>
                                            </td>
                                            <td style="max-width: 220px;">
                                                <small class="text-muted" style="display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= h($fStat['url']) ?>">
                                                    <?= h($fStat['url']) ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?php if ($fStat['status'] === 'error' && !$fStat['cached']): ?>
                                                    <span class="badge badge-danger">Abruf fehlgeschlagen</span>
                                                <?php elseif ($fStat['cached']): ?>
                                                    <span class="badge badge-success">Aktiv</span>
                                                    <div style="font-size: 11px; color: #64748b; margin-top: 2px;"><?= h($fStat['last_fetch']) ?></div>
                                                <?php else: ?>
                                                    <span class="badge badge-neutral">Wartet auf Abruf</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-right table-actions">
                                                <button type="button" class="btn btn-xs btn-outline" 
                                                        onclick="editIcalFeed(<?= htmlspecialchars(json_encode([
                                                            'id' => $fStat['id'],
                                                            'name' => $fStat['name'],
                                                            'url' => $fStat['url'],
                                                            'color' => $fStat['color']
                                                        ]), ENT_QUOTES) ?>)">
                                                    Bearbeiten
                                                </button>
                                                <form action="action.php" method="POST" style="display:inline;" 
                                                      onsubmit="return confirm('Möchten Sie das Kalender-Abo <?= addslashes(h($fStat['name'])) ?> wirklich entfernen?');">
                                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="admin_delete_ical_feed">
                                                    <input type="hidden" name="id" value="<?= (int)$fStat['id'] ?>">
                                                    <button type="submit" class="btn btn-xs btn-danger-outline">Löschen</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="admin-form-pane">
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title" id="icalFormTitle">Neues iCal-Abo hinzufügen</h2>
                        </div>
                        <form action="action.php" method="POST" class="card-body">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="admin_save_ical_feed">
                            <input type="hidden" name="id" id="icalId" value="0">

                            <div class="form-group">
                                <label for="icalName"><strong>Bezeichnung / Kategorie:</strong></label>
                                <input type="text" id="icalName" name="name" required placeholder="z.B. Ferien, Feiertage oder Schultermine" class="form-input">
                            </div>

                            <div class="form-group">
                                <label for="icalUrl"><strong>iCal-URL (.ics Adresse):</strong></label>
                                <input type="url" id="icalUrl" name="url" required placeholder="https://.../kalender.ics" class="form-input">
                                <small class="form-hint">Öffentliche iCal-Adresse (<code>https://</code> oder <code>webcal://</code>) aus Nextcloud, Google Kalender, IServ, Kalender.digital etc.</small>
                            </div>

                            <div class="form-group">
                                <label for="icalColor"><strong>Darstellungsfarbe im Plan:</strong></label>
                                <select id="icalColor" name="color" class="form-select">
                                    <?php foreach ($icalColorOptions as $cKey => $cLabel): ?>
                                        <option value="<?= h($cKey) ?>"><?= h($cLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary" id="btnSaveIcal">Kalender-Abo speichern</button>
                                <button type="button" class="btn btn-subtle" id="btnCancelEditIcal" style="display:none;" onclick="resetIcalForm()">Abbrechen</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        <!-- ================= TAB 5: KOLLEGIUM ================= -->
        <?php elseif ($activeTab === 'teachers'): ?>
            <div class="admin-layout">
                <div class="admin-list-pane">
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">Eingetragene Lehrkräfte (<?= count($teachers) ?>)</h2>
                        </div>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Kürzel</th>
                                        <th>PIN-Status</th>
                                        <th>Registriert am</th>
                                        <th class="text-right">Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($teachers)): ?>
                                        <tr>
                                            <td colspan="4" class="text-muted text-center">Bisher keine Lehrkräfte angelegt.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($teachers as $t): ?>
                                        <?php 
                                            $isLocked = !empty($t['locked_until']) && (int)$t['locked_until'] > time();
                                            $remainingLock = $isLocked ? ((int)$t['locked_until'] - time()) : 0;
                                        ?>
                                        <tr>
                                            <td>
                                                <strong style="font-size: 15px; letter-spacing: 0.5px;"><?= h($t['code']) ?></strong>
                                                <?php if (!empty($t['name'])): ?>
                                                    <span class="text-muted" style="margin-left: 6px;">(<?= h($t['name']) ?>)</span>
                                                <?php endif; ?>
                                                <?php if ($isLocked): ?>
                                                    <span class="badge badge-danger" style="margin-left: 8px;">
                                                        🚨 Gesperrt (noch <?= $remainingLock ?>s)
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($t['pin_hash'])): ?>
                                                    <span class="badge" style="background: #e0f2fe; color: #0369a1;" title="Schul-Passwort für dieses Kürzel deaktiviert">
                                                        🔑 Eigene PIN aktiv
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge badge-neutral">Schul-Passwort</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= date('d.m.Y', strtotime($t['created_at'])) ?></td>
                                            <td class="text-right table-actions">
                                                <?php if ($isLocked): ?>
                                                    <form action="action.php" method="POST" style="display:inline;">
                                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="admin_unlock_teacher">
                                                        <input type="hidden" name="teacher_id" value="<?= $t['id'] ?>">
                                                        <button type="submit" class="btn btn-xs btn-primary">Entsperren</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if (!empty($t['pin_hash'])): ?>
                                                    <form action="action.php" method="POST" style="display:inline;" 
                                                          onsubmit="return confirm('Möchten Sie die PIN für <?= addslashes(h($t['code'])) ?> wirklich zurücksetzen? Die Lehrkraft nutzt dann wieder das Schul-Passwort.');">
                                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="admin_reset_teacher_pin">
                                                        <input type="hidden" name="teacher_id" value="<?= $t['id'] ?>">
                                                        <button type="submit" class="btn btn-xs btn-outline">PIN reset</button>
                                                    </form>
                                                <?php endif; ?>
                                                <form action="action.php" method="POST" style="display:inline;" 
                                                      onsubmit="return confirm('Möchten Sie das Kürzel <?= addslashes(h($t['code'])) ?> wirklich entfernen?');">
                                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="admin_delete_teacher">
                                                    <input type="hidden" name="teacher_id" value="<?= $t['id'] ?>">
                                                    <button type="submit" class="btn btn-xs btn-danger-outline">Löschen</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="admin-form-pane">
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">Kürzel importieren</h2>
                        </div>
                        <form action="action.php" method="POST" class="card-body">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="admin_add_teachers">

                            <div class="form-group">
                                <label for="rawCodes"><strong>Kürzel der Kollegen (kommagetrennt oder zeilenweise):</strong></label>
                                <textarea id="rawCodes" name="raw_codes" rows="6" required 
                                          placeholder="z.B. MÜL, SCH, BEC, WAG, MEI, KÖH..." class="form-input uppercase-input"></textarea>
                                <small class="form-hint">Groß-/Kleinschreibung wird automatisch angepasst. Bereits vorhandene Kürzel werden übersprungen.</small>
                            </div>

                            <div class="alert alert-subtle" style="font-size: 13px; margin: 14px 0;">
                                💡 <strong>Automatische Aufnahme:</strong> Wenn ein Kollege sich mit einem neuen Kürzel und dem richtigen Schul-Passwort anmeldet, wird er ebenfalls automatisch in diese Liste aufgenommen!
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">Kürzel speichern</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        <!-- ================= TAB 4: AKTIVITÄTEN-LOG (30 TAGE) ================= -->
        <?php elseif ($activeTab === 'logs'): ?>
            <?php 
                $filterDate = trim($_GET['filter_date'] ?? '');
                $filterUser = trim($_GET['filter_user'] ?? '');
                $filterType = trim($_GET['filter_type'] ?? '');
                $page = max(1, (int)($_GET['page'] ?? 1));
                $perPage = 50;

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

                $countStmt = $db->prepare("SELECT COUNT(*) FROM activity_logs $whereSql");
                $countStmt->execute($params);
                $filteredCount = (int)$countStmt->fetchColumn();

                $totalPages = max(1, (int)ceil($filteredCount / $perPage));
                $page = min($page, $totalPages);
                $offset = ($page - 1) * $perPage;

                $stmt = $db->prepare("
                    SELECT * FROM activity_logs 
                    $whereSql
                    ORDER BY id DESC 
                    LIMIT ? OFFSET ?
                ");
                $queryParams = array_merge($params, [$perPage, $offset]);
                $stmt->execute($queryParams);
                $logs = $stmt->fetchAll();

                // Liste aller im Log vorhandenen Benutzer für das Filter-Dropdown
                $userList = $db->query("SELECT DISTINCT user_name FROM activity_logs ORDER BY user_name ASC")->fetchAll(PDO::FETCH_COLUMN);

                // Helper für Seitenlinks
                function make_log_url($targetPage, $fDate, $fUser, $fType) {
                    return 'admin.php?tab=logs&page=' . $targetPage . 
                           '&filter_date=' . urlencode($fDate) . 
                           '&filter_user=' . urlencode($fUser) . 
                           '&filter_type=' . urlencode($fType);
                }
            ?>
            <div class="card">
                <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                    <div>
                        <h2 class="card-title">📜 Aktivitäten-Protokoll (letzte 30 Tage)</h2>
                        <small class="text-muted">Maximal 50 Einträge pro Seite für minimale Serverlast und blitzschnelles Laden.</small>
                    </div>
                    <form action="action.php" method="POST" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="admin_download_log">
                        <input type="hidden" name="filter_date" value="<?= h($filterDate) ?>">
                        <input type="hidden" name="filter_user" value="<?= h($filterUser) ?>">
                        <input type="hidden" name="filter_type" value="<?= h($filterType) ?>">
                        <button type="submit" class="btn btn-sm btn-primary">
                            📥 <?= (!empty($filterDate) || !empty($filterUser) || !empty($filterType)) ? 'Gefiltertes Log' : 'Gesamtes Log' ?> (CSV) herunterladen
                        </button>
                    </form>
                </div>

                <!-- Filter-Leiste -->
                <div class="log-filter-box">
                    <form method="GET" action="admin.php" class="log-filter-form">
                        <input type="hidden" name="tab" value="logs">

                        <div class="filter-field">
                            <label for="filter_date"><strong>Datum:</strong></label>
                            <input type="date" id="filter_date" name="filter_date" value="<?= h($filterDate) ?>" class="form-input form-input-sm">
                        </div>

                        <div class="filter-field">
                            <label for="filter_user"><strong>Benutzer / Kürzel:</strong></label>
                            <select id="filter_user" name="filter_user" class="form-select form-select-sm">
                                <option value="">Alle Benutzer</option>
                                <?php foreach ($userList as $u): ?>
                                    <option value="<?= h($u) ?>" <?= $filterUser === $u ? 'selected' : '' ?>>
                                        <?= h($u) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-field">
                            <label for="filter_type"><strong>Aktivitäts-Typ:</strong></label>
                            <select id="filter_type" name="filter_type" class="form-select form-select-sm">
                                <option value="">Alle Aktionen</option>
                                <option value="booking" <?= $filterType === 'booking' ? 'selected' : '' ?>>Nur Reservierungen</option>
                                <option value="cancel" <?= $filterType === 'cancel' ? 'selected' : '' ?>>Nur Stornierungen</option>
                                <option value="login" <?= $filterType === 'login' ? 'selected' : '' ?>>Nur Logins &amp; Sperren</option>
                                <option value="admin" <?= $filterType === 'admin' ? 'selected' : '' ?>>Nur Admin-Aktionen</option>
                            </select>
                        </div>

                        <div class="filter-buttons">
                            <button type="submit" class="btn btn-sm btn-secondary">Filtern</button>
                            <?php if ($filterDate !== '' || $filterUser !== '' || $filterType !== ''): ?>
                                <a href="admin.php?tab=logs" class="btn btn-sm btn-subtle" title="Filter zurücksetzen">Zurücksetzen</a>
                            <?php endif; ?>
                        </div>
                    </form>

                    <!-- Schnellauswahl für Tage -->
                    <div class="quick-filter-chips">
                        <span class="quick-chip-label">Schnellfilter:</span>
                        <a href="admin.php?tab=logs&filter_date=<?= date('Y-m-d') ?>" class="filter-chip <?= $filterDate === date('Y-m-d') ? 'active' : '' ?>">Heute</a>
                        <a href="admin.php?tab=logs&filter_date=<?= date('Y-m-d', strtotime('-1 day')) ?>" class="filter-chip <?= $filterDate === date('Y-m-d', strtotime('-1 day')) ? 'active' : '' ?>">Gestern</a>
                        <a href="admin.php?tab=logs" class="filter-chip <?= empty($filterDate) && empty($filterUser) && empty($filterType) ? 'active' : '' ?>">Alle 30 Tage</a>
                    </div>
                </div>

                <!-- Status & Zähler -->
                <div class="log-status-bar">
                    <span>
                        Gefunden: <strong><?= $filteredCount ?></strong> Aktivitäten 
                        <?php if ($filteredCount > 0): ?>
                            (Einträge <?= $offset + 1 ?> bis <?= min($filteredCount, $offset + $perPage) ?>)
                        <?php endif; ?>
                    </span>
                    <?php if ($totalPages > 1): ?>
                        <span class="text-muted">Seite <?= $page ?> von <?= $totalPages ?></span>
                    <?php endif; ?>
                </div>

                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width: 140px;">Zeitpunkt</th>
                                <th style="width: 120px;">IP-Adresse</th>
                                <th style="width: 120px;">Benutzer</th>
                                <th style="width: 180px;">Aktivität</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="5" class="text-muted text-center" style="padding: 24px;">Keine Aktivitäten gefunden, die den Filterkriterien entsprechen.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td style="white-space: nowrap; font-size: 13px;">
                                        <?= date('d.m.Y H:i:s', strtotime($log['created_at'])) ?>
                                    </td>
                                    <td>
                                        <code style="font-size: 12px; background: #f1f5f9; padding: 2px 5px; border-radius: 4px;"><?= h($log['ip_address']) ?></code>
                                    </td>
                                    <td>
                                        <strong><?= h($log['user_name']) ?></strong>
                                    </td>
                                    <td>
                                        <?php 
                                            $act = $log['action'];
                                            $pillClass = 'badge-neutral';
                                            if (str_contains($act, 'reserviert') || str_contains($act, 'angelegt')) $pillClass = 'badge-success';
                                            elseif (str_contains($act, 'storniert') || str_contains($act, 'gelöscht') || str_contains($act, 'abgelehnt')) $pillClass = 'badge-danger';
                                            elseif (str_contains($act, 'Login')) $pillClass = 'badge-info';
                                        ?>
                                        <span class="badge <?= $pillClass ?>"><?= h($act) ?></span>
                                    </td>
                                    <td style="font-size: 13px;">
                                        <?= h($log['details']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination-Navigation -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination-bar">
                        <a href="<?= make_log_url(1, $filterDate, $filterUser, $filterType) ?>" 
                           class="page-link <?= $page <= 1 ? 'disabled' : '' ?>" title="Erste Seite">« Erste</a>
                        <a href="<?= make_log_url($page - 1, $filterDate, $filterUser, $filterType) ?>" 
                           class="page-link <?= $page <= 1 ? 'disabled' : '' ?>">‹ Zurück</a>

                        <span class="page-indicator">Seite <strong><?= $page ?></strong> von <strong><?= $totalPages ?></strong></span>

                        <a href="<?= make_log_url($page + 1, $filterDate, $filterUser, $filterType) ?>" 
                           class="page-link <?= $page >= $totalPages ? 'disabled' : '' ?>">Weiter ›</a>
                        <a href="<?= make_log_url($totalPages, $filterDate, $filterUser, $filterType) ?>" 
                           class="page-link <?= $page >= $totalPages ? 'disabled' : '' ?>" title="Letzte Seite">Letzte »</a>
                    </div>
                <?php endif; ?>
            </div>

        <!-- ================= TAB 5: PASSWÖRTER & BACKUP ================= -->
        <?php elseif ($activeTab === 'settings'): ?>
            <div class="settings-grid">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Passwörter ändern</h2>
                    </div>
                    <form action="action.php" method="POST" class="card-body">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="admin_change_passwords">

                        <div class="form-group">
                            <label for="newUserPass"><strong>Neues Kollegium-Passwort (Nutzer):</strong></label>
                            <input type="password" id="newUserPass" name="new_user_password" placeholder="Freilassen, falls unverändert" class="form-input">
                            <small class="form-hint">Wird von Lehrkräften zur Erstanmeldung benötigt (Standard: <code>schule</code>).</small>
                        </div>

                        <div class="form-group">
                            <label for="newAdminPass"><strong>Neues Administrator-Passwort:</strong></label>
                            <input type="password" id="newAdminPass" name="new_admin_password" placeholder="Freilassen, falls unverändert" class="form-input">
                            <small class="form-hint">Wird für den Zugriff auf diesen Administrationsbereich benötigt.</small>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">Passwörter speichern</button>
                        </div>
                    </form>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Datenbank &amp; Sicherung</h2>
                    </div>
                    <div class="card-body">
                        <p>Das System speichert alle Räume, Ressourcen, Lehrkräfte und Buchungen in einer einzigen SQLite-Datei.</p>
                        
                        <div class="stat-badge-box">
                            <div class="stat-item">
                                <div class="stat-number"><?= count($rooms) ?></div>
                                <div class="stat-label">Räume</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?= count($resources) ?></div>
                                <div class="stat-label">Ressourcen</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?= count($dividers) ?></div>
                                <div class="stat-label">Trenner</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?= (int)$bookingCount ?></div>
                                <div class="stat-label">Gebuchte Stunden</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?= count($teachers) ?></div>
                                <div class="stat-label">Lehrkräfte</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?= count($icalFeeds) ?></div>
                                <div class="stat-label">iCal-Abos</div>
                            </div>
                        </div>

                        <p class="text-muted mt-3">
                            Sie können die gesamte Datenbankdatei jederzeit als Sicherungskopie herunterladen:
                        </p>

                        <form action="action.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="admin_download_db">
                            <button type="submit" class="btn btn-outline btn-block">
                                💾 SQLite-Datenbank herunterladen
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <script>
        function editRoom(room) {
            document.getElementById('roomFormTitle').textContent = 'Raum bearbeiten';
            document.getElementById('roomId').value = room.id;
            document.getElementById('roomName').value = room.name;
            document.getElementById('roomNote').value = room.note || '';
            document.getElementById('btnSaveRoom').textContent = 'Änderungen speichern';
            document.getElementById('btnCancelEditRoom').style.display = 'inline-block';
            document.getElementById('roomName').focus();
        }

        function resetRoomForm() {
            document.getElementById('roomFormTitle').textContent = 'Neuen Raum anlegen';
            document.getElementById('roomId').value = '0';
            document.getElementById('roomName').value = '';
            document.getElementById('roomNote').value = '';
            document.getElementById('btnSaveRoom').textContent = 'Raum anlegen';
            document.getElementById('btnCancelEditRoom').style.display = 'none';
        }

        function editResource(res) {
            document.getElementById('resFormTitle').textContent = 'Ressource bearbeiten';
            document.getElementById('resId').value = res.id;
            document.getElementById('resName').value = res.name;
            document.getElementById('resLocation').value = res.room_location || '';
            document.getElementById('resQuantity').value = res.quantity || 1;
            document.getElementById('resNote').value = res.note || '';
            document.getElementById('btnSaveRes').textContent = 'Änderungen speichern';
            document.getElementById('btnCancelEditRes').style.display = 'inline-block';
            document.getElementById('resName').focus();
        }

        function resetResourceForm() {
            document.getElementById('resFormTitle').textContent = 'Neue Ressource anlegen';
            document.getElementById('resId').value = '0';
            document.getElementById('resName').value = '';
            document.getElementById('resLocation').value = '';
            document.getElementById('resQuantity').value = '1';
            document.getElementById('resNote').value = '';
            document.getElementById('btnSaveRes').textContent = 'Ressource anlegen';
            document.getElementById('btnCancelEditRes').style.display = 'none';
        }

        function editDivider(div) {
            document.getElementById('divFormTitle').textContent = 'Trenner bearbeiten';
            document.getElementById('divId').value = div.id;
            document.getElementById('divName').value = div.name;
            document.getElementById('divLocation').value = div.room_location || '';
            document.getElementById('divQuantity').value = div.quantity || 1;
            document.getElementById('divNote').value = div.note || '';
            document.getElementById('btnSaveDiv').textContent = 'Änderungen speichern';
            document.getElementById('btnCancelEditDiv').style.display = 'inline-block';
            document.getElementById('divName').focus();
        }

        function resetDividerForm() {
            document.getElementById('divFormTitle').textContent = 'Neuen Trenner anlegen';
            document.getElementById('divId').value = '0';
            document.getElementById('divName').value = '';
            document.getElementById('divLocation').value = '';
            document.getElementById('divQuantity').value = '15';
            document.getElementById('divNote').value = '';
            document.getElementById('btnSaveDiv').textContent = 'Trenner anlegen';
            document.getElementById('btnCancelEditDiv').style.display = 'none';
        }

        function editIcalFeed(feed) {
            document.getElementById('icalFormTitle').textContent = 'iCal-Abo bearbeiten';
            document.getElementById('icalId').value = feed.id;
            document.getElementById('icalName').value = feed.name;
            document.getElementById('icalUrl').value = feed.url;
            document.getElementById('icalColor').value = feed.color || 'blue';
            document.getElementById('btnSaveIcal').textContent = 'Änderungen speichern';
            document.getElementById('btnCancelEditIcal').style.display = 'inline-block';
            document.getElementById('icalName').focus();
        }

        function resetIcalForm() {
            document.getElementById('icalFormTitle').textContent = 'Neues iCal-Abo hinzufügen';
            document.getElementById('icalId').value = '0';
            document.getElementById('icalName').value = '';
            document.getElementById('icalUrl').value = '';
            document.getElementById('icalColor').value = 'blue';
            document.getElementById('btnSaveIcal').textContent = 'Kalender-Abo speichern';
            document.getElementById('btnCancelEditIcal').style.display = 'none';
        }
    </script>
</body>
</html>
