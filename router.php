<?php
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if ($uri === '/' || $uri === '') {
    require __DIR__ . '/public/auth/login.php';
    exit;
}

$filePath = __DIR__ . '/public' . $uri;

if (file_exists($filePath) && !is_dir($filePath)) {
    if (str_ends_with($filePath, '.php')) {
        require $filePath;
        exit;
    }

    $ext = pathinfo($filePath, PATHINFO_EXTENSION);
    $mimes = [
        'css' => 'text/css',
        'js'  => 'application/javascript',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'ttf' => 'font/ttf'
    ];
    if (isset($mimes[$ext])) {
        header('Content-Type: ' . $mimes[$ext]);
    }
    readfile($filePath);
    exit;
}

if ($uri === '/app' || $uri === '/dashboard') {
    require __DIR__ . '/public/app.php';
    exit;
}

if ($uri === '/login') {
    require __DIR__ . '/public/auth/login.php';
    exit;
}

http_response_code(404);
echo "404 Not Found";
