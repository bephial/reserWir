<?php
// ical.php - Dynamische Verwaltung, Abruf, Caching und Parsing von externen iCal-Feeds

require_once __DIR__ . '/config.php';

define('ICAL_CACHE_DIR', DATA_DIR . '/ical_cache');

/**
 * Verfügbare Farben für Kalender-Einträge im Stundenplan
 */
function get_ical_color_options(): array {
    return [
        'red'    => 'Rot (z. B. Ferien / Schließtage)',
        'yellow' => 'Gelb (z. B. Feiertage / Besonderes)',
        'green'  => 'Grün (z. B. Tagesereignisse / Konferenzen)',
        'blue'   => 'Blau (z. B. Prüfungen / Allgemein)',
        'purple' => 'Lila (z. B. Projekte / Exkursionen)',
    ];
}

/**
 * Prüft und normalisiert eine iCal-URL (unterstützt https://, http:// und webcal://, inkl. SSRF-Schutz)
 */
function normalize_and_validate_ical_url(string $rawUrl): ?string {
    $url = trim($rawUrl);
    if ($url === '') {
        return null;
    }

    // webcal:// automatisch in https:// umwandeln
    if (stripos($url, 'webcal://') === 0) {
        $url = 'https://' . substr($url, 9);
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }

    $parts = parse_url($url);
    $scheme = strtolower($parts['scheme'] ?? '');
    $host = strtolower($parts['host'] ?? '');

    if (!in_array($scheme, ['https', 'http'], true) || $host === '') {
        return null;
    }

    // Schutz vor lokalen/internen Zielen (SSRF-Abwehr)
    if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true) || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
        return null;
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }
    }

    return $url;
}

/**
 * Lädt alle im Adminbereich hinterlegten iCal-Feeds aus der Datenbank
 */
function get_ical_feed_configs(): array {
    $db = get_db();
    $rows = $db->query("SELECT * FROM ical_feeds ORDER BY id ASC")->fetchAll();
    $configs = [];
    foreach ($rows as $r) {
        $key = 'feed_' . (int)$r['id'];
        $configs[$key] = [
            'id'         => (int)$r['id'],
            'name'       => $r['name'],
            'color'      => $r['color'] ?: 'blue',
            'url'        => $r['url'],
            'created_at' => $r['created_at'] ?? ''
        ];
    }
    return $configs;
}

/**
 * Löscht die lokalen Cache-Dateien eines bestimmten Feeds
 */
function delete_ical_feed_cache(int $feedId): void {
    $feedKey = 'feed_' . $feedId;
    $cacheFile = ICAL_CACHE_DIR . '/' . $feedKey . '.ics';
    $cacheMetaFile = ICAL_CACHE_DIR . '/' . $feedKey . '_meta.json';
    if (file_exists($cacheFile)) {
        @unlink($cacheFile);
    }
    if (file_exists($cacheMetaFile)) {
        @unlink($cacheMetaFile);
    }
}

/**
 * Holt den Inhalt eines Feeds (mit 24h Datei-Cache und Ausfallsicherheit)
 */
function get_feed_ics_content(string $feedKey, string $url, bool $forceRefresh = false): string {
    if (!is_dir(ICAL_CACHE_DIR)) {
        mkdir(ICAL_CACHE_DIR, 0755, true);
    }

    $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', $feedKey);
    $cacheFile = ICAL_CACHE_DIR . '/' . $safeKey . '.ics';
    $cacheMetaFile = ICAL_CACHE_DIR . '/' . $safeKey . '_meta.json';

    $needsRefresh = $forceRefresh || !file_exists($cacheFile);

    // Prüfen, ob Cache älter als 24 Stunden ist
    if (!$needsRefresh && file_exists($cacheMetaFile)) {
        $meta = json_decode(file_get_contents($cacheMetaFile), true);
        if (empty($meta['last_fetch']) || (time() - $meta['last_fetch']) > 86400) {
            $needsRefresh = true;
        }
    }

    if ($needsRefresh) {
        $validUrl = normalize_and_validate_ical_url($url);
        if ($validUrl !== null) {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 5, // 5 Sekunden Timeout, damit die Seite nicht blockiert
                    'user_agent' => 'reserWir-iCal-Sync/1.0',
                    'follow_location' => 1
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false
                ]
            ]);

            $content = @file_get_contents($validUrl, false, $ctx);

            if ($content !== false && strpos($content, 'BEGIN:VCALENDAR') !== false) {
                file_put_contents($cacheFile, $content);
                file_put_contents($cacheMetaFile, json_encode([
                    'last_fetch' => time(),
                    'last_fetch_date' => date('Y-m-d H:i:s'),
                    'status' => 'success'
                ]));
                return $content;
            } else {
                // Fehlerstatus merken, falls noch kein Cache existiert oder Force-Refresh lief
                $prevMeta = file_exists($cacheMetaFile) ? (json_decode(file_get_contents($cacheMetaFile), true) ?: []) : [];
                file_put_contents($cacheMetaFile, json_encode([
                    'last_fetch' => $prevMeta['last_fetch'] ?? 0,
                    'last_fetch_date' => $prevMeta['last_fetch_date'] ?? 'Abruf fehlgeschlagen',
                    'status' => 'error'
                ]));
            }
        }
    }

    // Falls Download fehlschlägt oder nicht nötig: vorhandenen Cache verwenden
    if (file_exists($cacheFile)) {
        return file_get_contents($cacheFile);
    }

    return '';
}

/**
 * Parst einen ICS-Kalenderstring in ein Array von Tagesterminen
 */
function parse_ics_to_events(string $icsContent, string $feedKey, array $feedConfig): array {
    if (empty($icsContent)) {
        return [];
    }

    // Zeilen-Entfaltung nach RFC 5545 (Zeilen mit vorangestelltem Leerzeichen/Tab anhängen)
    $unfolded = preg_replace("/\r\n[ \t]|\r[ \t]|\n[ \t]/", "", $icsContent);

    // VEVENT Blöcke filtern
    preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $unfolded, $eventMatches);
    if (empty($eventMatches[1])) {
        return [];
    }

    $events = [];

    foreach ($eventMatches[1] as $block) {
        // SUMMARY
        $summary = '';
        if (preg_match('/^SUMMARY(?:;[^:]*)?:(.*)$/m', $block, $m)) {
            $summary = trim($m[1]);
            // Escaping auflösen (\, \; \n)
            $summary = str_replace(['\,', '\;', '\n', '\N', '\\\\'], [',', ';', ' ', ' ', '\\'], $summary);
        }
        if (!$summary) {
            $summary = $feedConfig['name'];
        }

        // DTSTART
        $dtstart = null;
        $isAllDay = false;
        if (preg_match('/^DTSTART(?:;[^:]*)?:(.*)$/m', $block, $m)) {
            $val = trim($m[1]);
            if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $val, $d)) {
                $dtstart = "{$d[1]}-{$d[2]}-{$d[3]}";
                if (strlen($val) <= 8 || strpos($m[0], 'VALUE=DATE') !== false) {
                    $isAllDay = true;
                }
            }
        }

        if (!$dtstart) {
            continue;
        }

        // DTEND
        $dtend = null;
        if (preg_match('/^DTEND(?:;[^:]*)?:(.*)$/m', $block, $m)) {
            $val = trim($m[1]);
            if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $val, $d)) {
                $dtend = "{$d[1]}-{$d[2]}-{$d[3]}";
            }
        }

        // Gültigen Datumsbereich berechnen
        $startTs = strtotime($dtstart);
        if ($dtend) {
            $endTs = strtotime($dtend);
            // Bei ganztägigen Terminen ist DTEND exklusiv (folgender Tag 00:00 Uhr)
            if ($isAllDay && $endTs > $startTs) {
                $endTs = strtotime('-1 day', $endTs);
            }
            if ($endTs < $startTs) {
                $endTs = $startTs;
            }
        } else {
            $endTs = $startTs;
        }

        // Tage durchlaufen und zuordnen (max 120 Tage pro Termin gegen Endlosschleifen)
        $curTs = $startTs;
        $guard = 0;
        while ($curTs <= $endTs && $guard < 120) {
            $curDate = date('Y-m-d', $curTs);
            $events[$curDate][] = [
                'title' => $summary,
                'feed'  => $feedKey,
                'color' => $feedConfig['color'],
                'name'  => $feedConfig['name']
            ];
            $curTs = strtotime('+1 day', $curTs);
            $guard++;
        }
    }

    return $events;
}

/**
 * Lädt alle konfigurierten Feeds und gibt alle Termine für den gewünschten Datumsbereich zurück
 */
function get_calendar_events_for_range(string $startDate, string $endDate, bool $forceRefresh = false): array {
    static $cachedResult = null;
    if ($cachedResult !== null && !$forceRefresh) {
        return $cachedResult;
    }

    $allEvents = [];
    $feeds = get_ical_feed_configs();

    foreach ($feeds as $feedKey => $feedConfig) {
        $ics = get_feed_ics_content($feedKey, $feedConfig['url'], $forceRefresh);
        $parsed = parse_ics_to_events($ics, $feedKey, $feedConfig);

        foreach ($parsed as $date => $dayEvents) {
            if ($date >= $startDate && $date <= $endDate) {
                if (!isset($allEvents[$date])) {
                    $allEvents[$date] = [];
                }
                foreach ($dayEvents as $ev) {
                    $allEvents[$date][] = $ev;
                }
            }
        }
    }

    $cachedResult = $allEvents;
    return $allEvents;
}

/**
 * Gibt alle konfigurierten Feeds inklusive Synchronisations-Status zurück
 */
function get_ical_sync_status(): array {
    $feeds = get_ical_feed_configs();
    $status = [];
    foreach ($feeds as $key => $cfg) {
        $metaFile = ICAL_CACHE_DIR . '/' . $key . '_meta.json';
        $cacheFile = ICAL_CACHE_DIR . '/' . $key . '.ics';
        $hasCache = file_exists($cacheFile);

        if (file_exists($metaFile)) {
            $data = json_decode(file_get_contents($metaFile), true) ?: [];
            $status[$key] = [
                'id'         => $cfg['id'],
                'name'       => $cfg['name'],
                'url'        => $cfg['url'],
                'color'      => $cfg['color'],
                'last_fetch' => $data['last_fetch_date'] ?? '–',
                'cached'     => $hasCache,
                'status'     => $data['status'] ?? ($hasCache ? 'success' : 'pending')
            ];
        } else {
            $status[$key] = [
                'id'         => $cfg['id'],
                'name'       => $cfg['name'],
                'url'        => $cfg['url'],
                'color'      => $cfg['color'],
                'last_fetch' => 'Noch nicht abgerufen',
                'cached'     => false,
                'status'     => 'pending'
            ];
        }
    }
    return $status;
}
