<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/firebase_helper.php';
require_once dirname(__DIR__) . '/includes/provider_helper.php';
require_once dirname(__DIR__) . '/includes/icons.php';

$user = auth_require_verified();
$uid = (string) ($user['uid'] ?? $user['id']);

$error = null;
$info = null;
$flash = flash_get();
if ($flash) {
    $flash['type'] === 'error' ? $error = $flash['message'] : $info = $flash['message'];
}
if (!empty($_GET['error'])) {
    $error = (string) $_GET['error'];
}
if (!empty($_GET['info'])) {
    $info = (string) $_GET['info'];
}

$catalogue = provider_catalogue();
$MAX_PROVIDERS = 3;

// Ensure a callback token exists (used for business auto-verification webhooks)
if (empty($user['callback_token'])) {
    try {
        user_update($uid, ['callback_token' => generate_token(16)]);
        $user = user_get($uid) ?? $user;
    } catch (Throwable $e) {
        error_log('Could not create callback token: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// POST actions
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $action = (string) ($_POST['action'] ?? '');

    // Delete a provider
    if ($action === 'delete') {
        $providerId = (string) ($_POST['provider_id'] ?? '');
        try {
            $existing = fs_get('users/' . $uid . '/providers', $providerId);
            if ($existing) {
                fs_delete('users/' . $uid . '/providers', $providerId);
                flash_set('success', 'Provider disconnected.');
            } else {
                flash_set('error', 'Provider not found.');
            }
        } catch (Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        redirect('connect.php');
    }

    $providers = [];
    try {
        $providers = user_providers($uid);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    $connectedIds = array_map(fn($p) => $p['provider'] ?? '', $providers);

    // Connect a business provider (UPI ID only)
    if ($action === 'connect_business') {
        if (count($providers) >= $MAX_PROVIDERS) {
            $error = 'You can connect a maximum of 3 providers. Disconnect one first.';
        } else {
            $providerId = (string) ($_POST['provider'] ?? '');
            $upiId = strtolower(trim((string) ($_POST['upi_id'] ?? '')));
            $meta = provider_by_id($providerId);
            if (!$meta || $meta['type'] !== 'business') {
                $error = 'Select a valid business provider.';
            } elseif (!preg_match('/^[\w.\-]{2,}@[a-z][a-z0-9]{1,}$/i', $upiId)) {
                $error = 'Enter a valid UPI ID (example: merchant@upi, name@okaxis, 9876543210@paytm).';
            } elseif (in_array($providerId, $connectedIds, true)) {
                $error = 'This provider is already connected.';
            } else {
                try {
                    $docId = generate_token(12);
                    fs_set('users/' . $uid . '/providers', $docId, [
                        'provider' => $providerId,
                        'name' => $meta['name'],
                        'upi_id' => $upiId,
                        'type' => 'business',
                        'status' => 'active',
                        'created_at' => time(),
                    ], false);
                    flash_set('success', $meta['name'] . ' connected successfully.');
                    redirect('connect.php');
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }
    }

    // Start the personal provider flow (send OTP)
    if ($action === 'start_personal') {
        if (count($providers) >= $MAX_PROVIDERS) {
            $error = 'You can connect a maximum of 3 providers. Disconnect one first.';
        } else {
            $providerId = (string) ($_POST['provider'] ?? '');
            $mobile = preg_replace('/\D/', '', (string) ($_POST['mobile'] ?? ''));
            $meta = provider_by_id($providerId);
            if (!$meta || $meta['type'] !== 'personal') {
                $error = 'Select a valid personal provider.';
            } elseif (in_array($providerId, $connectedIds, true)) {
                $error = 'This provider is already connected.';
            } else {
                $result = provider_send_otp($providerId, $mobile);
                if (!$result['ok']) {
                    $error = $result['message'];
                } else {
                    $_SESSION['pending_personal'] = ['provider' => $providerId, 'mobile' => $mobile, 'started_at' => time()];
                    redirect('provider-otp.php?step=otp&info=' . urlencode($result['message']));
                }
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Load existing connections + optional post-save transaction feed
// ---------------------------------------------------------------------------
$dbError = null;
$connected = [];
try {
    $connected = user_providers($uid);
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}
$limitReached = count($connected) >= $MAX_PROVIDERS;

// After a personal provider is saved, show its last 10 transactions
$savedTx = null;
$savedProviderName = null;
$savedId = (string) ($_GET['saved'] ?? '');
if ($savedId !== '') {
    try {
        $savedProvider = fs_get('users/' . $uid . '/providers', $savedId);
        if ($savedProvider && ($savedProvider['type'] ?? '') === 'personal' && !empty($savedProvider['session_token'])) {
            $token = decrypt_secret((string) $savedProvider['session_token']);
            $savedProviderName = $savedProvider['name'] ?? ucfirst((string) $savedProvider['provider']);
            $savedTx = $token !== false ? provider_recent_transactions((string) $savedProvider['provider'], $token, 10) : [];
        }
    } catch (Throwable $e) {
        error_log('Saved provider feed failed: ' . $e->getMessage());
        $savedTx = [];
    }
}

$selected = (string) ($_GET['select'] ?? '');
$selectedMeta = $selected !== '' ? provider_by_id($selected) : null;
$callbackToken = (string) ($user['callback_token'] ?? '');

$pageTitle = 'Connect Provider';
$activePage = 'connect';
require dirname(__DIR__) . '/includes/header.php';
?>

<?php if ($dbError): ?><div class="flash flash-error mb-4"><?= e($dbError) ?></div><?php endif; ?>
<?php if ($error): ?><div class="flash flash-error mb-4 flex items-start gap-2"><?= icon('alert-triangle', 'w-5 h-5 shrink-0') ?><span><?= e($error) ?></span></div><?php endif; ?>
<?php if ($info): ?><div class="flash flash-success mb-4 flex items-start gap-2"><?= icon('check-circle', 'w-5 h-5 shrink-0') ?><span><?= e($info) ?></span></div><?php endif; ?>

<!-- Just-saved: provider transaction feed -->
<?php if ($savedTx !== null): ?>
<div class="card p-5 sm:p-6 mb-6 animate-fade-up">
    <h3 class="font-bold flex items-center gap-2"><?= icon('receipt', 'w-5 h-5 text-emerald-500') ?> <?= e($savedProviderName ?? 'Provider') ?> - Recent Transactions (last 10)</h3>
    <?php if (count($savedTx) === 0): ?>
        <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">No recent transactions found (or the provider feed is not reachable right now). Your provider is connected regardless.</p>
    <?php else: ?>
    <div class="overflow-x-auto mt-3">
        <table class="data-table">
            <thead><tr><th>Time</th><th>Status</th><th>Amount</th><th>UTR</th><th>Payer</th></tr></thead>
            <tbody>
            <?php foreach ($savedTx as $tx): ?>
            <tr>
                <td class="text-xs text-slate-500 dark:text-slate-400"><?= e(date('d M, h:i A', (int) $tx['time'])) ?></td>
                <td><span class="badge badge-<?= $tx['status'] === 'success' ? 'success' : ($tx['status'] === 'failed' ? 'failed' : 'pending') ?>"><?= e($tx['status']) ?></span></td>
                <td class="font-semibold"><?= e(format_money($tx['amount'])) ?></td>
                <td class="font-mono text-xs"><?= e($tx['utr']) ?></td>
                <td class="text-xs"><?= e($tx['payer'] ?: '-') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Connected providers -->
<div class="card p-5 sm:p-6">
    <div class="flex items-center justify-between gap-3 flex-wrap">
        <div>
            <h2 class="text-lg font-extrabold tracking-tight">Payment Providers</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Up to <?= $MAX_PROVIDERS ?> providers per account. <span class="font-semibold"><?= count($connected) ?> of <?= $MAX_PROVIDERS ?> connected.</span></p>
        </div>
        <?php if ($limitReached): ?>
        <span class="inline-flex items-center gap-2 text-xs font-semibold text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/25 rounded-xl px-3 py-2"><?= icon('lock', 'w-4 h-4') ?> Limit reached</span>
        <?php endif; ?>
    </div>

    <?php if (empty($connected)): ?>
    <div class="py-10 text-center">
        <div class="mx-auto w-16 h-16 rounded-2xl bg-indigo-50 dark:bg-indigo-500/10 text-indigo-400 flex items-center justify-center"><?= icon('credit-card', 'w-8 h-8') ?></div>
        <h4 class="mt-4 font-bold">No providers connected</h4>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Pick a provider below to start receiving payments.</p>
    </div>
    <?php else: ?>
    <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        <?php foreach ($connected as $p): $pid = $p['provider'] ?? ''; $pmeta = provider_by_id((string) $pid); ?>
        <div class="rounded-2xl border border-slate-200 dark:border-slate-700/60 p-4 flex flex-col">
            <div class="flex items-start justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <span class="w-11 h-11 rounded-xl gradient-bg text-white flex items-center justify-center shrink-0"><?= icon($pmeta['icon'] ?? 'credit-card', 'w-6 h-6') ?></span>
                    <div class="min-w-0">
                        <p class="font-bold text-sm truncate"><?= e($p['name'] ?? ($pmeta['name'] ?? $pid)) ?></p>
                        <span class="badge badge-<?= ($p['type'] ?? 'business') === 'business' ? 'active' : 'success' ?> mt-1"><?= e(($p['type'] ?? 'business') === 'business' ? 'Business' : 'Personal') ?></span>
                    </div>
                </div>
                <span class="text-[10px] text-slate-400 shrink-0"><?= e(format_date($p['created_at'] ?? 0)) ?></span>
            </div>
            <div class="mt-3 text-xs text-slate-500 dark:text-slate-400 space-y-1">
                <?php if (($p['type'] ?? 'business') === 'business'): ?>
                <p class="flex items-center gap-1.5"><?= icon('smartphone', 'w-3.5 h-3.5') ?> <span class="font-mono"><?= e($p['upi_id'] ?? '') ?></span></p>
                <?php else: ?>
                <p class="flex items-center gap-1.5"><?= icon('smartphone', 'w-3.5 h-3.5') ?> Mobile ••••<?= e(substr((string) ($p['mobile'] ?? ''), -4)) ?></p>
                <p class="flex items-center gap-1.5"><?= icon('check-circle', 'w-3.5 h-3.5') ?> <span class="font-mono"><?= e($p['selected_upi'] ?? '') ?></span></p>
                <?php endif; ?>
            </div>
            <form method="POST" action="<?= e(url('connect.php')) ?>" class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800 flex justify-end" data-confirm="Disconnect <?= e($p['name'] ?? 'this provider') ?>? Existing payment links using it will stop verifying." data-confirm-title="Disconnect provider">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="provider_id" value="<?= e($p['id']) ?>">
                <button type="submit" class="inline-flex items-center gap-1.5 text-xs font-semibold text-rose-600 dark:text-rose-400 hover:text-rose-700 dark:hover:text-rose-300 transition"><?= icon('trash', 'w-4 h-4') ?> Disconnect</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Select provider -->
<h2 class="mt-8 mb-3 text-sm font-bold uppercase tracking-[.18em] text-slate-500 dark:text-slate-400">Select Provider</h2>

<!-- Business providers -->
<p class="text-[11px] font-bold uppercase tracking-[.16em] text-violet-600 dark:text-violet-300 mb-2 flex items-center gap-2"><?= icon('building', 'w-4 h-4') ?> Business Providers</p>
<div class="grid gap-3 md:grid-cols-2">
    <?php foreach ($catalogue['business'] as $p): $locked = $limitReached || in_array($p['id'], array_map(fn($x) => $x['provider'] ?? '', $connected), true); ?>
    <a href="<?= $locked ? '#' : e(url('connect.php?select=' . $p['id'])) ?>" class="card card-hover p-4 flex items-center gap-4 <?= $locked ? 'opacity-60 pointer-events-none' : '' ?> <?= $selected === $p['id'] ? 'ring-2 ring-violet-500' : '' ?>">
        <span class="w-12 h-12 rounded-2xl gradient-bg text-white flex items-center justify-center shrink-0"><?= icon($p['icon'], 'w-6 h-6') ?></span>
        <span class="min-w-0 flex-1">
            <span class="flex items-center gap-2 flex-wrap"><span class="font-bold text-sm"><?= e($p['name']) ?></span><span class="badge badge-active"><?= e($p['tag']) ?></span></span>
            <span class="block text-xs text-slate-500 dark:text-slate-400 mt-0.5"><?= e($p['desc']) ?></span>
        </span>
        <?= $locked ? icon('lock', 'w-5 h-5 text-slate-400 shrink-0') : icon('arrow-right', 'w-5 h-5 text-slate-400 shrink-0') ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Personal providers (clearly separated) -->
<p class="text-[11px] font-bold uppercase tracking-[.16em] text-slate-500 dark:text-slate-400 mt-6 mb-2 flex items-center gap-2"><?= icon('smartphone', 'w-4 h-4') ?> Personal Providers <span class="normal-case font-medium text-[10px] text-slate-400">(mobile + OTP, personal accounts)</span></p>
<div class="grid gap-3 md:grid-cols-2">
    <?php foreach ($catalogue['personal'] as $p): $locked = $limitReached || in_array($p['id'], array_map(fn($x) => $x['provider'] ?? '', $connected), true); ?>
    <a href="<?= $locked ? '#' : e(url('connect.php?select=' . $p['id'])) ?>" class="card card-hover p-4 flex items-center gap-4 <?= $locked ? 'opacity-60 pointer-events-none' : '' ?> <?= $selected === $p['id'] ? 'ring-2 ring-violet-500' : '' ?>">
        <span class="w-12 h-12 rounded-2xl bg-slate-800 dark:bg-slate-700 text-white flex items-center justify-center shrink-0"><?= icon($p['icon'], 'w-6 h-6') ?></span>
        <span class="min-w-0 flex-1">
            <span class="font-bold text-sm block"><?= e($p['name']) ?></span>
            <span class="block text-xs text-slate-500 dark:text-slate-400 mt-0.5"><?= e($p['desc']) ?></span>
        </span>
        <?= $locked ? icon('lock', 'w-5 h-5 text-slate-400 shrink-0') : icon('arrow-right', 'w-5 h-5 text-slate-400 shrink-0') ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($limitReached): ?>
<p class="mt-4 text-xs text-amber-700 dark:text-amber-300 flex items-center gap-2"><?= icon('lock', 'w-4 h-4') ?> You have reached the maximum of 3 providers. Disconnect one above to connect another.</p>
<?php endif; ?>

<!-- Selected provider form -->
<?php if ($selectedMeta): ?>
<div id="providerForm" class="card p-5 sm:p-6 mt-6 animate-fade-up">
    <div class="flex items-center gap-3">
        <span class="w-12 h-12 rounded-2xl <?= $selectedMeta['type'] === 'business' ? 'gradient-bg' : 'bg-slate-800 dark:bg-slate-700' ?> text-white flex items-center justify-center"><?= icon($selectedMeta['icon'], 'w-6 h-6') ?></span>
        <div>
            <h3 class="font-extrabold"><?= e($selectedMeta['name']) ?></h3>
            <p class="text-xs text-slate-500 dark:text-slate-400"><?= $selectedMeta['type'] === 'business' ? 'Enter the UPI ID where you want to receive payments.' : 'We will send an OTP to your mobile to link your account.' ?></p>
        </div>
    </div>

    <?php if ($selectedMeta['type'] === 'business'): ?>
    <form method="POST" action="<?= e(url('connect.php')) ?>" class="mt-5 flex flex-col sm:flex-row gap-3 max-w-2xl">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="connect_business">
        <input type="hidden" name="provider" value="<?= e($selectedMeta['id']) ?>">
        <div class="flex-1">
            <label class="label" for="upi_id">UPI ID / VPA</label>
            <input class="input font-mono" type="text" id="upi_id" name="upi_id" required placeholder="yourname@okaxis" pattern="[\w.\-]{2,}@[a-zA-Z][a-zA-Z0-9]{1,}" autocomplete="off" autofocus>
        </div>
        <div class="sm:self-end"><button type="submit" class="btn-primary h-[46px]"><?= icon('check', 'w-4 h-4') ?> Save</button></div>
    </form>

    <!-- Auto-verify instructions for business providers -->
    <details class="mt-5 rounded-2xl border border-slate-200 dark:border-slate-700/60 p-4">
        <summary class="cursor-pointer text-sm font-semibold flex items-center gap-2 select-none"><?= icon('zap', 'w-4 h-4 text-violet-500') ?> Enable automatic payment detection (SMS forwarder)</summary>
        <div class="mt-3 text-xs text-slate-600 dark:text-slate-300 leading-relaxed space-y-2">
            <p>Business UPI does not expose a public transaction feed. To verify payments automatically, install any "SMS Forwarder" app on your phone and forward UPI credit SMS messages to:</p>
            <div class="flex items-center gap-2 bg-slate-100 dark:bg-slate-800/70 rounded-xl px-3 py-2 font-mono text-[11px] break-all"><span class="flex-1"><?= e(url('callback.php?action=payment&token=' . $callbackToken)) ?></span>
                <button type="button" class="icon-btn !w-8 !h-8 shrink-0" data-copy="<?= e(url('callback.php?action=payment&token=' . $callbackToken)) ?>" aria-label="Copy callback URL"><?= icon('copy', 'w-4 h-4') ?></button>
            </div>
            <p>Send it as an HTTP POST with form fields: <span class="font-mono">amount, utr, upi_id</span>. CashPe matches the pending link and marks it paid automatically.</p>
            <p class="text-[11px] text-slate-400">Your callback token is secret - treat it like a password. Regenerating providers does not change it.</p>
        </div>
    </details>

    <?php else: ?>
    <form method="POST" action="<?= e(url('connect.php')) ?>" class="mt-5 flex flex-col sm:flex-row gap-3 max-w-2xl">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="start_personal">
        <input type="hidden" name="provider" value="<?= e($selectedMeta['id']) ?>">
        <div class="flex-1">
            <label class="label" for="mobile">Mobile number linked to <?= e($selectedMeta['name']) ?></label>
            <input class="input font-mono" type="tel" id="mobile" name="mobile" required inputmode="numeric" pattern="[6-9][0-9]{9}" placeholder="9876543210" autofocus>
            <p class="mt-1.5 text-[11px] text-slate-400">India numbers only. OTP is valid for 10 minutes.</p>
        </div>
        <div class="sm:self-end"><button type="submit" class="btn-primary h-[46px]">Next <?= icon('arrow-right', 'w-4 h-4') ?></button></div>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
