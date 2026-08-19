<?php
declare(strict_types=1);

$sessionPath = __DIR__ . '/storage/sessions';
if (!is_dir($sessionPath) && !mkdir($sessionPath, 0775, true) && !is_dir($sessionPath)) {
    throw new RuntimeException('Unable to create the application session directory.');
}
session_save_path($sessionPath);
session_start();

const DB_HOST = '127.0.0.1';
const DB_NAME = 'library_management_system';
const DB_USER = 'root';
const DB_PASS = '';
const FINE_PER_DAY = 50.00;

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database connection failed. Import database/schema.sql first.');
}

function e(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }
function redirect(string $url): never { header('Location: ' . $url); exit; }
function is_post(): bool { return $_SERVER['REQUEST_METHOD'] === 'POST'; }
function require_login(): void { if (empty($_SESSION['user'])) redirect('index.php'); }
function flash(string $type, string $message): void { $_SESSION['flash'] = compact('type', 'message'); }
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(24)); }
function verify_csrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419); exit('Invalid request token. Please go back and try again.');
    }
}
function update_overdue(PDO $pdo): void {
    $pdo->exec("UPDATE book_issues SET status='overdue', fine=GREATEST(DATEDIFF(CURDATE(), due_date), 0) * " . FINE_PER_DAY . " WHERE return_date IS NULL AND due_date < CURDATE()");
}
