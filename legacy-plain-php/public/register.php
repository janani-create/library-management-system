<?php
require dirname(__DIR__) . '/config.php';
$error = '';
if (is_post()) {
    verify_csrf();
    $name = trim($_POST['name'] ?? ''); $email = trim($_POST['email'] ?? ''); $password = $_POST['password'] ?? '';
    if (strlen($name) < 3 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) $error = 'Please complete all fields correctly.';
    elseif ($password !== ($_POST['confirm_password'] ?? '')) $error = 'Passwords do not match.';
    else {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO users (name,email,password,role) VALUES (?,?,?,'member')")->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
            $memberNo = 'MEM-' . str_pad((string)$pdo->lastInsertId(), 4, '0', STR_PAD_LEFT);
            $pdo->prepare('INSERT INTO members (member_no,full_name,email) VALUES (?,?,?)')->execute([$memberNo,$name,$email]);
            $pdo->commit(); flash('success','Account created. You can now sign in.'); redirect('index.php');
        } catch (PDOException $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $error = 'That email is already registered.'; }
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Register | Leaf & Lore</title><link rel="icon" type="image/svg+xml" href="favicon.ico"><link rel="stylesheet" href="css/style.css"></head><body><main class="login-shell"><section class="visual-panel"><div class="visual-copy"><p class="eyebrow">Begin your next chapter</p><h2>A world of books<br>awaits you.</h2></div></section><section class="form-panel"><div class="form-wrap"><header class="form-header"><p class="eyebrow eyebrow--green">Join the library</p><h1>Create your account</h1></header><?php if($error):?><div class="login-alert"><?=e($error)?></div><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><div class="field"><label>Full name</label><div class="input-wrap"><input name="name" required></div></div><div class="field"><label>Email</label><div class="input-wrap"><input type="email" name="email" required></div></div><div class="field"><label>Password</label><div class="input-wrap"><input type="password" name="password" minlength="6" required></div></div><div class="field"><label>Confirm password</label><div class="input-wrap"><input type="password" name="confirm_password" required></div></div><button class="submit-btn">Create account →</button></form><p class="signup">Already registered? <a href="index.php">Sign in</a></p></div></section></main></body></html>
