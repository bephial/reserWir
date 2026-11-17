<?php
// index.php - Hauptansicht des Schul-Reservierungssystems

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ical.php';
require_login();

$db = get_db();
$flash = get_flash();
$currentTeacherCode = get_logged_in_teacher_code();
$isAdmin = is_admin();

// Alle Räume, Ressourcen und Trenner laden (und natürlich sortieren: 1, 2, ..., 9, 10)
$rooms = $db->query("SELECT * FROM rooms ORDER BY name ASC")->fetchAll();
$resources = $db->query("SELECT * FROM resources ORDER BY name ASC")->fetchAll();
$dividers = $db->query("SELECT * FROM dividers ORDER BY name ASC")->fetchAll();
sort_items_naturally($rooms);
sort_items_naturally($resources);
sort_items_naturally($dividers);

// Ansichtsmodus: 'week' (Wochenansicht für 1 Objekt) oder 'day' (Tagesansicht für eine Kategorie)
$viewMode = $_GET['view'] ?? 'week';
if (!in_array($viewMode, ['week', 'day'], true)) {
    $viewMode = 'week';
}

// Aktuelles Datum bestimmen (Wochenenden überspringen auf nächsten Montag)
$dateParam = $_GET['date'] ?? date('Y-m-d');
$currentTs = strtotime($dateParam);
if (!$currentTs) {
    $currentTs = time();
}

$dayOfWeek = (int)date('N', $currentTs); // 1 = Mo, 7 = So
if ($dayOfWeek > 5) { // Samstag oder Sonntag -> Montag der Folgewoche
    $currentTs = strtotime('+ ' . (8 - $dayOfWeek) . ' days', $currentTs);
}

// Wochenstart (Montag) und Wochenende (Freitag)
$mondayTs = ($dayOfWeek === 1) ? $currentTs : strtotime('last monday', $currentTs);
$fridayTs = strtotime('+4 days', $mondayTs);

$mondayDate = date('Y-m-d', $mondayTs);
$fridayDate = date('Y-m-d', $fridayTs);
$selectedDate = date('Y-m-d', $currentTs);

// Ausgewähltes Item für die Wochenansicht (Standard: erster Raum, erste Ressource oder erster Trenner)
$defaultItemKey = !empty($rooms) ? 'room:' . $rooms[0]['id'] : (!empty($resources) ? 'resource:' . $resources[0]['id'] : (!empty($dividers) ? 'divider:' . $dividers[0]['id'] : ''));
$selectedItemKey = $_GET['item'] ?? $defaultItemKey;

$selectedItemType = '';
$selectedItemId = 0;
$selectedItem = null;

if ($selectedItemKey && strpos($selectedItemKey, ':') !== false) {
    [$selectedItemType, $selectedItemId] = explode(':', $selectedItemKey);
    $selectedItemId = (int)$selectedItemId;
    if ($selectedItemType === 'room') {
        foreach ($rooms as $r) {
            if ($r['id'] === $selectedItemId) { $selectedItem = $r; break; }
        }
    } elseif ($selectedItemType === 'resource') {
        foreach ($resources as $res) {
            if ($res['id'] === $selectedItemId) { $selectedItem = $res; break; }
        }
    } elseif ($selectedItemType === 'divider') {
        foreach ($dividers as $div) {
            if ($div['id'] === $selectedItemId) { $selectedItem = $div; break; }
        }
    }
}

// Kategorie für die gesplittete Tagesansicht ('rooms', 'resources', 'dividers')
$defaultCat = ($selectedItemType === 'resource') ? 'resources' : (($selectedItemType === 'divider') ? 'dividers' : 'rooms');
$dayCategory = $_GET['cat'] ?? $defaultCat;
if (!in_array($dayCategory, ['rooms', 'resources', 'dividers'], true)) {
    $dayCategory = 'rooms';
}

// Buchungen & iCal-Kalendertermine laden
$bookingsLookup = []; // key: "YYYY-MM-DD:period:itemType:itemId" => booking row
$calEvents = [];

if ($viewMode === 'week') {
    $calEvents = get_calendar_events_for_range($mondayDate, $fridayDate);
} else {
    $calEvents = get_calendar_events_for_range($selectedDate, $selectedDate);
}

if ($viewMode === 'week' && $selectedItem) {
    $stmt = $db->prepare("
        SELECT b.*, 
               (SELECT COUNT(DISTINCT b2.booking_date) FROM bookings b2 WHERE b2.series_id = b.series_id) as series_days,
               (SELECT COUNT(*) FROM bookings b2 WHERE b2.series_id = b.series_id) as series_count
        FROM bookings b
        WHERE b.item_type = ? AND b.item_id = ? AND b.booking_date BETWEEN ? AND ?
    ");
    $stmt->execute([$selectedItemType, $selectedItemId, $mondayDate, $fridayDate]);
    while ($row = $stmt->fetch()) {
        $key = "{$row['booking_date']}:{$row['period']}";
        $bookingsLookup[$key] = $row;
    }
} elseif ($viewMode === 'day') {
    $stmt = $db->prepare("
        SELECT b.*,
               (SELECT COUNT(DISTINCT b2.booking_date) FROM bookings b2 WHERE b2.series_id = b.series_id) as series_days,
               (SELECT COUNT(*) FROM bookings b2 WHERE b2.series_id = b.series_id) as series_count
        FROM bookings b
        WHERE b.booking_date = ?
    ");
    $stmt->execute([$selectedDate]);
    while ($row = $stmt->fetch()) {
        $key = "{$row['item_type']}:{$row['item_id']}:{$row['period']}";
        $bookingsLookup[$key] = $row;
    }
}

// Navigation Datums-Links berechnen
// Woche:
$prevWeekDate = date('Y-m-d', strtotime('-1 week', $mondayTs));
$nextWeekDate = date('Y-m-d', strtotime('+1 week', $mondayTs));
$thisWeekDate = date('Y-m-d');

// Tag (Mo-Fr überspringen Sa/So):
$prevDayTs = strtotime('-1 day', $currentTs);
if ((int)date('N', $prevDayTs) === 7) $prevDayTs = strtotime('-2 days', $prevDayTs); // Fr statt So
$prevDayDate = date('Y-m-d', $prevDayTs);

$nextDayTs = strtotime('+1 day', $currentTs);
if ((int)date('N', $nextDayTs) === 6) $nextDayTs = strtotime('+2 days', $nextDayTs); // Mo statt Sa
$nextDayDate = date('Y-m-d', $nextDayTs);
$todayDate = date('Y-m-d');

// Tage der aktuellen Woche für die Wochenansicht
$weekDays = [];
$germanDayNames = [1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag'];
for ($i = 0; $i < 5; $i++) {
    $ts = strtotime("+$i days", $mondayTs);
    $dStr = date('Y-m-d', $ts);
    $weekDays[] = [
        'date' => $dStr,
        'day_name' => $germanDayNames[$i + 1],
        'formatted' => date('d.m.', $ts),
        'is_today' => ($dStr === date('Y-m-d'))
    ];
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>reserWir - Schul-Reservierungssystem</title>
    <link rel="icon" type="image/png" href="<?= file_exists(__DIR__ . '/favicon.png') ? 'favicon.png' : 'logo_rw.png' ?>">
    <link rel="apple-touch-icon" href="<?= file_exists(__DIR__ . '/favicon.png') ? 'favicon.png' : 'logo_rw.png' ?>">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header class="app-header">
        <div class="header-container">
            <div class="header-brand">
                <img src="logo_rw.png" alt="reserWir Logo" class="header-logo-img">
                <div>
                    <h1 class="header-title">reserWir</h1>
                    <span class="header-subtitle">Räume, Ressourcen &amp; Trenner im Stundenplan</span>
                </div>
            </div>

            <div class="header-actions">
                <button type="button" class="btn btn-primary" onclick="openNewBookingModal()">
                    <span class="btn-icon">+</span> Neue Reservierung
                </button>

                <div class="user-badge">
                    <span class="role-pill <?= is_admin() ? 'role-admin' : 'role-user' ?>">
                        <?= is_admin() ? '👑 Administrator' : '👤 ' . h($currentTeacherCode) ?>
                    </span>
                    <?php if (!is_admin()): ?>
                        <button type="button" class="btn btn-xs btn-subtle" onclick="openModal('modalChangePin')" title="Eigene 4-stellige PIN setzen">
                            🔑 PIN
                        </button>
                    <?php endif; ?>
                    <?php if (is_admin()): ?>
                        <a href="admin.php" class="btn btn-sm btn-outline">Verwaltung</a>
                    <?php else: ?>
                        <button type="button" class="btn btn-sm btn-subtle" onclick="openModal('modalAdminLogin')" title="Zum Administrationsbereich">
                            ⚙️ Admin
                        </button>
                    <?php endif; ?>
                    <a href="logout.php" class="btn btn-sm btn-subtle" title="Abmelden">Abmelden</a>
                </div>
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

        <!-- Kontroll-Leiste (Filter, Datum, Ansicht) -->
        <section class="toolbar-card">
            <div class="toolbar-top">
                <!-- Ansicht umschalten -->
                <div class="view-switch">
                    <a href="index.php?view=week&date=<?= urlencode($selectedDate) ?>&item=<?= urlencode($selectedItemKey) ?>" 
                       class="view-btn <?= $viewMode === 'week' ? 'active' : '' ?>">
                        📅 Wochenansicht
                    </a>
                    <a href="index.php?view=day&cat=rooms&date=<?= urlencode($selectedDate) ?>&item=<?= urlencode($selectedItemKey) ?>" 
                       class="view-btn <?= ($viewMode === 'day' && $dayCategory === 'rooms') ? 'active' : '' ?>">
                        📍 Tag: Räume
                    </a>
                    <a href="index.php?view=day&cat=resources&date=<?= urlencode($selectedDate) ?>&item=<?= urlencode($selectedItemKey) ?>" 
                       class="view-btn <?= ($viewMode === 'day' && $dayCategory === 'resources') ? 'active' : '' ?>">
                        📦 Tag: Ressourcen
                    </a>
                    <a href="index.php?view=day&cat=dividers&date=<?= urlencode($selectedDate) ?>&item=<?= urlencode($selectedItemKey) ?>" 
                       class="view-btn <?= ($viewMode === 'day' && $dayCategory === 'dividers') ? 'active' : '' ?>">
                        🛡️ Tag: Trenner
                    </a>
                </div>

                <!-- Datums-Navigation -->
                <div class="date-nav">
                    <?php if ($viewMode === 'week'): ?>
                        <div class="date-nav-buttons">
                            <a href="index.php?view=week&date=<?= $prevWeekDate ?>&item=<?= urlencode($selectedItemKey) ?>" class="btn btn-sm btn-secondary">
                                <span class="nav-text-desktop">« Vorherige Woche</span>
                                <span class="nav-text-mobile">« Vor</span>
                            </a>
                            <a href="index.php?view=week&date=<?= $thisWeekDate ?>&item=<?= urlencode($selectedItemKey) ?>" class="btn btn-sm btn-outline">
                                <span class="nav-text-desktop">Diese Woche</span>
                                <span class="nav-text-mobile">Aktuell</span>
                            </a>
                            <a href="index.php?view=week&date=<?= $nextWeekDate ?>&item=<?= urlencode($selectedItemKey) ?>" class="btn btn-sm btn-secondary">
                                <span class="nav-text-desktop">Nächste Woche »</span>
                                <span class="nav-text-mobile">Weiter »</span>
                            </a>
                        </div>
                        <div class="date-nav-details">
                            <span class="date-display">
                                <strong><?= date('d.m.', $mondayTs) ?> – <?= date('d.m.Y', $fridayTs) ?></strong> (KW <?= date('W', $mondayTs) ?>)
                            </span>
                            <!-- Datumswähler Input -->
                            <form method="GET" class="quick-date-form">
                                <input type="hidden" name="view" value="<?= h($viewMode) ?>">
                                <input type="hidden" name="item" value="<?= h($selectedItemKey) ?>">
                                <input type="date" name="date" value="<?= h($selectedDate) ?>" onchange="this.form.submit()" class="input-date-picker">
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="date-nav-buttons">
                            <a href="index.php?view=day&cat=<?= urlencode($dayCategory) ?>&date=<?= $prevDayDate ?>&item=<?= urlencode($selectedItemKey) ?>" class="btn btn-sm btn-secondary">
                                <span class="nav-text-desktop">« Vorheriger Tag</span>
                                <span class="nav-text-mobile">« Vor</span>
                            </a>
                            <a href="index.php?view=day&cat=<?= urlencode($dayCategory) ?>&date=<?= $todayDate ?>&item=<?= urlencode($selectedItemKey) ?>" class="btn btn-sm btn-outline">Heute</a>
                            <a href="index.php?view=day&cat=<?= urlencode($dayCategory) ?>&date=<?= $nextDayDate ?>&item=<?= urlencode($selectedItemKey) ?>" class="btn btn-sm btn-secondary">
                                <span class="nav-text-desktop">Nächster Tag »</span>
                                <span class="nav-text-mobile">Weiter »</span>
                            </a>
                        </div>
                        <div class="date-nav-details">
                            <span class="date-display">
                                <strong><?= $germanDayNames[(int)date('N', $currentTs)] ?? '' ?>, <?= date('d.m.Y', $currentTs) ?></strong>
                            </span>
                            <!-- Datumswähler Input -->
                            <form method="GET" class="quick-date-form">
                                <input type="hidden" name="view" value="<?= h($viewMode) ?>">
                                <input type="hidden" name="cat" value="<?= h($dayCategory) ?>">
                                <input type="hidden" name="item" value="<?= h($selectedItemKey) ?>">
                                <input type="date" name="date" value="<?= h($selectedDate) ?>" onchange="this.form.submit()" class="input-date-picker">
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Bei Wochenansicht: Raum/Ressourcen/Trenner-Auswahl und Info-Box -->
            <?php if ($viewMode === 'week'): ?>
                <div class="toolbar-bottom">
                    <div class="item-select-wrap">
                        <label for="itemSelect"><strong>Ausgewähltes Objekt:</strong></label>
                        <select id="itemSelect" class="form-select" onchange="location.href='index.php?view=week&date=<?= urlencode($selectedDate) ?>&item=' + encodeURIComponent(this.value)">
                            <optgroup label="📍 Räume">
                                <?php foreach ($rooms as $r): ?>
                                    <option value="room:<?= $r['id'] ?>" <?= $selectedItemKey === "room:{$r['id']}" ? 'selected' : '' ?>>
                                        <?= h($r['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="📦 Ressourcen">
                                <?php foreach ($resources as $res): ?>
                                    <option value="resource:<?= $res['id'] ?>" <?= $selectedItemKey === "resource:{$res['id']}" ? 'selected' : '' ?>>
                                        <?= h($res['name']) ?> (<?= h($res['quantity']) ?>x)
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="🛡️ Trenner">
                                <?php foreach ($dividers as $div): ?>
                                    <option value="divider:<?= $div['id'] ?>" <?= $selectedItemKey === "divider:{$div['id']}" ? 'selected' : '' ?>>
                                        <?= h($div['name']) ?> (<?= h($div['quantity']) ?>x)
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>

                    <?php if ($selectedItem): ?>
                        <div class="item-meta-badge">
                            <?php if ($selectedItemType === 'resource' || $selectedItemType === 'divider'): ?>
                                <span>📍 Standort: <strong><?= h($selectedItem['room_location'] ?: 'Nicht angegeben') ?></strong></span>
                                <span class="meta-sep">•</span>
                                <span>🔢 Anzahl: <strong><?= h($selectedItem['quantity']) ?> Stück</strong></span>
                                <?php if (!empty($selectedItem['note'])): ?>
                                    <span class="meta-sep">•</span>
                                    <span>📝 <?= h($selectedItem['note']) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if (!empty($selectedItem['note'])): ?>
                                    <span>📝 Notiz: <strong><?= h($selectedItem['note']) ?></strong></span>
                                <?php else: ?>
                                    <span>Keine Notiz hinterlegt</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- Kalender-Raster -->
        <section class="schedule-container">
            <?php if ($viewMode === 'week'): ?>
                <!-- ================= WOCHENANSICHT ================= -->
                <?php if (!$selectedItem): ?>
                    <div class="empty-state">
                        <p>Es sind noch keine Räume, Ressourcen oder Trenner vorhanden.</p>
                        <?php if (is_admin()): ?>
                            <a href="admin.php" class="btn btn-primary">Jetzt Objekte anlegen</a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="schedule-table">
                            <thead>
                                <tr>
                                    <th class="col-period">Stunde</th>
                                    <?php foreach ($weekDays as $wd): ?>
                                        <th class="col-day <?= $wd['is_today'] ? 'col-today' : '' ?>">
                                            <div class="th-day-name"><?= $wd['day_name'] ?></div>
                                            <div class="th-day-date"><?= $wd['formatted'] ?></div>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Kalender-Ereignisse (iCal Feeds: Ferien, Feiertage, Tagesereignisse) -->
                                <tr class="row-calendar-feed-events">
                                    <td class="cell-period cell-calendar-title-cell">
                                        <div class="cal-header-label">📅 Kalender</div>
                                    </td>
                                    <?php foreach ($weekDays as $wd): ?>
                                        <?php $dayEvents = $calEvents[$wd['date']] ?? []; ?>
                                        <td class="cell-calendar-day-events <?= $wd['is_today'] ? 'slot-today' : '' ?>">
                                            <?php if (!empty($dayEvents)): ?>
                                                <div class="cal-events-list">
                                                    <?php foreach ($dayEvents as $ev): ?>
                                                        <div class="cal-pill cal-pill-<?= h($ev['color']) ?>" title="<?= h($ev['name'] . ': ' . $ev['title']) ?>">
                                                            <span class="cal-pill-dot"></span>
                                                            <span class="cal-pill-text"><?= h($ev['title']) ?></span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="cal-no-events">–</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>

                                <?php foreach (SCHOOL_PERIODS as $pNum => $pInfo): ?>
                                    <tr class="row-period <?= !empty($pInfo['is_lunch']) ? 'row-lunch-break' : '' ?>">
                                        <td class="cell-period">
                                            <div class="period-num"><?= $pNum ?>. Std.</div>
                                            <div class="period-time"><?= $pInfo['time'] ?></div>
                                            <?php if (!empty($pInfo['is_lunch'])): ?>
                                                <div class="period-sub-tag">Mittagspause</div>
                                            <?php endif; ?>
                                        </td>
                                        <?php foreach ($weekDays as $wd): ?>
                                            <?php 
                                                $key = "{$wd['date']}:{$pNum}";
                                                $booking = $bookingsLookup[$key] ?? null;
                                            ?>
                                            <td class="cell-slot <?= $wd['is_today'] ? 'slot-today' : '' ?>">
                                                <?php if ($booking): ?>
                                                    <div class="booking-card" 
                                                         onclick="openBookingDetailModal(<?= htmlspecialchars(json_encode($booking), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($selectedItem['name']), ENT_QUOTES) ?>)">
                                                        <div class="booking-teacher"><?= h($booking['teacher_code']) ?></div>
                                                        <div class="booking-info"><?= h($booking['class_name']) ?> • <?= h($booking['subject']) ?></div>
                                                        <?php if (!empty($booking['series_id'])): ?>
                                                            <?php 
                                                                $isWeekSeries = ($booking['series_type'] === 'week') || ((int)($booking['series_days'] ?? 1) > 1);
                                                            ?>
                                                            <?php if ($isWeekSeries): ?>
                                                                <span class="series-indicator series-tag-week" title="Wochenserie (<?= $booking['series_count'] ?> Termine über mehrere Wochen)">
                                                                    <span class="tag-desktop">🔁 Wochenserie</span>
                                                                    <span class="tag-mobile">🔁 Woche</span>
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="series-indicator series-tag-day" title="Tagesserie (<?= $booking['series_count'] ?> Stunden am selben Tag)">
                                                                    <span class="tag-desktop">📅 Tagesserie</span>
                                                                    <span class="tag-mobile">📅 Tag</span>
                                                                </span>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <button type="button" class="btn-slot-free" 
                                                            onclick="openNewBookingWithPrefill('<?= h($selectedItemKey) ?>', '<?= h($wd['date']) ?>', <?= $pNum ?>)" 
                                                            title="Klicken zum Reservieren">
                                                        <span class="slot-plus">+</span>
                                                        <span class="slot-label">Frei</span>
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <!-- ================= TAGESANSICHT (NACH KATEGORIE GETRENNT) ================= -->
                <?php
                    if ($dayCategory === 'resources') {
                        $dayItems = $resources;
                        $dayItemType = 'resource';
                        $dayHeaderLabel = '📦 Ressourcen (Tagesübersicht)';
                        $dayHeaderClass = 'group-resources';
                        $dayCardClass = 'booking-card booking-card-resource';
                        $dayEmptyMsg = 'Es sind noch keine Ressourcen angelegt.';
                        $dayAdminTab = 'resources';
                        $dayAdminBtn = 'Jetzt Ressourcen anlegen';
                    } elseif ($dayCategory === 'dividers') {
                        $dayItems = $dividers;
                        $dayItemType = 'divider';
                        $dayHeaderLabel = '🛡️ Trenner (Tagesübersicht)';
                        $dayHeaderClass = 'group-dividers';
                        $dayCardClass = 'booking-card booking-card-divider';
                        $dayEmptyMsg = 'Es sind noch keine Trenner angelegt.';
                        $dayAdminTab = 'dividers';
                        $dayAdminBtn = 'Jetzt Trenner anlegen';
                    } else {
                        $dayItems = $rooms;
                        $dayItemType = 'room';
                        $dayHeaderLabel = '📍 Räume (Tagesübersicht)';
                        $dayHeaderClass = 'group-rooms';
                        $dayCardClass = 'booking-card';
                        $dayEmptyMsg = 'Es sind noch keine Räume angelegt.';
                        $dayAdminTab = 'rooms';
                        $dayAdminBtn = 'Jetzt Räume anlegen';
                    }
                ?>

                <?php if (empty($dayItems)): ?>
                    <div class="empty-state">
                        <p><?= h($dayEmptyMsg) ?></p>
                        <?php if (is_admin()): ?>
                            <a href="admin.php?tab=<?= h($dayAdminTab) ?>" class="btn btn-primary"><?= h($dayAdminBtn) ?></a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="schedule-table day-view-table">
                            <thead>
                                <tr>
                                    <th class="col-period">Stunde</th>
                                    <th colspan="<?= count($dayItems) ?>" class="th-group-header <?= h($dayHeaderClass) ?>"><?= $dayHeaderLabel ?></th>
                                </tr>
                                <tr>
                                    <th class="col-period"></th>
                                    <?php foreach ($dayItems as $item): ?>
                                        <th class="th-item-sub">
                                            <div class="item-header-title"><?= h($item['name']) ?></div>
                                            <?php if ($dayItemType === 'room'): ?>
                                                <?php if (!empty($item['note'])): ?>
                                                    <small class="item-header-note" title="<?= h($item['note']) ?>"><?= h($item['note']) ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <small class="item-header-note" title="<?= h($item['note'] ?? '') ?>"><?= h($item['room_location'] ?: ($dayItemType === 'divider' ? 'Trenner' : 'Ressource')) ?> (<?= (int)$item['quantity'] ?>x)</small>
                                            <?php endif; ?>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Kalender-Ereignisse (iCal Feeds für den Tag) -->
                                <tr class="row-calendar-feed-events">
                                    <td class="cell-period cell-calendar-title-cell">
                                        <div class="cal-header-label">📅 Kalender</div>
                                    </td>
                                    <td colspan="<?= count($dayItems) ?>" class="cell-calendar-day-events">
                                        <?php $dayEvents = $calEvents[$selectedDate] ?? []; ?>
                                        <?php if (!empty($dayEvents)): ?>
                                            <div class="cal-events-list cal-events-day-view">
                                                <?php foreach ($dayEvents as $ev): ?>
                                                    <div class="cal-pill cal-pill-<?= h($ev['color']) ?>" title="<?= h($ev['name'] . ': ' . $ev['title']) ?>">
                                                        <span class="cal-pill-tag"><?= h($ev['name']) ?>:</span>
                                                        <span class="cal-pill-text"><?= h($ev['title']) ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="cal-no-events">Keine besonderen Kalender- oder Tagesereignisse eingetragen.</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>

                                <?php foreach (SCHOOL_PERIODS as $pNum => $pInfo): ?>
                                    <tr class="row-period <?= !empty($pInfo['is_lunch']) ? 'row-lunch-break' : '' ?>">
                                        <td class="cell-period">
                                            <div class="period-num"><?= $pNum ?>. Std.</div>
                                            <div class="period-time"><?= $pInfo['time'] ?></div>
                                            <?php if (!empty($pInfo['is_lunch'])): ?>
                                                <div class="period-sub-tag">Mittagspause</div>
                                            <?php endif; ?>
                                        </td>

                                        <?php foreach ($dayItems as $item): ?>
                                            <?php 
                                                $key = "{$dayItemType}:{$item['id']}:{$pNum}";
                                                $booking = $bookingsLookup[$key] ?? null;
                                            ?>
                                            <td class="cell-slot">
                                                <?php if ($booking): ?>
                                                    <div class="<?= h($dayCardClass) ?>" 
                                                         onclick="openBookingDetailModal(<?= htmlspecialchars(json_encode($booking), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($item['name']), ENT_QUOTES) ?>)">
                                                        <div class="booking-teacher"><?= h($booking['teacher_code']) ?></div>
                                                        <div class="booking-info"><?= h($booking['class_name']) ?> • <?= h($booking['subject']) ?></div>
                                                        <?php if (!empty($booking['series_id'])): ?>
                                                            <?php 
                                                                $isWeekSeries = ($booking['series_type'] === 'week') || ((int)($booking['series_days'] ?? 1) > 1);
                                                            ?>
                                                            <?php if ($isWeekSeries): ?>
                                                                <span class="series-indicator series-tag-week" title="Wochenserie (<?= $booking['series_count'] ?> Termine über mehrere Wochen)">
                                                                    <span class="tag-desktop">🔁 Wochenserie</span>
                                                                    <span class="tag-mobile">🔁 Woche</span>
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="series-indicator series-tag-day" title="Tagesserie (<?= $booking['series_count'] ?> Stunden am selben Tag)">
                                                                    <span class="tag-desktop">📅 Tagesserie</span>
                                                                    <span class="tag-mobile">📅 Tag</span>
                                                                </span>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <button type="button" class="btn-slot-free" 
                                                            onclick="openNewBookingWithPrefill('<?= $dayItemType ?>:<?= $item['id'] ?>', '<?= h($selectedDate) ?>', <?= $pNum ?>)"
                                                            title="Klicken zum Reservieren">
                                                        <span class="slot-plus">+</span>
                                                        <span class="slot-label">Frei</span>
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>

    <!-- ================= MODAL: NEUE RESERVIERUNG ================= -->
    <div id="modalNewBooking" class="modal-backdrop" style="display: none;">
        <div class="modal-window">
            <div class="modal-header">
                <h3 class="modal-title">✨ Neue Reservierung</h3>
                <button type="button" class="modal-close-btn" onclick="closeModal('modalNewBooking')">✕</button>
            </div>
            <form action="action.php" method="POST" class="modal-form">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="book">
                <input type="hidden" name="return_view" value="<?= h($viewMode) ?>">
                <input type="hidden" name="return_cat" value="<?= h($dayCategory) ?>">

                <div class="form-group">
                    <label for="bookItemKey"><strong>Raum, Ressource oder Trenner:</strong></label>
                    <select id="bookItemKey" name="item_key" class="form-select" required>
                        <optgroup label="📍 Räume">
                            <?php foreach ($rooms as $r): ?>
                                <option value="room:<?= $r['id'] ?>" <?= $selectedItemKey === "room:{$r['id']}" ? 'selected' : '' ?>>
                                    <?= h($r['name']) ?> <?= $r['note'] ? '(' . h($r['note']) . ')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="📦 Ressourcen">
                            <?php foreach ($resources as $res): ?>
                                <option value="resource:<?= $res['id'] ?>" <?= $selectedItemKey === "resource:{$res['id']}" ? 'selected' : '' ?>>
                                    <?= h($res['name']) ?> (Standort: <?= h($res['room_location'] ?: '–') ?>, <?= $res['quantity'] ?>x)
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="🛡️ Trenner">
                            <?php foreach ($dividers as $div): ?>
                                <option value="divider:<?= $div['id'] ?>" <?= $selectedItemKey === "divider:{$div['id']}" ? 'selected' : '' ?>>
                                    <?= h($div['name']) ?> (Standort: <?= h($div['room_location'] ?: '–') ?>, <?= $div['quantity'] ?>x)
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>

                <div class="form-group">
                    <label for="bookDate"><strong>Datum (Erster Termin):</strong></label>
                    <input type="date" id="bookDate" name="booking_date" value="<?= h($selectedDate) ?>" required class="form-input">
                </div>

                <!-- Checkboxen für Schulstunden 1 bis 10 -->
                <div class="form-group">
                    <div class="periods-header">
                        <label><strong>Schulstunden auswählen:</strong></label>
                        <div class="quick-period-btns">
                            <button type="button" class="btn btn-xs btn-subtle" onclick="selectPeriods([1,2,3,4,5,6])">1.–6. Std.</button>
                            <button type="button" class="btn btn-xs btn-subtle" onclick="selectPeriods([7,8,9,10,11])">7.–11. Std.</button>
                            <button type="button" class="btn btn-xs btn-subtle" onclick="selectPeriods([])">Keine</button>
                        </div>
                    </div>
                    <div class="periods-grid">
                        <?php foreach (SCHOOL_PERIODS as $num => $p): ?>
                            <label class="period-checkbox-label <?= !empty($p['is_lunch']) ? 'period-lunch-chip' : '' ?>" id="labelPeriod<?= $num ?>">
                                <input type="checkbox" name="periods[]" value="<?= $num ?>" class="period-checkbox" id="checkPeriod<?= $num ?>">
                                <span class="period-badge-box">
                                    <span class="period-badge-num"><?= $num ?>.</span>
                                    <span class="period-badge-time"><?= $p['time'] ?></span>
                                    <?php if (!empty($p['is_lunch'])): ?>
                                        <span class="period-chip-lunch-tag">Mittag</span>
                                    <?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-col">
                        <label for="teacherCode"><strong>Lehrerkürzel:</strong></label>
                        <?php if ($isAdmin): ?>
                            <input type="text" id="teacherCode" name="teacher_code" value="<?= h($currentTeacherCode ?: 'ADMIN') ?>" required maxlength="10" class="form-input uppercase-input">
                            <small class="form-hint">Admin kann für jedes Kürzel buchen.</small>
                        <?php else: ?>
                            <input type="text" id="teacherCode" name="teacher_code" value="<?= h($currentTeacherCode) ?>" readonly required maxlength="10" class="form-input uppercase-input input-readonly">
                            <small class="form-hint">Angemeldet als <strong><?= h($currentTeacherCode) ?></strong></small>
                        <?php endif; ?>
                    </div>
                    <div class="form-col">
                        <label for="className"><strong>Klasse:</strong></label>
                        <input type="text" id="className" name="class_name" placeholder="z.B. 9b" required class="form-input">
                    </div>
                    <div class="form-col">
                        <label for="subject"><strong>Fach:</strong></label>
                        <input type="text" id="subject" name="subject" placeholder="z.B. Physik" required class="form-input">
                    </div>
                </div>

                <div class="form-group repeat-group">
                    <label for="repeatType"><strong>Wiederholung:</strong></label>
                    <select id="repeatType" name="repeat_type" class="form-select">
                        <option value="none">Einmalig (nur dieser Tag)</option>
                        <option value="1">Jede Woche für 1 weitere Woche (insg. 2 Termine)</option>
                        <option value="2">Jede Woche für 2 weitere Wochen (insg. 3 Termine)</option>
                        <option value="3">Jede Woche für 3 weitere Wochen (insg. 4 Termine)</option>
                        <option value="4">Jede Woche für 4 weitere Wochen (insg. 5 Termine)</option>
                        <option value="5">Jede Woche für 5 weitere Wochen (insg. 6 Termine)</option>
                        <option value="halbjahr">Wöchentlich bis zum Halbjahr (bis 31. Januar)</option>
                        <option value="schuljahr">Wöchentlich bis zum Schuljahresende (bis 31. Juli)</option>
                    </select>
                    <small class="form-hint">Termine finden wöchentlich am selben Wochentag statt. Bei Doppelbelegungen wird die Buchung verhindert.</small>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('modalNewBooking')">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Verbindlich reservieren</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ================= MODAL: DETAILS & STORNIERUNG ================= -->
    <div id="modalBookingDetail" class="modal-backdrop" style="display: none;">
        <div class="modal-window">
            <div class="modal-header">
                <h3 class="modal-title">📋 Reservierungsdetails</h3>
                <button type="button" class="modal-close-btn" onclick="closeModal('modalBookingDetail')">✕</button>
            </div>
            <div class="modal-body">
                <div class="detail-row">
                    <span class="detail-label">Objekt:</span>
                    <span class="detail-val" id="detailItemName">–</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Datum &amp; Stunde:</span>
                    <span class="detail-val" id="detailDateTime">–</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Lehrkraft:</span>
                    <span class="detail-val" id="detailTeacher">–</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Klasse &amp; Fach:</span>
                    <span class="detail-val" id="detailClassSubject">–</span>
                </div>
                <div class="detail-row" id="detailSeriesRow" style="display: none;">
                    <span class="detail-label">Serie:</span>
                    <span class="detail-val" id="detailSeriesInfo">–</span>
                </div>

                <hr class="modal-divider">

                <div id="cancelBlockedNotice" style="display: none;" class="alert alert-subtle">
                    Dieser Termin wurde von <strong id="blockedTeacherCode">–</strong> reserviert. Er kann nur von dieser Lehrkraft selbst oder von der Administration storniert werden.
                </div>

                <div id="cancelAllowedContainer">
                    <form action="action.php" method="POST" id="cancelForm" class="cancel-form">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="booking_id" id="cancelBookingId" value="">
                        
                        <p class="cancel-hint">Möchten Sie diese Reservierung stornieren?</p>
                        <div class="cancel-buttons">
                            <button type="submit" name="scope" value="single" class="btn btn-danger" onclick="return confirm('Möchten Sie diesen einzelnen Termin wirklich stornieren?')">
                                Diesen Termin stornieren
                            </button>
                            <button type="submit" name="scope" value="series" id="btnCancelSeries" class="btn btn-danger-outline" style="display: none;" onclick="return confirm('Möchten Sie wirklich die GESAMTE Serie stornieren?')">
                                Gesamte Serie stornieren
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- ================= MODAL: EIGENE PIN SETZEN / ÄNDERN ================= -->
    <div id="modalChangePin" class="modal-backdrop" style="display: none;">
        <div class="modal-window" style="max-width: 440px;">
            <div class="modal-header">
                <h3 class="modal-title">🔑 Persönliche 4-stellige PIN</h3>
                <button type="button" class="modal-close-btn" onclick="closeModal('modalChangePin')">✕</button>
            </div>
            <form action="action.php" method="POST" class="modal-form">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="set_my_pin">

                <p class="text-muted" style="margin-bottom: 14px; font-size: 13px;">
                    Angemeldet als <strong><?= h($currentTeacherCode) ?></strong>.<br>
                    Sobald Sie eine 4-stellige PIN vergeben, ist Ihr Kürzel geschützt: <strong>Eine Anmeldung für <?= h($currentTeacherCode) ?> ist dann ausschließlich mit Ihrer PIN möglich</strong> (das allgemeine Schul-Passwort wird abgewiesen).
                </p>

                <div class="form-group">
                    <label for="new_pin"><strong>Neue 4-stellige PIN (Zahlen):</strong></label>
                    <input type="password" id="new_pin" name="new_pin" 
                           pattern="\d{4}" maxlength="4" inputmode="numeric" 
                           placeholder="z.B. 1234" class="form-input text-center" 
                           style="font-size: 20px; letter-spacing: 6px;">
                    <small class="form-hint">Genau 4 Ziffern. Leer lassen, um die PIN zu entfernen (zurück zum Schul-Passwort).</small>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('modalChangePin')">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">PIN speichern</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ================= MODAL: ADMIN-MODUS FREISCHALTEN ================= -->
    <div id="modalAdminLogin" class="modal-backdrop" style="display: none;">
        <div class="modal-window" style="max-width: 420px;">
            <div class="modal-header">
                <h3 class="modal-title">🔐 Administrator-Zugang</h3>
                <button type="button" class="modal-close-btn" onclick="closeModal('modalAdminLogin')">✕</button>
            </div>
            <form action="action.php" method="POST" class="modal-form">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="login_admin">

                <p class="text-muted" style="margin-bottom: 14px; font-size: 13px;">
                    Geben Sie das Administrator-Passwort ein, um Räume, Ressourcen und das Kollegium zu verwalten.
                </p>

                <div class="form-group">
                    <label for="admin_password"><strong>Admin-Passwort:</strong></label>
                    <input type="password" id="admin_password" name="admin_password" required autofocus
                           placeholder="Admin-Passwort eingeben" class="form-input">
                    <small class="form-hint">Geben Sie das Master-Passwort für die Verwaltung ein.</small>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('modalAdminLogin')">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Zur Verwaltung</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const CURRENT_TEACHER_CODE = <?= json_encode($currentTeacherCode) ?>;
        const IS_USER_ADMIN = <?= json_encode($isAdmin) ?>;
    </script>
    <script src="script.js"></script>
</body>
</html>
