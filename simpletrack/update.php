<?php
// update.php — receives location updates from the LocTrack Android app
// Place at: /var/www/track.prestile.com/loctrack/update.php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../auth.php';

define('STOP_MINUTES',       5);
define('STOP_RADIUS_M',    150);
define('OUTLIER_SPEED_MPH', 250);   // implied speed above this → outlier

function haversine_meters(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R    = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a    = sin($dLat/2) * sin($dLat/2)
          + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
          * sin($dLon/2) * sin($dLon/2);
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// ── Parse POST fields ─────────────────────────────────────────────────────────
$device        = isset($_POST['id'])        ? trim($_POST['id'])            : null;
$group_key     = isset($_POST['group'])     ? trim($_POST['group'])         : null;
$lat           = isset($_POST['lat'])       ? floatval($_POST['lat'])       : null;
$lon           = isset($_POST['lon'])       ? floatval($_POST['lon'])       : null;
$ts            = isset($_POST['timestamp']) ? intval($_POST['timestamp'])   : time();
$speed         = isset($_POST['speed'])     ? floatval($_POST['speed'])     : 0.0;
$bearing       = isset($_POST['bearing'])   ? floatval($_POST['bearing'])   : 0.0;
$altitude      = isset($_POST['altitude'])  ? floatval($_POST['altitude'])  : 0.0;
$accuracy      = isset($_POST['accuracy'])  ? floatval($_POST['accuracy'])  : 0.0;
$battery_level = isset($_POST['battery'])   ? floatval($_POST['battery'])   : null;
$is_charging   = isset($_POST['charging'])  ? (intval($_POST['charging']) ? 1 : 0) : 0;
$activity      = isset($_POST['activity'])  ? trim($_POST['activity'])      : null;

// Sanitise
if ($speed   < 0) $speed   = 0;
if ($bearing < 0) $bearing = 0;
if ($activity === '') $activity = null;

// ── Validate ──────────────────────────────────────────────────────────────────
if (!$device || $lat === null || $lon === null) {
    http_response_code(400);
    exit('Missing required fields');
}
if (!$group_key || !isset(GROUPS[$group_key])) {
    http_response_code(403);
    exit('Unknown group');
}
if (!device_in_group($device, $group_key)) {
    http_response_code(403);
    exit('Device not in group');
}
if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
    http_response_code(400);
    exit('Invalid coordinates');
}

$recorded_at = date('Y-m-d H:i:s', $ts);

// ── DB connection ─────────────────────────────────────────────────────────────
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
    error_log('LocTrack update.php DB error: ' . $e->getMessage());
    http_response_code(500);
    exit('Database error');
}

// ── Outlier detection ─────────────────────────────────────────────────────────
// Fetch the most recent non-outlier point for this device.
// If the implied speed to get here from there exceeds the threshold, flag this
// point as an outlier so it is excluded from history/stops queries.
$is_outlier      = 0;
$implied_mph     = null;

$prev_stmt = $pdo->prepare(
    "SELECT lat, lon, recorded_at
     FROM locations
     WHERE device = :device
       AND is_outlier = 0
     ORDER BY recorded_at DESC
     LIMIT 1"
);
$prev_stmt->execute([':device' => $device]);
$prev = $prev_stmt->fetch(PDO::FETCH_ASSOC);

if ($prev) {
    $dist_m      = haversine_meters(
        floatval($prev['lat']), floatval($prev['lon']), $lat, $lon
    );
    $elapsed_s   = max(1, $ts - strtotime($prev['recorded_at']));
    $implied_mph = ($dist_m / $elapsed_s) * 2.23694;   // m/s → mph

    if ($implied_mph > OUTLIER_SPEED_MPH) {
        $is_outlier = 1;
        error_log(sprintf(
            'LocTrack outlier: device=%s implied=%.0f mph dist=%.0f m elapsed=%ds',
            $device, $implied_mph, $dist_m, $elapsed_s
        ));
    }
}

// ── Insert location ───────────────────────────────────────────────────────────
$pdo->prepare(
    'INSERT INTO locations
        (device, lat, lon, speed, bearing, altitude, accuracy,
         battery_level, is_charging, activity, recorded_at, is_outlier)
     VALUES
        (:device, :lat, :lon, :speed, :bearing, :altitude, :accuracy,
         :battery_level, :is_charging, :activity, :recorded_at, :is_outlier)'
)->execute([
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
    ':is_outlier'    => $is_outlier,
]);

// ── Stop detection ────────────────────────────────────────────────────────────
// Skip stop logic entirely for outlier points — a bad GPS fix should never
// open or close a stop.
if (!$is_outlier) {

    $window   = STOP_MINUTES + 2;
    $pts_stmt = $pdo->prepare(
        "SELECT lat, lon, recorded_at
         FROM locations
         WHERE device = :device
           AND is_outlier = 0
           AND recorded_at >= NOW() - INTERVAL :window MINUTE
         ORDER BY recorded_at ASC"
    );
    $pts_stmt->execute([':device' => $device, ':window' => $window]);
    $points = $pts_stmt->fetchAll(PDO::FETCH_ASSOC);

    $is_stopped = false;
    $avg_lat    = $lat;
    $avg_lon    = $lon;
    $first      = null;

    if (count($points) >= 2) {
        $avg_lat = array_sum(array_column($points, 'lat')) / count($points);
        $avg_lon = array_sum(array_column($points, 'lon')) / count($points);

        $all_stationary = true;
        foreach ($points as $p) {
            $dist = haversine_meters($avg_lat, $avg_lon, floatval($p['lat']), floatval($p['lon']));
            if ($dist > STOP_RADIUS_M) { $all_stationary = false; break; }
        }

        $first        = $points[0];
        $span_minutes = (strtotime($recorded_at) - strtotime($first['recorded_at'])) / 60;
        $is_stopped   = $all_stationary && $span_minutes >= STOP_MINUTES;
    }

    $open_stmt = $pdo->prepare(
        "SELECT id, lat, lon, arrived_at
         FROM stops
         WHERE device = :device AND departed_at IS NULL
         ORDER BY arrived_at DESC LIMIT 1"
    );
    $open_stmt->execute([':device' => $device]);
    $open_stop = $open_stmt->fetch(PDO::FETCH_ASSOC);

    if ($is_stopped) {
        if ($open_stop) {
            $dist_from_stop = haversine_meters(
                floatval($open_stop['lat']), floatval($open_stop['lon']), $avg_lat, $avg_lon
            );
            if ($dist_from_stop > STOP_RADIUS_M) {
                $duration = round((strtotime($recorded_at) - strtotime($open_stop['arrived_at'])) / 60);
                $pdo->prepare("UPDATE stops SET departed_at = :d, duration_min = :dur WHERE id = :id")
                    ->execute([':d' => $recorded_at, ':dur' => $duration, ':id' => $open_stop['id']]);
                $pdo->prepare("INSERT INTO stops (device,lat,lon,arrived_at) VALUES (:dev,:lat,:lon,:arr)")
                    ->execute([':dev' => $device, ':lat' => $avg_lat, ':lon' => $avg_lon, ':arr' => $first['recorded_at']]);
            }
        } else {
            $pdo->prepare("INSERT INTO stops (device,lat,lon,arrived_at) VALUES (:dev,:lat,:lon,:arr)")
                ->execute([':dev' => $device, ':lat' => $avg_lat, ':lon' => $avg_lon, ':arr' => $first['recorded_at']]);
        }
    } else {
        if ($open_stop) {
            $duration = round((strtotime($recorded_at) - strtotime($open_stop['arrived_at'])) / 60);
            $pdo->prepare("UPDATE stops SET departed_at = :d, duration_min = :dur WHERE id = :id")
                ->execute([':d' => $recorded_at, ':dur' => $duration, ':id' => $open_stop['id']]);
        }
    }

} // end if (!$is_outlier)

http_response_code(200);
echo 'OK';
