<?php
/**
 * CashPe - Magic link handler. Verifies token, activates account, logs the user in.
 */
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/firebase_helper.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
require_once dirname(__DIR__) . '/includes/icons.php';

$state = 'error';   // error | used | expired | notfound
$message = 'This verification link is invalid.';

$token = trim((string) ($_GET['token'] ?? ''));

if ($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token)) {
    try {
        $rows = fs_collection_group_query('tokens', [['token', '==', $token]], 1);
        $tokenDoc = $rows[0] ?? null;

        if ($tokenDoc === null) {
            $state = 'notfound';
            $message = 'This verification link does not exist. It may have been superseded by a newer one.';
        } elseif (!empty($tokenDoc['used'])) {
            $state = 'used';
            $message = 'This magic link was already used. Try signing in instead.';
        } elseif ((int) ($tokenDoc['expires_at'] ?? 0) < time()) {
            $state = 'expired';
            $message = 'This magic link expired. Verification links are valid for 15 minutes.';
        } else {
            $uid = (string) ($tokenDoc['uid'] ?? '');
            // Mark token as used (single-use) and activate the account
            $path = (string) ($tokenDoc['__path'] ?? '');
            if ($path !== '') {
                fs_update(dirname($path), basename($path), ['used' => true, 'used_at' => time()]);
            }
            if ($uid !== '') {
                fs_update('users', $uid, ['email_verified' => true]);
                $user = user_get($uid);
                if ($user) {
                    try {
                        send_welcome((string) $user['email'], (string) ($user['name'] ?? 'there'));
                    } catch (Throwable $e) {
                        error_log('Welcome email failed: ' . $e->getMessage());
                    }
                    auth_login_user($user);
                    flash_set('success', 'Email verified. Welcome to CashPe!');
                    redirect('dashboard.php');
                }
            }
            $state = 'error';
            $message = 'Your account could not be activated. Please contact support.';
        }
    } catch (Throwable $e) {
        $state = 'error';
        $message = $e->getMessage();
    }
} elseif ($token !== '') {
    $state = 'error';
    $message = 'Malformed verification token.';
} else {
    $message = 'No token supplied.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify Email - <?= e(APP_NAME) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%236d28d9'/%3E%3Cpath d='M36 8 L16 36 H30 L26 56 L46 27 H33 Z' fill='%23fff'/%3E%3C/svg%3E">
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased flex items-center justify-center p-6">
<div aria-hidden="true" class="fixed inset-0 pointer-events-none">
    <div class="absolute -top-40 left-1/3 w-[32rem] h-[32rem] rounded-full" style="background:radial-gradient(circle, rgba(139,92,246,.3) 0%, transparent 65%)"></div>
</div>
<div class="relative glass !bg-slate-900/70 !border-white/10 rounded-3xl max-w-md w-full p-8 text-center shadow-2xl">
    <div class="mx-auto w-16 h-16 rounded-2xl bg-amber-400/15 text-amber-300 flex items-center justify-center">
        <?= icon('alert-triangle', 'w-8 h-8') ?>
    </div>
    <h1 class="mt-5 text-2xl font-bold text-white">Link problem</h1>
    <p class="mt-2 text-sm text-slate-300 leading-relaxed"><?= e($message) ?></p>
    <div class="mt-6 flex flex-col gap-3">
        <a href="<?= e(url('index.php?resend=1')) ?>" class="btn-primary w-full"><?= icon('refresh', 'w-4 h-4') ?> Send me a new link</a>
        <a href="<?= e(url('index.php')) ?>" class="btn-secondary w-full"><?= icon('arrow-left', 'w-4 h-4') ?> Back to sign in</a>
    </div>
</div>
</body>
</html>
