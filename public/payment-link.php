<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/firebase_helper.php';
require_once dirname(__DIR__) . '/includes/wallet_helper.php';
require_once dirname(__DIR__) . '/includes/provider_helper.php';
require_once dirname(__DIR__) . '/includes/icons.php';

$user = auth_require_verified();
$uid = (string) ($user['uid'] ?? $user['id']);
$percent = commission_percent();

$error = null;
$info = null;
$flash = flash_get();
if ($flash) {
    $flash['type'] === 'error' ? $error = $flash['message'] : $info = $flash['message'];
}

$dbError = null;
$providers = [];
try {
    $providers = user_active_providers($uid);
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

// One-shot display of the just-created link
$createdLink = $_SESSION['created_link'] ?? null;
unset($_SESSION['created_link']);

// ---------------------------------------------------------------------------
// Create a payment link
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $amount = (float) ($_POST['amount'] ?? 0);
    $customerName = trim((string) ($_POST['customer_name'] ?? ''));
    $customerEmail = strtolower(trim((string) ($_POST['customer_email'] ?? '')));
    $customerPhone = preg_replace('/\D/', '', (string) ($_POST['customer_phone'] ?? ''));
    $note = trim((string) ($_POST['note'] ?? ''));
    $providerId = (string) ($_POST['provider_id'] ?? '');

    if ($amount <= 0 || $amount > 1000000) {
        $error = 'Enter a valid amount between ₹1 and ₹10,00,000.';
    } elseif ($customerName === '') {
        $error = 'Customer name is required.';
    } elseif ($customerEmail !== '' && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Customer email is not valid.';
    } elseif ($customerPhone !== '' && !preg_match('/^[6-9]\d{9}$/', $customerPhone)) {
        $error = 'Customer phone must be a valid 10-digit Indian mobile number.';
    } elseif ($providerId === '') {
        $error = 'Connect and select a provider first.';
    } else {
        try {
            $provider = fs_get('users/' . $uid . '/providers', $providerId);
            if (!$provider || ($provider['status'] ?? '') !== 'active') {
                $error = 'Selected provider is not available.';
            } else {
                $commission = commission_for($amount);
                $balance = wallet_balance_fresh($uid);
                if ($balance < $commission) {
                    $error = 'Insufficient wallet balance. You need ' . format_money($commission) . ' to create this link (2% of the amount). Add funds to your wallet.';
                } else {
                    $linkId = generate_link_id();
                    $docId = generate_token(12);
                    $upiId = (string) (($provider['type'] ?? 'business') === 'personal' ? ($provider['selected_upi'] ?? '') : ($provider['upi_id'] ?? ''));
                    fs_set('payment_links', $docId, [
                        'link_id' => $linkId,
                        'uid' => $uid,
                        'amount' => round($amount, 2),
                        'commission' => $commission,
                        'customer_name' => $customerName,
                        'customer_email' => $customerEmail,
                        'customer_phone' => $customerPhone,
                        'note' => mb_substr($note, 0, 200),
                        'provider_id' => $providerId,
                        'provider' => (string) ($provider['provider'] ?? ''),
                        'upi_id' => $upiId,
                        'status' => 'pending',
                        'utr' => null,
                        'merchant_name' => (string) ($user['name'] ?? 'Merchant'),
                        'created_at' => time(),
                    ], false);
                    $_SESSION['created_link'] = [
                        'link_id' => $linkId,
                        'amount' => round($amount, 2),
                        'customer_name' => $customerName,
                        'url' => url('pay.php?id=' . $linkId),
                    ];
                    redirect('payment-link.php');
                }
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

// ---------------------------------------------------------------------------
// Load links table
// ---------------------------------------------------------------------------
$links = [];
try {
    $links = fs_query('payment_links', [['uid', '==', $uid]], 50, 'created_at', 'desc');
} catch (Throwable $e) {
    $dbError = $dbError ?? $e->getMessage();
}

$pageTitle = 'Payment Links';
$activePage = 'payment-link';
require dirname(__DIR__) . '/includes/header.php';
?>

<?php if ($dbError): ?><div class="flash flash-error mb-4"><?= e($dbError) ?></div><?php endif; ?>
<?php if ($error): ?><div class="flash flash-error mb-4 flex items-start gap-2"><?= icon('alert-triangle', 'w-5 h-5 shrink-0') ?><span><?= e($error) ?></span></div><?php endif; ?>
<?php if ($info): ?><div class="flash flash-success mb-4 flex items-start gap-2"><?= icon('check-circle', 'w-5 h-5 shrink-0') ?><span><?= e($info) ?></span></div><?php endif; ?>

<?php if ($createdLink): ?>
<div class="card p-5 sm:p-6 mb-6 border-emerald-200 dark:border-emerald-500/30 animate-fade-up">
    <div class="flex items-center gap-3">
        <span class="w-12 h-12 rounded-2xl bg-emerald-500/15 text-emerald-500 flex items-center justify-center shrink-0"><?= icon('check-circle', 'w-6 h-6') ?></span>
        <div>
            <h3 class="font-extrabold text-emerald-700 dark:text-emerald-300">Payment link created</h3>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5"><?= e($createdLink['customer_name']) ?> &middot; <?= e(format_money($createdLink['amount'])) ?> &middot; expires in 30 minutes</p>
        </div>
    </div>
    <div class="mt-4 flex flex-col sm:flex-row gap-2">
        <code class="flex-1 text-xs bg-slate-100 dark:bg-slate-800/70 rounded-xl px-4 py-3 font-mono break-all self-center"><?= e($createdLink['url']) ?></code>
        <button type="button" class="btn-secondary" data-copy="<?= e($createdLink['url']) ?>"><?= icon('copy', 'w-4 h-4') ?> Copy</button>
        <a class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold text-white bg-[#25D366] hover:bg-[#1fb457] transition" target="_blank" rel="noopener" href="https://wa.me/?text=<?= rawurlencode('Hi! Please pay ' . format_money($createdLink['amount']) . ' via this link: ' . $createdLink['url']) ?>"><?= icon('whatsapp', 'w-5 h-5') ?> WhatsApp</a>
    </div>
</div>
<?php endif; ?>

<div class="grid lg:grid-cols-5 gap-6">
    <!-- Form -->
    <div class="card p-5 sm:p-6 lg:col-span-2 h-fit">
        <h2 class="font-extrabold text-lg tracking-tight flex items-center gap-2"><?= icon('plus', 'w-5 h-5 text-violet-500') ?> Create Payment Link</h2>
        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">A 2% commission (<?= e(rtrim(rtrim(number_format($percent, 2), '0'), '.')) ?>%) is deducted from your wallet only after the customer pays.</p>

        <?php if (empty($providers)): ?>
        <div class="mt-5 rounded-2xl border border-amber-200 dark:border-amber-500/25 bg-amber-50 dark:bg-amber-500/10 p-4 text-sm text-amber-800 dark:text-amber-200">
            <p class="font-semibold flex items-center gap-2"><?= icon('alert-triangle', 'w-4 h-4') ?> No provider connected</p>
            <p class="mt-1">Connect a business or personal provider first, then come back to create links.</p>
            <a href="<?= e(url('connect.php')) ?>" class="btn-secondary mt-3 !py-2"><?= icon('credit-card', 'w-4 h-4') ?> Connect Provider</a>
        </div>
        <?php else: ?>
        <form method="POST" action="<?= e(url('payment-link.php')) ?>" class="mt-5 space-y-4" id="linkForm">
            <?= csrf_field() ?>
            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label class="label" for="amount">Amount (₹)</label>
                    <input class="input text-2xl font-extrabold" type="number" id="amount" name="amount" min="1" max="1000000" step="0.01" required placeholder="500" inputmode="decimal">
                </div>
                <div class="col-span-2 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-800 px-4 py-3 grid grid-cols-2 gap-2 text-xs">
                    <div><p class="text-slate-400 uppercase text-[9px] font-bold tracking-wider">Commission (<?= e(rtrim(rtrim(number_format($percent, 2), '0'), '.')) ?>%)</p><p id="commissionPreview" class="font-bold text-rose-500 mt-0.5">₹0.00</p></div>
                    <div><p class="text-slate-400 uppercase text-[9px] font-bold tracking-wider">You collect</p><p id="netPreview" class="font-bold text-emerald-600 dark:text-emerald-400 mt-0.5">₹0.00</p></div>
                </div>
            </div>
            <div>
                <label class="label" for="customer_name">Customer name <span class="text-rose-500">*</span></label>
                <input class="input" type="text" id="customer_name" name="customer_name" required maxlength="80" placeholder="Rahul Sharma">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="label" for="customer_email">Email (optional)</label>
                    <input class="input" type="email" id="customer_email" name="customer_email" placeholder="customer@email.com">
                </div>
                <div>
                    <label class="label" for="customer_phone">Phone (optional)</label>
                    <input class="input" type="tel" id="customer_phone" name="customer_phone" inputmode="numeric" pattern="[6-9][0-9]{9}" placeholder="9876543210">
                </div>
            </div>
            <div>
                <label class="label" for="note">Note (optional)</label>
                <input class="input" type="text" id="note" name="note" maxlength="200" placeholder="Invoice #1042 - AC repair">
            </div>
            <div>
                <label class="label" for="provider_id">Provider</label>
                <select class="input" id="provider_id" name="provider_id" required>
                    <?php foreach ($providers as $p): ?>
                    <option value="<?= e($p['id']) ?>"><?= e(($p['name'] ?? ucfirst((string) ($p['provider'] ?? 'Provider')))) ?> - <?= e(($p['type'] ?? 'business') === 'business' ? ($p['upi_id'] ?? '') : ('••' . substr((string) ($p['mobile'] ?? ''), -4))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-primary w-full !py-3"><?= icon('link', 'w-5 h-5') ?> Generate Payment Link</button>
        </form>
        <?php endif; ?>
    </div>

    <!-- Links table -->
    <div class="card lg:col-span-3 overflow-hidden">
        <div class="flex items-center justify-between px-5 sm:px-6 py-4 border-b border-slate-100 dark:border-slate-800">
            <h3 class="font-bold flex items-center gap-2"><?= icon('list', 'w-5 h-5 text-violet-500') ?> Your Payment Links</h3>
            <span class="text-xs text-slate-400"><?= count($links) ?> total</span>
        </div>
        <?php if (empty($links)): ?>
        <div class="p-10 text-center">
            <div class="mx-auto w-16 h-16 rounded-2xl bg-violet-50 dark:bg-violet-500/10 text-violet-400 flex items-center justify-center"><?= icon('link', 'w-8 h-8') ?></div>
            <h4 class="mt-4 font-bold">No links yet</h4>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Your generated links will appear here with live status.</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead><tr><th>Link ID</th><th>Amount</th><th>Customer</th><th>Status</th><th>UTR</th><th>Date</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($links as $link): $linkUrl = url('pay.php?id=' . ($link['link_id'] ?? '')); ?>
                <tr>
                    <td class="font-mono text-xs"><?= e($link['link_id'] ?? '') ?></td>
                    <td class="font-semibold"><?= e(format_money($link['amount'] ?? 0)) ?></td>
                    <td><?= e($link['customer_name'] ?? 'Customer') ?></td>
                    <td><span class="badge badge-<?= e($link['status'] ?? 'pending') ?>"><?= e($link['status'] ?? 'pending') ?></span></td>
                    <td class="font-mono text-[11px] text-slate-500 dark:text-slate-400"><?= e($link['utr'] ?? '-') ?></td>
                    <td class="text-xs text-slate-500 dark:text-slate-400 whitespace-nowrap"><?= e(format_date($link['created_at'] ?? 0)) ?></td>
                    <td><button type="button" class="icon-btn !w-8 !h-8" data-copy="<?= e($linkUrl) ?>" title="Copy link" aria-label="Copy link"><?= icon('copy', 'w-4 h-4') ?></button></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Live commission preview
(function () {
    var amount = document.getElementById('amount');
    var commissionEl = document.getElementById('commissionPreview');
    var netEl = document.getElementById('netPreview');
    if (!amount || !commissionEl || !netEl) return;
    var percent = <?= json_encode($percent) ?>;
    function update() {
        var value = parseFloat(amount.value) || 0;
        var commission = Math.round(value * percent / 100 * 100) / 100;
        var net = Math.round((value - commission) * 100) / 100;
        commissionEl.textContent = '₹' + commission.toFixed(2);
        netEl.textContent = '₹' + net.toFixed(2);
    }
    amount.addEventListener('input', update);
    update();
})();
</script>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
