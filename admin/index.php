<?php
// بالبيت admin: visits, sources, devices, buy clicks and orders.
// First visit: create the admin password (saved as a hash in bilbeit-data/admin.json, outside public_html).
// Forgot it? Delete bilbeit-data/admin.json in Hostinger File Manager, then open /admin again to set a new one.
declare(strict_types=1);
require dirname(__DIR__) . '/inc/common.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'");

session_name('bbadmin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/admin', 'secure' => !empty($_SERVER['HTTPS']),
                           'httponly' => true, 'samesite' => 'Strict']);
session_start();

$authFile = data_dir() . '/admin.json';
$failFile = data_dir() . '/admin-fails.json';
$ipKey = substr(hash('sha256', client_ip()), 0, 16);
$msg = '';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_ok(): bool { return hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? '')); }
function fails(string $file): array { $f = @json_decode((string)@file_get_contents($file), true); return is_array($f) ? $f : []; }

$hasAdmin = is_file($authFile);
$action = (string)($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $msg = 'The page expired. Try again.';
    } elseif ($action === 'setup' && !$hasAdmin) {
        $p1 = (string)($_POST['p1'] ?? ''); $p2 = (string)($_POST['p2'] ?? '');
        if (strlen($p1) < 10) $msg = 'Use at least 10 characters.';
        elseif ($p1 !== $p2) $msg = 'The two passwords are not the same.';
        else {
            $fh = @fopen($authFile, 'x'); // "x" fails if someone else created it first
            if ($fh === false) { $msg = 'Could not save. Refresh the page.'; }
            else {
                fwrite($fh, json_encode(['hash' => password_hash($p1, PASSWORD_DEFAULT), 'created' => date('c')]));
                fclose($fh); @chmod($authFile, 0600);
                session_regenerate_id(true); $_SESSION['ok'] = true;
                header('Location: ./', true, 303); exit;
            }
        }
    } elseif ($action === 'login' && $hasAdmin) {
        $all = fails($failFile); $me = $all[$ipKey] ?? ['n' => 0, 't' => 0];
        if ($me['n'] >= 5 && time() - $me['t'] < 900) {
            $msg = 'Too many tries. Wait 15 minutes.';
        } else {
            $auth = json_decode((string)file_get_contents($authFile), true);
            if (password_verify((string)($_POST['p'] ?? ''), (string)($auth['hash'] ?? ''))) {
                unset($all[$ipKey]); @file_put_contents($failFile, json_encode($all), LOCK_EX);
                session_regenerate_id(true); $_SESSION['ok'] = true;
                header('Location: ./', true, 303); exit;
            }
            $me = ['n' => (time() - $me['t'] < 900 ? $me['n'] : 0) + 1, 't' => time()];
            $all[$ipKey] = $me; @file_put_contents($failFile, json_encode($all), LOCK_EX);
            usleep(800000);
            $msg = 'Wrong password.';
        }
    } elseif ($action === 'logout') {
        $_SESSION = []; session_destroy();
        header('Location: ./', true, 303); exit;
    }
}

$in = !empty($_SESSION['ok']) && $hasAdmin;

// ---------- data ----------
$days = (int)($_GET['d'] ?? 30);
if (!in_array($days, [1, 7, 30, 90, 365], true)) $days = 30;
$from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
$today = date('Y-m-d');

$stats = null;
if ($in) {
    $daily = [];
    for ($t = strtotime($from); $t <= strtotime($today); $t = strtotime('+1 day', $t)) $daily[date('Y-m-d', $t)] = ['v' => [], 'pv' => 0];
    $sources = $pages = $devices = []; $visitors = []; $pv = $buy = $copy = $ordersEv = 0; $live = [];
    $months = []; for ($t = strtotime(date('Y-m-01', strtotime($from))); $t <= time(); $t = strtotime('+1 month', $t)) $months[] = date('Y-m', $t);
    foreach ($months as $m) {
        $fh = @fopen(DATA_DIR . "/events-$m.csv", 'rb'); if ($fh === false) continue;
        while (($r = fgetcsv($fh, null, ',', '"', '\\')) !== false) {
            if (count($r) < 7) continue;
            [$ts, $day, $ev, $page, $src, $dev, $vid] = $r;
            if ($day < $from || $day > $today) continue;
            $key = $day . $vid;
            if ($ev === 'view') {
                $pv++; $daily[$day]['pv']++; $pages[$page] = ($pages[$page] ?? 0) + 1;
                if (!isset($visitors[$key])) {
                    $visitors[$key] = true; $daily[$day]['v'][$vid] = true;
                    $sources[$src] = ($sources[$src] ?? 0) + 1; $devices[$dev] = ($devices[$dev] ?? 0) + 1;
                }
                if (time() - (int)$ts < 1800) $live[$vid] = true;
            } elseif ($ev === 'buy') $buy++;
            elseif ($ev === 'copy') $copy++;
            elseif ($ev === 'order') $ordersEv++;
        }
        fclose($fh);
    }
    arsort($sources); arsort($pages); arsort($devices);
    $names = ['/' => 'Home', '/index.html' => 'Home', '/order.html' => 'Order page', '/terms.html' => 'Terms', '/refund.html' => 'Refund', '/privacy.html' => 'Privacy'];
    $named = []; foreach ($pages as $k => $n) { $l = $names[$k] ?? $k; $named[$l] = ($named[$l] ?? 0) + $n; } $pages = $named;

    $orders = [];
    if (($fh = @fopen(ORDERS_CSV, 'rb')) !== false) {
        while (($r = fgetcsv($fh, null, ',', '"', '\\')) !== false) if (count($r) >= 7) $orders[] = $r;
        fclose($fh);
    }
    $ordersIn = array_values(array_filter($orders, fn($o) => substr($o[0], 0, 10) >= $from));
    $byVersion = ['women' => 0, 'men' => 0];
    foreach ($ordersIn as $o) if (isset($byVersion[$o[2]])) $byVersion[$o[2]]++;
    $stats = compact('daily', 'sources', 'pages', 'devices', 'pv', 'buy', 'copy', 'live', 'orders', 'ordersIn', 'byVersion') + ['uv' => count($visitors)];
}

function bars(array $map, int $total): string {
    if (!$map) return '<p class="muted">No data yet.</p>';
    $out = ''; $max = max($map);
    foreach (array_slice($map, 0, 8, true) as $k => $n) {
        $pct = $total ? round($n * 100 / $total) : 0;
        $out .= '<div class="row"><span class="lbl">' . h((string)$k) . '</span><span class="track"><i style="width:' . round($n * 100 / $max) . '%"></i></span><b>' . $n . '</b><small>' . $pct . '%</small></div>';
    }
    return $out;
}

function chart(array $daily): string {
    $n = count($daily); if (!$n) return '';
    $vals = array_map(fn($d) => count($d['v']), $daily); $max = max(1, max($vals));
    $w = 900; $hgt = 220; $gap = 2; $bw = max(2, ($w - $gap * $n) / $n);
    $svg = '<svg viewBox="0 0 ' . $w . ' ' . ($hgt + 26) . '" class="chart" role="img" aria-label="Visitors per day">';
    $i = 0; $every = max(1, (int)ceil($n / 10));
    foreach ($daily as $day => $d) {
        $v = count($d['v']); $bh = $v ? max(3, $v / $max * $hgt) : 0; $x = $i * ($bw + $gap);
        $svg .= '<rect x="' . round($x, 1) . '" y="' . round($hgt - $bh, 1) . '" width="' . round($bw, 1) . '" height="' . round($bh, 1) . '" rx="3"><title>' . $day . ': ' . $v . ' visitors, ' . $d['pv'] . ' views</title></rect>';
        if ($i % $every === 0) $svg .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . ($hgt + 18) . '">' . date('j M', strtotime($day)) . '</text>';
        $i++;
    }
    return $svg . '<text x="0" y="12" class="max">' . $max . '</text></svg>';
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Admin | بالبيت</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;700&display=swap" rel="stylesheet">
<style>
:root { --bg:#FBF5EF; --card:#fff; --soft:#F6DDD3; --text:#221C1A; --muted:#6B5D57; --accent:#E0674F; --dark:#111418; --blue:#3D7BFF; }
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'IBM Plex Sans Arabic', system-ui, sans-serif; background:var(--bg); color:var(--text); font-size:15px; line-height:1.5; }
.wrap { max-width:1100px; margin:0 auto; padding:20px 16px 60px; }
header { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:18px; }
h1 { font-size:22px; } h2 { font-size:16px; margin-bottom:12px; }
.muted { color:var(--muted); }
.card { background:var(--card); border-radius:18px; padding:18px; box-shadow:0 6px 20px rgba(34,28,26,.06); }
.grid { display:grid; gap:14px; }
.kpis { grid-template-columns:repeat(2,1fr); margin-bottom:14px; }
.kpi b { display:block; font-size:30px; line-height:1.1; } .kpi span { color:var(--muted); font-size:13px; }
.kpi.hi { background:var(--accent); color:#fff; } .kpi.hi span { color:#ffe3dc; }
.two { grid-template-columns:1fr; margin-top:14px; }
.range { display:flex; gap:6px; flex-wrap:wrap; }
.range a, button, .btn { border:0; background:var(--card); color:var(--text); padding:8px 14px; border-radius:999px; text-decoration:none; font:inherit; font-weight:500; cursor:pointer; }
.range a.on, .btn-main { background:var(--dark); color:#fff; }
.chart { width:100%; height:auto; display:block; } .chart rect { fill:var(--accent); } .chart rect:hover { fill:var(--dark); }
.chart text { font-size:11px; fill:var(--muted); text-anchor:middle; } .chart .max { text-anchor:start; }
.row { display:grid; grid-template-columns:110px 1fr 40px 38px; align-items:center; gap:8px; padding:5px 0; }
.lbl { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.track { background:var(--soft); border-radius:6px; height:10px; overflow:hidden; } .track i { display:block; height:100%; background:var(--accent); border-radius:6px; }
.row b { text-align:right; } .row small { color:var(--muted); text-align:right; }
.funnel div { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--soft); }
table { width:100%; border-collapse:collapse; font-size:14px; } th, td { text-align:left; padding:8px 6px; border-bottom:1px solid var(--soft); white-space:nowrap; }
th { color:var(--muted); font-weight:500; } .scroll { overflow-x:auto; }
td.ar { direction:rtl; text-align:right; }
.login { max-width:360px; margin:12vh auto; } .login input { width:100%; padding:12px 14px; margin:6px 0 12px; border:2px solid var(--soft); border-radius:12px; font:inherit; }
.login button { width:100%; background:var(--accent); color:#fff; padding:12px; font-weight:700; }
.err { background:#FDECEA; color:#8A2A1A; padding:10px 12px; border-radius:10px; margin-bottom:12px; }
.note { font-size:13px; color:var(--muted); margin-top:18px; }
@media (min-width:760px) { .kpis { grid-template-columns:repeat(5,1fr); } .two { grid-template-columns:1fr 1fr; } }
</style>
</head>
<body>
<?php if (!$hasAdmin): ?>
  <form class="card login" method="post" autocomplete="off">
    <h1>Create the admin password</h1>
    <p class="muted" style="margin:6px 0 14px">This shows only once. Pick a password only you know (10+ characters).</p>
    <?php if ($msg): ?><p class="err"><?= h($msg) ?></p><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="setup">
    <label>Password<input type="password" name="p1" minlength="10" required autocomplete="new-password"></label>
    <label>Same password again<input type="password" name="p2" minlength="10" required autocomplete="new-password"></label>
    <button type="submit">Save and open the dashboard</button>
  </form>
<?php elseif (!$in): ?>
  <form class="card login" method="post">
    <h1>بالبيت admin</h1>
    <?php if ($msg): ?><p class="err" style="margin-top:12px"><?= h($msg) ?></p><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="login">
    <label style="display:block;margin-top:12px">Password<input type="password" name="p" required autocomplete="current-password" autofocus></label>
    <button type="submit">Log in</button>
  </form>
<?php else: $s = $stats; $conv = $s['uv'] ? round(count($s['ordersIn']) * 100 / $s['uv'], 1) : 0; ?>
<div class="wrap">
  <header>
    <div><h1>بالبيت dashboard</h1><p class="muted"><?= h(date('j M Y', strtotime($from))) ?> – today · Beirut time</p></div>
    <nav class="range">
      <?php foreach ([1 => 'Today', 7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => 'Year'] as $d => $label): ?>
        <a href="?d=<?= $d ?>" class="<?= $d === $days ? 'on' : '' ?>"><?= $label ?></a>
      <?php endforeach; ?>
      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="logout"><button type="submit">Log out</button></form>
    </nav>
  </header>

  <section class="grid kpis">
    <div class="card kpi"><b><?= $s['uv'] ?></b><span>Visitors</span></div>
    <div class="card kpi"><b><?= $s['pv'] ?></b><span>Page views</span></div>
    <div class="card kpi"><b><?= $s['buy'] ?></b><span>Buy button clicks</span></div>
    <div class="card kpi hi"><b><?= count($s['ordersIn']) ?></b><span>Orders (<?= $s['byVersion']['women'] ?> women · <?= $s['byVersion']['men'] ?> men)</span></div>
    <div class="card kpi"><b><?= count($s['live']) ?></b><span>On the site now (30 min)</span></div>
  </section>

  <section class="card"><h2>Visitors per day</h2><?= chart($s['daily']) ?></section>

  <section class="grid two">
    <div class="card"><h2>Where visitors come from</h2><?= bars($s['sources'], $s['uv']) ?></div>
    <div class="card"><h2>Funnel</h2>
      <div class="funnel">
        <div><span>Visitors</span><b><?= $s['uv'] ?></b></div>
        <div><span>Clicked a buy button</span><b><?= $s['buy'] ?></b></div>
        <div><span>Copied the Whish number</span><b><?= $s['copy'] ?></b></div>
        <div><span>Sent an order</span><b><?= count($s['ordersIn']) ?></b></div>
        <div><span>Visitor → order</span><b><?= $conv ?>%</b></div>
      </div>
    </div>
    <div class="card"><h2>Pages</h2><?= bars($s['pages'], $s['pv']) ?></div>
    <div class="card"><h2>Devices</h2><?= bars($s['devices'], $s['uv']) ?></div>
  </section>

  <section class="card" style="margin-top:14px"><h2>Latest orders</h2>
    <?php if (!$s['orders']): ?><p class="muted">No orders yet.</p><?php else: ?>
    <div class="scroll"><table>
      <tr><th>When</th><th>Order</th><th>Version</th><th>Name</th><th>Email</th><th>Whish number</th><th>Ref</th></tr>
      <?php foreach (array_slice(array_reverse($s['orders']), 0, 50) as $o): ?>
        <tr><td><?= h($o[0]) ?></td><td><?= h($o[1]) ?></td><td><?= h($o[2]) ?></td><td class="ar"><?= h(ltrim($o[3], "'")) ?></td>
            <td><a href="mailto:<?= h(ltrim($o[4], "'")) ?>"><?= h(ltrim($o[4], "'")) ?></a></td><td><?= h(ltrim($o[5], "'")) ?></td><td><?= h(ltrim($o[6], "'")) ?></td></tr>
      <?php endforeach; ?>
    </table></div><?php endif; ?>
  </section>

  <p class="note">Visitors are counted once per day, without cookies and without storing IP addresses; bots are skipped.
    Instagram reach and followers are in Meta Business Suite → Insights. Tip: add <code>?utm_source=ig_bio</code> to the bio link to see it here.</p>
</div>
<?php endif; ?>
</body>
</html>
