<?php
declare(strict_types=1);

// Ensure storage session directory exists
$sessionPath = __DIR__ . '/storage/sessions';
if (!is_dir($sessionPath) && !mkdir($sessionPath, 0775, true) && !is_dir($sessionPath)) {
    throw new RuntimeException('Unable to create the application session directory.');
}
if (session_status() === PHP_SESSION_NONE) {
    session_save_path($sessionPath);
    session_start();
}

// Database Credentials
const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'library_management_system';
const DB_USER = 'root';
const DB_PASS = '';
const FINE_PER_DAY = 50.00; // Rs. 50.00 per overdue day

// PDO Connection with automatic utf8mb4 charset and error handling
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    // If database connection fails, give user a helpful message
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Database Connection Error | Library Management System</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light d-flex align-items-center min-vh-100">
        <div class="container" style="max-width: 650px;">
            <div class="card shadow-sm border-0 rounded-4 p-4 text-center">
                <div class="mb-3 text-danger display-4">⚠️</div>
                <h3 class="fw-bold mb-2">Database Connection Failed</h3>
                <p class="text-muted">Could not connect to MySQL database <code><?=htmlspecialchars(DB_NAME)?></code>.</p>
                <div class="alert alert-warning text-start small">
                    <strong>Quick Setup Guide:</strong>
                    <ol class="mb-0 mt-1 ps-3">
                        <li>Make sure <strong>Apache</strong> and <strong>MySQL</strong> are started in XAMPP Control Panel.</li>
                        <li>Open <a href="http://localhost/phpmyadmin" target="_blank" class="alert-link">phpMyAdmin</a>.</li>
                        <li>Import <code>database/schema.sql</code> or <code>database/library_management_system.sql</code>.</li>
                        <li>Refresh this page.</li>
                    </ol>
                </div>
                <div class="text-start bg-body-tertiary p-2 rounded small text-secondary">
                    <em>Error details: <?=htmlspecialchars($e->getMessage())?></em>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// -------------------------------------------------------------
// Core Helper Functions
// -------------------------------------------------------------

function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

function is_post(): bool {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function require_login(): void {
    if (empty($_SESSION['user'])) {
        redirect('/auth/login.php');
    }
}

function require_admin(): void {
    require_login();
    if (($_SESSION['user']['role'] ?? '') !== 'admin') {
        flash('error', 'Access denied. Administrator privilege required.');
        redirect('/app.php?page=dashboard');
    }
}

function flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function csrf_token(): string {
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
}

function verify_csrf(): void {
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(419);
        exit('Security token expired or invalid request. Please go back and try again.');
    }
}

function format_currency(float|int|string $amount): string {
    return 'Rs. ' . number_format((float)$amount, 2);
}

/**
 * Automatically synchronize overdue statuses and calculate fines
 */
function update_overdue(PDO $pdo): void {
    try {
        $fineRate = FINE_PER_DAY;
        $pdo->exec("
            UPDATE book_issues 
            SET status = 'overdue',
                fine = GREATEST(DATEDIFF(CURDATE(), due_date), 0) * {$fineRate}
            WHERE return_date IS NULL 
              AND due_date < CURDATE()
        ");
    } catch (Throwable) {
        // Silently continue if table is not yet migrated
    }
}
