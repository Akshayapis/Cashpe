<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/firebase_helper.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
require_once dirname(__DIR__) . '/includes/icons.php';

// Already signed in? Go straight to the dashboard.
if (auth_uid() !== null) {
    try {
        if (auth_user()) {
            redirect('dashboard.php');
        }
    } catch (Throwable $e) {
        // fall through to the login screen on transient DB errors
    }
}

$error = null;
$info = null;
$showResend = isset($_GET['resend']);
$resendEmail = '';
$flash = flash_get();
if ($flash) {
    $flash['type'] === 'error' ? $error = $flash['message'] : $info = $flash['message'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $action = (string) ($_POST['action'] ?? 'login');

    if ($action === 'login') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $resendEmail = $email;
        try {
            $user = user_find_by_email($email);
        } catch (Throwable $e) {
            $user = null;
            $error = $e->getMessage();
        }
        if ($error === null) {
            if (!$user) {
                $error = 'Invalid email or password.';
            } elseif (empty($user['password_hash'])) {
                $error = 'This account uses Google Sign-In. Please continue with Google.';
            } elseif (!password_verify($password, (string) $user['password_hash'])) {
                $error = 'Invalid email or password.';
            } elseif (($user['status'] ?? 'active') === 'blocked') {
                $error = 'Your account has been suspended. Contact support@cashpe.onrender.com';
            } elseif (empty($user['email_verified'])) {
                $showResend = true;
                $error = 'Your email is not verified yet. Check your inbox for the magic link, or send a new one.';
            } else {
                auth_login_user($user);
                redirect('dashboard.php');
            }
        }
    }

    if ($action === 'resend') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $resendEmail = $email;
        // Rate limit: one email per minute per session
        $last = (int) ($_SESSION['last_resend_at'] ?? 0);
        if (time() - $last < 60) {
            $showResend = true;
            $error = 'Please wait a minute before requesting another email.';
        } else {
            try {
                $user = user_find_by_email($email);
                if ($user && empty($user['email_verified'])) {
                    $_SESSION['last_resend_at'] = time();
                    $uid = (string) ($user['uid'] ?? $user['id']);
                    $token = generate_token(32);
                    fs_set('users/' . $uid . '/tokens', $token, [
                        'token' => $token,
                        'uid' => $uid,
                        'email' => $email,
                        'expires_at' => time() + 900,
                        'used' => false,
                        'created_at' => time(),
                    ], false);
                    $mailSent = send_magic_link($email, (string) ($user['name'] ?? 'there'), url('verify.php?token=' . $token));
                    if (!$mailSent) {
                        $showResend = true;
                        $error = 'We could not send the email right now. Please try again in a few minutes.';
                    } else {
                        // Same message either way to avoid leaking which emails exist
                        $showResend = true;
                        $info = 'If that account exists and is unverified, a fresh magic link is on its way.';
                    }
                } else {
                    // Do not leak which emails exist
                    $showResend = true;
                    $info = 'If that account exists and is unverified, a fresh magic link is on its way.';
                }
            } catch (Throwable $e) {
                $showResend = true;
                $error = $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e(APP_NAME) ?> - <?= e(APP_TAGLINE) ?></title>
<meta name="description" content="CashPe lets Indian merchants accept UPI payments directly to their UPI ID - instant QR codes, payment links, real-time UTR verification, 2% flat commission.">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%236d28d9'/%3E%3Cpath d='M36 8 L16 36 H30 L26 56 L46 27 H33 Z' fill='%23fff'/%3E%3C/svg%3E">
<script>try{if(localStorage.getItem('cashpe-theme')==='dark'){document.documentElement.classList.add('dark');}}catch(e){}</script>
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={darkMode:'class',theme:{extend:{fontFamily:{sans:['Inter','system-ui','sans-serif']}}}};</script>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased overflow-x-hidden">

<!-- Background art -->
<div aria-hidden="true" class="fixed inset-0 pointer-events-none">
    <div class="absolute -top-40 -left-40 w-[34rem] h-[34rem] rounded-full" style="background:radial-gradient(circle, rgba(99,102,241,.35) 0%, transparent 65%)"></div>
    <div class="absolute top-1/3 -right-40 w-[36rem] h-[36rem] rounded-full" style="background:radial-gradient(circle, rgba(217,70,239,.28) 0%, transparent 65%)"></div>
    <div class="absolute -bottom-48 left-1/4 w-[38rem] h-[38rem] rounded-full" style="background:radial-gradient(circle, rgba(139,92,246,.3) 0%, transparent 65%)"></div>
    <div class="absolute inset-0 opacity-[.12]" style="background-image:linear-gradient(rgba(255,255,255,.5) 1px, transparent 1px),linear-gradient(90deg, rgba(255,255,255,.5) 1px, transparent 1px);background-size:64px 64px;mask-image:radial-gradient(ellipse 90% 70% at 50% 30%, black, transparent)"></div>
</div>

<div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 min-h-screen flex flex-col">

    <!-- Nav -->
    <nav class="h-16 flex items-center justify-between border-b border-white/5">
        <a href="<?= e(url('index.php')) ?>" class="flex items-center gap-3">
            <?= cashpe_logo('w-10 h-10', 'w-6 h-6') ?>
            <span class="text-2xl font-extrabold tracking-tight text-white">CashPe</span>
        </a>
        <div class="flex items-center gap-2">
            <button id="darkToggle" class="icon-btn !text-slate-300 hover:!text-white" aria-label="Toggle dark mode">
                <span class="dark:hidden"><?= icon('moon', 'w-5 h-5') ?></span>
                <span class="hidden dark:inline"><?= icon('sun', 'w-5 h-5') ?></span>
            </button>
            <a href="<?= e(url('register.php')) ?>" class="btn-primary !py-2.5 !px-4 sm:!px-5 text-sm">Create Account <?= icon('arrow-right', 'w-4 h-4') ?></a>
        </div>
    </nav>

    <!-- Hero + Login -->
    <div class="flex-1 grid lg:grid-cols-2 gap-10 lg:gap-16 items-center py-8 lg:py-0">

        <!-- Left: pitch -->
        <div class="max-w-xl">
            <div class="inline-flex items-center gap-2 rounded-full border border-violet-400/30 bg-violet-500/10 px-4 py-1.5 text-[11px] font-semibold tracking-wide text-violet-200 mb-6">
                <?= icon('zap', 'w-3.5 h-3.5') ?> BUILT FOR INDIA'S UPI ECOSYSTEM
            </div>
            <h1 class="text-4xl sm:text-5xl xl:text-6xl font-black tracking-tight leading-[1.05] text-white">
                Receive Payments.<br>
                <span class="gradient-text">Anytime. Anywhere.</span>
            </h1>
            <p class="mt-5 text-base sm:text-lg text-slate-300 leading-relaxed">CashPe generates premium UPI payment links and QR codes that settle directly to your UPI ID. Share on WhatsApp, track payments in real time with UTR verification, and pay just 2% per successful transaction.</p>

            <ul class="mt-7 space-y-3 text-sm text-slate-200">
                <li class="flex items-center gap-3"><span class="w-6 h-6 rounded-full bg-emerald-400/15 text-emerald-300 flex items-center justify-center shrink-0"><?= icon('check', 'w-4 h-4') ?></span>Direct settlement to any UPI ID - no bank integration needed</li>
                <li class="flex items-center gap-3"><span class="w-6 h-6 rounded-full bg-emerald-400/15 text-emerald-300 flex items-center justify-center shrink-0"><?= icon('check', 'w-4 h-4') ?></span>Payment links expire in 30 minutes for safety</li>
                <li class="flex items-center gap-3"><span class="w-6 h-6 rounded-full bg-emerald-400/15 text-emerald-300 flex items-center justify-center shrink-0"><?= icon('check', 'w-4 h-4') ?></span>Real-time UTR detection via provider transaction feeds</li>
                <li class="flex items-center gap-3"><span class="w-6 h-6 rounded-full bg-emerald-400/15 text-emerald-300 flex items-center justify-center shrink-0"><?= icon('check', 'w-4 h-4') ?></span>REST API, webhooks and CSV exports for reconciliation</li>
            </ul>

            <!-- Trust chips -->
            <div class="mt-8 flex flex-wrap items-center gap-3 text-[11px] text-slate-400">
                <span class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5"><?= icon('shield-check', 'w-4 h-4 text-emerald-400') ?> Bank-grade security</span>
                <span class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5"><?= icon('qr', 'w-4 h-4 text-violet-300') ?> QR + deep links</span>
                <span class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5"><?= icon('receipt', 'w-4 h-4 text-fuchsia-300') ?> 2% flat commission</span>
            </div>
        </div>

        <!-- Right: login card -->
        <div class="w-full max-w-md lg:justify-self-end">
            <div class="glass !bg-slate-900/70 !border-white/10 rounded-3xl p-6 sm:p-8 shadow-2xl">
                <h2 class="text-2xl font-bold text-white">Welcome back</h2>
                <p class="text-sm text-slate-400 mt-1">Sign in to your CashPe merchant dashboard.</p>

                <?php if ($error): ?>
                <div class="flash flash-error mt-4 flex items-start gap-2"><?= icon('alert-triangle', 'w-5 h-5 shrink-0') ?><span><?= e($error) ?></span></div>
                <?php endif; ?>
                <?php if ($info): ?>
                <div class="flash flash-success mt-4 flex items-start gap-2"><?= icon('check-circle', 'w-5 h-5 shrink-0') ?><span><?= e($info) ?></span></div>
                <?php endif; ?>

                <!-- Google OAuth -->
                <a href="<?= e(url('callback.php?action=google')) ?>" class="mt-5 w-full inline-flex items-center justify-center gap-3 rounded-xl border border-white/15 bg-white/5 hover:bg-white/10 transition px-4 py-3 text-sm font-semibold text-white">
                    <?= icon('google', 'w-5 h-5') ?> Continue with Google
                </a>

                <div class="flex items-center gap-3 my-5">
                    <div class="h-px flex-1 bg-white/10"></div>
                    <span class="text-[10px] font-semibold uppercase tracking-[.2em] text-slate-500">or with email</span>
                    <div class="h-px flex-1 bg-white/10"></div>
                </div>

                <form method="POST" action="<?= e(url('index.php')) ?>" class="space-y-4">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="login">
                    <div>
                        <label class="label !text-slate-400" for="email">Email</label>
                        <input class="input" type="email" id="email" name="email" required autocomplete="email" placeholder="you@business.com" value="<?= e($resendEmail) ?>">
                    </div>
                    <div>
                        <label class="label !text-slate-400" for="password">Password</label>
                        <div class="relative">
                            <input class="input pr-12" type="password" id="password" name="password" required autocomplete="current-password" placeholder="Your password">
                            <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 icon-btn !w-9 !h-9" data-toggle-password="password" aria-label="Show password"><?= icon('eye', 'w-5 h-5') ?></button>
                        </div>
                    </div>
                    <button type="submit" class="btn-primary w-full !py-3">Sign In <?= icon('arrow-right', 'w-4 h-4') ?></button>
                </form>

                <?php if ($showResend): ?>
                <form method="POST" action="<?= e(url('index.php')) ?>" class="mt-4 rounded-2xl border border-amber-400/25 bg-amber-400/10 p-4">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="resend">
                    <p class="text-xs font-semibold text-amber-200 flex items-center gap-2"><?= icon('mail', 'w-4 h-4') ?> Didn't get the magic link?</p>
                    <div class="mt-2 flex gap-2">
                        <input class="input !py-2 text-sm" type="email" name="email" required placeholder="you@business.com" value="<?= e($resendEmail) ?>">
                        <button type="submit" class="btn-secondary !py-2 whitespace-nowrap"><?= icon('refresh', 'w-4 h-4') ?> Resend</button>
                    </div>
                </form>
                <?php endif; ?>

                <p class="mt-5 text-center text-sm text-slate-400">New to CashPe?
                    <a href="<?= e(url('register.php')) ?>" class="font-semibold text-violet-300 hover:text-violet-200">Create an account</a>
                </p>
            </div>
        </div>
    </div>

    <footer class="relative border-t border-white/5 py-4 flex flex-col sm:flex-row items-center justify-between gap-2 text-[11px] text-slate-500">
        <p>&copy; <?= date('Y') ?> CashPe. All rights reserved.</p>
        <p>Secured by industry-standard encryption. CashPe never stores your UPI PIN.</p>
    </footer>
</div>

<script>
// Password visibility toggle
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
