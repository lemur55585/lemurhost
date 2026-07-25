<?php
// ============================================================
// LEMUR HOST — bezpieczny endpoint zamówień
// Webhook Discorda żyje TYLKO tutaj, po stronie serwera.
// Ten plik nigdy nie jest widoczny dla przeglądarki — PHP wykonuje się
// na serwerze, a do klienta trafia wyłącznie wynik (JSON).
// ============================================================

header('Content-Type: application/json; charset=utf-8');

// --- KONFIGURACJA: podmień na swój prawdziwy webhook ---
const DISCORD_WEBHOOK_URL = 'https://discord.com/api/webhooks/1525558768631287958/XCao1bOPQbQf5hq32azBBA1rkJq_ES0tP5vSpAlFBzb0Hb34GGoz_oqGD4qQJc2nr0xg';

function respond(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$raw = file_get_contents('php://input');
$in = json_decode($raw, true);
if (!is_array($in)) {
    respond(['ok' => false, 'error' => 'Nieprawidłowe dane.'], 400);
}

function s(array $a, string $key): string {
    return isset($a[$key]) ? trim((string) $a[$key]) : '';
}

$typ    = s($in, 'typ');
$imie   = s($in, 'imie');
$email  = s($in, 'email');
$uwagi  = s($in, 'uwagi');

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
    $nazwa = s($in, 'domena_nazwa');
    $rozsz = s($in, 'domena_rozszerzenie');
    $lata  = (int) s($in, 'domena_lata');
    if ($nazwa === '') respond(['ok' => false, 'error' => 'Podaj nazwę domeny.'], 400);
    if (!in_array($rozsz, ['.com', '.net', '.org', '.com.pl'], true)) respond(['ok' => false, 'error' => 'Nieprawidłowe rozszerzenie domeny.'], 400);
    $lata = max(1, min(10, $lata));
    $lataLabel = $lata === 1 ? 'rok' : ($lata < 5 ? 'lata' : 'lat');
    $fields[] = ['name' => 'Domena', 'value' => "{$nazwa}{$rozsz} — {$lata} {$lataLabel}", 'inline' => false];
    $price += domainPrice($lata);
}

if ($typ === 'hosting' || $typ === 'oba') {
    $pkgKey = s($in, 'hosting_pkg');
    $okres  = s($in, 'hosting_okres');
    if (!isset(PACKAGES[$pkgKey])) respond(['ok' => false, 'error' => 'Nieprawidłowy pakiet hostingu.'], 400);
    if (!in_array($okres, ['month', 'year'], true)) respond(['ok' => false, 'error' => 'Nieprawidłowy okres rozliczeniowy.'], 400);
    $pkg = PACKAGES[$pkgKey];
    $price += $okres === 'year' ? $pkg['year'] : $pkg['month'];
    $fields[] = ['name' => 'Hosting', 'value' => $pkg['name'] . ' — ' . ($okres === 'year' ? 'rocznie' : 'miesięcznie'), 'inline' => false];

    $addonKeys = is_array($in['addons'] ?? null) ? $in['addons'] : [];
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
if ($uwagi !== '') $fields[] = ['name' => 'Uwagi', 'value' => mb_substr($uwagi, 0, 900), 'inline' => false];
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
