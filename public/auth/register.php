<?php
require dirname(__DIR__, 2) . '/config.php';

$error = '';
if (is_post()) {
    verify_csrf();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($name) < 3 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
        $error = 'Please fill out all fields with valid information (Password min 6 characters).';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $pdo->beginTransaction();
            // Check if email exists
            $chk = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $chk->execute([$email]);
            if ($chk->fetch()) {
                throw new Exception('An account with this email already exists.');
            }

            // Create user
            $pdo->prepare("INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, 'member', 'active')")
                ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int)$pdo->lastInsertId();

            // Create matching member profile
            $memberNo = 'MEM-' . str_pad((string)$userId, 4, '0', STR_PAD_LEFT);
            $pdo->prepare('INSERT INTO members (member_no, full_name, email, phone, status) VALUES (?, ?, ?, ?, "active")')
                ->execute([$memberNo, $name, $email, $phone]);

            $pdo->commit();
            flash('success', 'Registration successful! You can now log in with your email.');
            redirect('/auth/login.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign Up | Modern Library Management System</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            color: #334155;
            position: relative;
            overflow-x: hidden;
            background-color: #0f172a;
        }

        .video-bg-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            overflow: hidden;
            z-index: -2;
        }

        .video-bg-container video {
            position: absolute;
            top: 50%;
            left: 50%;
            min-width: 100%;
            min-height: 100%;
            width: auto;
            height: auto;
            transform: translate(-50%, -50%);
            object-fit: cover;
        }

        .video-bg-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: radial-gradient(circle at center, rgba(15, 23, 42, 0.65) 0%, rgba(15, 23, 42, 0.85) 100%);
            backdrop-filter: blur(4px);
            z-index: -1;
        }

        .register-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.4);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 30px 60px -15px rgba(0, 0, 0, 0.5);
            max-width: 550px;
            width: 100%;
            padding: 3rem 2.5rem;
            z-index: 1;
            animation: fadeInCard 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes fadeInCard {
            from {
                opacity: 0;
                transform: translateY(15px) scale(0.98);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
    </style>
</head>
<body>

<!-- Video Background -->
<div class="video-bg-container">
    <video autoplay muted loop playsinline id="bgVideo">
        <source src="/videos/library_system.mp4" type="video/mp4">
    </video>
</div>
<div class="video-bg-overlay"></div>

<div class="register-card">
    <div class="text-center mb-4">
        <div class="d-inline-flex p-3 bg-primary-subtle text-primary rounded-4 mb-2">
            <i class="bi bi-person-plus-fill fs-3"></i>
        </div>
        <h3 class="fw-bold text-dark">Join the Library</h3>
        <p class="text-muted small">Create your library member account to borrow books.</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 py-2 small" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div><?=e($error)?></div>
        </div>
    <?php endif; ?>

    <form method="post" action="/auth/register.php">
        <input type="hidden" name="csrf" value="<?=csrf_token()?>">

        <div class="mb-3">
            <label class="form-label fw-semibold small text-secondary">Full Name</label>
            <div class="input-group">
                <span class="input-group-text bg-light"><i class="bi bi-person"></i></span>
                <input type="text" name="name" class="form-control" placeholder="e.g. Kasun Perera" required value="<?=e($_POST['name']??'')?>">
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label fw-semibold small text-secondary">Email Address</label>
            <div class="input-group">
                <span class="input-group-text bg-light"><i class="bi bi-envelope"></i></span>
                <input type="email" name="email" class="form-control" placeholder="kasun@example.com" required value="<?=e($_POST['email']??'')?>">
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label fw-semibold small text-secondary">Phone Number</label>
            <div class="input-group">
                <span class="input-group-text bg-light"><i class="bi bi-telephone"></i></span>
                <input type="text" name="phone" class="form-control" placeholder="+94 77 123 4567" value="<?=e($_POST['phone']??'')?>">
            </div>
        </div>

        <div class="row g-2 mb-4">
            <div class="col-md-6">
                <label class="form-label fw-semibold small text-secondary">Password</label>
                <input type="password" name="password" class="form-control" placeholder="Min 6 characters" minlength="6" required>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold small text-secondary">Confirm Password</label>
                <input type="password" name="confirm_password" class="form-control" placeholder="Re-enter password" minlength="6" required>
            </div>
        </div>

        <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold shadow-sm mb-3" style="background: #2563eb; border: none; border-radius: 12px;">
            Create Account <i class="bi bi-check-circle ms-1"></i>
        </button>

        <div class="text-center small text-muted">
            Already have an account? <a href="/auth/login.php" class="text-primary fw-semibold text-decoration-none">Sign In</a>
        </div>
    </form>
</div>

</body>
</html>
