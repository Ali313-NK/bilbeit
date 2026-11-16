<?php
// Visit counter. t.js sends one small request per page view or button click. No cookies, no IP stored.
declare(strict_types=1);
require __DIR__ . '/inc/common.php';

header('Cache-Control: no-store');
http_response_code(204);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') exit;

$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
if (is_bot($ua)) exit;

$event = (string)($_POST['e'] ?? 'view');
if (!in_array($event, ['view', 'buy', 'copy'], true)) exit;
$page = preg_replace('/[^a-z0-9._\/-]/i', '', mb_substr((string)($_POST['p'] ?? '/'), 0, 60));
$page = $page === '' ? '/' : $page;
$source = source_of(mb_substr((string)($_POST['s'] ?? ''), 0, 40), mb_substr((string)($_POST['r'] ?? ''), 0, 300), $ua);

log_event($event, $page, $source, device_of($ua), visitor_id(client_ip(), $ua));
