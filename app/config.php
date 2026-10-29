<?php
// TurFesta হিসাব — ডেটাবেস সেটিংস।
// আসল তথ্য এই ফাইলে রাখবেন না। install.php প্রথমবার চালানোর সময় app/config.local.php ফাইলে নিজে থেকে সেভ করে।
// config.local.php কখনো Git-এ যায় না।
if (!defined('HISAB')) { http_response_code(403); exit; }

const DB_HOST = 'localhost';
const DB_NAME = 'CHANGE_ME';
const DB_USER = 'CHANGE_ME';
const DB_PASS = 'CHANGE_ME';
