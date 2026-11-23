<?php
/**
 * CashPe - Public payment page. Premium fintech UI, auto-polling for payment status.
 */
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/firebase_helper.php';
require_once dirname(__DIR__) . '/includes/icons.php';

$linkId = trim((string) ($_GET['id'] ?? ''));
$link = null;
$merchantName = 'Merchant';
$errorCode = null;

if ($linkId === '' || !preg_match('/^pl_[a-f0-9]{20}$/', $linkId)) {
    $errorCode = 'not_found';
} else {
    try {
        $rows = fs_query('payment_links', [['link_id', '==', $linkId]], 1);
        $link = $rows[0] ?? null;
        if ($link) {
            // Lazily expire on view
            $createdAt = (int) ($link['created_at'] ?? 0);
            if (($link['status'] ?? 'pending') === 'pending' && $createdAt > 0 && time() - $createdAt > 1800) {
                fs_update('payment_links', (string) $link['id'], ['status' => 'expired']);
                $link['status'] = 'expired';
            }
            $merchantName = (string) ($link['merchant_name'] ?? 'Merchant');
        } else {
            $errorCode = 'not_found';
        }
    } catch (Throwable $e) {
        error_log('pay.php load failed: ' . $e->getMessage());
        $errorCode = 'unavailable';
    }
}

$status = $link['status'] ?? 'pending';
$amount = (float) ($link['amount'] ?? 0);
$upiId = (string) ($link['upi_id'] ?? '');
$note = (string) ($link['note'] ?? '');
$customerName = (string) ($link['customer_name'] ?? '');
$utr = (string) ($link['utr'] ?? '');
$createdAt = (int) ($link['created_at'] ?? time());

// UPI deep link
$upiParams = http_build_query(['pa' => $upiId, 'pn' => $merchantName, 'am' => number_format($amount, 2, '.', ''), 'cu' => 'INR', 'tn' => $note !== '' ? $note : 'CashPe Payment']);
$upiUri = 'upi://pay?' . $upiParams;
$gpayUri = 'tez://upi/pay?' . $upiParams;
$phonepeUri = 'phonepe://pay?' . $upiParams;
$paytmUri = 'paytmmp://pay?' . $upiParams;

// QR code as inline SVG (chillerlan/php-qrcode)
$qrSvg = null;
if ($errorCode === null && $status === 'pending') {
    try {
        if (class_exists(\chillerlan\QRCode\QRCode::class)) {
            $options = new \chillerlan\QRCode\QROptions([
                'eccLevel' => \chillerlan\QRCode\Common\EccLevel::L,
                'outputType' => \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG,
                'scale' => 6,
                'quietzone' => 2,
                'svgAddXmlHeader' => false,
            ]);
            $qrSvg = (new \chillerlan\QRCode\QRCode($options))->render($upiUri);
        }
    } catch (Throwable $e) {
        error_log('QR generation failed: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pay <?= e($merchantName) ?> - CashPe</title>
<meta name="description" content="Complete your UPI payment securely with CashPe.">
<meta name="robots" content="noindex">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%236d28d9'/%3E%3Cpath d='M36 8 L16 36 H30 L26 56 L46 27 H33 Z' fill='%23fff'/%3E%3C/svg%3E">
<script src="https://cdn.tailwindcss.com"></script>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased flex items-center justify-center p-4 sm:p-6 overflow-x-hidden">

<!-- Ambient background -->
<div aria-hidden="true" class="fixed inset-0 pointer-events-none">
    <div class="absolute -top-40 left-1/2 -translate-x-1/2 w-[46rem] h-[46rem] rounded-full" style="background:radial-gradient(circle, rgba(99,102,241,.4) 0%, transparent 62%)"></div>
    <div class="absolute -bottom-56 -left-40 w-[40rem] h-[40rem] rounded-full" style="background:radial-gradient(circle, rgba(217,70,239,.3) 0%, transparent 62%)"></div>
    <div class="absolute -bottom-56 -right-40 w-[40rem] h-[40rem] rounded-full" style="background:radial-gradient(circle, rgba(139,92,246,.3) 0%, transparent 62%)"></div>
</div>

<div class="relative w-full max-w-md">

    <!-- Brand -->
    <div class="flex items-center justify-center gap-3 mb-6">
        <?= cashpe_logo('w-11 h-11', 'w-6 h-6') ?>
        <div class="text-center">
            <p class="text-xl font-extrabold tracking-tight text-white leading-none">CashPe</p>
            <p class="text-[9px] uppercase tracking-[.22em] text-slate-400 font-semibold mt-1">Secure UPI Payment</p>
        </div>
    </div>

<?php if ($errorCode !== null): ?>
    <div class="glass !bg-slate-900/70 !border-white/10 rounded-3xl p-8 text-center shadow-2xl">
        <div class="mx-auto w-16 h-16 rounded-2xl bg-rose-500/15 text-rose-300 flex items-center justify-center"><?= icon('x-circle', 'w-8 h-8') ?></div>
        <h1 class="mt-5 text-xl font-bold text-white"><?= $errorCode === 'unavailable' ? 'Something went wrong' : 'Payment link not found' ?></h1>
        <p class="mt-2 text-sm text-slate-400"><?= $errorCode === 'unavailable' ? 'We could not load this payment right now. Please try again in a moment.' : 'This link does not exist or was mistyped. Check the URL and try again.' ?></p>
    </div>
<?php else: ?>

    <!-- Payment card -->
    <div class="glass !bg-slate-900/70 !border-white/10 rounded-3xl shadow-2xl overflow-hidden">

        <!-- Amount header -->
        <div class="relative px-6 sm:px-8 pt-7 pb-6 text-center border-b border-white/5">
            <div class="absolute top-4 right-4 inline-flex items-center gap-1.5 text-[10px] font-bold text-emerald-300 bg-emerald-400/10 border border-emerald-400/20 rounded-full px-2.5 py-1"><?= icon('lock', 'w-3 h-3') ?> SECURE</div>
            <p class="text-[10px] uppercase tracking-[.22em] text-slate-400 font-semibold">You are paying</p>
            <h1 class="mt-1 text-lg font-bold text-white"><?= e($merchantName) ?></h1>
            <?php if ($customerName !== ''): ?><p class="text-[11px] text-slate-400 mt-0.5">Customer: <span class="text-slate-200"><?= e($customerName) ?></span></p><?php endif; ?>
            <p class="mt-4 text-5xl font-black tracking-tight text-white"><span class="text-2xl align-top text-slate-400">₹</span><?= e(number_format($amount, 2)) ?></p>
            <?php if ($note !== ''): ?><p class="mt-2 text-xs text-slate-400 bg-white/5 rounded-lg inline-block px-3 py-1.5"><?= e($note) ?></p><?php endif; ?>
        </div>

<?php if ($status === 'success'): ?>
        <!-- SUCCESS state -->
        <div class="px-6 sm:px-8 py-10 text-center">
            <div class="mx-auto w-24 h-24 rounded-full bg-emerald-500/15 pulse-ring flex items-center justify-center">
                <svg class="w-16 h-16" viewBox="0 0 72 72" fill="none">
                    <circle class="draw-circle" cx="36" cy="36" r="32" stroke="#34d399" stroke-width="4" stroke-linecap="round"/>
                    <path class="draw-check" d="M23 37.5 32 46.5 50 27" stroke="#34d399" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <h2 class="mt-6 text-2xl font-extrabold text-white">Payment Successful</h2>
            <p class="mt-1 text-sm text-slate-400">Thank you! <?= e($merchantName) ?> has been notified.</p>
            <div class="mt-5 inline-flex flex-col items-center gap-1 bg-white/5 border border-white/10 rounded-2xl px-6 py-3">
                <span class="text-[10px] uppercase tracking-[.2em] text-slate-500 font-semibold">UTR Number</span>
                <span class="font-mono text-sm text-emerald-300"><?= e($utr !== '' ? $utr : 'N/A') ?></span>
            </div>
            <p class="mt-5 text-[10px] text-slate-600">Powered by CashPe &middot; <?= e($linkId) ?></p>
        </div>

<?php elseif ($status === 'expired'): ?>
        <!-- EXPIRED state -->
        <div class="px-6 sm:px-8 py-10 text-center">
            <div class="mx-auto w-20 h-20 rounded-2xl bg-amber-400/10 text-amber-300 flex items-center justify-center"><?= icon('clock', 'w-10 h-10') ?></div>
            <h2 class="mt-5 text-xl font-extrabold text-white">Link expired</h2>
            <p class="mt-1 text-sm text-slate-400">Payment links are valid for 30 minutes. Ask <?= e($merchantName) ?> to share a fresh link.</p>
        </div>

<?php elseif ($status === 'failed'): ?>
        <!-- FAILED state -->
        <div class="px-6 sm:px-8 py-10 text-center">
            <div class="mx-auto w-20 h-20 rounded-2xl bg-rose-500/10 text-rose-300 flex items-center justify-center"><?= icon('x-circle', 'w-10 h-10') ?></div>
            <h2 class="mt-5 text-xl font-extrabold text-white">Payment failed</h2>
            <p class="mt-1 text-sm text-slate-400">The payment did not go through. Please try again or contact <?= e($merchantName) ?>.</p>
            <button onclick="window.location.reload()" class="btn-primary mt-6"><?= icon('refresh', 'w-4 h-4') ?> Try again</button>
        </div>

<?php else: ?>
        <!-- PENDING state -->
        <div class="px-6 sm:px-8 py-6" id="payPending">
            <!-- Timer -->
            <p class="text-center text-[11px] text-amber-300/90 font-semibold flex items-center justify-center gap-1.5"><?= icon('clock', 'w-4 h-4') ?> Link expires in <span id="payTimer" class="font-mono">30:00</span></p>

            <!-- QR -->
            <div class="mt-4 mx-auto w-fit rounded-3xl bg-white p-4 shadow-[0_0_50px_-10px_rgba(139,92,246,.55)]">
                <?php if ($qrSvg !== null): ?>
                    <div class="w-44 h-44 sm:w-48 sm:h-48"><?= $qrSvg ?></div>
                <?php else: ?>
                    <div class="w-44 h-44 sm:w-48 sm:h-48 flex flex-col items-center justify-center text-center text-slate-500 px-3">
                        <?= icon('qr', 'w-10 h-10') ?>
                        <p class="mt-2 text-[10px] font-semibold">QR unavailable</p>
                        <p class="text-[9px]">Use the button below instead</p>
                    </div>
                <?php endif; ?>
            </div>
            <p class="mt-3 text-center text-[11px] text-slate-400">Scan with any UPI app &middot; <span class="font-mono text-slate-300"><?= e($upiId) ?></span></p>

            <!-- Open UPI app -->
            <a href="<?= e($upiUri) ?>" class="btn-primary w-full mt-5 !py-4 !text-base"><?= icon('zap', 'w-5 h-5') ?> Open UPI App</a>
            <div class="grid grid-cols-3 gap-2 mt-2">
                <a href="<?= e($gpayUri) ?>" class="text-[10px] font-semibold text-center rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 transition px-2 py-2 text-slate-300">Google Pay</a>
                <a href="<?= e($phonepeUri) ?>" class="text-[10px] font-semibold text-center rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 transition px-2 py-2 text-slate-300">PhonePe</a>
                <a href="<?= e($paytmUri) ?>" class="text-[10px] font-semibold text-center rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 transition px-2 py-2 text-slate-300">Paytm</a>
            </div>

            <!-- Waiting indicator -->
            <div class="mt-5 flex items-center justify-center gap-2 text-[11px] text-slate-500" id="payWaiting">
                <span class="w-4 h-4 rounded-full border-2 border-violet-400/40 border-t-violet-300 spin-slow"></span>
                Waiting for payment&hellip; auto-verifying every 3 seconds
            </div>
        </div>

        <!-- Success overlay (hidden until poll succeeds) -->
        <div id="paySuccess" class="hidden px-6 sm:px-8 py-10 text-center">
            <div class="mx-auto w-24 h-24 rounded-full bg-emerald-500/15 pulse-ring flex items-center justify-center">
                <svg class="w-16 h-16" viewBox="0 0 72 72" fill="none">
                    <circle class="draw-circle" cx="36" cy="36" r="32" stroke="#34d399" stroke-width="4" stroke-linecap="round"/>
                    <path class="draw-check" d="M23 37.5 32 46.5 50 27" stroke="#34d399" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <h2 class="mt-6 text-2xl font-extrabold text-white">Payment Successful</h2>
            <div class="mt-5 inline-flex flex-col items-center gap-1 bg-white/5 border border-white/10 rounded-2xl px-6 py-3">
                <span class="text-[10px] uppercase tracking-[.2em] text-slate-500 font-semibold">UTR Number</span>
                <span class="font-mono text-sm text-emerald-300" id="payUtr">-</span>
            </div>
        </div>

        <!-- Expired overlay (hidden until poll reports expiry) -->
        <div id="payExpired" class="hidden px-6 sm:px-8 py-10 text-center">
            <div class="mx-auto w-20 h-20 rounded-2xl bg-amber-400/10 text-amber-300 flex items-center justify-center"><?= icon('clock', 'w-10 h-10') ?></div>
            <h2 class="mt-5 text-xl font-extrabold text-white">Link expired</h2>
            <p class="mt-1 text-sm text-slate-400">This link is no longer valid. Ask <?= e($merchantName) ?> to share a new one.</p>
        </div>
<?php endif; ?>
    </div>

    <p class="text-center text-[10px] text-slate-600 mt-5">Powered by <span class="font-semibold text-slate-400">CashPe</span> &middot; <?= e($linkId) ?> &middot; <?= e(format_date($createdAt)) ?></p>
<?php endif; ?>
</div>

<?php if ($errorCode === null && $status === 'pending'): ?>
<script>
(function () {
    var linkId = <?= json_encode($linkId) ?>;
    var createdAt = <?= json_encode($createdAt) ?>;
    var pending = document.getElementById('payPending');
    var success = document.getElementById('paySuccess');
    var expired = document.getElementById('payExpired');
    var utrEl = document.getElementById('payUtr');
    var timerEl = document.getElementById('payTimer');
    var expiresAt = (createdAt + 1800) * 1000;

    function showSuccess(utr) {
        if (pending) pending.classList.add('hidden');
        if (success) {
            utrEl.textContent = utr || 'Verified';
            success.classList.remove('hidden');
        }
    }
    function showExpired() {
        if (pending) pending.classList.add('hidden');
        if (expired) expired.classList.remove('hidden');
    }
    function tick() {
        var remain = Math.max(0, Math.floor((expiresAt - Date.now()) / 1000));
        var m = String(Math.floor(remain / 60)).padStart(2, '0');
        var s = String(remain % 60).padStart(2, '0');
        if (timerEl) timerEl.textContent = m + ':' + s;
        if (remain === 0) showExpired();
    }
    setInterval(tick, 1000);
    tick();

    function poll() {
        fetch('check_status.php?id=' + encodeURIComponent(linkId), { headers: { 'Accept': 'application/json' }, cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.status) return;
                if (data.status === 'success') { showSuccess(data.utr); }
                else if (data.status === 'expired') { showExpired(); }
            })
            .catch(function () { /* transient network error - keep polling */ });
    }
    setInterval(poll, 3000);
    poll();
})();
</script>
<?php endif; ?>
</body>
</html>
