<?php
require dirname(__DIR__) . '/config.php';
if (!empty($_SESSION['user'])) redirect('app.php');
$error = '';
if (is_post()) {
    verify_csrf();
    $email = trim($_POST['email'] ?? '');
    $stmt = $pdo->prepare('SELECT id, name, email, password, role FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user && password_verify($_POST['password'] ?? '', $user['password'])) {
        unset($user['password']); $_SESSION['user'] = $user; redirect('app.php');
    }
    $error = 'Invalid email or password.';
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Login | Leaf & Lore</title><link rel="icon" type="image/svg+xml" href="favicon.ico"><link rel="stylesheet" href="css/style.css"></head>
<body><main class="login-shell"><section class="visual-panel"><div class="brand brand--light"><span class="brand__mark">📖</span><span>Leaf &amp; Lore</span></div><div class="visual-copy"><p class="eyebrow">Library management system</p><h2>Every great story<br>starts right here.</h2><p>Manage books, members, lending and reports from one quiet corner.</p></div></section>
<section class="form-panel"><div class="form-wrap"><header class="form-header"><p class="eyebrow eyebrow--green">Welcome back</p><h1>Sign in to your account</h1><p>Enter your admin details to continue.</p></header>
<?php if ($error): ?><div class="login-alert"><?=e($error)?></div><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><div class="field"><label for="email">Email address</label><div class="input-wrap"><input type="email" id="email" name="email" placeholder="admin@library.com" required></div></div><div class="field"><label for="password">Password</label><div class="input-wrap"><input type="password" id="password" name="password" placeholder="Enter your password" required></div></div><label class="remember"><input type="checkbox"><span>Keep me signed in</span></label><button class="submit-btn" type="submit">Sign in →</button></form>
<p class="signup">New member? <a href="register.php">Create an account</a></p><p class="demo-login">Demo admin: admin@library.com / admin123</p></div></section></main></body></html>
