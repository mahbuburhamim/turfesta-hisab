<?php
declare(strict_types=1);
if (!defined('HISAB')) { http_response_code(403); exit; }

$cfgFile = getenv('HISAB_CONFIG') ?: (is_file(__DIR__ . '/config.local.php') ? __DIR__ . '/config.local.php' : __DIR__ . '/config.php');
require $cfgFile;

date_default_timezone_set('Asia/Dhaka');

const PARTNERS = ['hamim' => 'হামিম', 'amit' => 'অমিত', 'jami' => 'জামি'];
const METHODS  = ['cash' => 'ক্যাশ', 'bkash' => 'বিকাশ', 'nagad' => 'নগদ', 'bank' => 'ব্যাংক'];
const CATS = [
  ['lease', 'লিজ অ্যাডভান্স ও ভাড়া'], ['earth', 'মাটি ভরাট ও লেভেলিং'], ['drain', 'ড্রেনেজ'],
  ['boundary', 'বাউন্ডারি ও গেট'], ['turf', 'টার্ফ ও ঘাস'], ['net', 'নেট, পোল ও স্ট্রাকচার'],
  ['light', 'ফ্লাডলাইট ও ইলেকট্রিক'], ['pool', 'সুইমিং পুল'], ['build', 'ক্যাফে, অফিস ও শপ নির্মাণ'],
  ['labour', 'লেবার মজুরি'], ['permit', 'পারমিট ও আইনি'], ['design', 'ডিজাইন ও কনসালট্যান্ট'], ['other', 'যাতায়াত ও অন্যান্য'],
];
const DEFAULT_SHARES = ['hamim' => 33.34, 'amit' => 33.33, 'jami' => 33.33];

function data_dir(): string { return dirname(__DIR__) . '/data'; }
function is_installed(): bool { return file_exists(data_dir() . '/install.lock'); }
const SEED_VENDORS = ['বেলাল মামা', 'বিমল দা', 'ইকবাল', 'ইঞ্জি. শান্ত', 'কারেন্ট', 'ওয়েল্ডিং', 'অন্যান্য'];
const VENDOR_BN = ['Belal Mama' => 'বেলাল মামা', 'Bimol Da' => 'বিমল দা', 'Iqbal' => 'ইকবাল', 'Eng. Shanto' => 'ইঞ্জি. শান্ত',
  'Current' => 'কারেন্ট', 'Welding' => 'ওয়েল্ডিং', 'Others' => 'অন্যান্য'];

function custom_cats(): array {
  $st = db()->prepare('SELECT v FROM settings WHERE k = ?'); $st->execute(['custom_cats']);
  $v = json_decode((string)$st->fetchColumn(), true);
  return is_array($v) ? array_values(array_filter($v, fn($c) => is_array($c) && count($c) === 2)) : [];
}
function all_cats(): array { static $c = null; if ($c === null) $c = array_merge(CATS, custom_cats()); return $c; }
function cat_ids(): array { return array_column(all_cats(), 0); }
function cat_label(string $id): string { foreach (all_cats() as [$k, $l]) if ($k === $id) return $l; return $id; }

/** Small one-time data migrations, safe to call on every request. */
function migrate(): void {
  $st = db()->prepare('SELECT 1 FROM settings WHERE k = ?'); $st->execute(['vendors_bn_v1']);
  if (!$st->fetchColumn()) {
    $up = db()->prepare('UPDATE vendors SET name = ? WHERE name = ?');
    $n = 0;
    foreach (VENDOR_BN as $en => $bn) { $up->execute([$bn, $en]); $n += $up->rowCount(); }
    db()->prepare('INSERT INTO settings (k, v, updated_at) VALUES (?,?,?)')->execute(['vendors_bn_v1', '[]', now()]);
    if ($n) log_act('system', 'ভেন্ডরের নাম বাংলায় করা হয়েছে');
  }
  $st = db()->prepare('SELECT 1 FROM settings WHERE k = ?'); $st->execute(['seed_vendors_v1']);
  if ($st->fetchColumn()) return;
  $have = array_map('mb_strtolower', db()->query('SELECT name FROM vendors')->fetchAll(PDO::FETCH_COLUMN));
  $ins = db()->prepare("INSERT INTO vendors (name, phone, work, contract, created_by, created_at) VALUES (?, '', '', 0, 'system', ?)");
  $added = [];
  foreach (SEED_VENDORS as $n) { if (!in_array(mb_strtolower($n), $have, true)) { $ins->execute([$n, now()]); $added[] = $n; } }
  db()->prepare('INSERT INTO settings (k, v, updated_at) VALUES (?,?,?)')->execute(['seed_vendors_v1', '[]', now()]);
  if ($added) log_act('system', 'প্রাথমিক ভেন্ডর তালিকা যোগ: ' . implode(', ', $added));
}

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
  $pdo->exec("SET time_zone = '+06:00'");
  return $pdo;
}

function start_session(): void {
  $dir = data_dir() . '/sessions';
  if (!is_dir($dir)) @mkdir($dir, 0700, true);
  $life = 60 * 60 * 24 * 30;
  ini_set('session.gc_maxlifetime', (string)$life);
  ini_set('session.use_strict_mode', '1');
  if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
  session_name('tfhisab');
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  session_set_cookie_params(['lifetime' => $life, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
  session_start();
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function me(): ?string {
  $u = $_SESSION['uid'] ?? null;
  return ($u && isset(PARTNERS[$u])) ? $u : null;
}

function now(): string { return date('Y-m-d H:i:s'); }

function log_act(string $user, string $detail): void {
  db()->prepare('INSERT INTO activity (user_id, detail, created_at) VALUES (?,?,?)')->execute([$user, mb_substr($detail, 0, 250), now()]);
}

function security_headers(): void {
  header('X-Frame-Options: DENY');
  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: same-origin');
  header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data: blob:; frame-ancestors 'none'; form-action 'self'; base-uri 'self'");
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function bn_money(float $n): string {
  $s = number_format(round($n), 0, '.', ',');
  return '৳' . strtr($s, ['0'=>'০','1'=>'১','2'=>'২','3'=>'৩','4'=>'৪','5'=>'৫','6'=>'৬','7'=>'৭','8'=>'৮','9'=>'৯']);
}

const SCHEMA = [
  "CREATE TABLE IF NOT EXISTS users (
     id VARCHAR(20) PRIMARY KEY, name VARCHAR(60) NOT NULL, pass_hash VARCHAR(255) NOT NULL,
     created_at DATETIME NOT NULL, updated_at DATETIME NULL
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
  "CREATE TABLE IF NOT EXISTS vendors (
     id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, phone VARCHAR(20) NOT NULL DEFAULT '',
     work VARCHAR(20) NOT NULL, contract DECIMAL(14,2) NOT NULL DEFAULT 0,
     created_by VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, updated_by VARCHAR(20) NULL, updated_at DATETIME NULL
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
  "CREATE TABLE IF NOT EXISTS expenses (
     id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, spent_on DATE NOT NULL, amount DECIMAL(14,2) NOT NULL,
     category VARCHAR(20) NOT NULL, paid_by VARCHAR(20) NOT NULL, method VARCHAR(10) NOT NULL,
     vendor_id INT UNSIGNED NULL, note VARCHAR(200) NOT NULL DEFAULT '',
     receipt_file VARCHAR(120) NULL, receipt_type VARCHAR(40) NULL, receipt_by VARCHAR(20) NULL, receipt_at DATETIME NULL,
     status VARCHAR(10) NOT NULL DEFAULT 'ok',
     created_by VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL,
     void_by VARCHAR(20) NULL, void_at DATETIME NULL, void_reason VARCHAR(160) NULL,
     INDEX (spent_on), INDEX (vendor_id)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
  "CREATE TABLE IF NOT EXISTS deposits (
     id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, partner VARCHAR(20) NOT NULL, amount DECIMAL(14,2) NOT NULL,
     paid_on DATE NOT NULL, method VARCHAR(10) NOT NULL, note VARCHAR(120) NOT NULL DEFAULT '',
     status VARCHAR(10) NOT NULL DEFAULT 'ok', created_by VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL,
     void_by VARCHAR(20) NULL, void_at DATETIME NULL, void_reason VARCHAR(160) NULL
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
  "CREATE TABLE IF NOT EXISTS settings (
     k VARCHAR(40) PRIMARY KEY, v TEXT NOT NULL, updated_by VARCHAR(20) NULL, updated_at DATETIME NULL
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
  "CREATE TABLE IF NOT EXISTS activity (
     id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id VARCHAR(20) NOT NULL, detail VARCHAR(250) NOT NULL, created_at DATETIME NOT NULL
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
  "CREATE TABLE IF NOT EXISTS login_attempts (
     id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(45) NOT NULL, created_at DATETIME NOT NULL, INDEX (ip, created_at)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
