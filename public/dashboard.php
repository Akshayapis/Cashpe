<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/firebase_helper.php';
require_once dirname(__DIR__) . '/includes/wallet_helper.php';
require_once dirname(__DIR__) . '/includes/icons.php';

$user = auth_require_verified();
$uid = (string) ($user['uid'] ?? $user['id']);

$dbError = null;
$links = [];
$successCount = 0;
$pendingCount = 0;
$volume = 0.0;
$balance = 0.0;

try {
    $balance = wallet_balance_fresh($uid);
    $links = fs_query('payment_links', [['uid', '==', $uid]], null, 'created_at', 'desc');
    foreach ($links as $link) {
        $status = $link['status'] ?? 'pending';
        if ($status === 'success') {
            $successCount++;
            $volume += (float) ($link['amount'] ?? 0);
        } elseif ($status === 'pending') {
            $pendingCount++;
        }
    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$recent = array_slice($links, 0, 5);
$pageTitle = 'Dashboard';
$activePage = 'dashboard';
require dirname(__DIR__) . '/includes/header.php';
?>

<?php if ($dbError): ?>
<div class="flash flash-error mb-6 flex items-start gap-2"><?= icon('alert-triangle', 'w-5 h-5 shrink-0') ?><span><?= e($dbError) ?></span></div>
<?php endif; ?>

<!-- Greeting -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
    <div>
        <h2 class="text-2xl font-extrabold tracking-tight">Hello, <?= e(explode(' ', (string) ($user['name'] ?? 'Merchant'))[0]) ?></h2>
        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Here is what is happening with your payments today.</p>
    </div>
    <a href="<?= e(url('payment-link.php')) ?>" class="btn-primary"><?= icon('plus', 'w-4 h-4') ?> New Payment Link</a>
</div>

<!-- Stats -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 stagger">
    <a href="<?= e(url('wallet.php')) ?>" class="card card-hover animate-fade-up p-4 sm:p-5">
        <div class="flex items-center justify-between">
            <span class="w-10 h-10 rounded-xl bg-violet-100 dark:bg-violet-500/15 text-violet-600 dark:text-violet-300 flex items-center justify-center"><?= icon('wallet', 'w-5 h-5') ?></span>
            <?= icon('arrow-up-right', 'w-4 h-4 text-slate-400') ?>
        </div>
        <p class="mt-3 text-[10px] font-bold uppercase tracking-[.16em] text-slate-400 dark:text-slate-500">Wallet Balance</p>
        <p class="mt-1 text-lg sm:text-2xl font-extrabold tracking-tight"><?= e(format_money($balance)) ?></p>
    </a>

    <div class="card animate-fade-up p-4 sm:p-5">
        <span class="w-10 h-10 rounded-xl bg-indigo-100 dark:bg-indigo-500/15 text-indigo-600 dark:text-indigo-300 flex items-center justify-center"><?= icon('link', 'w-5 h-5') ?></span>
        <p class="mt-3 text-[10px] font-bold uppercase tracking-[.16em] text-slate-400 dark:text-slate-500">Payment Links</p>
        <p class="mt-1 text-lg sm:text-2xl font-extrabold tracking-tight"><?= count($links) ?></p>
    </div>

    <div class="card animate-fade-up p-4 sm:p-5">
        <span class="w-10 h-10 rounded-xl bg-emerald-100 dark:bg-emerald-500/15 text-emerald-600 dark:text-emerald-300 flex items-center justify-center"><?= icon('check-circle', 'w-5 h-5') ?></span>
        <p class="mt-3 text-[10px] font-bold uppercase tracking-[.16em] text-slate-400 dark:text-slate-500">Successful</p>
        <p class="mt-1 text-lg sm:text-2xl font-extrabold tracking-tight"><?= $successCount ?></p>
        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5"><?= e(format_money($volume)) ?> collected</p>
    </div>

    <div class="card animate-fade-up p-4 sm:p-5">
        <span class="w-10 h-10 rounded-xl bg-amber-100 dark:bg-amber-500/15 text-amber-600 dark:text-amber-300 flex items-center justify-center"><?= icon('clock', 'w-5 h-5') ?></span>
        <p class="mt-3 text-[10px] font-bold uppercase tracking-[.16em] text-slate-400 dark:text-slate-500">Pending</p>
        <p class="mt-1 text-lg sm:text-2xl font-extrabold tracking-tight"><?= $pendingCount ?></p>
        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Awaiting payment</p>
    </div>
</div>

<div class="grid lg:grid-cols-3 gap-6 mt-6">
    <!-- Recent links -->
    <div class="card lg:col-span-2 overflow-hidden">
        <div class="flex items-center justify-between px-5 sm:px-6 py-4 border-b border-slate-100 dark:border-slate-800">
            <h3 class="font-bold flex items-center gap-2"><?= icon('list', 'w-5 h-5 text-violet-500') ?> Recent Activity</h3>
            <a href="<?= e(url('transactions.php')) ?>" class="text-xs font-semibold text-violet-600 dark:text-violet-300 hover:underline inline-flex items-center gap-1">View all <?= icon('arrow-right', 'w-3.5 h-3.5') ?></a>
        </div>
        <?php if (empty($recent)): ?>
        <div class="p-10 text-center">
            <div class="mx-auto w-16 h-16 rounded-2xl bg-violet-50 dark:bg-violet-500/10 text-violet-400 flex items-center justify-center"><?= icon('link', 'w-8 h-8') ?></div>
            <h4 class="mt-4 font-bold">No payment activity yet</h4>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Create your first payment link and share it with a customer.</p>
            <a href="<?= e(url('payment-link.php')) ?>" class="btn-primary mt-5"><?= icon('plus', 'w-4 h-4') ?> Create Payment Link</a>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead><tr><th>Link</th><th>Amount</th><th>Customer</th><th>Status</th><th>Date</th></tr></thead>
                <tbody>
                <?php foreach ($recent as $link): ?>
                <tr>
                    <td class="font-mono text-xs"><?= e($link['link_id'] ?? '') ?></td>
                    <td class="font-semibold"><?= e(format_money($link['amount'] ?? 0)) ?></td>
                    <td><?= e($link['customer_name'] ?? 'Customer') ?></td>
                    <td><span class="badge badge-<?= e($link['status'] ?? 'pending') ?>"><?= e($link['status'] ?? 'pending') ?></span></td>
                    <td class="text-slate-500 dark:text-slate-400 text-xs"><?= e(format_date($link['created_at'] ?? 0)) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Quick actions -->
    <div class="card p-5 sm:p-6">
        <h3 class="font-bold flex items-center gap-2"><?= icon('zap', 'w-5 h-5 text-fuchsia-500') ?> Quick Actions</h3>
        <div class="mt-4 grid grid-cols-1 gap-3">
            <a href="<?= e(url('payment-link.php')) ?>" class="flex items-center gap-3 rounded-2xl border border-slate-200 dark:border-slate-700/60 p-4 hover:border-violet-400 dark:hover:border-violet-500/50 hover:-translate-y-0.5 transition">
                <span class="w-11 h-11 rounded-xl gradient-bg text-white flex items-center justify-center shrink-0"><?= icon('link', 'w-5 h-5') ?></span>
                <span><span class="block font-semibold text-sm">Create Payment Link</span><span class="block text-xs text-slate-500 dark:text-slate-400">Amount, customer, provider - done in seconds</span></span>
            </a>
            <a href="<?= e(url('wallet.php')) ?>" class="flex items-center gap-3 rounded-2xl border border-slate-200 dark:border-slate-700/60 p-4 hover:border-violet-400 dark:hover:border-violet-500/50 hover:-translate-y-0.5 transition">
                <span class="w-11 h-11 rounded-xl bg-emerald-500 text-white flex items-center justify-center shrink-0"><?= icon('plus', 'w-5 h-5') ?></span>
                <span><span class="block font-semibold text-sm">Add Funds to Wallet</span><span class="block text-xs text-slate-500 dark:text-slate-400">Needed to cover the 2% commission</span></span>
            </a>
            <a href="<?= e(url('connect.php')) ?>" class="flex items-center gap-3 rounded-2xl border border-slate-200 dark:border-slate-700/60 p-4 hover:border-violet-400 dark:hover:border-violet-500/50 hover:-translate-y-0.5 transition">
                <span class="w-11 h-11 rounded-xl bg-indigo-500 text-white flex items-center justify-center shrink-0"><?= icon('credit-card', 'w-5 h-5') ?></span>
                <span><span class="block font-semibold text-sm">Connect Provider</span><span class="block text-xs text-slate-500 dark:text-slate-400">PhonePe, Paytm, GPay, BharatPe, Navi</span></span>
            </a>
            <a href="<?= e(url('api.php')) ?>" class="flex items-center gap-3 rounded-2xl border border-slate-200 dark:border-slate-700/60 p-4 hover:border-violet-400 dark:hover:border-violet-500/50 hover:-translate-y-0.5 transition">
                <span class="w-11 h-11 rounded-xl bg-slate-800 dark:bg-slate-700 text-white flex items-center justify-center shrink-0"><?= icon('key', 'w-5 h-5') ?></span>
                <span><span class="block font-semibold text-sm">API Keys &amp; Docs</span><span class="block text-xs text-slate-500 dark:text-slate-400">Integrate CashPe into your stack</span></span>
            </a>
        </div>
        <div class="mt-5 rounded-2xl bg-gradient-to-br from-indigo-50 to-fuchsia-50 dark:from-indigo-500/10 dark:to-fuchsia-500/10 border border-violet-100 dark:border-violet-500/20 p-4">
            <p class="text-xs font-bold uppercase tracking-[.14em] text-violet-600 dark:text-violet-300 flex items-center gap-1.5"><?= icon('info', 'w-4 h-4') ?> Tip</p>
            <p class="mt-1.5 text-xs text-slate-600 dark:text-slate-300 leading-relaxed">Wallet must cover 2% of every link. Keep it topped up so new links never get blocked.</p>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
