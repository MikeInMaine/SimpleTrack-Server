<?php
// track.php - Receives location updates from Traccar Client app
// Also detects stops in real-time on each check-in.

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/auth.php';

define('STOP_MINUTES',  5);   // Minutes stationary before it counts as a stop
define('STOP_RADIUS_M', 150); // Meters - movement under this is considered stationary

function haversine_meters(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R    = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a    = sin($dLat/2) * sin($dLat/2)
          + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
          * sin($dLon/2) * sin($dLon/2);
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);

    if (!$data) {
        http_response_code(400);
        exit('Invalid JSON');
    }

    if (isset($data[0])) $data = $data[0];

    if (isset($data['location'])) {
        $coords        = $data['location']['coords']    ?? [];
        $battery       = $data['location']['battery']   ?? [];
        $activity_data = $data['location']['activity']  ?? [];
        $device        = $data['device_id']             ?? null;
        $ts_raw        = $data['location']['timestamp'] ?? null;
        $lat           = isset($coords['latitude'])     ? floatval($coords['latitude'])  : null;
        $lon           = isset($coords['longitude'])    ? floatval($coords['longitude']) : null;
        $speed         = isset($coords['speed'])        ? floatval($coords['speed'])     : 0;
        $bearing       = isset($coords['heading'])      ? floatval($coords['heading'])   : 0;
        $altitude      = isset($coords['altitude'])     ? floatval($coords['altitude'])  : 0;
        $accuracy      = isset($coords['accuracy'])     ? floatval($coords['accuracy'])  : 0;
        $battery_level = isset($battery['level'])       ? floatval($battery['level'])    : null;
        $is_charging   = isset($battery['is_charging']) ? ($battery['is_charging'] ? 1 : 0) : 0;
        $activity      = $activity_data['type']         ?? null;
        if ($speed   < 0) $speed   = 0;
        if ($bearing < 0) $bearing = 0;
        $ts = $ts_raw ? strtotime($ts_raw) : time();

    } elseif (isset($data['lat'])) {
        $device        = $data['id']           ?? null;
        $lat           = isset($data['lat'])       ? floatval($data['lat'])       : null;
        $lon           = isset($data['lon'])       ? floatval($data['lon'])       : null;
        $ts            = isset($data['timestamp']) ? intval($data['timestamp'])   : time();
        $speed         = isset($data['speed'])     ? floatval($data['speed'])     : 0;
        $bearing       = isset($data['bearing'])   ? floatval($data['bearing'])   : 0;
        $altitude      = isset($data['altitude'])  ? floatval($data['altitude'])  : 0;
        $accuracy      = isset($data['accuracy'])  ? floatval($data['accuracy'])  : 0;
        $battery_level = null;
        $is_charging   = 0;
        $activity      = null;
        if ($ts > 9999999999) $ts = intval($ts / 1000);

    } else {
        http_response_code(400);
        exit('Unrecognized payload format');
    }

} elseif ($method === 'GET') {
    $device        = isset($_GET['id'])        ? trim($_GET['id'])           : null;
    $lat           = isset($_GET['lat'])       ? floatval($_GET['lat'])      : null;
    $lon           = isset($_GET['lon'])       ? floatval($_GET['lon'])      : null;
    $ts            = isset($_GET['timestamp']) ? intval($_GET['timestamp'])  : time();
    $speed         = isset($_GET['speed'])     ? floatval($_GET['speed'])    : 0;
    $bearing       = isset($_GET['bearing'])   ? floatval($_GET['bearing'])  : 0;
    $altitude      = isset($_GET['altitude'])  ? floatval($_GET['altitude']) : 0;
    $accuracy      = isset($_GET['accuracy'])  ? floatval($_GET['accuracy']) : 0;
    $battery_level = null;
    $is_charging   = 0;
    $activity      = null;
    if ($ts > 9999999999) $ts = intval($ts / 1000);

} else {
    http_response_code(405);
    exit('Method Not Allowed');
}

if (!$device || $lat === null || $lon === null) {
    http_response_code(400); exit('Missing required parameters');
}
$group_key = null;
if ($method === 'POST') {
    $group_key = isset($data['group']) ? trim($data['group']) : null;
} elseif ($method === 'GET') {
    $group_key = isset($_GET['group']) ? trim($_GET['group']) : null;
}
if (!$group_key || !isset(GROUPS[$group_key])) {
    http_response_code(403); exit('Unknown group');
}
if (!device_in_group($device, $group_key)) {
    http_response_code(403); exit('Device not in group');
}
if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
    http_response_code(400); exit('Invalid coordinates');
}

$recorded_at = date('Y-m-d H:i:s', $ts);

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
    error_log('LocTrack DB error: ' . $e->getMessage());
    exit('Database error');
}

$stmt = $pdo->prepare(
    'INSERT INTO locations
        (device, lat, lon, speed, bearing, altitude, accuracy,
         battery_level, is_charging, activity, recorded_at)
     VALUES
        (:device, :lat, :lon, :speed, :bearing, :altitude, :accuracy,
         :battery_level, :is_charging, :activity, :recorded_at)'
);
$stmt->execute([
    ':device'        => $device,
    ':lat'           => $lat,
    ':lon'           => $lon,
    ':speed'         => $speed,
    ':bearing'       => $bearing,
    ':altitude'      => $altitude,
    ':accuracy'      => $accuracy,
    ':battery_level' => $battery_level,
    ':is_charging'   => $is_charging,
    ':activity'      => $activity,
    ':recorded_at'   => $recorded_at,
]);

// Stop detection
$window = STOP_MINUTES + 2;
$pts_stmt = $pdo->prepare(
    "SELECT lat, lon, recorded_at
     FROM locations
     WHERE device = :device
       AND recorded_at >= NOW() - INTERVAL :window MINUTE
     ORDER BY recorded_at ASC"
);
$pts_stmt->execute([':device' => $device, ':window' => $window]);
$points = $pts_stmt->fetchAll(PDO::FETCH_ASSOC);

$all_stationary = true;
if (count($points) >= 2) {
    $avg_lat = array_sum(array_column($points, 'lat')) / count($points);
    $avg_lon = array_sum(array_column($points, 'lon')) / count($points);
    foreach ($points as $p) {
        $dist = haversine_meters(
            lat1: $avg_lat,
            lon1: $avg_lon,
            lat2: floatval($p['lat']),
            lon2: floatval($p['lon'])
        );
        if ($dist > STOP_RADIUS_M) {
            $all_stationary = false;
            break;
        }
    }
    $first        = $points[0];
    $span_minutes = (strtotime($recorded_at) - strtotime($first['recorded_at'])) / 60;
    $is_stopped   = $all_stationary && $span_minutes >= STOP_MINUTES;
} else {
    $is_stopped = false;
}

$open_stmt = $pdo->prepare(
    "SELECT id, lat, lon, arrived_at
     FROM stops
     WHERE device = :device
       AND departed_at IS NULL
     ORDER BY arrived_at DESC
     LIMIT 1"
);
$open_stmt->execute([':device' => $device]);
$open_stop = $open_stmt->fetch(PDO::FETCH_ASSOC);

if ($is_stopped) {
    if ($open_stop) {
        $dist_from_stop = haversine_meters(
            lat1: floatval($open_stop['lat']),
            lon1: floatval($open_stop['lon']),
            lat2: $avg_lat,
            lon2: $avg_lon
        );
        if ($dist_from_stop > STOP_RADIUS_M) {
            $duration = round(
                (strtotime($recorded_at) - strtotime($open_stop['arrived_at'])) / 60
            );
            $pdo->prepare(
                "UPDATE stops SET departed_at = :departed, duration_min = :duration WHERE id = :id"
            )->execute([
                ':departed' => $recorded_at,
                ':duration' => $duration,
                ':id'       => $open_stop['id'],
            ]);
            $pdo->prepare(
                "INSERT INTO stops (device, lat, lon, arrived_at) VALUES (:device, :lat, :lon, :arrived_at)"
            )->execute([
                ':device'     => $device,
                ':lat'        => $avg_lat,
                ':lon'        => $avg_lon,
                ':arrived_at' => $first['recorded_at'],
            ]);
        }
    } else {
        $pdo->prepare(
            "INSERT INTO stops (device, lat, lon, arrived_at) VALUES (:device, :lat, :lon, :arrived_at)"
        )->execute([
            ':device'     => $device,
            ':lat'        => $avg_lat,
            ':lon'        => $avg_lon,
            ':arrived_at' => $first['recorded_at'],
        ]);
    }
} else {
    if ($open_stop) {
        $duration = round(
            (strtotime($recorded_at) - strtotime($open_stop['arrived_at'])) / 60
        );
        $pdo->prepare(
            "UPDATE stops SET departed_at = :departed, duration_min = :duration WHERE id = :id"
        )->execute([
            ':departed' => $recorded_at,
            ':duration' => $duration,
            ':id'       => $open_stop['id'],
        ]);
    }
}

http_response_code(200);
echo 'OK';
