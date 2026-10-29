<?php
declare(strict_types=1);
define('HISAB', 1);
require __DIR__ . '/app/lib.php';

if (!is_installed()) { http_response_code(503); exit; }
start_session();
$me = me();
session_write_close();
if (!$me) { http_response_code(401); exit('আবার লগইন করুন।'); }

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT receipt_file, receipt_type FROM expenses WHERE id = ?');
$st->execute([$id]);
$r = $st->fetch();
if (!$r || !$r['receipt_file'] || !preg_match('#^\d{4}-\d{2}/[a-f0-9]{24}\.(jpg|png|webp|pdf)$#', $r['receipt_file'])) { http_response_code(404); exit('রসিদ পাওয়া যায়নি।'); }
$path = data_dir() . '/uploads/' . $r['receipt_file'];
if (!is_file($path)) { http_response_code(404); exit('রসিদের ফাইল সার্ভারে নেই।'); }

header('Content-Type: ' . $r['receipt_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="receipt-' . $id . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
readfile($path);
