<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/firebase_helper.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
require_once dirname(__DIR__) . '/includes/icons.php';

if (auth_uid() !== null) {
    try {
        if (auth_user()) {
            redirect('dashboard.php');
        }
    } catch (Throwable $e) {
        // show the form anyway
    }
}

$error = null;
$registered = false;
$registeredEmail = '';
$mailFailed = false;
$old = ['name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    $old = ['name' => $name, 'email' => $email];

    if ($name === '' || mb_strlen($name) < 2) {
        $error = 'Please enter your full name.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        $error = 'Password must be at least 8 characters and include at least one letter and one number.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $existing = user_find_by_email($email);
            if ($existing) {
                $error = 'An account with this email already exists. Try signing in instead.';
            } else {
                $uid = uuid4();
                fs_set('users', $uid, [
                    'uid' => $uid,
                    'name' => $name,
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'google_id' => null,
                    'email_verified' => false,
                    'wallet_balance' => 0,
                    'api_key' => generate_api_key(),
                    'callback_token' => generate_token(16),
                    'role' => 'user',
                    'status' => 'active',
                    'webhook_url' => '',
                    'ip_whitelist' => [],
                    'created_at' => time(),
                ], false);

                $token = generate_token(32);
                fs_set('users/' . $uid . '/tokens', $token, [
                    'token' => $token,
                    'uid' => $uid,
                    'email' => $email,
                    'expires_at' => time() + 900,
                    'used' => false,
                    'created_at' => time(),
                ], false);

                $mailSent = send_magic_link($email, $name, url('verify.php?token=' . $token));

                $registered = true;
                $registeredEmail = $email;
                $mailFailed = !$mailSent;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Account - <?= e(APP_NAME) ?></title>
<meta name="description" content="Create your CashPe merchant account and start receiving UPI payments in minutes.">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%236d28d9'/%3E%3Cpath d='M36 8 L16 36 H30 L26 56 L46 27 H33 Z' fill='%23fff'/%3E%3C/svg%3E">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={darkMode:'class',theme:{extend:{fontFamily:{sans:['Inter','system-ui','sans-serif']}}}};</script>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
<div aria-hidden="true" class="fixed inset-0 pointer-events-none">
    <div class="absolute -top-40 left-1/4 w-[34rem] h-[34rem] rounded-full" style="background:radial-gradient(circle, rgba(99,102,241,.35) 0%, transparent 65%)"></div>
    <div class="absolute bottom-0 -right-40 w-[36rem] h-[36rem] rounded-full" style="background:radial-gradient(circle, rgba(217,70,239,.25) 0%, transparent 65%)"></div>
</div>

<div class="relative min-h-screen flex flex-col">
    <nav class="h-16 px-4 sm:px-6 flex items-center justify-between border-b border-white/5 max-w-7xl w-full mx-auto">
        <a href="<?= e(url('index.php')) ?>" class="flex items-center gap-3"><?= cashpe_logo('w-9 h-9', 'w-5 h-5') ?><span class="text-xl font-extrabold text-white">CashPe</span></a>
        <a href="<?= e(url('index.php')) ?>" class="text-sm text-slate-400 hover:text-white inline-flex items-center gap-2"><?= icon('arrow-left', 'w-4 h-4') ?> Sign in instead</a>
    </nav>

    <div class="flex-1 flex items-center justify-center p-4 sm:p-8">
        <div class="w-full max-w-md">
            <?php if ($registered): ?>
            <!-- Success state -->
            <div class="glass !bg-slate-900/70 !border-white/10 rounded-3xl p-8 text-center shadow-2xl animate-fade-up">
                <div class="mx-auto w-16 h-16 rounded-2xl bg-gradient-to-br from-emerald-400 to-teal-500 flex items-center justify-center shadow-lg shadow-emerald-500/30">
                    <?= icon('mail', 'w-8 h-8 text-white') ?>
                </div>
                <h1 class="mt-5 text-2xl font-bold text-white">Check your inbox</h1>
                <p class="mt-2 text-sm text-slate-300">We sent a magic link to <span class="font-semibold text-white"><?= e($registeredEmail) ?></span>. The link expires in 15 minutes and works exactly once.</p>
                <?php if ($mailFailed): ?>
                <div class="flash flash-error mt-4 flex items-start gap-2 text-left"><?= icon('alert-triangle', 'w-5 h-5 shrink-0') ?><span>The confirmation email could not be sent right now (the mail server did not accept it). Your account was created - use the button below to send the link again in a few minutes.</span></div>
                <?php endif; ?>
                <p class="mt-4 text-xs text-slate-500">Not seeing it? Check your spam folder, or go back and use "Resend verification".</p>
                <a href="<?= e(url('index.php?resend=1')) ?>" class="btn-secondary mt-6"><?= icon('refresh', 'w-4 h-4') ?> Resend verification email</a>
            </div>
            <?php else: ?>

            <div class="glass !bg-slate-900/70 !border-white/10 rounded-3xl p-6 sm:p-8 shadow-2xl">
                <h1 class="text-2xl font-bold text-white">Create your account</h1>
                <p class="text-sm text-slate-400 mt-1">Free to join. 2% per successful payment, nothing else.</p>

                <?php if ($error): ?>
                <div class="flash flash-error mt-4 flex items-start gap-2"><?= icon('alert-triangle', 'w-5 h-5 shrink-0') ?><span><?= e($error) ?></span></div>
                <?php endif; ?>

                <a href="<?= e(url('callback.php?action=google')) ?>" class="mt-5 w-full inline-flex items-center justify-center gap-3 rounded-xl border border-white/15 bg-white/5 hover:bg-white/10 transition px-4 py-3 text-sm font-semibold text-white">
                    <?= icon('google', 'w-5 h-5') ?> Sign up with Google
                </a>

                <div class="flex items-center gap-3 my-5">
                    <div class="h-px flex-1 bg-white/10"></div>
                    <span class="text-[10px] font-semibold uppercase tracking-[.2em] text-slate-500">or with email</span>
                    <div class="h-px flex-1 bg-white/10"></div>
                </div>

                <form method="POST" action="<?= e(url('register.php')) ?>" class="space-y-4">
                    <?= csrf_field() ?>
                    <div>
                        <label class="label !text-slate-400" for="name">Full name</label>
                        <input class="input" type="text" id="name" name="name" required minlength="2" autocomplete="name" placeholder="Rahul Sharma" value="<?= e($old['name']) ?>">
                    </div>
                    <div>
                        <label class="label !text-slate-400" for="email">Email</label>
                        <input class="input" type="email" id="email" name="email" required autocomplete="email" placeholder="you@business.com" value="<?= e($old['email']) ?>">
                    </div>
                    <div>
                        <label class="label !text-slate-400" for="password">Password</label>
                        <div class="relative">
                            <input class="input pr-12" type="password" id="password" name="password" required minlength="8" autocomplete="new-password" placeholder="Min 8 chars, 1 letter, 1 number">
                            <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 icon-btn !w-9 !h-9" data-toggle-password="password" aria-label="Show password"><?= icon('eye', 'w-5 h-5') ?></button>
                        </div>
                    </div>
                    <div>
                        <label class="label !text-slate-400" for="confirm_password">Confirm password</label>
                        <input class="input" type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password" placeholder="Repeat your password">
                    </div>
                    <button type="submit" class="btn-primary w-full !py-3">Create Account <?= icon('arrow-right', 'w-4 h-4') ?></button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var input = document.getElementById(btn.getAttribute('data-toggle-password'));
        if (input) input.type = input.type === 'password' ? 'text' : 'password';
    });
});
</script>
<script src="<?= e(url('assets/js/app.js')) ?>" defer></script>
</body>
</html>
