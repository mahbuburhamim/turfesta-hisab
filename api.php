<?php
declare(strict_types=1);
define('HISAB', 1);
require __DIR__ . '/app/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
security_headers();

function out(array $d, int $code = 200): never {
  http_response_code($code);
  echo json_encode($d, JSON_UNESCAPED_UNICODE);
  exit;
}
function fail(string $msg, int $code = 400): never { out(['error' => $msg], $code); }

if (!is_installed()) fail('অ্যাপটা এখনো ইনস্টল হয়নি।', 503);
start_session();
$me = me();
if (!$me) fail('আবার লগইন করুন।', 401);

$a = (string)($_GET['a'] ?? '');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$in = [];
if ($isPost) {
  $tok = $_SERVER['HTTP_X_CSRF'] ?? '';
  if (!is_string($tok) || !hash_equals($_SESSION['csrf'], $tok)) fail('পাতাটা রিফ্রেশ করে আবার চেষ্টা করুন।', 403);
  if (str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];
  } else {
    $in = $_POST;
  }
  session_write_close();
}

/* ---------- validators ---------- */
function v_amount($v): float {
  $s = str_replace([',', ' ', '৳'], '', (string)$v);
  if (!is_numeric($s)) fail('টাকার পরিমাণ ঠিকভাবে লিখুন।');
  $n = round((float)$s, 2);
  if ($n <= 0 || $n > 1e11) fail('টাকার পরিমাণ ঠিকভাবে লিখুন।');
  return $n;
}
function v_money0($v): float {
  $s = str_replace([',', ' ', '৳'], '', (string)$v);
  if ($s === '') return 0.0;
  if (!is_numeric($s) || (float)$s < 0 || (float)$s > 1e11) fail('টাকার পরিমাণ ঠিকভাবে লিখুন।');
  return round((float)$s, 2);
}
function v_date($v): string {
  $s = (string)$v;
  $d = DateTime::createFromFormat('!Y-m-d', $s);
  if (!$d || $d->format('Y-m-d') !== $s) fail('তারিখ ঠিক নেই।');
  if ($s > date('Y-m-d')) fail('ভবিষ্যতের তারিখ দেওয়া যাবে না।');
  if ($s < '2020-01-01') fail('তারিখ ঠিক নেই।');
  return $s;
}
function v_in($v, array $allowed, string $what): string {
  $s = (string)$v;
  if (!in_array($s, $allowed, true)) fail($what . ' বাছাই করুন।');
  return $s;
}
function v_text($v, int $max): string { return mb_substr(trim((string)$v), 0, $max); }
function v_id($v): int { $n = (int)$v; if ($n <= 0) fail('এন্ট্রি পাওয়া যায়নি।', 404); return $n; }

/* ---------- uploads ---------- */
function save_upload(?array $f): array {
  if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, null];
  $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
  if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) fail('ফাইলটা অনেক বড়। ১০ MB এর ছোট ছবি দিন।');
  if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) fail('ছবি আপলোড হয়নি। আবার চেষ্টা করুন।');
  if ($f['size'] > 10 * 1024 * 1024) fail('ফাইলটা অনেক বড়। ১০ MB এর ছোট ছবি দিন।');
  $head = (string)file_get_contents($f['tmp_name'], false, null, 0, 16);
  if (str_starts_with($head, "\xFF\xD8\xFF")) { $mime = 'image/jpeg'; $ext = 'jpg'; }
  elseif (str_starts_with($head, "\x89PNG")) { $mime = 'image/png'; $ext = 'png'; }
  elseif (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') { $mime = 'image/webp'; $ext = 'webp'; }
  elseif (str_starts_with($head, '%PDF')) { $mime = 'application/pdf'; $ext = 'pdf'; }
  else fail('শুধু JPG, PNG, WEBP ছবি বা PDF দেওয়া যাবে।');
  $sub = date('Y-m');
  $dir = data_dir() . '/uploads/' . $sub;
  if (!is_dir($dir) && !@mkdir($dir, 0755, true)) fail('সার্ভারে ফাইল রাখার ফোল্ডার নেই।', 500);
  $rel = $sub . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
  if (!move_uploaded_file($f['tmp_name'], data_dir() . '/uploads/' . $rel)) fail('ছবি সেভ হয়নি।', 500);
  return [$rel, $mime];
}

/* ---------- shapes ---------- */
function exp_out(array $r): array {
  return [
    'id' => (int)$r['id'], 'date' => $r['spent_on'], 'amount' => (float)$r['amount'], 'category' => $r['category'],
    'paidBy' => $r['paid_by'], 'method' => $r['method'], 'vendorId' => $r['vendor_id'] ? (int)$r['vendor_id'] : null,
    'note' => $r['note'], 'hasReceipt' => (bool)$r['receipt_file'], 'receiptType' => $r['receipt_type'],
    'receiptBy' => $r['receipt_by'], 'receiptAt' => $r['receipt_at'],
    'status' => $r['status'], 'createdBy' => $r['created_by'], 'createdAt' => $r['created_at'],
    'voidBy' => $r['void_by'], 'voidAt' => $r['void_at'], 'voidReason' => $r['void_reason'],
  ];
}
function dep_out(array $r): array {
  return [
    'id' => (int)$r['id'], 'partner' => $r['partner'], 'amount' => (float)$r['amount'], 'date' => $r['paid_on'],
    'method' => $r['method'], 'note' => $r['note'], 'status' => $r['status'], 'createdBy' => $r['created_by'],
    'createdAt' => $r['created_at'], 'voidBy' => $r['void_by'], 'voidAt' => $r['void_at'], 'voidReason' => $r['void_reason'],
  ];
}
function ven_out(array $r): array {
  return ['id' => (int)$r['id'], 'name' => $r['name'], 'phone' => $r['phone'], 'work' => $r['work'], 'contract' => (float)$r['contract']];
}
function get_setting(string $k, $default) {
  $st = db()->prepare('SELECT v FROM settings WHERE k = ?'); $st->execute([$k]);
  $v = $st->fetchColumn();
  if ($v === false) return $default;
  $d = json_decode((string)$v, true);
  return is_array($d) ? $d : $default;
}
function put_setting(string $k, array $v, string $me): void {
  db()->prepare('INSERT INTO settings (k, v, updated_by, updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)')
    ->execute([$k, json_encode($v, JSON_UNESCAPED_UNICODE), $me, now()]);
}

$name = PARTNERS[$me];
$payers = array_merge(array_keys(PARTNERS), ['fund']);

try {
  switch ($a) {

    case 'state':
      $exp = array_map('exp_out', db()->query('SELECT * FROM expenses ORDER BY spent_on DESC, id DESC')->fetchAll());
      $dep = array_map('dep_out', db()->query('SELECT * FROM deposits ORDER BY paid_on DESC, id DESC')->fetchAll());
      $ven = array_map('ven_out', db()->query('SELECT * FROM vendors ORDER BY name')->fetchAll());
      $act = db()->query('SELECT user_id AS user, detail, created_at AS at FROM activity ORDER BY id DESC LIMIT 40')->fetchAll();
      out([
        'expenses' => $exp, 'deposits' => $dep, 'vendors' => $ven, 'activity' => $act,
        'shares' => get_setting('shares', DEFAULT_SHARES), 'budgets' => (object)get_setting('budgets', []),
      ]);

    case 'add_expense':
      if (!$isPost) fail('ভুল অনুরোধ।', 405);
      $amount = v_amount($in['amount'] ?? '');
      $date = v_date($in['date'] ?? '');
      $cat = v_in($in['category'] ?? '', cat_ids(), 'খাত');
      $paid = v_in($in['paidBy'] ?? '', $payers, 'টাকা কে দিল');
      $method = v_in($in['method'] ?? '', array_keys(METHODS), 'মাধ্যম');
      $vendor = null;
      if (($in['vendorId'] ?? '') !== '') {
        $vendor = v_id($in['vendorId']);
        $st = db()->prepare('SELECT 1 FROM vendors WHERE id = ?'); $st->execute([$vendor]);
        if (!$st->fetchColumn()) fail('ভেন্ডর পাওয়া যায়নি।');
      }
      $note = v_text($in['note'] ?? '', 200);
      [$file, $type] = save_upload($_FILES['receipt'] ?? null);
      db()->prepare('INSERT INTO expenses (spent_on, amount, category, paid_by, method, vendor_id, note, receipt_file, receipt_type, receipt_by, receipt_at, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$date, $amount, $cat, $paid, $method, $vendor, $note, $file, $type, $file ? $me : null, $file ? now() : null, 'ok', $me, now()]);
      log_act($me, 'খরচ যোগ: ' . bn_money($amount) . ' · ' . cat_label($cat) . ($file ? '' : ' · রসিদ ছাড়া'));
      out(['ok' => true]);

    case 'add_receipt':
      if (!$isPost) fail('ভুল অনুরোধ।', 405);
      $id = v_id($in['id'] ?? 0);
      $st = db()->prepare('SELECT * FROM expenses WHERE id = ?'); $st->execute([$id]);
      $row = $st->fetch();
      if (!$row) fail('এন্ট্রি পাওয়া যায়নি।', 404);
      if ($row['status'] !== 'ok') fail('বাতিল খরচে রসিদ যোগ করা যায় না।');
      if ($row['receipt_file']) fail('এই খরচে রসিদ আগেই আছে।');
      [$file, $type] = save_upload($_FILES['receipt'] ?? null);
      if (!$file) fail('ছবি বাছাই করুন।');
      db()->prepare('UPDATE expenses SET receipt_file = ?, receipt_type = ?, receipt_by = ?, receipt_at = ? WHERE id = ? AND receipt_file IS NULL')
        ->execute([$file, $type, $me, now(), $id]);
      log_act($me, 'রসিদ যোগ: ' . bn_money((float)$row['amount']) . ' · ' . cat_label($row['category']));
      out(['ok' => true]);

    case 'void':
      if (!$isPost) fail('ভুল অনুরোধ।', 405);
      $col = v_in($in['col'] ?? '', ['expenses', 'deposits'], 'ধরন');
      $id = v_id($in['id'] ?? 0);
      $reason = v_text($in['reason'] ?? '', 160);
      if ($reason === '') fail('বাতিলের কারণ লিখুন।');
      $st = db()->prepare("SELECT amount FROM $col WHERE id = ? AND status = 'ok'"); $st->execute([$id]);
      $amt = $st->fetchColumn();
      if ($amt === false) fail('এন্ট্রি পাওয়া যায়নি বা আগেই বাতিল হয়েছে।', 404);
      db()->prepare("UPDATE $col SET status = 'void', void_by = ?, void_at = ?, void_reason = ? WHERE id = ? AND status = 'ok'")
        ->execute([$me, now(), $reason, $id]);
      log_act($me, ($col === 'expenses' ? 'খরচ বাতিল: ' : 'জমা বাতিল: ') . bn_money((float)$amt) . ' · কারণ: ' . $reason);
      out(['ok' => true]);

    case 'add_deposit':
      if (!$isPost) fail('ভুল অনুরোধ।', 405);
      $amount = v_amount($in['amount'] ?? '');
      $partner = v_in($in['partner'] ?? '', array_keys(PARTNERS), 'কে জমা দিল');
      $date = v_date($in['date'] ?? '');
      $method = v_in($in['method'] ?? '', array_keys(METHODS), 'মাধ্যম');
      $note = v_text($in['note'] ?? '', 120);
      db()->prepare('INSERT INTO deposits (partner, amount, paid_on, method, note, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$partner, $amount, $date, $method, $note, 'ok', $me, now()]);
      log_act($me, 'ফান্ডে জমা: ' . bn_money($amount) . ' · ' . PARTNERS[$partner]);
      out(['ok' => true]);

    case 'save_vendor':
      if (!$isPost) fail('ভুল অনুরোধ।', 405);
      $vname = v_text($in['name'] ?? '', 80);
      if ($vname === '') fail('নাম লিখুন।');
      $phone = v_text($in['phone'] ?? '', 20);
      $work = v_in($in['work'] ?? '', cat_ids(), 'কাজ');
      $contract = v_money0($in['contract'] ?? '');
      if (!empty($in['id'])) {
        $id = v_id($in['id']);
        $st = db()->prepare('SELECT contract FROM vendors WHERE id = ?'); $st->execute([$id]);
        $old = $st->fetchColumn();
        if ($old === false) fail('ভেন্ডর পাওয়া যায়নি।', 404);
        db()->prepare('UPDATE vendors SET name = ?, phone = ?, work = ?, contract = ?, updated_by = ?, updated_at = ? WHERE id = ?')
          ->execute([$vname, $phone, $work, $contract, $me, now(), $id]);
        $chg = ((float)$old !== $contract) ? ' · চুক্তি ' . bn_money((float)$old) . ' → ' . bn_money($contract) : '';
        log_act($me, 'ভেন্ডর এডিট: ' . $vname . $chg);
      } else {
        db()->prepare('INSERT INTO vendors (name, phone, work, contract, created_by, created_at) VALUES (?,?,?,?,?,?)')
          ->execute([$vname, $phone, $work, $contract, $me, now()]);
        log_act($me, 'নতুন ভেন্ডর: ' . $vname . ($contract ? ' · চুক্তি ' . bn_money($contract) : ''));
      }
      out(['ok' => true]);

    case 'save_budget':
      if (!$isPost) fail('ভুল অনুরোধ।', 405);
      $raw = $in['budgets'] ?? [];
      if (!is_array($raw)) fail('বাজেট ঠিক নেই।');
      $b = [];
      foreach (cat_ids() as $c) { if (isset($raw[$c])) { $v = v_money0($raw[$c]); if ($v > 0) $b[$c] = $v; } }
      put_setting('budgets', $b, $me);
      log_act($me, 'বাজেট বদল · মোট ' . bn_money(array_sum($b)));
      out(['ok' => true]);

    case 'save_shares':
      if (!$isPost) fail('ভুল অনুরোধ।', 405);
      $raw = $in['shares'] ?? [];
      if (!is_array($raw)) fail('ভাগ ঠিক নেই।');
      $s = []; $tot = 0.0;
      foreach (array_keys(PARTNERS) as $p) {
        $v = str_replace(',', '', (string)($raw[$p] ?? ''));
        if (!is_numeric($v) || (float)$v < 0 || (float)$v > 100) fail('ভাগের সংখ্যা ঠিক নেই।');
        $s[$p] = round((float)$v, 2); $tot += $s[$p];
      }
      if (abs($tot - 100) > 0.05) fail('ভাগের মোট ১০০% হতে হবে।');
      put_setting('shares', $s, $me);
      log_act($me, 'মালিকানার ভাগ বদল: ' . implode(' / ', array_map(fn($p) => PARTNERS[$p] . ' ' . $s[$p] . '%', array_keys($s))));
      out(['ok' => true]);

    case 'change_password':
      if (!$isPost) fail('ভুল অনুরোধ।', 405);
      $cur = (string)($in['current'] ?? ''); $new = (string)($in['new'] ?? '');
      if (mb_strlen($new) < 8) fail('নতুন পাসওয়ার্ড কমপক্ষে ৮ অক্ষরের হতে হবে।');
      $st = db()->prepare('SELECT pass_hash FROM users WHERE id = ?'); $st->execute([$me]);
      if (!password_verify($cur, (string)$st->fetchColumn())) fail('বর্তমান পাসওয়ার্ড মেলেনি।');
      db()->prepare('UPDATE users SET pass_hash = ?, updated_at = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), now(), $me]);
      log_act($me, 'নিজের পাসওয়ার্ড বদলেছেন');
      out(['ok' => true]);

    case 'logout':
      if (!$isPost) fail('ভুল অনুরোধ।', 405);
      session_start();
      $_SESSION = [];
      session_destroy();
      out(['ok' => true]);

    case 'csv':
      $st = ['ok' => 'ঠিক আছে', 'void' => 'বাতিল'];
      $payN = PARTNERS + ['fund' => 'কমন ফান্ড'];
      $vn = [];
      foreach (db()->query('SELECT id, name FROM vendors') as $r) $vn[(int)$r['id']] = $r['name'];
      header('Content-Type: text/csv; charset=utf-8');
      header('Content-Disposition: attachment; filename="turfesta-hisab-' . date('Y-m-d') . '.csv"');
      $o = fopen('php://output', 'w');
      fwrite($o, "\xEF\xBB\xBF");
      $safe = fn($v) => (is_string($v) && preg_match('/^[=+\-@\t\r]/', $v)) ? "'" . $v : $v;
      fputcsv($o, ['ধরন', 'তারিখ', 'টাকা', 'খাত', 'কে দিল', 'মাধ্যম', 'ভেন্ডর', 'বিবরণ', 'অবস্থা', 'এন্ট্রি দিয়েছেন', 'এন্ট্রির সময়', 'রসিদ', 'বাতিলের কারণ']);
      foreach (db()->query('SELECT * FROM expenses ORDER BY spent_on, id') as $r) {
        fputcsv($o, array_map($safe, ['খরচ', $r['spent_on'], (float)$r['amount'], cat_label($r['category']), $payN[$r['paid_by']] ?? $r['paid_by'],
          METHODS[$r['method']] ?? $r['method'], $vn[(int)$r['vendor_id']] ?? '', $r['note'], $st[$r['status']] ?? $r['status'],
          PARTNERS[$r['created_by']] ?? $r['created_by'], $r['created_at'], $r['receipt_file'] ? 'আছে' : 'নেই', (string)$r['void_reason']]));
      }
      foreach (db()->query('SELECT * FROM deposits ORDER BY paid_on, id') as $r) {
        fputcsv($o, array_map($safe, ['ফান্ডে জমা', $r['paid_on'], (float)$r['amount'], '', PARTNERS[$r['partner']] ?? $r['partner'],
          METHODS[$r['method']] ?? $r['method'], '', $r['note'], $st[$r['status']] ?? $r['status'],
          PARTNERS[$r['created_by']] ?? $r['created_by'], $r['created_at'], '', (string)$r['void_reason']]));
      }
      fclose($o);
      exit;

    default:
      fail('অজানা অনুরোধ।', 404);
  }
} catch (PDOException $e) {
  error_log('hisab db: ' . $e->getMessage());
  fail('ডেটাবেসে সমস্যা হয়েছে। একটু পরে আবার চেষ্টা করুন।', 500);
}
