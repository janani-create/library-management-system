<?php
require dirname(__DIR__, 2) . '/config.php';
if (!empty($_SESSION['user'])) redirect('/app.php?page=dashboard');
$error = '';
if (is_post()) {
    verify_csrf();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = $pdo->prepare('SELECT id, name, email, password, role, status FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password'])) {
        if ($user['status'] !== 'active') {
            $error = 'Your account has been suspended or deactivated. Please contact the administrator.';
        } else {
            unset($user['password']);
            $_SESSION['user'] = $user;
            flash('success', 'Welcome back, ' . htmlspecialchars($user['name']) . '!');
            redirect('/app.php?page=dashboard');
        }
    } else $error = 'Invalid email address or password. Please try again.';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign In | Library Management System</title><link rel="icon" type="image/svg+xml" href="/favicon.svg?v=1">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--blue:#1769ff;--cyan:#14c8e7;--white:#fff}*{box-sizing:border-box}
body{min-height:100vh;margin:0;display:grid;place-items:center;padding:6vw;overflow-x:hidden;color:#fff;background:#071a38;font-family:Montserrat,Arial,sans-serif}
body:before,body:after{position:fixed;z-index:-1;content:"";border:28px solid rgba(35,126,205,.3);transform:rotate(45deg)}body:before{width:350px;height:350px;left:-180px;bottom:-210px}body:after{width:420px;height:420px;right:-290px;bottom:-160px}
.video-bg{position:fixed;inset:0;z-index:-3;width:100%;height:100%;object-fit:cover}.video-overlay{position:fixed;inset:0;z-index:-2;background:linear-gradient(135deg,rgba(3,20,55,.82),rgba(8,54,110,.67) 52%,rgba(0,105,145,.72));backdrop-filter:blur(2px)}
.login-scene{position:relative;isolation:isolate;width:min(100%,1080px);min-height:630px;display:grid;grid-template-columns:56% 44%;overflow:hidden;background:linear-gradient(135deg,rgba(10,41,104,.88),rgba(8,76,139,.76));box-shadow:0 28px 60px rgba(0,8,25,.55)}
.geometry{position:absolute;inset:0;z-index:-1;pointer-events:none;overflow:hidden}.geometry span{position:absolute;display:block}.g1{width:310px;height:310px;left:22%;top:-220px;border:10px solid rgba(80,168,255,.24);transform:rotate(45deg)}.g2{width:420px;height:420px;left:20%;bottom:-315px;background:rgba(2,24,70,.45);transform:rotate(45deg)}.g3{width:390px;height:390px;right:-265px;top:-230px;border-radius:44%;border:62px solid rgba(83,181,228,.32)}.g4{width:45px;height:420px;left:38%;top:78px;border:7px solid rgba(66,151,220,.34);border-radius:50px;transform:rotate(45deg)}.g5{width:145px;height:430px;right:-22px;bottom:-155px;border-radius:55%;background:rgba(20,164,205,.45);transform:rotate(12deg)}
.welcome-panel{display:flex;flex-direction:column;padding:54px 74px}.brand-mark{width:46px;height:36px;display:flex;align-items:center;gap:8px}.brand-mark span:first-child{width:12px;height:34px;background:#fff}.brand-mark span:last-child{width:12px;height:14px;background:#fff;align-self:flex-end}.welcome-copy{margin:auto 0;max-width:510px}.welcome-copy h1{margin:0;font-size:clamp(3rem,6vw,4.6rem);line-height:.98;letter-spacing:-.05em;font-weight:800}.rule{width:52px;height:3px;margin:42px 0;background:#fff}.welcome-copy p{max-width:430px;margin:0 0 42px;color:rgba(255,255,255,.78);font-size:.8rem;line-height:1.8}
.welcome-actions{display:flex;align-items:center;gap:10px}.welcome-action{display:inline-flex;align-items:center;gap:7px;padding:11px 17px;border:1px solid transparent;border-radius:7px;color:#fff;font-size:.72rem;font-weight:600;text-decoration:none;transition:.2s}.welcome-action.primary{background:linear-gradient(90deg,var(--blue),var(--cyan));box-shadow:0 8px 20px rgba(20,140,231,.25)}.welcome-action.secondary{border-color:rgba(255,255,255,.65);background:rgba(4,30,75,.22)}.welcome-action:hover{color:#fff;transform:translateY(-2px)}
.signin-panel{display:grid;place-items:center;padding:42px}.signin-card{width:min(100%,370px);padding:45px 48px 38px;border:1px solid rgba(255,255,255,.13);background:rgba(20,72,135,.48);backdrop-filter:blur(10px)}.signin-card h2{margin:0 0 31px;text-align:center;font-size:2rem;letter-spacing:-.04em}.field{margin-bottom:25px}.field label{display:block;margin-bottom:9px;font-size:.68rem;font-weight:700}.field input{width:100%;height:34px;padding:0 16px;border:0;border-radius:20px;outline:0;color:#fff;background:rgba(255,255,255,.15);font:500 .76rem Montserrat,sans-serif;transition:.2s}.field input::placeholder{color:rgba(255,255,255,.55)}.field input:focus{background:rgba(255,255,255,.22);box-shadow:0 0 0 2px rgba(66,190,255,.55)}
.submit-btn{width:100%;height:38px;margin-top:16px;border:0;border-radius:22px;color:#fff;background:linear-gradient(90deg,var(--blue),var(--cyan));font:700 .77rem Montserrat,sans-serif;cursor:pointer;transition:.2s}.submit-btn:hover{transform:translateY(-2px);box-shadow:0 10px 22px rgba(20,140,231,.35)}.alert{margin:-13px 0 20px;padding:10px 12px;border-radius:5px;background:rgba(218,45,78,.65);font-size:.68rem;line-height:1.45}.alert.success{background:rgba(39,174,96,.55)}.account-links{margin:21px 0 0;text-align:center;color:rgba(255,255,255,.72);font-size:.67rem}.account-links a{color:#fff;font-weight:700;text-decoration:none}.demo-login{display:flex;justify-content:center;gap:15px;margin-top:23px}.demo-login button{padding:0;border:0;color:rgba(255,255,255,.88);background:none;font:600 .65rem Montserrat,sans-serif;cursor:pointer}.demo-login button:hover{color:#55dcff}
@media(max-width:820px){body{padding:24px}.login-scene{grid-template-columns:1fr}.welcome-panel{min-height:310px;padding:38px 44px}.welcome-copy{margin-top:55px}.welcome-copy h1{font-size:3.5rem}.rule{margin:24px 0}.welcome-copy p{display:none}.signin-panel{padding:0 30px 48px}.signin-card{width:min(100%,440px)}}
@media(max-width:480px){body{padding:0}.login-scene{min-height:100vh}.welcome-panel{min-height:250px;padding:30px 25px}.welcome-copy{margin-top:42px}.welcome-copy h1{font-size:2.75rem}.welcome-actions{display:none}.signin-panel{padding:0 18px 35px}.signin-card{padding:38px 28px 32px}}
@media(prefers-reduced-motion:reduce){*{transition:none!important}}
</style></head>
<body><video class="video-bg" autoplay muted loop playsinline aria-hidden="true"><source src="/videos/library_system.mp4" type="video/mp4"></video><div class="video-overlay" aria-hidden="true"></div><main class="login-scene">
<div class="geometry" aria-hidden="true"><span class="g1"></span><span class="g2"></span><span class="g3"></span><span class="g4"></span><span class="g5"></span></div>
<section class="welcome-panel"><div class="brand-mark" aria-label="Leaf and Lore"><span></span><span></span></div><div class="welcome-copy"><h1>Welcome!</h1><div class="rule"></div><p>Access your library workspace to manage books, members, circulation, overdue fines, and reports from one place.</p><div class="welcome-actions"><a class="welcome-action primary" href="/auth/register.php"><span aria-hidden="true">＋</span> Join the Library</a><a class="welcome-action secondary" href="#sign-in-form"><span aria-hidden="true">◇</span> Sign In</a></div></div></section>
<section class="signin-panel" id="sign-in-form"><div class="signin-card"><h2>Sign in</h2>
<?php if ($error): ?><div class="alert" role="alert"><?=e($error)?></div><?php endif; ?>
<?php $flash = get_flash(); if ($flash): ?><div class="alert <?=$flash['type']==='success'?'success':''?>" role="status"><?=e($flash['message'])?></div><?php endif; ?>
<form method="post" action="/auth/login.php"><input type="hidden" name="csrf" value="<?=csrf_token()?>">
<div class="field"><label for="email">Email Address</label><input type="email" id="email" name="email" autocomplete="email" placeholder="admin@library.com" required value="admin@library.com"></div>
<div class="field"><label for="password">Password</label><input type="password" id="password" name="password" autocomplete="current-password" placeholder="••••••••" required value="admin123"></div>
<button class="submit-btn" type="submit">Submit</button></form>
<div class="demo-login" aria-label="Demo accounts"><button type="button" onclick="fillDemo('admin@library.com','admin123')">Admin demo</button><button type="button" onclick="fillDemo('librarian@library.com','librarian123')">Librarian demo</button></div>
<p class="account-links">New to the library? <a href="/auth/register.php">Create account</a></p></div></section>
</main><script>function fillDemo(email,password){document.getElementById('email').value=email;document.getElementById('password').value=password}</script></body></html>
