<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/firebase_helper.php';
require_once dirname(__DIR__) . '/includes/wallet_helper.php';
require_once dirname(__DIR__) . '/includes/icons.php';

$user = auth_require_verified();
$uid = (string) ($user['uid'] ?? $user['id']);

$error = null;
$info = null;
$flash = flash_get();
if ($flash) {
    $flash['type'] === 'error' ? $error = $flash['message'] : $info = $flash['message'];
}

// Add Funds -> creates a wallet request for admin approval
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add_funds') {
        $amount = (float) ($_POST['amount'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($amount < 10 || $amount > 100000) {
            $error = 'Enter an amount between ₹10 and ₹1,00,000.';
        } else {
            try {
                fs_add('wallet_requests', [
                    'uid' => $uid,
                    'user_name' => (string) ($user['name'] ?? ''),
                    'user_email' => (string) ($user['email'] ?? ''),
                    'amount' => round($amount, 2),
                    'note' => mb_substr($note, 0, 200),
                    'status' => 'pending',
                    'created_at' => time(),
                ]);
                flash_set('success', 'Wallet request of ' . format_money($amount) . ' submitted. An admin will approve it shortly.');
                redirect('wallet.php');
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$dbError = null;
$balance = 0.0;
$transactions = [];
$pendingRequests = [];
try {
    $balance = wallet_balance_fresh($uid);
    $transactions = wallet_transactions_for($uid, 30);
    $pendingRequests = fs_query('wallet_requests', [['uid', '==', $uid], ['status', '==', 'pending']], 10, 'created_at', 'desc');
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}
$lowBalance = $balance < 50;

$pageTitle = 'Wallet';
$activePage = 'wallet';
require dirname(__DIR__) . '/includes/header.php';
?>

<?php if ($dbError): ?><div class="flash flash-error mb-4"><?= e($dbError) ?></div><?php endif; ?>
<?php if ($error): ?><div class="flash flash-error mb-4 flex items-start gap-2"><?= icon('alert-triangle', 'w-5 h-5 shrink-0') ?><span><?= e($error) ?></span></div><?php endif; ?>
<?php if ($info): ?><div class="flash flash-success mb-4 flex items-start gap-2"><?= icon('check-circle', 'w-5 h-5 shrink-0') ?><span><?= e($info) ?></span></div><?php endif; ?>

<?php if ($lowBalance): ?>
<div class="mb-4 rounded-2xl border border-rose-200 dark:border-rose-500/30 bg-rose-50 dark:bg-rose-500/10 text-rose-700 dark:text-rose-300 px-4 py-3 flex items-start gap-3 text-sm">
    <?= icon('alert-triangle', 'w-5 h-5 shrink-0 mt-0.5') ?>
    <div><span class="font-bold">Low wallet balance.</span> Your balance is <?= e(format_money($balance)) ?> - payment links need 2% of the amount to be prepaid from the wallet. Add funds to keep creating links.</div>
</div>
<?php endif; ?>

<!-- Balance card -->
<div class="relative overflow-hidden rounded-3xl p-6 sm:p-8 text-white shadow-glow" style="background:linear-gradient(135deg,#4f46e5 0%,#7c3aed 45%,#c026d3 100%)">
    <svg aria-hidden="true" class="absolute -top-10 -right-10 w-56 h-56 opacity-20" viewBox="0 0 200 200" fill="none"><circle cx="100" cy="100" r="90" stroke="white" stroke-width="1.5" stroke-dasharray="4 6"/><circle cx="100" cy="100" r="60" stroke="white" stroke-width="1.5" stroke-dasharray="4 6"/><circle cx="100" cy="100" r="30" stroke="white" stroke-width="1.5"/></svg>
    <div class="relative flex flex-col sm:flex-row sm:items-center justify-between gap-6">
        <div>
            <p class="text-[11px] uppercase tracking-[.22em] text-violet-200 font-bold flex items-center gap-2"><?= icon('banknote', 'w-5 h-5') ?> CashPe Wallet</p>
            <p class="mt-3 text-4xl sm:text-5xl font-black tracking-tight"><?= e(format_money($balance)) ?></p>
            <p class="mt-2 text-xs text-violet-200/90">Available for commissions &middot; 2% per successful payment link</p>
        </div>
        <button type="button" id="openAddFunds" class="inline-flex items-center justify-center gap-2 bg-white text-violet-700 hover:bg-violet-50 transition font-bold rounded-2xl px-6 py-4 text-sm shadow-lg shrink-0"><?= icon('plus', 'w-5 h-5') ?> Add Funds</button>
    </div>
</div>

<!-- Pending requests -->
<?php if (!empty($pendingRequests)): ?>
<div class="card mt-6 p-5">
    <h3 class="font-bold text-sm flex items-center gap-2"><?= icon('clock', 'w-4 h-4 text-amber-500') ?> Pending top-up requests</h3>
    <div class="mt-3 flex flex-wrap gap-2">
        <?php foreach ($pendingRequests as $req): ?>
        <span class="inline-flex items-center gap-2 text-xs bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/25 text-amber-800 dark:text-amber-200 rounded-xl px-3 py-2"><?= e(format_money($req['amount'] ?? 0)) ?><span class="opacity-60">&middot; awaiting approval</span></span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Ledger -->
<div class="card mt-6 overflow-hidden">
    <div class="flex items-center justify-between px-5 sm:px-6 py-4 border-b border-slate-100 dark:border-slate-800">
        <h3 class="font-bold flex items-center gap-2"><?= icon('list', 'w-5 h-5 text-violet-500') ?> Wallet History</h3>
        <span class="text-xs text-slate-400">Last 30 entries</span>
    </div>
    <?php if (empty($transactions)): ?>
    <div class="p-10 text-center">
        <div class="mx-auto w-16 h-16 rounded-2xl bg-indigo-50 dark:bg-indigo-500/10 text-indigo-400 flex items-center justify-center"><?= icon('wallet', 'w-8 h-8') ?></div>
        <h4 class="mt-4 font-bold">No wallet activity yet</h4>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Commission deductions and top-ups will be listed here.</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Description</th><th>Balance</th></tr></thead>
            <tbody>
            <?php foreach ($transactions as $txn): $isCredit = ($txn['type'] ?? 'credit') === 'credit'; ?>
            <tr>
                <td class="text-xs text-slate-500 dark:text-slate-400 whitespace-nowrap"><?= e(format_date($txn['created_at'] ?? 0)) ?></td>
                <td><span class="badge <?= $isCredit ? 'badge-success' : 'badge-failed' ?>"><?= $isCredit ? 'Credit' : 'Debit' ?></span></td>
                <td class="font-semibold <?= $isCredit ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' ?>"><?= $isCredit ? '+' : '-' ?><?= e(format_money($txn['amount'] ?? 0)) ?></td>
                <td class="text-xs max-w-xs"><?= e($txn['description'] ?? '') ?></td>
                <td class="font-mono text-xs"><?= e(format_money($txn['balance_after'] ?? 0)) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Add Funds modal -->
<div id="addFundsModal" class="fixed inset-0 z-[80] hidden items-center justify-center" role="dialog" aria-modal="true">
    <div class="absolute inset-0 bg-slate-950/60 backdrop-blur-sm" data-close-addfunds></div>
    <div class="relative glass rounded-3xl max-w-md w-full mx-4 p-6 shadow-2xl animate-pop">
        <div class="flex items-center justify-between">
            <h3 class="font-extrabold text-lg flex items-center gap-2"><?= icon('wallet', 'w-5 h-5 text-violet-500') ?> Add Funds</h3>
            <button type="button" class="icon-btn" data-close-addfunds aria-label="Close"><?= icon('x', 'w-6 h-6') ?></button>
        </div>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Submit a top-up request. Once an admin approves it, the amount is credited to your wallet instantly.</p>
        <form method="POST" action="<?= e(url('wallet.php')) ?>" class="mt-4 space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_funds">
            <div>
                <label class="label" for="fund_amount">Amount (₹)</label>
                <input class="input text-2xl font-extrabold" type="number" id="fund_amount" name="amount" min="10" max="100000" step="1" required placeholder="100" inputmode="numeric">
            </div>
            <div>
                <label class="label" for="fund_note">Note for admin (optional)</label>
                <input class="input" type="text" id="fund_note" name="note" maxlength="200" placeholder="UPI ref / reason for top-up">
            </div>
            <button type="submit" class="btn-primary w-full !py-3"><?= icon('send', 'w-5 h-5') ?> Submit Request</button>
        </form>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('addFundsModal');
    var openBtn = document.getElementById('openAddFunds');
    if (!modal || !openBtn) return;
    function open() { modal.classList.remove('hidden'); modal.classList.add('flex'); document.body.style.overflow = 'hidden'; }
    function close() { modal.classList.add('hidden'); modal.classList.remove('flex'); document.body.style.overflow = ''; }
    openBtn.addEventListener('click', open);
    modal.addEventListener('click', function (ev) { if (ev.target.closest('[data-close-addfunds]')) close(); });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') close(); });
})();
</script>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
