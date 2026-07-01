<?php
// api.php - JSON data endpoint for the map page

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/auth.php';

$group   = auth_check();
$devices = group_devices($group);

header('Content-Type: application/json');

$action = $_GET['action'] ?? 'latest';

try {
    $pdo = new PDO(
        dsn:      'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        username: DB_USER,
        password: DB_PASS,
        options:  [
            PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$placeholders = implode(',', array_fill(0, count($devices), '?'));

// ── latest ────────────────────────────────────────────────────────────────────
if ($action === 'latest') {
    $stmt = $pdo->prepare(
        "SELECT device, lat, lon, speed, bearing, altitude, accuracy,
                battery_level, is_charging, activity, recorded_at
         FROM locations
         WHERE device IN ($placeholders)
           AND is_outlier = 0
           AND id IN (
               SELECT MAX(id)
               FROM locations
               WHERE device IN ($placeholders)
                 AND is_outlier = 0
               GROUP BY device
           )
         ORDER BY device"
    );
    $stmt->execute(array_merge($devices, $devices));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $now     = new DateTime('now', new DateTimeZone('UTC'));
    $results = [];
    foreach ($rows as $row) {
        $last_seen  = new DateTime($row['recorded_at'], new DateTimeZone('UTC'));
        $diff_mins  = round(($now->getTimestamp() - $last_seen->getTimestamp()) / 60);
        $online     = $diff_mins <= OFFLINE_MINUTES;
        $speed_mph  = round($row['speed'] * 2.23694, 1);
        $results[]  = [
            'device'        => $row['device'],
            'lat'           => floatval($row['lat']),
            'lon'           => floatval($row['lon']),
            'speed_mph'     => $speed_mph,
            'bearing'       => floatval($row['bearing']),
            'altitude_ft'   => round($row['altitude'] * 3.28084),
            'accuracy_ft'   => round($row['accuracy'] * 3.28084),
            'battery_level' => $row['battery_level'] !== null ? floatval($row['battery_level']) : null,
            'is_charging'   => (bool)$row['is_charging'],
            'activity'      => $row['activity'],
            'recorded_at'   => $row['recorded_at'],
            'mins_ago'      => $diff_mins,
            'online'        => $online,
        ];
    }
    echo json_encode($results);

// ── history ───────────────────────────────────────────────────────────────────
} elseif ($action === 'history') {
    $device = isset($_GET['device']) ? trim($_GET['device']) : null;
    $hours  = isset($_GET['hours'])  ? intval($_GET['hours']) : HISTORY_HOURS;

    if (!$device || !in_array($device, $devices)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid device']);
        exit;
    }

    $hours = max(1, min($hours, 168));

    $stmt = $pdo->prepare(
        'SELECT lat, lon, speed, recorded_at
         FROM locations
         WHERE device = ?
           AND is_outlier = 0
           AND recorded_at >= NOW() - INTERVAL ? HOUR
         ORDER BY recorded_at ASC'
    );
    $stmt->execute([$device, $hours]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($rows as $row) {
        $results[] = [
            'lat'         => floatval($row['lat']),
            'lon'         => floatval($row['lon']),
            'speed_mph'   => round($row['speed'] * 2.23694, 1),
            'recorded_at' => $row['recorded_at'],
        ];
    }
    echo json_encode($results);

// ── stops ─────────────────────────────────────────────────────────────────────
} elseif ($action === 'stops') {
    $device = isset($_GET['device']) ? trim($_GET['device']) : null;
    $days   = isset($_GET['days'])   ? intval($_GET['days']) : 7;
    $days   = max(1, min($days, 90));

    if ($device && !in_array($device, $devices)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid device']);
        exit;
    }

    if ($device) {
        $stmt = $pdo->prepare(
            "SELECT id, device, lat, lon, arrived_at, departed_at, duration_min, address
             FROM stops
             WHERE device = ?
               AND arrived_at >= NOW() - INTERVAL ? DAY
             ORDER BY arrived_at DESC
             LIMIT 500"
        );
        $stmt->execute([$device, $days]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, device, lat, lon, arrived_at, departed_at, duration_min, address
             FROM stops
             WHERE device IN ($placeholders)
               AND arrived_at >= NOW() - INTERVAL ? DAY
             ORDER BY arrived_at DESC
             LIMIT 500"
        );
        $stmt->execute(array_merge($devices, [$days]));
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($rows as $row) {
        $results[] = [
            'id'           => intval($row['id']),
            'device'       => $row['device'],
            'lat'          => floatval($row['lat']),
            'lon'          => floatval($row['lon']),
            'arrived_at'   => $row['arrived_at'],
            'departed_at'  => $row['departed_at'],
            'duration_min' => $row['duration_min'] !== null ? intval($row['duration_min']) : null,
            'address'      => $row['address'],
            'open'         => $row['departed_at'] === null,
        ];
    }
    echo json_encode($results);

// ── alerts ────────────────────────────────────────────────────────────────────
} elseif ($action === 'alerts') {
    // Returns all active alerts for the group.
    // An alert is active if:
    //   - not cleared by sender, AND
    //   - the requesting device has not dismissed it
    // (We return all non-cleared alerts and let the client decide based on dismissed_by)
    $calling_device = $_GET['device'] ?? '';

    $stmt = $pdo->prepare(
        "SELECT id, device, type, created_at, cleared_by_sender, dismissed_by
         FROM alerts
         WHERE group_key = ?
           AND cleared_by_sender = 0
           AND created_at >= NOW() - INTERVAL 24 HOUR
         ORDER BY created_at DESC"
    );
    $stmt->execute([$group]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($rows as $row) {
        $dismissed_by = $row['dismissed_by'] ? json_decode($row['dismissed_by'], true) : [];
        // Skip if this device already dismissed it
        if ($calling_device && in_array($calling_device, $dismissed_by)) continue;
        $results[] = [
            'id'               => intval($row['id']),
            'device'           => $row['device'],
            'type'             => $row['type'],
            'created_at'       => $row['created_at'],
            'cleared_by_sender'=> (bool)$row['cleared_by_sender'],
            'dismissed_by'     => $dismissed_by,
        ];
    }
    echo json_encode($results);

// ── send_alert ────────────────────────────────────────────────────────────────
} elseif ($action === 'send_alert') {
    // POST: device, type (SOS or CRASH)
    $device = trim($_POST['device'] ?? $_GET['device'] ?? '');
    $type   = strtoupper(trim($_POST['type']   ?? $_GET['type']   ?? ''));

    if (!$device || !in_array($device, $devices)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid device']);
        exit;
    }
    if (!in_array($type, ['SOS', 'CRASH'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid type']);
        exit;
    }

    // Avoid duplicate active alerts for same device+type within last 5 minutes
    $dup = $pdo->prepare(
        "SELECT id FROM alerts
         WHERE device = ? AND type = ? AND group_key = ?
           AND cleared_by_sender = 0
           AND created_at >= NOW() - INTERVAL 5 MINUTE"
    );
    $dup->execute([$device, $type, $group]);
    if ($dup->fetch()) {
        echo json_encode(['ok' => true, 'duplicate' => true]);
        exit;
    }

    $pdo->prepare(
        "INSERT INTO alerts (device, group_key, type) VALUES (?, ?, ?)"
    )->execute([$device, $group, $type]);

    echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);

// ── dismiss_alert ─────────────────────────────────────────────────────────────
} elseif ($action === 'dismiss_alert') {
    $alert_id = intval($_GET['id'] ?? 0);
    $device   = trim($_GET['device'] ?? '');

    if (!$alert_id || !$device || !in_array($device, $devices)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid parameters']);
        exit;
    }

    // Fetch current dismissed_by
    $stmt = $pdo->prepare(
        "SELECT dismissed_by FROM alerts WHERE id = ? AND group_key = ?"
    );
    $stmt->execute([$alert_id, $group]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Alert not found']);
        exit;
    }

    $dismissed = $row['dismissed_by'] ? json_decode($row['dismissed_by'], true) : [];
    if (!in_array($device, $dismissed)) {
        $dismissed[] = $device;
    }

    $pdo->prepare(
        "UPDATE alerts SET dismissed_by = ? WHERE id = ?"
    )->execute([json_encode($dismissed), $alert_id]);

    echo json_encode(['ok' => true]);

// ── clear_alert (sender marks "I'm OK") ──────────────────────────────────────
} elseif ($action === 'clear_alert') {
    $alert_id = intval($_GET['id'] ?? 0);
    $device   = trim($_GET['device'] ?? '');

    if (!$alert_id || !$device || !in_array($device, $devices)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid parameters']);
        exit;
    }

    // Only the originating device can clear
    $stmt = $pdo->prepare(
        "SELECT device FROM alerts WHERE id = ? AND group_key = ?"
    );
    $stmt->execute([$alert_id, $group]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || $row['device'] !== $device) {
        http_response_code(403);
        echo json_encode(['error' => 'Not authorised to clear this alert']);
        exit;
    }

    $pdo->prepare(
        "UPDATE alerts SET cleared_by_sender = 1 WHERE id = ?"
    )->execute([$alert_id]);

    echo json_encode(['ok' => true]);

// ── devices ───────────────────────────────────────────────────────────────────
} elseif ($action === 'devices') {
    echo json_encode($devices);

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
}
