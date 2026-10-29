<?php
declare(strict_types=1);
define('HISAB', 1);
require __DIR__ . '/app/lib.php';

if (!is_installed()) { header('Location: install.php'); exit; }
start_session();
security_headers();
header('Cache-Control: no-store');

$err = '';
$ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'login') {
  try {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
      $err = 'পাতাটা পুরনো হয়ে গেছে। আবার চেষ্টা করুন।';
    } else {
      db()->prepare('DELETE FROM login_attempts WHERE created_at < ?')->execute([date('Y-m-d H:i:s', time() - 86400)]);
      $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > ?');
      $st->execute([$ip, date('Y-m-d H:i:s', time() - 900)]);
      if ((int)$st->fetchColumn() >= 8) {
        $err = 'অনেকবার ভুল পাসওয়ার্ড দেওয়া হয়েছে। ১৫ মিনিট পরে আবার চেষ্টা করুন।';
      } else {
        $uid = (string)($_POST['user'] ?? '');
        $st = db()->prepare('SELECT pass_hash FROM users WHERE id = ?');
        $st->execute([$uid]);
        $hash = $st->fetchColumn();
        if (isset(PARTNERS[$uid]) && $hash && password_verify((string)($_POST['password'] ?? ''), (string)$hash)) {
          session_regenerate_id(true);
          $_SESSION['uid'] = $uid;
          $_SESSION['csrf'] = bin2hex(random_bytes(16));
          db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
          if (password_needs_rehash((string)$hash, PASSWORD_DEFAULT)) {
            db()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([password_hash((string)$_POST['password'], PASSWORD_DEFAULT), $uid]);
          }
          header('Location: ./', true, 303);
          exit;
        }
        db()->prepare('INSERT INTO login_attempts (ip, created_at) VALUES (?,?)')->execute([$ip, now()]);
        $err = 'নাম বা পাসওয়ার্ড মেলেনি।';
      }
    }
  } catch (PDOException $e) {
    error_log('hisab login: ' . $e->getMessage());
    $err = 'ডেটাবেসে সমস্যা হয়েছে। একটু পরে চেষ্টা করুন।';
  }
}

$me = me();
$v = (string)@filemtime(__DIR__ . '/assets/app.js') . (string)@filemtime(__DIR__ . '/assets/app.css');
?><!doctype html>
<html lang="bn"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#123A1C">
<meta name="robots" content="noindex,nofollow">
<title>TurFesta হিসাব</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anek+Bangla:wdth,wght@75..125,400..800&family=Hind+Siliguri:wght@400;500;600&display=swap">
<link rel="stylesheet" href="assets/app.css?v=<?= h($v) ?>">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='7' fill='%23123A1C'/%3E%3Cpath d='M9 22V10h14' stroke='%23A6E84A' stroke-width='3.5' fill='none'/%3E%3C/svg%3E">
</head>
<?php if (!$me): ?>
<body class="auth">
<main class="auth-card">
  <div class="mark big-mark">TURF<b>ESTA</b><small>নির্মাণের হিসাব</small></div>
  <form method="post" style="display:grid;gap:16px" autocomplete="on">
    <input type="hidden" name="do" value="login">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
    <div class="field"><span class="lab">আপনি কে?</span>
      <div class="seg" role="radiogroup">
        <?php $sel = (string)($_POST['user'] ?? ''); foreach (PARTNERS as $p => $n): ?>
          <input type="radio" name="user" id="u-<?= $p ?>" value="<?= $p ?>" <?= $sel === $p ? 'checked' : '' ?> required><label for="u-<?= $p ?>"><?= $n ?></label>
        <?php endforeach; ?>
      </div></div>
    <div class="field"><label for="password">পাসওয়ার্ড</label>
      <input class="inp" type="password" id="password" name="password" required autocomplete="current-password"></div>
    <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
    <button class="btn" type="submit">লগইন</button>
  </form>
  <p class="small muted" style="margin:0">একবার লগইন করলে এই ফোনে ৩০ দিন লগইন থাকবে।</p>
</main>
</body></html>
<?php exit; endif; ?>
<body>
<script type="application/json" id="boot"><?= json_encode([
  'me' => $me, 'csrf' => $_SESSION['csrf'], 'partners' => PARTNERS, 'methods' => METHODS, 'cats' => CATS,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<header class="top"><div class="top-in">
  <div class="mark">TURF<b>ESTA</b><small>নির্মাণের হিসাব</small></div>
  <button class="me" data-act="account" type="button"><b><?= h(PARTNERS[$me]) ?></b> ▾</button>
</div></header>

<main id="view" aria-live="polite"><div class="skel"></div><div class="skel"></div></main>

<button class="fab" id="fab" data-act="newExpense" type="button" hidden>+ খরচ</button>

<nav class="tabs" aria-label="বিভাগ"><div class="tabs-in">
  <a href="#home" data-tab="home"><svg viewBox="0 0 24 24"><path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg>সারাংশ</a>
  <a href="#expenses" data-tab="expenses"><svg viewBox="0 0 24 24"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/></svg>খরচ</a>
  <a href="#fund" data-tab="fund"><svg viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="13" rx="2"/><path d="M16 12.5h2M3 10h18"/></svg>ফান্ড</a>
  <a href="#vendors" data-tab="vendors"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.6 3.4-5.5 6.5-5.5s5.7 1.9 6.5 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.8c1.9.8 3.1 2.5 3.5 5.2"/></svg>ভেন্ডর</a>
  <a href="#budget" data-tab="budget"><svg viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>বাজেট</a>
</div></nav>
<script src="assets/app.js?v=<?= h($v) ?>"></script>
</body></html>
