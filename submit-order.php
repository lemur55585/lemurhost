<?php
// ============================================================
// LEMUR HOST — bezpieczny endpoint zamówień
// Webhook Discorda żyje TYLKO tutaj, po stronie serwera.
// Ten plik nigdy nie jest widoczny dla przeglądarki — PHP wykonuje się
// na serwerze, a do klienta trafia wyłącznie wynik (JSON).
//
// Zabezpieczenia w tym pliku:
//  1) Honeypot — ukryte pole, które wypełniają tylko boty.
//  2) Minimalny czas wypełniania formularza — odrzuca błyskawiczne
//     zgłoszenia typowe dla botów.
//  3) Rate limiting per adres IP — plik na dysku, bez bazy danych.
//  4) Limity długości pól i limit rozmiaru całego żądania.
//  5) Cena zawsze liczona od nowa po stronie serwera.
// ============================================================

header('Content-Type: application/json; charset=utf-8');

// --- KONFIGURACJA: podmień na swój prawdziwy webhook ---
const DISCORD_WEBHOOK_URL = 'https://discord.com/api/webhooks/1525558768631287958/XCao1bOPQbQf5hq32azBBA1rkJq_ES0tP5vSpAlFBzb0Hb34GGoz_oqGD4qQJc2nr0xg';

const RATE_LIMIT_MAX = 5;          // maks. zgłoszeń...
const RATE_LIMIT_WINDOW = 900;     // ...na okno czasowe w sekundach (900 = 15 minut)
const MIN_FILL_SECONDS = 3;        // formularz wypełniony szybciej niż to = podejrzane
const MAX_BODY_BYTES = 20000;      // maksymalny rozmiar żądania (bajty)

function respond(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['ok' => false, 'error' => 'Method not allowed'], 405);
}

// ---- Limit rozmiaru żądania ----
$rawLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($rawLength > MAX_BODY_BYTES) {
    respond(['ok' => false, 'error' => 'Żądanie zbyt duże.'], 413);
}

// ============================================================
// Rate limiting per IP (plik JSON, bez bazy danych)
// ============================================================
function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function rate_limit_check(): void {
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $file = $dir . '/ratelimit.json';

    $fh = fopen($file, 'c+');
    if (!$fh) return; // jeśli nie da się zapisać, nie blokujemy zamówień z tego powodu
    flock($fh, LOCK_EX);

    $raw = stream_get_contents($fh);
    $store = json_decode($raw ?: '{}', true);
    if (!is_array($store)) $store = [];

    $ip = client_ip();
    $now = time();
    $hits = array_values(array_filter($store[$ip] ?? [], fn($t) => $t > $now - RATE_LIMIT_WINDOW));

    if (count($hits) >= RATE_LIMIT_MAX) {
        flock($fh, LOCK_UN);
        fclose($fh);
        respond(['ok' => false, 'error' => 'Zbyt wiele zgłoszeń w krótkim czasie. Spróbuj ponownie za kilka minut.'], 429);
    }

    $hits[] = $now;
    $store[$ip] = $hits;

    // Sprzątanie: usuń adresy IP bez aktywności w oknie czasowym
    foreach ($store as $key => $timestamps) {
        $fresh = array_values(array_filter($timestamps, fn($t) => $t > $now - RATE_LIMIT_WINDOW));
        if (empty($fresh)) unset($store[$key]); else $store[$key] = $fresh;
    }

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($store));
    flock($fh, LOCK_UN);
    fclose($fh);
}

rate_limit_check();

// ============================================================
// Wczytanie i podstawowa walidacja danych
// ============================================================
$raw = file_get_contents('php://input');
$in = json_decode($raw, true);
if (!is_array($in)) {
    respond(['ok' => false, 'error' => 'Nieprawidłowe dane.'], 400);
}

function s(array $a, string $key, int $maxLen = 300): string {
    $v = isset($a[$key]) ? trim((string) $a[$key]) : '';
    return mb_substr($v, 0, $maxLen);
}

// ---- Honeypot: ukryte pole "website" — boty je wypełniają, ludzie go nie widzą ----
if (s($in, 'website', 200) !== '') {
    // Udajemy sukces, żeby bot nie wiedział, że został zablokowany.
    respond(['ok' => true, 'code' => 'LH-000000-0000', 'price' => 0]);
}

// ---- Minimalny czas wypełniania formularza ----
$renderedAt = (float) ($in['rendered_at'] ?? 0);
if ($renderedAt <= 0 || (microtime(true) * 1000 - $renderedAt) < MIN_FILL_SECONDS * 1000) {
    respond(['ok' => false, 'error' => 'Zgłoszenie odrzucone. Spróbuj ponownie.'], 400);
}

$typ    = s($in, 'typ', 20);
$imie   = s($in, 'imie', 80);
$email  = s($in, 'email', 190);
$uwagi  = s($in, 'uwagi', 900);

if (!in_array($typ, ['domena', 'hosting', 'oba'], true)) {
    respond(['ok' => false, 'error' => 'Nieprawidłowy typ zamówienia.'], 400);
}
if ($imie === '') respond(['ok' => false, 'error' => 'Podaj imię lub nick.'], 400);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['ok' => false, 'error' => 'Podaj poprawny adres e-mail.'], 400);

// ---- Ceny liczone WYŁĄCZNIE po stronie serwera (nie ufamy przeglądarce) ----
function domainPrice(int $years): int {
    $years = max(1, min(10, $years));
    $base = [1 => 60, 2 => 65, 3 => 85, 4 => 140];
    if ($years <= 4) return $base[$years];
    return 140 + 60 * ($years - 4);
}

const PACKAGES = [
    'mini'  => ['name' => 'LemurHost MINI',  'month' => 5,  'year' => 50],
    'start' => ['name' => 'LemurHost START', 'month' => 10, 'year' => 100],
    'plus'  => ['name' => 'LemurHost PLUS',  'month' => 20, 'year' => 200],
    'pro'   => ['name' => 'LemurHost PRO',   'month' => 50, 'year' => 500],
];
const ADDONS = [
    'backup'  => ['name' => 'Backup PRO',   'month' => 15],
    'monitor' => ['name' => 'Monitor',      'month' => 10],
    'support' => ['name' => 'Support PRO',  'month' => 10],
    'secure'  => ['name' => 'Secure PRO',   'month' => 20],
    'db'      => ['name' => 'Database',     'month' => 5],
    'dbpro'   => ['name' => 'Database PRO', 'month' => 10],
];

$fields = [];
$price = 0;

if ($typ === 'domena' || $typ === 'oba') {
    $nazwa = s($in, 'domena_nazwa', 63);
    $rozsz = s($in, 'domena_rozszerzenie', 10);
    $lata  = (int) s($in, 'domena_lata', 4);
    if ($nazwa === '') respond(['ok' => false, 'error' => 'Podaj nazwę domeny.'], 400);
    if (!preg_match('/^[a-zA-Z0-9-]+$/', $nazwa)) respond(['ok' => false, 'error' => 'Nazwa domeny może zawierać tylko litery, cyfry i myślnik.'], 400);
    if (!in_array($rozsz, ['.com', '.net', '.org', '.com.pl'], true)) respond(['ok' => false, 'error' => 'Nieprawidłowe rozszerzenie domeny.'], 400);
    $lata = max(1, min(10, $lata));
    $lataLabel = $lata === 1 ? 'rok' : ($lata < 5 ? 'lata' : 'lat');
    $fields[] = ['name' => 'Domena', 'value' => "{$nazwa}{$rozsz} — {$lata} {$lataLabel}", 'inline' => false];
    $price += domainPrice($lata);
}

if ($typ === 'hosting' || $typ === 'oba') {
    $pkgKey = s($in, 'hosting_pkg', 20);
    $okres  = s($in, 'hosting_okres', 10);
    if (!isset(PACKAGES[$pkgKey])) respond(['ok' => false, 'error' => 'Nieprawidłowy pakiet hostingu.'], 400);
    if (!in_array($okres, ['month', 'year'], true)) respond(['ok' => false, 'error' => 'Nieprawidłowy okres rozliczeniowy.'], 400);
    $pkg = PACKAGES[$pkgKey];
    $price += $okres === 'year' ? $pkg['year'] : $pkg['month'];
    $fields[] = ['name' => 'Hosting', 'value' => $pkg['name'] . ' — ' . ($okres === 'year' ? 'rocznie' : 'miesięcznie'), 'inline' => false];

    $addonKeysRaw = is_array($in['addons'] ?? null) ? $in['addons'] : [];
    $addonKeys = array_slice(array_values(array_filter($addonKeysRaw, 'is_string')), 0, 10);
    $addonNames = [];
    foreach ($addonKeys as $key) {
        if (!isset(ADDONS[$key])) continue;
        $addon = ADDONS[$key];
        $price += $okres === 'year' ? $addon['month'] * 10 : $addon['month'];
        $addonNames[] = $addon['name'];
    }
    if ($addonNames) {
        $fields[] = ['name' => 'Dodatki', 'value' => implode(', ', $addonNames), 'inline' => false];
    }
}

$fields[] = ['name' => 'Klient', 'value' => "{$imie} ({$email})", 'inline' => false];
if ($uwagi !== '') $fields[] = ['name' => 'Uwagi', 'value' => $uwagi, 'inline' => false];
$fields[] = ['name' => 'Cena', 'value' => $price . ' zł', 'inline' => false];

// ---- Numer zamówienia ----
$code = 'LH-' . date('ymd') . '-' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

// ---- Wysyłka na Discord (webhook zostaje tutaj, po stronie serwera) ----
$payload = json_encode([
    'username' => 'Lemur Host — Zamówienia',
    'embeds' => [[
        'title' => "Nowe zamówienie — {$code}",
        'color' => hexdec('3AA8F0'),
        'fields' => $fields,
        'timestamp' => date('c'),
    ]],
]);

$ch = curl_init(DISCORD_WEBHOOK_URL);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
]);
$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode < 200 || $httpCode >= 300) {
    respond(['ok' => false, 'error' => 'Nie udało się wysłać zamówienia na Discord.'], 502);
}

respond(['ok' => true, 'code' => $code, 'price' => $price]);
