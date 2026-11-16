<?php
// Receives the order form from order.html (Whish payment, manual delivery).
// Emails the order to the shop, sends the buyer a confirmation, and keeps a backup line
// in a CSV file one folder above public_html (not reachable from the web).
declare(strict_types=1);
require __DIR__ . '/inc/common.php';

const SHOP_EMAIL = 'bilbeit.fit@gmail.com';
const FROM_EMAIL = 'orders@bilbeit.kachakech.tech';

function back(string $query): void {
    header('Location: order.html?' . $query, true, 303);
    exit;
}

function field(string $key, int $max): string {
    $v = (string)($_POST[$key] ?? '');
    $v = str_replace(["\r", "\n", "\t"], ' ', $v);
    return trim(mb_substr($v, 0, $max));
}

function encode_header(string $s): string {
    return '=?UTF-8?B?' . base64_encode($s) . '?=';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    back('');
}
if (field('website', 100) !== '') {
    back('sent=1'); // spam bot filled the hidden field
}

$version = field('version', 10);
$name = field('name', 80);
$email = field('email', 120);
$phone = field('phone', 30);
$ref = field('ref', 60);

if (!in_array($version, ['women', 'men'], true) || $name === '' || $phone === ''
    || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    back('err=1&v=' . ($version === 'men' ? 'men' : 'women'));
}

$id = 'B' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
$label = $version === 'women' ? '28 يوم بالبيت – للنساء' : '28 يوم بالبيت – للرجال';
$when = (new DateTime('now', new DateTimeZone('Asia/Beirut')))->format('Y-m-d H:i');

// Backup copy. A leading quote stops spreadsheet formulas in user text.
$safe = function (string $s): string {
    return preg_match('/^[=+\-@]/', $s) ? "'" . $s : $s;
};
$saved = false;
$fh = @fopen(ORDERS_CSV, 'ab');
if ($fh !== false) {
    $saved = fputcsv($fh, array_map($safe, [$when, $id, $version, $name, $email, $phone, $ref]), ',', '"', '\\') !== false;
    fclose($fh);
}

$from = 'From: ' . encode_header('بالبيت') . ' <' . FROM_EMAIL . '>';
$common = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";

$shopBody = "طلب جديد\n\n"
    . "رقم الطلب: $id\nالوقت: $when (بيروت)\nالنسخة: $label\n"
    . "الاسم: $name\nالإيميل: $email\nرقم Whish: $phone\nرقم العملية: " . ($ref !== '' ? $ref : '-') . "\n\n"
    . "1) شيك بتطبيق Whish إنو وصلت الـ15$ من هالرقم.\n"
    . "2) ردّ على هالإيميل (بيروح للزبون) وحطّ الـPDF.\n";
$sentShop = mail(SHOP_EMAIL, encode_header("طلب جديد $id – $label"), $shopBody,
    "$from\r\nReply-To: $email\r\n$common", '-f' . FROM_EMAIL);

$buyerBody = "أهلا {$name}،\n\n"
    . "وصلنا طلبك: $label\nرقم الطلب: $id\n\n"
    . "منتأكد من الدفع عبر Whish، ومنبعتلك الـPDF على هالإيميل خلال 24 ساعة، وغالبًا أسرع.\n"
    . "إذا ما شفته، شيك بالـSpam.\n\n"
    . "أي سؤال؟ ردّ على هالإيميل.\n\nبالبيت\nhttps://bilbeit.kachakech.tech\n";
mail($email, encode_header("وصلنا طلبك $id"), $buyerBody,
    "$from\r\nReply-To: " . SHOP_EMAIL . "\r\n$common", '-f' . FROM_EMAIL);

$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
log_event('order', '/order.php', source_of(field('src', 40), '', $ua), device_of($ua), visitor_id(client_ip(), $ua));

if (!$sentShop && !$saved) {
    back('err=2&v=' . $version);
}
back('sent=1&id=' . rawurlencode($id));
