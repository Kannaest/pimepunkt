<?php

declare(strict_types=1);

function game_bounds(int $gameId): ?array
{
    $stmt = db()->prepare('SELECT MIN(lat) min_lat, MAX(lat) max_lat, MIN(lng) min_lng, MAX(lng) max_lng FROM checkpoints WHERE game_id = ?');
    $stmt->execute([$gameId]);
    $row = $stmt->fetch();
    if (!$row || $row['min_lat'] === null) {
        return null;
    }
    return array_map('floatval', $row);
}

function http_request(string $url, ?string $body = null, int $timeout = 60): string
{
    $headers = "User-Agent: Pimepunkt/1.0 (https://kand.ee/pimepunkt)\r\n";
    if ($body !== null) {
        $headers .= "Content-Type: application/x-www-form-urlencoded\r\n";
    }
    $context = stream_context_create(['http' => [
        'method' => $body === null ? 'GET' : 'POST',
        'header' => $headers,
        'content' => $body ?? '',
        'timeout' => $timeout,
        'ignore_errors' => true,
    ]]);
    $result = @file_get_contents($url, false, $context);
    $status = $http_response_header[0] ?? '';
    if ($result === false || !preg_match('/\s2\d\d\s/', $status)) {
        throw new RuntimeException('Välise teenuse päring ebaõnnestus: ' . $status);
    }
    return $result;
}

function generated_map_bounds(array $bounds, int $width = 3200, int $height = 2000): array
{
    $centerLat = ($bounds['min_lat'] + $bounds['max_lat']) / 2;
    $centerLng = ($bounds['min_lng'] + $bounds['max_lng']) / 2;
    $latMeters = max(1.0, ($bounds['max_lat'] - $bounds['min_lat']) * 111320);
    $lngMeters = max(1.0, ($bounds['max_lng'] - $bounds['min_lng']) * 111320 * cos(deg2rad($centerLat)));

    $targetWidth = $lngMeters * 1.12;
    $targetHeight = $latMeters * 1.12;
    if (max($targetWidth, $targetHeight) < 8000) {
        $factor = 8000 / max($targetWidth, $targetHeight);
        $targetWidth *= $factor;
        $targetHeight *= $factor;
    }
    $targetHeight = max($targetWidth * $height / $width, $targetHeight);
    $targetWidth = max($targetWidth, $targetHeight * $width / $height);

    $halfLat = ($targetHeight / 111320) / 2;
    $halfLng = ($targetWidth / (111320 * max(0.2, cos(deg2rad($centerLat))))) / 2;
    return [
        'min_lat' => $centerLat - $halfLat,
        'max_lat' => $centerLat + $halfLat,
        'min_lng' => $centerLng - $halfLng,
        'max_lng' => $centerLng + $halfLng,
    ];
}

function generated_map_spec(array $bounds): array
{
    $centerLat = ($bounds['min_lat'] + $bounds['max_lat']) / 2;
    $latMeters = max(1.0, ($bounds['max_lat'] - $bounds['min_lat']) * 111320);
    $lngMeters = max(1.0, ($bounds['max_lng'] - $bounds['min_lng']) * 111320 * cos(deg2rad($centerLat)));
    $mapWidthMeters = $lngMeters * 1.12;
    $mapHeightMeters = $latMeters * 1.12;

    // Keep compact and nearly linear games usable while retaining the area's orientation.
    if ($mapWidthMeters / $mapHeightMeters < 0.5) {
        $mapWidthMeters = $mapHeightMeters * 0.5;
    } elseif ($mapWidthMeters / $mapHeightMeters > 2.0) {
        $mapHeightMeters = $mapWidthMeters / 2.0;
    }
    if (max($mapWidthMeters, $mapHeightMeters) < 8000) {
        $factor = 8000 / max($mapWidthMeters, $mapHeightMeters);
        $mapWidthMeters *= $factor;
        $mapHeightMeters *= $factor;
    }

    $pixelsPerMeter = 300 * 39.37007874 / 80000;
    $width = $mapWidthMeters * $pixelsPerMeter;
    $height = $mapHeightMeters * $pixelsPerMeter;
    $factor = max(2400 / max($width, $height), 1.0);
    $width *= $factor;
    $height *= $factor;
    $factor = min(1.0, 7200 / max($width, $height), sqrt(24000000 / ($width * $height)));
    $width = max(1200, (int)round($width * $factor));
    $height = max(1200, (int)round($height * $factor));

    return [
        'width' => $width,
        'height' => $height,
        'orientation' => $width >= $height ? 'landscape' : 'portrait',
        'bounds' => generated_map_bounds($bounds, $width, $height),
    ];
}

/** WGS84 latitude/longitude to L-EST97 (EPSG:3301). */
function lest97_xy(float $lat, float $lng): array
{
    $a = 6378137.0;
    $inverseFlattening = 298.257222101;
    $f = 1 / $inverseFlattening;
    $e = sqrt(2 * $f - $f * $f);
    $lat0 = deg2rad(57 + 31 / 60 + 3.19415 / 3600);
    $lat1 = deg2rad(59 + 20 / 60);
    $lat2 = deg2rad(58);
    $lng0 = deg2rad(24);
    $m = static fn(float $phi): float => cos($phi) / sqrt(1 - $e * $e * sin($phi) ** 2);
    $t = static fn(float $phi): float => tan(M_PI / 4 - $phi / 2) / (((1 - $e * sin($phi)) / (1 + $e * sin($phi))) ** ($e / 2));
    $n = (log($m($lat1)) - log($m($lat2))) / (log($t($lat1)) - log($t($lat2)));
    $capitalF = $m($lat1) / ($n * $t($lat1) ** $n);
    $rho0 = $a * $capitalF * $t($lat0) ** $n;
    $phi = deg2rad($lat);
    $rho = $a * $capitalF * $t($phi) ** $n;
    $theta = $n * (deg2rad($lng) - $lng0);
    return [500000 + $rho * sin($theta), 6375000 + $rho0 - $rho * cos($theta)];
}

function generate_player_map(int $gameId): string
{
    if (!extension_loaded('gd')) {
        throw new RuntimeException('Kaardi genereerimiseks puudub GD laiendus. Ehita Docker image uuesti.');
    }
    ini_set('memory_limit', '384M');
    $bounds = game_bounds($gameId);
    if (!$bounds) {
        throw new RuntimeException('Mängul ei ole kaardi genereerimiseks punkte.');
    }
    $mapSpec = generated_map_spec($bounds);
    $width = $mapSpec['width'];
    $height = $mapSpec['height'];
    $mapBounds = $mapSpec['bounds'];
    $projectedCorners = [
        lest97_xy($mapBounds['min_lat'], $mapBounds['min_lng']),
        lest97_xy($mapBounds['min_lat'], $mapBounds['max_lng']),
        lest97_xy($mapBounds['max_lat'], $mapBounds['min_lng']),
        lest97_xy($mapBounds['max_lat'], $mapBounds['max_lng']),
    ];
    $xs = array_column($projectedCorners, 0);
    $ys = array_column($projectedCorners, 1);
    $projectedBounds = ['min_x' => min($xs), 'max_x' => max($xs), 'min_y' => min($ys), 'max_y' => max($ys)];
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    $tileSize = 1800;
    for ($top = 0; $top < $height; $top += $tileSize) {
        for ($left = 0; $left < $width; $left += $tileSize) {
            $tileWidth = min($tileSize, $width - $left);
            $tileHeight = min($tileSize, $height - $top);
            $minX = $projectedBounds['min_x'] + $left / $width * ($projectedBounds['max_x'] - $projectedBounds['min_x']);
            $maxX = $projectedBounds['min_x'] + ($left + $tileWidth) / $width * ($projectedBounds['max_x'] - $projectedBounds['min_x']);
            $maxY = $projectedBounds['max_y'] - $top / $height * ($projectedBounds['max_y'] - $projectedBounds['min_y']);
            $minY = $projectedBounds['max_y'] - ($top + $tileHeight) / $height * ($projectedBounds['max_y'] - $projectedBounds['min_y']);
            $url = 'https://kaart.maaamet.ee/wms/hallkaart?' . http_build_query([
                'SERVICE' => 'WMS', 'VERSION' => '1.1.1', 'REQUEST' => 'GetMap',
                'LAYERS' => 'kaart_ht', 'STYLES' => '', 'SRS' => 'EPSG:3301',
                'BBOX' => implode(',', [$minX, $minY, $maxX, $maxY]),
                'WIDTH' => $tileWidth, 'HEIGHT' => $tileHeight,
                'FORMAT' => 'image/png', 'TRANSPARENT' => 'FALSE',
            ]);
            $tile = @imagecreatefromstring(http_request($url, null, 90));
            if (!$tile) {
                imagedestroy($image);
                throw new RuntimeException('Hallkaardi pilti ei saanud avada.');
            }
            imagecopy($image, $tile, $left, $top, 0, 0, $tileWidth, $tileHeight);
            imagedestroy($tile);
        }
    }
    imagealphablending($image, true);
    imageresolution($image, 300, 300);

    $stmt = db()->prepare('SELECT number, lat, lng, difficulty FROM checkpoints WHERE game_id = ? ORDER BY number');
    $stmt->execute([$gameId]);
    $points = $stmt->fetchAll();
    $denseMap = count($points) > 200;
    $pink = imagecolorallocatealpha($image, 226, 107, 149, 72);
    $white = imagecolorallocatealpha($image, 255, 255, 255, 18);
    $dark = imagecolorallocatealpha($image, 37, 37, 37, 5);
    $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    $markerScale = max(1.0, min(1.7, max($width, $height) / 3200));
    $markerRadius = $denseMap ? (int)round(8 * $markerScale) : (int)round(20 * $markerScale);
    $fontSize = (int)round(18 * $markerScale);
    foreach ($points as $point) {
        [$pointX, $pointY] = lest97_xy((float)$point['lat'], (float)$point['lng']);
        $x = (int)round(($pointX - $projectedBounds['min_x']) / ($projectedBounds['max_x'] - $projectedBounds['min_x']) * $width);
        $y = (int)round(($projectedBounds['max_y'] - $pointY) / ($projectedBounds['max_y'] - $projectedBounds['min_y']) * $height);
        draw_map_checkpoint($image, $x, $y, checkpoint_difficulty($point['difficulty'] ?? 1), $pink, $white, $dark, $markerRadius);
        if (!$denseMap) {
            if (is_file($font)) {
                imagettftext($image, $fontSize, 0, $x + $markerRadius + 5, $y - $markerRadius + 2, $dark, $font, (string)$point['number']);
            } else {
                imagestring($image, 5, $x + 23, $y - 25, (string)$point['number'], $dark);
            }
        }
    }
    if (is_file($font)) {
        imagettftext($image, 12, 0, 12, $height - 14, $dark, $font, 'Maa- ja Ruumiamet | Pimepunkt');
    } else {
        imagestring($image, 2, 8, $height - 18, 'Maa- ja Ruumiamet | Pimepunkt', $dark);
    }

    $dir = dirname(__DIR__) . '/storage/uploads/maps';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Kaardikataloogi ei saanud luua.');
    }
    $name = 'game-' . $gameId . '-generated-' . time() . '.png';
    if (!imagepng($image, $dir . '/' . $name, 7)) {
        throw new RuntimeException('Kaardipilti ei saanud salvestada.');
    }
    imagedestroy($image);
    $path = '/uploads/maps/' . $name;
    db()->prepare('UPDATE games SET map_path = ? WHERE id = ?')->execute([$path, $gameId]);
    foreach (glob($dir . '/game-' . $gameId . '-generated-*.png') ?: [] as $oldMap) {
        if (basename($oldMap) !== $name) @unlink($oldMap);
    }
    return $path;
}

function draw_map_checkpoint(GdImage $image, int $x, int $y, int $difficulty, int $fill, int $white, int $line, int $radius): void
{
    $sides = min(7, $difficulty + 1);
    if ($difficulty === 1) {
        imagefilledellipse($image, $x, $y, $radius * 2, $radius * 2, $fill);
        imageellipse($image, $x, $y, $radius * 2, $radius * 2, $line);
    } else {
        $points = [];
        for ($i = 0; $i < $sides; $i++) {
            $angle = -M_PI / 2 + $i * 2 * M_PI / $sides;
            $points[] = (int)round($x + cos($angle) * $radius);
            $points[] = (int)round($y + sin($angle) * $radius);
        }
        imagefilledpolygon($image, $points, $fill);
        imagepolygon($image, $points, $line);
    }
    $center = $radius > 10 ? 8 : 4;
    imagefilledellipse($image, $x, $y, $center, $center, $white);
    imageellipse($image, $x, $y, $center, $center, $line);
}

function game_gpx(int $gameId): string
{
    $gameStmt = db()->prepare('SELECT * FROM games WHERE id = ?');
    $gameStmt->execute([$gameId]);
    $game = $gameStmt->fetch();
    if (!$game) throw new RuntimeException('Mängu ei leitud.');
    $stmt = db()->prepare('SELECT number, title, lat, lng, difficulty FROM checkpoints WHERE game_id = ? ORDER BY number');
    $stmt->execute([$gameId]);
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<gpx version="1.1" creator="Pimepunkt" xmlns="http://www.topografix.com/GPX/1/1">' . "\n";
    $xml .= '  <metadata><name>' . htmlspecialchars($game['name'], ENT_XML1) . '</name></metadata>' . "\n";
    foreach ($stmt->fetchAll() as $point) {
        $name = $point['number'] . ' ' . $point['title'];
        $description = checkpoint_difficulty_label((int)$point['difficulty']);
        $xml .= '  <wpt lat="' . $point['lat'] . '" lon="' . $point['lng'] . '"><name>' . htmlspecialchars($name, ENT_XML1) . '</name><desc>' . htmlspecialchars($description, ENT_XML1) . '</desc></wpt>' . "\n";
    }
    return $xml . "</gpx>\n";
}

function parse_maxspeed(string $value): ?int
{
    if (preg_match('/^(\d{1,3})\s*mph$/i', trim($value), $m)) {
        return (int)round((int)$m[1] * 1.609344);
    }
    return preg_match('/^(\d{1,3})$/', trim($value), $m) ? (int)$m[1] : null;
}

function sync_overpass_speed_zones(int $gameId): int
{
    $bounds = game_bounds($gameId);
    if (!$bounds) {
        throw new RuntimeException('Mängul ei ole punkte.');
    }
    if (($bounds['max_lat'] - $bounds['min_lat']) > 1.5 || ($bounds['max_lng'] - $bounds['min_lng']) > 2.5) {
        throw new RuntimeException('Mänguala on avaliku Overpassi ühe päringu jaoks liiga suur. Lisa sellisele mängule kiirusalad käsitsi.');
    }
    $padding = 0.03;
    $bbox = implode(',', [
        $bounds['min_lat'] - $padding, $bounds['min_lng'] - $padding,
        $bounds['max_lat'] + $padding, $bounds['max_lng'] + $padding,
    ]);
    $query = '[out:json][timeout:90];way["maxspeed"](' . $bbox . ');out tags geom;';
    $payload = null;
    $lastError = null;
    foreach (['https://overpass-api.de/api/interpreter', 'https://overpass.kumi.systems/api/interpreter', 'https://overpass.nchc.org.tw/api/interpreter'] as $endpoint) {
        try {
            $payload = json_decode(http_request($endpoint, http_build_query(['data' => $query]), 120), true, 512, JSON_THROW_ON_ERROR);
            break;
        } catch (Throwable $e) {
            $lastError = $e;
        }
    }
    if ($payload === null) throw new RuntimeException('Overpassi instantsid ei vastanud.', 0, $lastError);
    $upsert = db()->prepare('INSERT INTO speed_zones (game_id, source, source_id, name, speed_limit_kmh, geometry_type, geometry_json) VALUES (?, "overpass", ?, ?, ?, "polyline", ?) ON DUPLICATE KEY UPDATE name=VALUES(name), speed_limit_kmh=VALUES(speed_limit_kmh), geometry_json=VALUES(geometry_json), updated_at=NOW()');
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM speed_zones WHERE game_id = ? AND source = "overpass"')->execute([$gameId]);
    $seen = [];
    foreach ($payload['elements'] ?? [] as $way) {
        $limit = parse_maxspeed((string)($way['tags']['maxspeed'] ?? ''));
        if (!$limit || empty($way['geometry'])) {
            continue;
        }
        $sourceId = 'way:' . (int)$way['id'];
        $geometry = array_map(static fn(array $p): array => [(float)$p['lat'], (float)$p['lon']], $way['geometry']);
        $name = trim((string)($way['tags']['name'] ?? $way['tags']['ref'] ?? 'OSM tee'));
        $upsert->execute([$gameId, $sourceId, $name, $limit, json_encode($geometry, JSON_UNESCAPED_UNICODE)]);
        $seen[] = $sourceId;
    }
    $pdo->commit();
    return count($seen);
}

function tarktee_restrictions_geojson(int $gameId): array
{
    $bounds = game_bounds($gameId);
    if (!$bounds) return ['type' => 'FeatureCollection', 'features' => []];
    $geometry = implode(',', [$bounds['min_lng'] - .05, $bounds['min_lat'] - .03, $bounds['max_lng'] + .05, $bounds['max_lat'] + .03]);
    $query = http_build_query([
        'where' => '1=1', 'geometry' => $geometry, 'geometryType' => 'esriGeometryEnvelope',
        'inSR' => 4326, 'outSR' => 4326, 'spatialRel' => 'esriSpatialRelIntersects',
        'outFields' => 'objectid,road_nr,road_name,cause,effect,extra_info,date_from,date_to',
        'returnGeometry' => 'true', 'f' => 'geojson',
    ]);
    return json_decode(http_request('https://tarktee.transpordiamet.ee/tarktee/rest/services/restrictions_traffic/MapServer/1/query?' . $query, null, 30), true, 512, JSON_THROW_ON_ERROR);
}

function sync_tarktee_speed_zones(int $gameId): int
{
    $bounds = game_bounds($gameId);
    if (!$bounds) throw new RuntimeException('Mängul ei ole punkte.');
    $geometry = implode(',', [$bounds['min_lng'] - .05, $bounds['min_lat'] - .03, $bounds['max_lng'] + .05, $bounds['max_lat'] + .03]);
    $query = http_build_query([
        'where' => '1=1', 'geometry' => $geometry, 'geometryType' => 'esriGeometryEnvelope',
        'inSR' => 4326, 'outSR' => 4326, 'spatialRel' => 'esriSpatialRelIntersects',
        'outFields' => 'objectid,road_nr,road_name,restriction_limit,extra_info,date_from,date_to',
        'returnGeometry' => 'true', 'f' => 'geojson',
    ]);
    $data = json_decode(http_request('https://tarktee.transpordiamet.ee/tarktee/rest/services/restrictions_increased_speed/MapServer/1/query?' . $query, null, 45), true, 512, JSON_THROW_ON_ERROR);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM speed_zones WHERE game_id=? AND source="tarktee"')->execute([$gameId]);
        $insert = $pdo->prepare('INSERT INTO speed_zones (game_id,source,source_id,name,speed_limit_kmh,geometry_type,geometry_json,valid_from,valid_to) VALUES (?,"tarktee",?,?,?,"polyline",?,?,?)');
        $count = 0;
        foreach ($data['features'] ?? [] as $feature) {
            $properties = $feature['properties'] ?? [];
            $limit = (int)($properties['restriction_limit'] ?? 0);
            $coordinates = $feature['geometry']['coordinates'] ?? [];
            if (($feature['geometry']['type'] ?? '') === 'MultiLineString') $coordinates = $coordinates[0] ?? [];
            if ($limit < 5 || count($coordinates) < 2) continue;
            $points = array_map(static fn(array $point): array => [(float)$point[1], (float)$point[0]], $coordinates);
            $from = !empty($properties['date_from']) ? date('Y-m-d H:i:s', (int)($properties['date_from'] / 1000)) : null;
            $to = !empty($properties['date_to']) ? date('Y-m-d H:i:s', (int)($properties['date_to'] / 1000)) : null;
            $insert->execute([$gameId, 'restriction:' . (int)$properties['objectid'], (string)($properties['road_name'] ?? 'Tarktee piirang'), $limit, json_encode($points), $from, $to]);
            $count++;
        }
        $pdo->commit();
        return $count;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function haversine_m(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function point_segment_distance_m(float $lat, float $lng, array $a, array $b): float
{
    $scaleX = 111320 * cos(deg2rad($lat));
    $ax = ($a[1] - $lng) * $scaleX; $ay = ($a[0] - $lat) * 111320;
    $bx = ($b[1] - $lng) * $scaleX; $by = ($b[0] - $lat) * 111320;
    $dx = $bx - $ax; $dy = $by - $ay;
    $length2 = $dx * $dx + $dy * $dy;
    $t = $length2 > 0 ? max(0, min(1, -($ax * $dx + $ay * $dy) / $length2)) : 0;
    return hypot($ax + $t * $dx, $ay + $t * $dy);
}

function speed_zone_at(int $gameId, float $lat, float $lng): ?array
{
    $stmt = db()->prepare('SELECT * FROM speed_zones WHERE game_id = ? AND (valid_from IS NULL OR valid_from <= NOW()) AND (valid_to IS NULL OR valid_to >= NOW())');
    $stmt->execute([$gameId]);
    $match = null;
    $matchPriority = 0;
    foreach ($stmt->fetchAll() as $zone) {
        $distance = INF;
        if ($zone['geometry_type'] === 'circle') {
            $distance = haversine_m($lat, $lng, (float)$zone['center_lat'], (float)$zone['center_lng']);
            if ($distance > (int)$zone['radius_m']) continue;
        } else {
            $points = json_decode((string)$zone['geometry_json'], true) ?: [];
            for ($i = 1; $i < count($points); $i++) $distance = min($distance, point_segment_distance_m($lat, $lng, $points[$i - 1], $points[$i]));
            if ($distance > 35) continue;
        }
        $priority = ['overpass' => 1, 'tarktee' => 2, 'admin' => 3][$zone['source']] ?? 0;
        if (!$match || $priority > $matchPriority || ($priority === $matchPriority && (int)$zone['speed_limit_kmh'] < (int)$match['speed_limit_kmh'])) {
            $match = $zone;
            $matchPriority = $priority;
        }
    }
    return $match;
}

function record_location_and_speed(array $team, float $lat, float $lng, float $accuracy): array
{
    $lastStmt = db()->prepare('SELECT *, TIMESTAMPDIFF(MICROSECOND, created_at, NOW()) / 1000000 AS elapsed_seconds FROM location_logs WHERE team_id = ? AND ignored_reason IS NULL ORDER BY id DESC LIMIT 1');
    $lastStmt->execute([(int)$team['id']]);
    $last = $lastStmt->fetch();
    $speed = null;
    $ignored = null;
    if ($accuracy > 60) {
        $ignored = 'poor_accuracy';
    } elseif ($last) {
        $seconds = max(0.001, (float)$last['elapsed_seconds']);
        if ($seconds < 2) {
            return ['speed' => null, 'limit' => null, 'ignored' => 'too_frequent'];
        }
        $distance = haversine_m((float)$last['lat'], (float)$last['lng'], $lat, $lng);
        $noiseRadius = max(8.0, min(35.0, ($accuracy + (float)$last['accuracy_m']) * .35));
        $speed = $distance <= $noiseRadius ? 0.0 : ($distance - $noiseRadius) / $seconds * 3.6;
        if ($speed > 220) {
            $ignored = 'impossible_speed';
            $speed = null;
        }
    }
    $zone = $ignored === null && empty($team['paused_at']) ? speed_zone_at((int)$team['game_id'], $lat, $lng) : null;
    $limit = $zone ? (int)$zone['speed_limit_kmh'] : null;
    db()->prepare('INSERT INTO location_logs (team_id, lat, lng, accuracy_m, filtered_speed_kmh, speed_limit_kmh, ignored_reason) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([(int)$team['id'], $lat, $lng, $accuracy, $speed, $limit, $ignored]);
    update_speeding_event($team, $zone, $speed);
    return ['speed' => $speed, 'limit' => $limit, 'ignored' => $ignored];
}

function update_speeding_event(array $team, ?array $zone, ?float $speed): void
{
    $stmt = db()->prepare('SELECT * FROM speeding_events WHERE team_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1');
    $stmt->execute([(int)$team['id']]);
    $open = $stmt->fetch();
    $isSpeeding = $zone && $speed !== null && $speed > (int)$zone['speed_limit_kmh'] * 1.10;
    if (!$isSpeeding) {
        if ($open) db()->prepare('UPDATE speeding_events SET ended_at = NOW() WHERE id = ?')->execute([(int)$open['id']]);
        return;
    }
    if (!$open || (int)$open['speed_zone_id'] !== (int)$zone['id']) {
        if ($open) db()->prepare('UPDATE speeding_events SET ended_at = NOW() WHERE id = ?')->execute([(int)$open['id']]);
        db()->prepare('INSERT INTO speeding_events (team_id, speed_zone_id, started_at, max_speed_kmh, limit_kmh) VALUES (?, ?, NOW(), ?, ?)')
            ->execute([(int)$team['id'], (int)$zone['id'], $speed, (int)$zone['speed_limit_kmh']]);
        return;
    }
    $durationStmt = db()->prepare('SELECT TIMESTAMPDIFF(SECOND, started_at, NOW()) FROM speeding_events WHERE id = ?');
    $durationStmt->execute([(int)$open['id']]);
    $seconds = (int)$durationStmt->fetchColumn();
    $penaltyStmt = db()->prepare('SELECT speeding_penalty FROM games WHERE id = ?');
    $penaltyStmt->execute([(int)$team['game_id']]);
    $penalty = $seconds > 10 ? (int)$penaltyStmt->fetchColumn() : 0;
    $status = $seconds > 10 ? 'confirmed' : 'pending';
    db()->prepare('UPDATE speeding_events SET max_speed_kmh = GREATEST(max_speed_kmh, ?), penalty_points = ?, status = ? WHERE id = ?')
        ->execute([$speed, $penalty, $status, (int)$open['id']]);
}

function team_deadline(array $team, array $game): ?DateTimeImmutable
{
    if (!$game['duration_minutes'] || !$team['play_started_at']) return null;
    $stmt = db()->prepare('SELECT UNIX_TIMESTAMP(DATE_ADD(play_started_at, INTERVAL (? + paused_seconds) SECOND)) FROM teams WHERE id = ?');
    $stmt->execute([(int)$game['duration_minutes'] * 60, (int)$team['id']]);
    $timestamp = (int)$stmt->fetchColumn();
    return $timestamp ? (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get())) : null;
}

function team_time_expired(array $team, array $game): bool
{
    $deadline = team_deadline($team, $game);
    return $deadline !== null && !$team['paused_at'] && $deadline <= new DateTimeImmutable();
}
