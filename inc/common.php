<?php
// Shared helpers for the visit counter (t.php), order.php and the admin page.
// Data lives one folder ABOVE public_html, so it can never be downloaded from the web.
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

define('HOME_DIR', dirname(__DIR__, 2));
define('DATA_DIR', HOME_DIR . '/bilbeit-data');
define('ORDERS_CSV', HOME_DIR . '/bilbeit-orders.csv');
date_default_timezone_set('Asia/Beirut');

function data_dir(): string {
    if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0700, true); }
    return DATA_DIR;
}

function is_bot(string $ua): bool {
    return $ua === '' || (bool)preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|headless|curl|wget|python|lighthouse|monitor|scan/i', $ua);
}

function device_of(string $ua): string {
    if (preg_match('/iPad|Tablet/i', $ua)) return 'Tablet';
    if (preg_match('/Mobi|Android|iPhone/i', $ua)) return 'Mobile';
    return 'Desktop';
}

/** Where the visit came from: utm_source first, then the in-app browser, then the referrer host. */
function source_of(string $utm, string $ref, string $ua): string {
    $utm = strtolower(trim($utm));
    if ($utm !== '') {
        if (str_contains($utm, 'ig') || str_contains($utm, 'insta')) return 'Instagram';
        if (str_contains($utm, 'fb') || str_contains($utm, 'face')) return 'Facebook';
        return mb_substr($utm, 0, 30);
    }
    if (stripos($ua, 'Instagram') !== false) return 'Instagram';
    if (preg_match('/FBAN|FBAV|FB_IAB/', $ua)) return 'Facebook';
    $host = strtolower((string)parse_url($ref, PHP_URL_HOST));
    $host = preg_replace('/^(www|m|l|lm)\./', '', $host);
    if ($host === '') return 'Direct';
    if (str_contains($host, 'instagram')) return 'Instagram';
    if (str_contains($host, 'facebook') || $host === 'fb.com') return 'Facebook';
    if (str_contains($host, 'google')) return 'Google';
    if (str_contains($host, 'whatsapp') || $host === 'wa.me') return 'WhatsApp';
    if (str_contains($host, 'tiktok')) return 'TikTok';
    return mb_substr($host, 0, 40);
}

/** Anonymous visitor id: changes every day, the IP is never stored. */
function visitor_id(string $ip, string $ua): string {
    $file = data_dir() . '/salt.json';
    $today = date('Y-m-d');
    $salt = @json_decode((string)@file_get_contents($file), true);
    if (!is_array($salt) || ($salt['day'] ?? '') !== $today) {
        $salt = ['day' => $today, 'salt' => bin2hex(random_bytes(16))];
        @file_put_contents($file, json_encode($salt), LOCK_EX);
    }
    return substr(hash('sha256', $salt['salt'] . '|' . $ip . '|' . $ua), 0, 12);
}

/** One line per event in bilbeit-data/events-YYYY-MM.csv: time, day, event, page, source, device, visitor. */
function log_event(string $event, string $page, string $source, string $device, string $vid): void {
    $fh = @fopen(data_dir() . '/events-' . date('Y-m') . '.csv', 'ab');
    if ($fh === false) return;
    @fputcsv($fh, [time(), date('Y-m-d'), $event, $page, $source, $device, $vid], ',', '"', '\\');
    fclose($fh);
}

function client_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}
