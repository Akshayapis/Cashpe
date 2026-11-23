<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/firebase_helper.php';
require_once dirname(__DIR__) . '/includes/icons.php';

$user = auth_require_verified();
$uid = (string) ($user['uid'] ?? $user['id']);

$dbError = null;
$links = [];
try {
    $links = fs_query('payment_links', [['uid', '==', $uid]], null, 'created_at', 'desc');
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

// ---- Filters ----
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$provider = trim((string) ($_GET['provider'] ?? ''));

$fromTs = $from !== '' ? strtotime($from . ' 00:00:00') : null;
$toTs = $to !== '' ? strtotime($to . ' 23:59:59') : null;

// Distinct provider list for the dropdown (before filtering)
$providerNames = [];
foreach ($links as $l) {
    $p = (string) ($l['provider'] ?? '');
    if ($p !== '') {
        $providerNames[$p] = true;
    }
}
$providerNames = array_keys($providerNames);
sort($providerNames);

$filtered = array_filter($links, function ($l) use ($fromTs, $toTs, $status, $provider) {
    $created = (int) ($l['created_at'] ?? 0);
    if ($fromTs && $created < $fromTs) return false;
    if ($toTs && $created > $toTs) return false;
    if ($status !== '' && ($l['status'] ?? '') !== $status) return false;
    if ($provider !== '' && ($l['provider'] ?? '') !== $provider) return false;
    return true;
});

// ---- CSV export ----
if (($_GET['export'] ?? '') === 'csv') {
    $filename = 'cashpe-transactions-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Link ID', 'Amount', 'Commission', 'Customer', 'Email', 'Phone', 'Provider', 'UPI ID', 'Status', 'UTR', 'Note', 'Created At']);
    foreach ($filtered as $l) {
        fputcsv($out, [
            $l['link_id'] ?? '',
            number_format((float) ($l['amount'] ?? 0), 2, '.', ''),
            number_format((float) ($l['commission'] ?? 0), 2, '.', ''),
            $l['customer_name'] ?? '',
            $l['customer_email'] ?? '',
            $l['customer_phone'] ?? '',
            $l['provider'] ?? '',
            $l['upi_id'] ?? '',
            $l['status'] ?? '',
            $l['utr'] ?? '',
            $l['note'] ?? '',
            format_date($l['created_at'] ?? 0),
        ]);
    }
    fclose($out);
    exit;
}

$totalVolume = 0.0;
foreach ($filtered as $l) {
    if (($l['status'] ?? '') === 'success') {
        $totalVolume += (float) ($l['amount'] ?? 0);
    }
}
$queryString = http_build_query(array_filter(['from' => $from, 'to' => $to, 'status' => $status, 'provider' => $provider], fn($v) => $v !== ''));

$pageTitle = 'Transactions';
$activePage = 'transactions';
require dirname(__DIR__) . '/includes/header.php';
?>

<?php if ($dbError): ?><div class="flash flash-error mb-4"><?= e($dbError) ?></div><?php endif; ?>

<!-- Filters -->
<form method="GET" action="<?= e(url('transactions.php')) ?>" class="card p-4 sm:p-5 flex flex-wrap items-end gap-3">
    <div>
        <label class="label" for="from">From</label>
        <input class="input !w-auto" type="date" id="from" name="from" value="<?= e($from) ?>">
    </div>
    <div>
        <label class="label" for="to">To</label>
        <input class="input !w-auto" type="date" id="to" name="to" value="<?= e($to) ?>">
    </div>
    <div>
        <label class="label" for="status">Status</label>
        <select class="input !w-auto" id="status" name="status">
            <option value="">All</option>
            <?php foreach (['pending', 'success', 'failed', 'expired'] as $s): ?>
            <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="label" for="provider">Provider</label>
        <select class="input !w-auto" id="provider" name="provider">
            <option value="">All</option>
            <?php foreach ($providerNames as $p): ?>
            <option value="<?= e($p) ?>" <?= $provider === $p ? 'selected' : '' ?>><?= e($p) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn-secondary !py-2.5"><?= icon('filter', 'w-4 h-4') ?> Apply</button>
    <a href="<?= e(url('transactions.php')) ?>" class="btn-secondary !py-2.5"><?= icon('x', 'w-4 h-4') ?> Clear</a>
    <a href="<?= e(url('transactions.php?' . $queryString . ($queryString !== '' ? '&' : '')) . 'export=csv') ?>" class="btn-primary !py-2.5"><?= icon('download', 'w-4 h-4') ?> Export CSV</a>
</form>

<div class="flex items-center gap-4 mt-4 text-xs text-slate-500 dark:text-slate-400 px-1">
    <span><strong class="text-slate-800 dark:text-slate-100"><?= count($filtered) ?></strong> result<?= count($filtered) === 1 ? '' : 's' ?></span>
    <span>&middot;</span>
    <span>Successful volume: <strong class="text-emerald-600 dark:text-emerald-400"><?= e(format_money($totalVolume)) ?></strong></span>
</div>

<!-- Table -->
<div class="card overflow-hidden mt-2">
    <?php if (empty($filtered)): ?>
    <div class="p-10 text-center">
        <div class="mx-auto w-16 h-16 rounded-2xl bg-indigo-50 dark:bg-indigo-500/10 text-indigo-400 flex items-center justify-center"><?= icon('list', 'w-8 h-8') ?></div>
        <h4 class="mt-4 font-bold">No transactions match these filters</h4>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Try widening the date range or clearing the filters.</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Link ID</th><th>Amount</th><th>Customer</th><th>Provider</th><th>UPI ID</th><th>Status</th><th>UTR</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($filtered as $l): ?>
            <tr class="row-clickable" data-detail='<?= e(json_encode([
                'link_id' => $l['link_id'] ?? '',
                'amount' => format_money($l['amount'] ?? 0),
                'commission' => format_money($l['commission'] ?? 0),
                'customer_name' => $l['customer_name'] ?? '',
                'customer_email' => $l['customer_email'] ?? '',
                'customer_phone' => $l['customer_phone'] ?? '',
                'provider' => $l['provider'] ?? '',
                'upi_id' => $l['upi_id'] ?? '',
                'status' => $l['status'] ?? '',
                'utr' => $l['utr'] ?? '-',
                'note' => $l['note'] ?? '',
                'created' => format_date($l['created_at'] ?? 0),
                'paid_at' => !empty($l['paid_at']) ? format_date($l['paid_at']) : '-',
            ], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>
                <td class="font-mono text-xs"><?= e($l['link_id'] ?? '') ?></td>
                <td class="font-semibold"><?= e(format_money($l['amount'] ?? 0)) ?></td>
                <td><?= e($l['customer_name'] ?? '') ?></td>
                <td class="text-xs"><?= e($l['provider'] ?? '') ?></td>
                <td class="font-mono text-[11px] text-slate-500 dark:text-slate-400"><?= e($l['upi_id'] ?? '') ?></td>
                <td><span class="badge badge-<?= e($l['status'] ?? 'pending') ?>"><?= e($l['status'] ?? 'pending') ?></span></td>
                <td class="font-mono text-[11px]"><?= e($l['utr'] ?? '-') ?></td>
                <td class="text-xs text-slate-500 dark:text-slate-400 whitespace-nowrap"><?= e(format_date($l['created_at'] ?? 0)) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Detail modal -->
<div id="detailModal" class="fixed inset-0 z-[80] hidden items-center justify-center" role="dialog" aria-modal="true">
    <div class="absolute inset-0 bg-slate-950/60 backdrop-blur-sm" data-close-detail></div>
    <div class="relative glass rounded-3xl max-w-md w-full mx-4 p-6 shadow-2xl animate-pop">
        <div class="flex items-center justify-between">
            <h3 class="font-extrabold flex items-center gap-2"><?= icon('receipt', 'w-5 h-5 text-violet-500') ?> Transaction Detail</h3>
            <button type="button" class="icon-btn" data-close-detail aria-label="Close"><?= icon('x', 'w-6 h-6') ?></button>
        </div>
        <dl class="mt-4 grid grid-cols-3 gap-y-3 text-sm" id="detailBody">
            <dt class="text-slate-400 col-span-1">Link ID</dt><dd class="col-span-2 font-mono text-xs break-all" data-field="link_id">-</dd>
            <dt class="text-slate-400">Amount</dt><dd class="col-span-2 font-bold" data-field="amount">-</dd>
            <dt class="text-slate-400">Commission</dt><dd class="col-span-2" data-field="commission">-</dd>
            <dt class="text-slate-400">Customer</dt><dd class="col-span-2" data-field="customer_name">-</dd>
            <dt class="text-slate-400">Email</dt><dd class="col-span-2 text-xs" data-field="customer_email">-</dd>
            <dt class="text-slate-400">Phone</dt><dd class="col-span-2 text-xs" data-field="customer_phone">-</dd>
            <dt class="text-slate-400">Provider</dt><dd class="col-span-2" data-field="provider">-</dd>
            <dt class="text-slate-400">UPI ID</dt><dd class="col-span-2 font-mono text-xs" data-field="upi_id">-</dd>
            <dt class="text-slate-400">Status</dt><dd class="col-span-2" data-field="status">-</dd>
            <dt class="text-slate-400">UTR</dt><dd class="col-span-2 font-mono text-xs" data-field="utr">-</dd>
            <dt class="text-slate-400">Note</dt><dd class="col-span-2" data-field="note">-</dd>
            <dt class="text-slate-400">Created</dt><dd class="col-span-2 text-xs" data-field="created">-</dd>
            <dt class="text-slate-400">Paid at</dt><dd class="col-span-2 text-xs" data-field="paid_at">-</dd>
        </dl>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('detailModal');
    if (!modal) return;
    var fields = modal.querySelectorAll('[data-field]');
    function open(data) {
        fields.forEach(function (el) { el.textContent = data[el.getAttribute('data-field')] || '-'; });
        modal.classList.remove('hidden'); modal.classList.add('flex'); document.body.style.overflow = 'hidden';
    }
    function close() { modal.classList.add('hidden'); modal.classList.remove('flex'); document.body.style.overflow = ''; }
    document.querySelectorAll('tr[data-detail]').forEach(function (row) {
        row.addEventListener('click', function () {
            try { open(JSON.parse(row.getAttribute('data-detail'))); } catch (e) {}
        });
    });
    modal.addEventListener('click', function (ev) { if (ev.target.closest('[data-close-detail]')) close(); });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') close(); });
})();
</script>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
