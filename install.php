<?php
declare(strict_types=1);
define('HISAB', 1);
require __DIR__ . '/app/lib.php';
security_headers();

$msg = ''; $ok = false; $err = '';
$problems = [];
$stage = 'users';
$localCfg = __DIR__ . '/app/config.local.php';

if (is_installed()) {
  $ok = true;
  $msg = 'অ্যাপ আগেই ইনস্টল হয়ে গেছে। এই পাতা আর কিছু করবে না।';
} elseif (DB_NAME === 'CHANGE_ME' || DB_USER === 'CHANGE_ME') {
  $stage = 'db';
  if (!is_writable(__DIR__ . '/app')) $problems[] = 'app ফোল্ডারে লেখা যাচ্ছে না। File Manager থেকে app ফোল্ডারের permission 755 করুন।';
  if (!$problems && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['stage'] ?? '') === 'db') {
    $n = trim((string)($_POST['db_name'] ?? '')); $u = trim((string)($_POST['db_user'] ?? '')); $p = (string)($_POST['db_pass'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $n) || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $u) || $p === '') {
      $err = 'ডেটাবেসের নাম, ইউজার আর পাসওয়ার্ড ঠিকভাবে দিন।';
    } else {
      try {
        new PDO('mysql:host=localhost;dbname=' . $n . ';charset=utf8mb4', $u, $p, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $php = "<?php\nif (!defined('HISAB')) { http_response_code(403); exit; }\n"
          . "const DB_HOST = 'localhost';\n"
          . 'const DB_NAME = ' . var_export($n, true) . ";\n"
          . 'const DB_USER = ' . var_export($u, true) . ";\n"
          . 'const DB_PASS = ' . var_export($p, true) . ";\n";
        if (@file_put_contents($localCfg, $php) === false) throw new RuntimeException('config.local.php লেখা যায়নি');
        @chmod($localCfg, 0600);
        header('Location: install.php', true, 303);
        exit;
      } catch (PDOException $e) {
        $err = 'এই তথ্য দিয়ে ডেটাবেসে কানেক্ট হলো না। নাম, ইউজার আর পাসওয়ার্ড আবার মিলিয়ে দেখুন।';
      } catch (Throwable $e) { $err = 'সেভ হয়নি: ' . h($e->getMessage()); }
    }
  }
} else {
  if (!is_dir(data_dir()) || !is_writable(data_dir())) $problems[] = 'data ফোল্ডারে লেখা যাচ্ছে না। File Manager থেকে data ফোল্ডারের permission 755 করুন।';
  if (!$problems) {
    try { db()->query('SELECT 1'); }
    catch (Throwable $e) { $problems[] = 'ডেটাবেসে কানেক্ট হচ্ছে না। app/config.local.php মুছে আবার install.php খুলে তথ্য দিন।'; }
  }

  if (!$problems && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['stage'] ?? '') === 'users') {
    $pw = [];
    foreach (array_keys(PARTNERS) as $p) {
      $v = (string)($_POST['pw_' . $p] ?? '');
      if (mb_strlen($v) < 8) { $err = PARTNERS[$p] . ' এর পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের দিন।'; break; }
      $pw[$p] = $v;
    }
    if (!$err && count(array_unique($pw)) < count($pw)) $err = 'তিনজনের পাসওয়ার্ড আলাদা হতে হবে।';
    if (!$err) {
      try {
        foreach (SCHEMA as $sql) db()->exec($sql);
        $ins = db()->prepare('INSERT INTO users (id, name, pass_hash, created_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE pass_hash = VALUES(pass_hash), updated_at = VALUES(created_at)');
        foreach ($pw as $p => $v) $ins->execute([$p, PARTNERS[$p], password_hash($v, PASSWORD_DEFAULT), now()]);
        db()->prepare('INSERT IGNORE INTO settings (k, v, updated_at) VALUES (?,?,?)')->execute(['shares', json_encode(DEFAULT_SHARES), now()]);
        log_act('hamim', 'অ্যাপ ইনস্টল হয়েছে');
        if (@file_put_contents(data_dir() . '/install.lock', now()) === false) throw new RuntimeException('install.lock লেখা যায়নি');
        $ok = true;
        $msg = 'ইনস্টল সম্পূর্ণ। এখন লগইন করুন। এই পাতা আর কোনো কাজ করবে না, তাই রেখে দিলেও সমস্যা নেই।';
      } catch (Throwable $e) { $err = 'ইনস্টল হয়নি: ' . h($e->getMessage()); }
    }
  }
}
?><!doctype html>
<html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>TurFesta হিসাব · ইনস্টল</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anek+Bangla:wdth,wght@75..125,400..800&family=Hind+Siliguri:wght@400;500;600&display=swap">
<link rel="stylesheet" href="assets/app.css">
</head><body class="auth">
<main class="auth-card">
  <div class="mark big-mark">TURF<b>ESTA</b><small>হিসাব · প্রথমবার সেটআপ</small></div>
  <?php if ($ok): ?>
    <div class="note info"><?= h($msg) ?></div>
    <a class="btn" href="./">লগইন পাতায় যান</a>
  <?php elseif ($problems): ?>
    <?php foreach ($problems as $p): ?><div class="note"><?= $p ?></div><?php endforeach; ?>
    <a class="btn ghost" href="install.php">আবার দেখুন</a>
  <?php elseif ($stage === 'db'): ?>
    <p class="muted small" style="margin:0">ধাপ ১ / ২ · hPanel এ বানানো MySQL ডেটাবেসের তথ্য দিন। এটা শুধু এই সার্ভারে সেভ হবে।</p>
    <form method="post" style="display:grid;gap:14px" autocomplete="off">
      <input type="hidden" name="stage" value="db">
      <div class="field"><label for="db_name">ডেটাবেসের নাম</label><input class="inp" id="db_name" name="db_name" required placeholder="u123456789_hisab" value="<?= h((string)($_POST['db_name'] ?? '')) ?>"></div>
      <div class="field"><label for="db_user">ডেটাবেস ইউজার</label><input class="inp" id="db_user" name="db_user" required placeholder="u123456789_hisab" value="<?= h((string)($_POST['db_user'] ?? '')) ?>"></div>
      <div class="field"><label for="db_pass">ডেটাবেস পাসওয়ার্ড</label><input class="inp" type="password" id="db_pass" name="db_pass" required></div>
      <?php if ($err): ?><div class="err"><?= $err ?></div><?php endif; ?>
      <button class="btn" type="submit">কানেক্ট করুন</button>
    </form>
  <?php else: ?>
    <p class="muted small" style="margin:0">ধাপ ২ / ২ · ডেটাবেস কানেক্ট হয়েছে। তিন পার্টনারের জন্য আলাদা পাসওয়ার্ড দিন (কমপক্ষে ৮ অক্ষর)। পরে প্রত্যেকে নিজের পাসওয়ার্ড অ্যাপ থেকেই বদলাতে পারবেন।</p>
    <form method="post" style="display:grid;gap:14px">
      <input type="hidden" name="stage" value="users">
      <?php foreach (PARTNERS as $p => $n): ?>
        <div class="field"><label for="pw_<?= $p ?>"><?= $n ?> এর পাসওয়ার্ড</label>
          <input class="inp" type="text" id="pw_<?= $p ?>" name="pw_<?= $p ?>" minlength="8" required autocomplete="off"></div>
      <?php endforeach; ?>
      <?php if ($err): ?><div class="err"><?= $err ?></div><?php endif; ?>
      <button class="btn" type="submit">ইনস্টল করুন</button>
    </form>
  <?php endif; ?>
</main>
</body></html>
