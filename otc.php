<?php

header('Content-Type: application/json');
date_default_timezone_set('Asia/Dhaka');

// === Log IP to file ===
$clientIP = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
$logFile = __DIR__ . '/qxpersonallog.txt';
$logEntry = date('Y-m-d H:i:s') . " - IP: $clientIP\n";
file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

// === Parameters ===
$pair = isset($_GET['pair']) ? strtoupper(trim($_GET['pair'])) : null;
$minutes_param = isset($_GET['minutes']) ? intval($_GET['minutes']) : -1;
$broker = isset($_GET['broker']) ? strtoupper(trim($_GET['broker'])) : null;

if (!$pair) {
    http_response_code(400);
    echo json_encode(["error" => "Missing 'pair' parameter"]);
    exit;
}

// Calculate minutes
if ($minutes_param === -1) {
    $now = new DateTime();
    $minutes = $now->format('G') * 60 + $now->format('i');
} else {
    $minutes = $minutes_param;
}

// Validate minutes
if ($minutes < 1 || $minutes > 1440) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid 'minutes' value"]);
    exit;
}

// === Fetch Extra Candles for Indicator Warm-up ===
$extraCandleCount = 50;
$fetchLimit = $minutes + $extraCandleCount;

$url = "http://149.28.150.60/candles/?terminal=MT4&verify=MtcxOkgxMyYRfA"
     . "&limit={$minutes_param}&broker={$broker}"
     . "&asset=OTC-{$pair}&period=60&token=c74302689eb88a1007e84e5b";

$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_ENCODING => ''
]);

$response = curl_exec($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode([
        "error" => "Failed to fetch candle data",
        "curl_error" => curl_error($ch)
    ]);
    curl_close($ch);
    exit;
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    http_response_code(502);
    echo json_encode([
        "error" => "API returned HTTP $httpCode"
    ]);
    exit;
}

// === Parse Candles ===
$lines = explode('$', trim($response));
$candles = [];

foreach ($lines as $line) {
    if (strpos($line, 'END_CANDLE') !== false) continue;
    if (empty(trim($line))) continue; // Skip empty lines
    
    $parts = explode('|', $line);
    if (count($parts) != 7) continue;

    $timestamp = (int)$parts[1];
    $open = (float)$parts[2];
    $high = (float)$parts[3];
    $low  = (float)$parts[4];
    $close = (float)$parts[5];

    $candles[] = [
        'timestamp' => $timestamp,
        'open' => $open,
        'high' => $high,
        'low' => $low,
        'close' => $close,
    ];
}

// Check if we have enough candles
if (count($candles) < 26) { // Need at least 26 for EMA26
    http_response_code(500);
    echo json_encode(["error" => "Insufficient candle data for indicators"]);
    exit;
}

// === Indicator Calculations ===

// EMA
function calculateEMA(array $prices, int $period): array {
    if (empty($prices) || $period < 1) return [];
    
    $k = 2 / ($period + 1);
    $ema = [];
    $ema[0] = $prices[0];
    for ($i = 1; $i < count($prices); $i++) {
        $ema[$i] = $prices[$i] * $k + $ema[$i - 1] * (1 - $k);
    }
    return $ema;
}

// RSI
function calculateRSI(array $prices, int $period): array {
    if (count($prices) < $period + 1) return [];
    
    $rsi = [];
    $gains = [];
    $losses = [];

    for ($i = 1; $i < count($prices); $i++) {
        $change = $prices[$i] - $prices[$i - 1];
        $gains[] = max($change, 0);
        $losses[] = max(-$change, 0);
    }

    $avgGain = array_sum(array_slice($gains, 0, $period)) / $period;
    $avgLoss = array_sum(array_slice($losses, 0, $period)) / $period;

    $rsi[$period] = ($avgLoss == 0) ? 100 : 100 - (100 / (1 + ($avgGain / $avgLoss)));

    for ($i = $period + 1; $i < count($prices); $i++) {
        $gain = $gains[$i - 1];
        $loss = $losses[$i - 1];

        $avgGain = (($avgGain * ($period - 1)) + $gain) / $period;
        $avgLoss = (($avgLoss * ($period - 1)) + $loss) / $period;

        $rsi[$i] = ($avgLoss == 0) ? 100 : 100 - (100 / (1 + ($avgGain / $avgLoss)));
    }

    // Pad start
    for ($i = 0; $i < $period; $i++) {
        $rsi[$i] = null;
    }

    ksort($rsi);
    return $rsi;
}

// Prepare for indicators
$closes = array_column($candles, 'close');
$ema12 = calculateEMA($closes, 12);
$ema26 = calculateEMA($closes, 26);

// MACD Line
$macdLine = [];
for ($i = 0; $i < count($closes); $i++) {
    $macdLine[$i] = isset($ema12[$i], $ema26[$i]) ? $ema12[$i] - $ema26[$i] : null;
}

// Signal Line (EMA 9 of MACD) - Fix alignment
$validMacd = [];
$validMacdIndices = [];
foreach ($macdLine as $idx => $value) {
    if ($value !== null && is_numeric($value)) {
        $validMacd[] = $value;
        $validMacdIndices[] = $idx;
    }
}

$signalValues = [];
if (count($validMacd) >= 9) {
    $signalValues = calculateEMA($validMacd, 9);
    
    // Map signal values back to original indices
    $signalLine = [];
    for ($i = 0; $i < count($validMacd); $i++) {
        $signalLine[$validMacdIndices[$i]] = $signalValues[$i];
    }
} else {
    $signalLine = [];
}

// RSI
$rsi = calculateRSI($closes, 14);

// === Format Output ===
$output = [];

foreach ($candles as $i => $candle) {
    $direction = ($candle['close'] > $candle['open']) ? "CALL" : "PUT";

    $dt = new DateTime("@{$candle['timestamp']}");
    $dt->setTimezone(new DateTimeZone('Asia/Dhaka'));
    $formatted_time = $dt->format('H:i');

    $output[] = [
        "time" => $formatted_time,
        "direction" => $direction,
        "open" => round($candle['open'], 5),
        "high" => round($candle['high'], 5),
        "low"  => round($candle['low'], 5),
        "close" => round($candle['close'], 5),
        "ema12" => isset($ema12[$i]) ? round($ema12[$i], 5) : null,
        "ema26" => isset($ema26[$i]) ? round($ema26[$i], 5) : null,
        "macd" => isset($macdLine[$i]) ? round($macdLine[$i], 5) : null,
        "signal" => isset($signalLine[$i]) ? round($signalLine[$i], 5) : null,
        "rsi" => isset($rsi[$i]) && $rsi[$i] !== null ? round($rsi[$i], 2) : null
    ];
}

// === Return Only Last $minutes Candles ===
if (count($output) > $minutes) {
    $output = array_slice($output, -$minutes);
}

echo json_encode($output, JSON_PRETTY_PRINT);
?>