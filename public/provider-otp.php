<?php
/**
 * CashPe - OTP screen for personal providers.
 * step=otp    - enter the OTP sent to the mobile number
 * step=select - pick which UPI ID receives payments
 */
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/firebase_helper.php';
require_once dirname(__DIR__) . '/includes/provider_helper.php';
require_once dirname(__DIR__) . '/includes/icons.php';

$user = auth_require_verified();
$uid = (string) ($user['uid'] ?? $user['id']);

$step = (string) ($_GET['step'] ?? 'otp');
$error = (string) ($_GET['error'] ?? '');
$info = (string) ($_GET['info'] ?? '');
$MAX_PROVIDERS = 3;

// Resend OTP (rate limited)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend_otp') {
    csrf_validate();
    $pending = $_SESSION['pending_personal'] ?? null;
    $last = (int) ($_SESSION['last_otp_resend_at'] ?? 0);
    if (!is_array($pending)) {
        $error = 'Your session expired. Please start again.';
        $step = 'otp';
    } elseif (time() - $last < 60) {
        $error = 'Please wait a minute before requesting another OTP.';
    } else {
        $res = provider_send_otp((string) $pending['provider'], (string) $pending['mobile']);
        $_SESSION['last_otp_resend_at'] = time();
        if ($res['ok']) {
            $info = 'OTP resent.';
        } else {
            $error = $res['message'];
        }
    }
}

// Save the selected UPI ID (final step of the personal flow)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_personal') {
    csrf_validate();
    $verified = $_SESSION['personal_verified'] ?? null;
    if (!is_array($verified) || time() - (int) ($verified['verified_at'] ?? 0) > 600) {
        flash_set('error', 'Your verification expired. Please link the provider again.');
        redirect('connect.php?error=' . urlencode('Verification expired. Please try again.'));
    }
    $selectedUpi = trim((string) ($_POST['selected_upi'] ?? ''));
    $allowed = array_map('strval', (array) ($verified['upi_ids'] ?? []));
    if ($selectedUpi === '' || !in_array($selectedUpi, $allowed, true)) {
        $error = 'Select one of the UPI IDs returned by the provider.';
        $step = 'select';
    } else {
        try {
            $connected = user_providers($uid);
            if (count($connected) >= $MAX_PROVIDERS) {
                flash_set('error', 'You can connect a maximum of 3 providers. Disconnect one first.');
                redirect('connect.php');
            }
            $meta = provider_by_id((string) $verified['provider']);
            $docId = generate_token(12);
            // Regenerate session material on every login; store it encrypted at rest
            fs_set('users/' . $uid . '/providers', $docId, [
                'provider' => (string) $verified['provider'],
                'name' => $meta['name'] ?? (string) $verified['provider'],
                'mobile' => (string) $verified['mobile'],
                'session_token' => encrypt_secret((string) $verified['session_token']),
                'upi_ids' => $allowed,
                'selected_upi' => $selectedUpi,
                'type' => 'personal',
                'status' => 'active',
                'created_at' => time(),
            ], false);
            unset($_SESSION['personal_verified'], $_SESSION['pending_personal']);
            session_regenerate_id(true);
            redirect('connect.php?saved=' . $docId . '&info=' . urlencode(($meta['name'] ?? 'Provider') . ' connected successfully.'));
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $step = 'select';
        }
    }
}

$pageTitle = 'Verify Provider';
$activePage = 'connect';
require dirname(__DIR__) . '/includes/header.php';

function masked_mobile(string $mobile): string
{
    $mobile = preg_replace('/\D/', '', $mobile);
    if (strlen($mobile) >= 4) {
        return str_repeat('•', max(0, strlen($mobile) - 4)) . substr($mobile, -4);
    }
    return $mobile;
}
?>

<?php if ($error): ?><div class="flash flash-error mb-4 flex items-start gap-2"><?= icon('alert-triangle', 'w-5 h-5 shrink-0') ?><span><?= e($error) ?></span></div><?php endif; ?>
<?php if ($info): ?><div class="flash flash-success mb-4 flex items-start gap-2"><?= icon('check-circle', 'w-5 h-5 shrink-0') ?><span><?= e($info) ?></span></div><?php endif; ?>

<?php if ($step === 'otp'): ?>
    <?php $pending = $_SESSION['pending_personal'] ?? null; ?>
    <?php if (!is_array($pending)): ?>
        <div class="card p-8 text-center max-w-lg mx-auto">
            <div class="mx-auto w-14 h-14 rounded-2xl bg-amber-50 dark:bg-amber-500/10 text-amber-500 flex items-center justify-center"><?= icon('clock', 'w-7 h-7') ?></div>
            <h2 class="mt-4 font-bold text-lg">Session expired</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Please start the provider setup again.</p>
            <a href="<?= e(url('connect.php')) ?>" class="btn-primary mt-5"><?= icon('arrow-left', 'w-4 h-4') ?> Back to providers</a>
        </div>
    <?php else: ?>
    <?php $meta = provider_by_id((string) $pending['provider']); ?>
    <div class="card p-6 sm:p-8 max-w-lg mx-auto animate-fade-up">
        <div class="text-center">
            <span class="mx-auto w-16 h-16 rounded-2xl bg-slate-800 dark:bg-slate-700 text-white flex items-center justify-center"><?= icon('smartphone', 'w-8 h-8') ?></span>
            <h2 class="mt-4 text-xl font-extrabold">Enter OTP</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400"><?= e($meta['name'] ?? 'Provider') ?> sent a 6-digit code to <span class="font-semibold text-slate-700 dark:text-slate-200 font-mono"><?= e(masked_mobile((string) $pending['mobile'])) ?></span></p>
        </div>
        <form method="POST" action="<?= e(url('callback.php?action=provider_otp')) ?>" class="mt-6">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="provider_otp">
            <label class="label" for="otp">One-Time Password</label>
            <input class="input text-center text-2xl tracking-[.5em] font-mono" type="text" id="otp" name="otp" inputmode="numeric" pattern="[0-9]{4,8}" maxlength="8" required autocomplete="one-time-code" placeholder="------" autofocus>
            <button type="submit" class="btn-primary w-full mt-4 !py-3"><?= icon('shield-check', 'w-5 h-5') ?> Verify &amp; Continue</button>
        </form>
        <div class="mt-4 flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
            <a href="<?= e(url('connect.php')) ?>" class="hover:text-violet-500 inline-flex items-center gap-1"><?= icon('arrow-left', 'w-4 h-4') ?> Change number</a>
            <form method="POST" action="<?= e(url('provider-otp.php?step=otp')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="resend_otp">
                <button type="submit" class="hover:text-violet-500 inline-flex items-center gap-1 font-semibold"><?= icon('refresh', 'w-4 h-4') ?> Resend OTP</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

<?php else: /* step = select */ ?>
    <?php $verified = $_SESSION['personal_verified'] ?? null; ?>
    <?php if (!is_array($verified)): ?>
        <div class="card p-8 text-center max-w-lg mx-auto">
            <div class="mx-auto w-14 h-14 rounded-2xl bg-amber-50 dark:bg-amber-500/10 text-amber-500 flex items-center justify-center"><?= icon('clock', 'w-7 h-7') ?></div>
            <h2 class="mt-4 font-bold text-lg">Verification missing</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Verify your OTP first, then pick a UPI ID.</p>
            <a href="<?= e(url('connect.php')) ?>" class="btn-primary mt-5"><?= icon('arrow-left', 'w-4 h-4') ?> Back to providers</a>
        </div>
    <?php else: ?>
    <?php $meta = provider_by_id((string) $verified['provider']); $upiIds = array_values(array_filter((array) ($verified['upi_ids'] ?? []))); ?>
    <div class="card p-6 sm:p-8 max-w-lg mx-auto animate-fade-up">
        <div class="text-center">
            <span class="mx-auto w-16 h-16 rounded-2xl bg-emerald-500/15 text-emerald-500 flex items-center justify-center"><?= icon('check-circle', 'w-8 h-8') ?></span>
            <h2 class="mt-4 text-xl font-extrabold">Verified</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Choose the UPI ID to receive payments on your <?= e($meta['name'] ?? 'provider') ?> account.</p>
        </div>

        <?php if (empty($upiIds)): ?>
        <div class="flash flash-error mt-5 flex items-start gap-2"><?= icon('alert-triangle', 'w-5 h-5 shrink-0') ?><span>The provider confirmed your login but did not return any UPI IDs for this account. Make sure this account has a UPI ID created in the provider app (e.g. create one under "My UPI ID"), then link it again.</span></div>
        <a href="<?= e(url('connect.php')) ?>" class="btn-primary w-full mt-4"><?= icon('arrow-left', 'w-4 h-4') ?> Back to providers</a>
        <?php else: ?>
        <form method="POST" action="<?= e(url('provider-otp.php')) ?>" class="mt-6 space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_personal">
            <?php foreach ($upiIds as $i => $upi): ?>
            <label class="flex items-center gap-3 rounded-2xl border border-slate-200 dark:border-slate-700/60 px-4 py-3 cursor-pointer hover:border-violet-400 dark:hover:border-violet-500/50 has-[:checked]:border-violet-500 has-[:checked]:ring-2 has-[:checked]:ring-violet-500/40 transition">
                <input type="radio" name="selected_upi" value="<?= e($upi) ?>" class="w-4 h-4 accent-violet-600" <?= $i === 0 ? 'checked' : '' ?> required>
                <span class="font-mono text-sm"><?= e($upi) ?></span>
            </label>
            <?php endforeach; ?>
            <button type="submit" class="btn-primary w-full !py-3"><?= icon('check', 'w-5 h-5') ?> Save Provider</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
