<?php
/**
 * CashPe - Admin panel (SINGLE FILE).
 * Tabs: dashboard | users | links | transactions | support | requests | settings
 * Auth: ADMIN_EMAIL / ADMIN_PASSWORD from .env, session guarded.
 */
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/firebase_helper.php';
require_once dirname(__DIR__) . '/includes/wallet_helper.php';
require_once dirname(__DIR__) . '/includes/icons.php';

$MAX_LINK_SCAN = 2000;
$flash = flash_get();
$error = null;
$info = null;
if ($flash) {
    $flash['type'] === 'error' ? $error = $flash['message'] : $info = $flash['message'];
}

// ---------------------------------------------------------------------------
// Login screen (standalone)
// ---------------------------------------------------------------------------
if (!admin_is_logged_in()) {
    $loginError = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'admin_login') {
        csrf_validate();
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if (admin_login($email, $password)) {
            redirect('admin.php');
        }
        $loginError = 'Invalid admin credentials.';
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin - <?= e(APP_NAME) ?></title>
<meta name="robots" content="noindex">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%236d28d9'/%3E%3Cpath d='M36 8 L16 36 H30 L26 56 L46 27 H33 Z' fill='%23fff'/%3E%3C/svg%3E">
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased flex items-center justify-center p-6">
<div aria-hidden="true" class="fixed inset-0 pointer-events-none">
    <div class="absolute -top-32 left-1/3 w-[34rem] h-[34rem] rounded-full" style="background:radial-gradient(circle, rgba(139,92,246,.3) 0%, transparent 65%)"></div>
</div>
<div class="relative glass !bg-slate-900/70 !border-white/10 rounded-3xl max-w-md w-full p-8 shadow-2xl">
    <div class="flex items-center gap-3"><?= cashpe_logo('w-11 h-11', 'w-6 h-6') ?><div><p class="text-xl font-extrabold text-white">CashPe Admin</p><p class="text-[10px] uppercase tracking-[.2em] text-slate-400 font-semibold">Restricted Area</p></div></div>
    <?php if ($loginError): ?><div class="flash flash-error mt-5 flex items-start gap-2"><?= icon('alert-triangle', 'w-5 h-5 shrink-0') ?><span><?= e($loginError) ?></span></div><?php endif; ?>
    <form method="POST" action="<?= e(url('admin.php')) ?>" class="mt-6 space-y-4">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="admin_login">
        <div>
            <label class="label !text-slate-400" for="email">Admin email</label>
            <input class="input" type="email" id="email" name="email" required autocomplete="username" placeholder="admin@example.com">
        </div>
        <div>
            <label class="label !text-slate-400" for="password">Password</label>
            <input class="input" type="password" id="password" name="password" required autocomplete="current-password" placeholder="Admin password">
        </div>
        <button type="submit" class="btn-primary w-full !py-3"><?= icon('lock', 'w-5 h-5') ?> Sign in to Admin</button>
    </form>
    <p class="mt-5 text-center text-[11px] text-slate-500">Credentials are set via the <code class="font-mono">ADMIN_EMAIL</code> and <code class="font-mono">ADMIN_PASSWORD</code> environment variables.</p>
</div>
</body>
</html>
    <?php
    exit;
}

// ---------------------------------------------------------------------------
// Logout
// ---------------------------------------------------------------------------
if (($_GET['action'] ?? '') === 'logout') {
    admin_logout();
    redirect('admin.php');
}

$tab = (string) ($_GET['tab'] ?? 'dashboard');
$allowedTabs = ['dashboard', 'users', 'links', 'transactions', 'support', 'requests', 'settings'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'dashboard';
}

// ===========================================================================
// JSON poll for support threads (admin side)
// ===========================================================================
if ($tab === 'support' && ($_GET['action'] ?? '') === 'poll') {
    header('Cache-Control: no-store');
    $chatUid = (string) ($_GET['chat'] ?? '');
    $since = (int) ($_GET['since'] ?? 0);
    if ($chatUid === '') {
        json_response(['messages' => []], 400);
    }
    try {
        $messages = fs_query('support_chats/' . $chatUid . '/messages', [], 200, 'created_at', 'asc');
        $fresh = array_values(array_filter($messages, fn($m) => (int) ($m['created_at'] ?? 0) > $since));
        fs_set('support_chats', $chatUid, ['unread_admin' => 0], true);
        $out = array_map(fn($m) => ['sender' => $m['sender'] ?? 'user', 'text' => $m['text'] ?? '', 'image' => $m['image'] ?? null, 'created_at' => (int) ($m['created_at'] ?? 0)], $fresh);
        json_response(['messages' => $out, 'server_time' => time()]);
    } catch (Throwable $e) {
        json_response(['messages' => [], 'error' => $e->getMessage()], 500);
    }
}

// ===========================================================================
// POST handlers
// ===========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $action = (string) ($_POST['action'] ?? '');

    // --- Users: credit / debit wallet ---
    if ($action === 'wallet_adjust') {
        $targetUid = (string) ($_POST['uid'] ?? '');
        $direction = (string) ($_POST['direction'] ?? 'credit');
        $amount = (float) ($_POST['amount'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($targetUid === '' || $amount <= 0 || $amount > 1000000) {
            flash_set('error', 'Enter a valid amount.');
        } else {
            try {
                if ($direction === 'credit') {
                    wallet_credit($targetUid, $amount, 'admin_credit', 'Admin credit: ' . ($reason !== '' ? $reason : 'Manual adjustment'), 'admin');
                    flash_set('success', 'Wallet credited with ' . format_money($amount) . '.');
                } else {
                    wallet_debit($targetUid, $amount, 'admin_debit', 'Admin debit: ' . ($reason !== '' ? $reason : 'Manual adjustment'), 'admin', false);
                    flash_set('success', 'Wallet debited by ' . format_money($amount) . '.');
                }
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage());
            }
        }
        redirect('admin.php?tab=users');
    }

    // --- Users: block / unblock ---
    if ($action === 'set_status') {
        $targetUid = (string) ($_POST['uid'] ?? '');
        $status = (string) ($_POST['status'] ?? 'active');
        $status = $status === 'blocked' ? 'blocked' : 'active';
        try {
            user_update($targetUid, ['status' => $status]);
            flash_set('success', $status === 'blocked' ? 'User blocked.' : 'User unblocked.');
        } catch (Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        redirect('admin.php?tab=users');
    }

    // --- Support: admin reply ---
    if ($action === 'support_reply') {
        $chatUid = (string) ($_POST['chat_uid'] ?? '');
        $text = trim((string) ($_POST['text'] ?? ''));
        if ($chatUid !== '' && $text !== '') {
            try {
                fs_add('support_chats/' . $chatUid . '/messages', [
                    'sender' => 'admin',
                    'text' => mb_substr($text, 0, 2000),
                    'image' => null,
                    'created_at' => time(),
                ]);
                $chat = fs_get('support_chats', $chatUid);
                fs_set('support_chats', $chatUid, [
                    'last_message' => mb_substr($text, 0, 100),
                    'last_at' => time(),
                    'unread_user' => (int) ($chat['unread_user'] ?? 0) + 1,
                    'unread_admin' => 0,
                ], true);
                flash_set('success', 'Reply sent.');
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage());
            }
        }
        redirect('admin.php?tab=support&chat=' . urlencode($chatUid));
    }

    // --- Wallet requests: approve / reject ---
    if ($action === 'request_approve' || $action === 'request_reject') {
        $requestId = (string) ($_POST['request_id'] ?? '');
        try {
            $req = fs_get('wallet_requests', $requestId);
            if (!$req) {
                flash_set('error', 'Request not found.');
            } elseif (($req['status'] ?? '') !== 'pending') {
                flash_set('error', 'Request already processed.');
            } elseif ($action === 'request_approve') {
                $targetUid = (string) ($req['uid'] ?? '');
                wallet_credit($targetUid, (float) ($req['amount'] ?? 0), 'topup', 'Wallet top-up approved (request ' . substr($requestId, 0, 8) . ')', $requestId);
                fs_update('wallet_requests', $requestId, ['status' => 'approved', 'processed_at' => time()]);
                flash_set('success', 'Top-up of ' . format_money($req['amount'] ?? 0) . ' approved and credited.');
            } else {
                fs_update('wallet_requests', $requestId, ['status' => 'rejected', 'processed_at' => time()]);
                flash_set('success', 'Request rejected.');
            }
        } catch (Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        redirect('admin.php?tab=requests');
    }

    // --- Settings ---
    if ($action === 'save_settings') {
        $commission = (float) ($_POST['commission_percent'] ?? 2);
        $commission = max(0.1, min(50.0, $commission));
        $settings = [
            'commission_percent' => $commission,
            'smtp_host' => trim((string) ($_POST['smtp_host'] ?? '')),
            'smtp_port' => (int) ($_POST['smtp_port'] ?? 587),
            'smtp_user' => trim((string) ($_POST['smtp_user'] ?? '')),
            'smtp_from_email' => trim((string) ($_POST['smtp_from_email'] ?? '')),
            'smtp_from_name' => trim((string) ($_POST['smtp_from_name'] ?? 'CashPe')),
            'provider_phonepe_personal_base' => trim((string) ($_POST['provider_phonepe_personal_base'] ?? '')),
            'provider_paytm_personal_base' => trim((string) ($_POST['provider_paytm_personal_base'] ?? '')),
            'provider_navi_personal_base' => trim((string) ($_POST['provider_navi_personal_base'] ?? '')),
        ];
        $smtpPass = (string) ($_POST['smtp_pass'] ?? '');
        if ($smtpPass !== '') {
            $settings['smtp_pass'] = $smtpPass;
        }
        try {
            fs_set('settings', 'app', $settings, true);
            flash_set('success', 'Settings saved.');
        } catch (Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        redirect('admin.php?tab=settings');
    }
}

// ===========================================================================
// Data loading per tab
// ===========================================================================
$dbError = null;

// Global CSV export (Transactions tab)
if ($tab === 'transactions' && ($_GET['export'] ?? '') === 'csv') {
    try {
        $successLinks = fs_query('payment_links', [['status', '==', 'success']], $MAX_LINK_SCAN, 'created_at', 'desc');
    } catch (Throwable $e) {
        $successLinks = [];
    }
    $filename = 'cashpe-all-transactions-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Link ID', 'UID', 'Amount', 'Commission', 'Customer', 'Email', 'Phone', 'Provider', 'UPI ID', 'UTR', 'Created At']);
    foreach ($successLinks as $l) {
        fputcsv($out, [
            $l['link_id'] ?? '', $l['uid'] ?? '',
            number_format((float) ($l['amount'] ?? 0), 2, '.', ''),
            number_format((float) ($l['commission'] ?? 0), 2, '.', ''),
            $l['customer_name'] ?? '', $l['customer_email'] ?? '', $l['customer_phone'] ?? '',
            $l['provider'] ?? '', $l['upi_id'] ?? '', $l['utr'] ?? '',
            format_date($l['created_at'] ?? 0),
        ]);
    }
    fclose($out);
    exit;
}

// Dashboard data
$dashUsers = 0;
$dashVolume = 0.0;
$dashCommission = 0.0;
$dashActiveLinks = 0;
$dashSuccessLinks = [];
$chartData = array_fill(0, 7, 0.0);

if ($tab === 'dashboard') {
    try {
        $allUsers = fs_query('users');
        $dashUsers = count($allUsers);
        $successLinks = fs_query('payment_links', [['status', '==', 'success']], $MAX_LINK_SCAN);
        $dashSuccessLinks = $successLinks;
        $pendingLinks = fs_query('payment_links', [['status', '==', 'pending']], $MAX_LINK_SCAN);
        $dashActiveLinks = count($pendingLinks);
        $todayStart = strtotime('today midnight');
        foreach ($successLinks as $l) {
            $dashVolume += (float) ($l['amount'] ?? 0);
            $dashCommission += (float) ($l['commission'] ?? 0);
            $created = (int) ($l['created_at'] ?? 0);
            for ($i = 0; $i < 7; $i++) {
                $dayStart = strtotime('-' . (6 - $i) . ' days', $todayStart);
                $dayEnd = $dayStart + 86399;
                if ($created >= $dayStart && $created <= $dayEnd) {
                    $chartData[$i] += (float) ($l['amount'] ?? 0);
                }
            }
        }
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

// Users data
$usersList = [];
$userSearch = trim((string) ($_GET['q'] ?? ''));
if ($tab === 'users') {
    try {
        $all = fs_query('users', [], null, 'created_at', 'desc');
        $q = mb_strtolower($userSearch);
        foreach ($all as $u) {
            if ($q === '' || str_contains(mb_strtolower((string) ($u['name'] ?? '')), $q) || str_contains(mb_strtolower((string) ($u['email'] ?? '')), $q)) {
                $usersList[] = $u;
            }
        }
        $usersList = array_slice($usersList, 0, 200);
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

// Links data
$adminLinks = [];
$linkStatusFilter = trim((string) ($_GET['status'] ?? ''));
if ($tab === 'links') {
    try {
        $where = $linkStatusFilter !== '' ? [['status', '==', $linkStatusFilter]] : [];
        $adminLinks = fs_query('payment_links', $where, 100, 'created_at', 'desc');
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

// Transactions data
$adminTransactions = [];
if ($tab === 'transactions') {
    try {
        $adminTransactions = fs_query('payment_links', [['status', '==', 'success']], 200, 'created_at', 'desc');
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

// Support data
$chatList = [];
$activeChat = null;
$activeChatMessages = [];
$chatWith = trim((string) ($_GET['chat'] ?? ''));
if ($tab === 'support') {
    try {
        if ($chatWith !== '') {
            $activeChat = fs_get('support_chats', $chatWith);
            $activeChatMessages = fs_query('support_chats/' . $chatWith . '/messages', [], 100, 'created_at', 'asc');
            fs_set('support_chats', $chatWith, ['unread_admin' => 0], true);
        } else {
            $chatList = fs_query('support_chats', [], 100, 'last_at', 'desc');
            usort($chatList, function ($a, $b) {
                $ua = (int) ($a['unread_admin'] ?? 0) > 0 ? 1 : 0;
                $ub = (int) ($b['unread_admin'] ?? 0) > 0 ? 1 : 0;
                if ($ua !== $ub) return $ub <=> $ua;
                return (int) ($b['last_at'] ?? 0) <=> (int) ($a['last_at'] ?? 0);
            });
        }
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

// Requests data
$pendingRequests = [];
$processedRequests = [];
if ($tab === 'requests') {
    try {
        $pendingRequests = fs_query('wallet_requests', [['status', '==', 'pending']], 100, 'created_at', 'asc');
        $processedRequests = array_slice(array_merge(
            fs_query('wallet_requests', [['status', '==', 'approved']], 50, 'processed_at', 'desc'),
            fs_query('wallet_requests', [['status', '==', 'rejected']], 50, 'processed_at', 'desc')
        ), 0, 30);
        usort($processedRequests, fn($a, $b) => (int) ($b['processed_at'] ?? 0) <=> (int) ($a['processed_at'] ?? 0));
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

// Settings data
$currentSettings = app_settings();

$navItems = [
    'dashboard' => ['label' => 'Dashboard', 'icon' => 'layout-grid'],
    'users' => ['label' => 'Users', 'icon' => 'users'],
    'links' => ['label' => 'Payment Links', 'icon' => 'link'],
    'transactions' => ['label' => 'Transactions', 'icon' => 'bar-chart'],
    'support' => ['label' => 'Support Chats', 'icon' => 'headset'],
    'requests' => ['label' => 'Wallet Requests', 'icon' => 'wallet'],
    'settings' => ['label' => 'Settings', 'icon' => 'settings'],
];

// Chart geometry (7 points)
$chartMax = max($chartData) > 0 ? max($chartData) : 1;
$chartPoints = [];
foreach ($chartData as $i => $value) {
    $x = 10 + $i * (560 / 6);
    $y = 120 - (($value / $chartMax) * 100);
    $chartPoints[] = round($x, 1) . ',' . round($y, 1);
}
$chartPolyline = implode(' ', $chartPoints);
$chartArea = '10,120 ' . $chartPolyline . ' 570,120';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin - <?= e(APP_NAME) ?></title>
<meta name="robots" content="noindex">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%236d28d9'/%3E%3Cpath d='M36 8 L16 36 H30 L26 56 L46 27 H33 Z' fill='%23fff'/%3E%3C/svg%3E">
<script>try{if(localStorage.getItem('cashpe-theme')==='dark'){document.documentElement.classList.add('dark');}}catch(e){}</script>
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={darkMode:'class',theme:{extend:{fontFamily:{sans:['Inter','system-ui','sans-serif']}}}};</script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="min-h-screen bg-slate-100 dark:bg-slate-950 text-slate-800 dark:text-slate-100 antialiased">

<!-- Top bar -->
<header class="sticky top-0 z-30 backdrop-blur-xl bg-white/80 dark:bg-slate-900/80 border-b border-slate-200/70 dark:border-slate-800/70">
    <div class="max-w-[1500px] mx-auto h-16 px-4 sm:px-6 flex items-center justify-between gap-4">
        <a href="<?= e(url('admin.php')) ?>" class="flex items-center gap-3"><?= cashpe_logo('w-9 h-9', 'w-5 h-5') ?><span class="leading-tight"><span class="block font-extrabold gradient-text">CashPe Admin</span><span class="block text-[9px] uppercase tracking-[.2em] text-slate-400 font-semibold">Control Panel</span></span></a>
        <div class="flex items-center gap-2">
            <button id="darkToggle" class="icon-btn" aria-label="Toggle dark mode">
                <span class="dark:hidden"><?= icon('moon', 'w-5 h-5') ?></span>
                <span class="hidden dark:inline"><?= icon('sun', 'w-5 h-5') ?></span>
            </button>
            <a href="<?= e(url('admin.php?action=logout')) ?>" class="btn-secondary !py-2 text-xs"><?= icon('logout', 'w-4 h-4') ?> Logout</a>
        </div>
    </div>
    <!-- Tabs -->
    <nav class="max-w-[1500px] mx-auto px-4 sm:px-6 pb-3 flex gap-2 overflow-x-auto">
        <?php foreach ($navItems as $key => $item): $active = $tab === $key; ?>
        <a href="<?= e(url('admin.php?tab=' . $key)) ?>" class="flex items-center gap-2 whitespace-nowrap rounded-xl px-3.5 py-2 text-xs font-semibold border transition <?= $active ? 'gradient-bg text-white border-transparent shadow-glow' : 'bg-white dark:bg-slate-800/60 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-700/60 hover:text-violet-600 dark:hover:text-violet-300' ?>">
            <?= icon($item['icon'], 'w-4 h-4') ?> <?= e($item['label']) ?>
        </a>
        <?php endforeach; ?>
    </nav>
</header>

<main class="max-w-[1500px] mx-auto p-4 sm:p-6">

<?php if ($dbError): ?><div class="flash flash-error mb-4 flex items-start gap-2"><?= icon('alert-triangle', 'w-5 h-5 shrink-0') ?><span><?= e($dbError) ?></span></div><?php endif; ?>
<?php if ($error): ?><div class="flash flash-error mb-4"><?= e($error) ?></div><?php endif; ?>
<?php if ($info): ?><div class="flash flash-success mb-4"><?= e($info) ?></div><?php endif; ?>

<?php /* ==================== TAB 1: DASHBOARD ==================== */ ?>
<?php if ($tab === 'dashboard'): ?>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
    <div class="card p-4 sm:p-5"><span class="w-10 h-10 rounded-xl bg-indigo-100 dark:bg-indigo-500/15 text-indigo-600 dark:text-indigo-300 flex items-center justify-center"><?= icon('users', 'w-5 h-5') ?></span><p class="mt-3 text-[10px] font-bold uppercase tracking-[.16em] text-slate-400">Total Users</p><p class="mt-1 text-2xl font-extrabold"><?= (int) $dashUsers ?></p></div>
    <div class="card p-4 sm:p-5"><span class="w-10 h-10 rounded-xl bg-emerald-100 dark:bg-emerald-500/15 text-emerald-600 dark:text-emerald-300 flex items-center justify-center"><?= icon('banknote', 'w-5 h-5') ?></span><p class="mt-3 text-[10px] font-bold uppercase tracking-[.16em] text-slate-400">Volume Processed</p><p class="mt-1 text-2xl font-extrabold"><?= e(format_money($dashVolume)) ?></p></div>
    <div class="card p-4 sm:p-5"><span class="w-10 h-10 rounded-xl bg-fuchsia-100 dark:bg-fuchsia-500/15 text-fuchsia-600 dark:text-fuchsia-300 flex items-center justify-center"><?= icon('zap', 'w-5 h-5') ?></span><p class="mt-3 text-[10px] font-bold uppercase tracking-[.16em] text-slate-400">Commission Earned</p><p class="mt-1 text-2xl font-extrabold"><?= e(format_money($dashCommission)) ?></p></div>
    <div class="card p-4 sm:p-5"><span class="w-10 h-10 rounded-xl bg-amber-100 dark:bg-amber-500/15 text-amber-600 dark:text-amber-300 flex items-center justify-center"><?= icon('link', 'w-5 h-5') ?></span><p class="mt-3 text-[10px] font-bold uppercase tracking-[.16em] text-slate-400">Active Links</p><p class="mt-1 text-2xl font-extrabold"><?= (int) $dashActiveLinks ?></p></div>
</div>

<div class="grid lg:grid-cols-3 gap-6 mt-6">
    <div class="card p-5 sm:p-6 lg:col-span-2">
        <div class="flex items-center justify-between"><h3 class="font-bold flex items-center gap-2"><?= icon('trending-up', 'w-5 h-5 text-violet-500') ?> Volume - Last 7 Days</h3><span class="text-xs text-slate-400"><?= e(format_money(array_sum($chartData))) ?> total</span></div>
        <svg viewBox="0 0 580 140" class="w-full mt-4" role="img" aria-label="Volume chart for the last 7 days">
            <defs><linearGradient id="areaGrad" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#8b5cf6" stop-opacity=".45"/><stop offset="1" stop-color="#8b5cf6" stop-opacity="0"/></linearGradient></defs>
            <line x1="10" y1="120" x2="570" y2="120" stroke="currentColor" stroke-opacity=".15"/>
            <polygon points="<?= e($chartArea) ?>" fill="url(#areaGrad)"/>
            <polyline points="<?= e($chartPolyline) ?>" fill="none" stroke="#8b5cf6" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
            <?php foreach ($chartData as $i => $value): $x = 10 + $i * (560 / 6); $y = 120 - (($value / $chartMax) * 100); ?>
            <circle cx="<?= round($x, 1) ?>" cy="<?= round($y, 1) ?>" r="3" fill="#a78bfa"/>
            <?php endforeach; ?>
            <?php for ($i = 0; $i < 7; $i++): $x = 10 + $i * (560 / 6); $label = date('d M', strtotime('-' . (6 - $i) . ' days')); ?>
            <text x="<?= round($x, 1) ?>" y="136" text-anchor="middle" font-size="9" fill="currentColor" fill-opacity=".55"><?= e($label) ?></text>
            <?php endfor; ?>
        </svg>
    </div>

    <div class="card p-5 sm:p-6">
        <h3 class="font-bold flex items-center gap-2"><?= icon('receipt', 'w-5 h-5 text-emerald-500') ?> Latest Paid</h3>
        <?php $latestPaid = array_slice($dashSuccessLinks, 0, 5); usort($latestPaid, fn($a, $b) => (int) ($b['paid_at'] ?? 0) <=> (int) ($a['paid_at'] ?? 0)); $latestPaid = array_slice($latestPaid, 0, 5); ?>
        <?php if (empty($latestPaid)): ?>
        <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">No successful payments yet.</p>
        <?php else: ?>
        <ul class="mt-3 divide-y divide-slate-100 dark:divide-slate-800">
            <?php foreach ($latestPaid as $l): ?>
            <li class="py-2.5 flex items-center justify-between gap-3">
                <span class="min-w-0"><span class="block text-sm font-semibold truncate"><?= e($l['customer_name'] ?? 'Customer') ?></span><span class="block text-[10px] text-slate-400 font-mono"><?= e($l['utr'] ?? '-') ?></span></span>
                <span class="text-sm font-bold text-emerald-600 dark:text-emerald-400 shrink-0">+<?= e(format_money($l['amount'] ?? 0)) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php /* ==================== TAB 2: USERS ==================== */ ?>
<?php if ($tab === 'users'): ?>
<form method="GET" action="<?= e(url('admin.php')) ?>" class="flex gap-2 max-w-xl">
    <input type="hidden" name="tab" value="users">
    <div class="relative flex-1">
        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"><?= icon('search', 'w-5 h-5') ?></span>
        <input class="input !pl-10" type="text" name="q" value="<?= e($userSearch) ?>" placeholder="Search by name or email...">
    </div>
    <button type="submit" class="btn-secondary"><?= icon('search', 'w-4 h-4') ?> Search</button>
</form>

<div class="card overflow-hidden mt-4">
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Name</th><th>Email</th><th>Wallet</th><th>Status</th><th>Joined</th><th class="text-right">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($usersList as $u): $uidRow = (string) ($u['uid'] ?? $u['id']); $blocked = ($u['status'] ?? 'active') === 'blocked'; ?>
            <tr>
                <td class="font-semibold"><?= e($u['name'] ?? 'Unnamed') ?></td>
                <td class="text-xs"><?= e($u['email'] ?? '') ?></td>
                <td class="font-mono text-xs"><?= e(format_money($u['wallet_balance'] ?? 0)) ?></td>
                <td><span class="badge <?= $blocked ? 'badge-blocked' : 'badge-success' ?>"><?= $blocked ? 'Blocked' : 'Active' ?></span></td>
                <td class="text-xs text-slate-500 dark:text-slate-400"><?= e(format_date($u['created_at'] ?? 0)) ?></td>
                <td>
                    <div class="flex items-center gap-1.5 justify-end">
                        <button type="button" class="icon-btn !w-8 !h-8 text-emerald-600 dark:text-emerald-400" title="Credit wallet" aria-label="Credit wallet" data-wallet-adjust="credit" data-uid="<?= e($uidRow) ?>" data-name="<?= e($u['name'] ?? '') ?>"><?= icon('plus', 'w-5 h-5') ?></button>
                        <button type="button" class="icon-btn !w-8 !h-8 text-rose-600 dark:text-rose-400" title="Debit wallet" aria-label="Debit wallet" data-wallet-adjust="debit" data-uid="<?= e($uidRow) ?>" data-name="<?= e($u['name'] ?? '') ?>"><?= icon('banknote', 'w-5 h-5') ?></button>
                        <form method="POST" action="<?= e(url('admin.php?tab=users')) ?>" data-confirm="<?= $blocked ? 'Unblock this user?' : 'Block this user? They will not be able to sign in.' ?>" data-confirm-title="<?= $blocked ? 'Unblock user' : 'Block user' ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="set_status">
                            <input type="hidden" name="uid" value="<?= e($uidRow) ?>">
                            <input type="hidden" name="status" value="<?= $blocked ? 'active' : 'blocked' ?>">
                            <button type="submit" class="icon-btn !w-8 !h-8" title="<?= $blocked ? 'Unblock' : 'Block' ?>" aria-label="<?= $blocked ? 'Unblock' : 'Block' ?>"><?= icon($blocked ? 'unlock' : 'ban', 'w-5 h-5') ?></button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($usersList)): ?><tr><td colspan="6" class="text-center py-8 text-slate-400">No users found<?= $userSearch !== '' ? ' for "' . e($userSearch) . '"' : '' ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Credit/Debit modal -->
<div id="walletAdjustModal" class="fixed inset-0 z-[80] hidden items-center justify-center" role="dialog" aria-modal="true">
    <div class="absolute inset-0 bg-slate-950/60 backdrop-blur-sm" data-close-adjust></div>
    <div class="relative glass rounded-3xl max-w-md w-full mx-4 p-6 shadow-2xl animate-pop">
        <div class="flex items-center justify-between">
            <h3 class="font-extrabold flex items-center gap-2" id="walletAdjustTitle"><?= icon('wallet', 'w-5 h-5 text-violet-500') ?> Adjust wallet</h3>
            <button type="button" class="icon-btn" data-close-adjust aria-label="Close"><?= icon('x', 'w-6 h-6') ?></button>
        </div>
        <form method="POST" action="<?= e(url('admin.php?tab=users')) ?>" class="mt-4 space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="wallet_adjust">
            <input type="hidden" name="uid" id="adjustUid">
            <input type="hidden" name="direction" id="adjustDirection" value="credit">
            <p class="text-sm text-slate-500 dark:text-slate-400">Adjusting wallet of <strong id="adjustName" class="text-slate-800 dark:text-slate-100">user</strong></p>
            <div><label class="label" for="adjustAmount">Amount (₹)</label><input class="input text-xl font-extrabold" type="number" id="adjustAmount" name="amount" min="1" max="1000000" step="0.01" required placeholder="100"></div>
            <div><label class="label" for="adjustReason">Reason</label><input class="input" type="text" id="adjustReason" name="reason" maxlength="200" placeholder="Refund / penalty / goodwill credit"></div>
            <button type="submit" class="btn-primary w-full" id="adjustSubmit">Apply adjustment</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php /* ==================== TAB 3: PAYMENT LINKS ==================== */ ?>
<?php if ($tab === 'links'): ?>
<form method="GET" action="<?= e(url('admin.php')) ?>" class="flex gap-2 flex-wrap items-end">
    <input type="hidden" name="tab" value="links">
    <div><label class="label" for="status">Status</label>
        <select class="input !w-auto" id="status" name="status" onchange="this.form.submit()">
            <option value="">All</option>
            <?php foreach (['pending', 'success', 'failed', 'expired'] as $s): ?>
            <option value="<?= e($s) ?>" <?= $linkStatusFilter === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <span class="text-xs text-slate-400 pb-2.5">Showing latest <?= count($adminLinks) ?> links</span>
</form>
<div class="card overflow-hidden mt-4">
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Link ID</th><th>Merchant UID</th><th>Amount</th><th>Customer</th><th>Provider</th><th>Status</th><th>UTR</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($adminLinks as $l): ?>
            <tr>
                <td class="font-mono text-xs"><?= e($l['link_id'] ?? '') ?></td>
                <td class="font-mono text-[10px] text-slate-400" title="<?= e($l['uid'] ?? '') ?>"><?= e(substr((string) ($l['uid'] ?? ''), 0, 12)) ?>…</td>
                <td class="font-semibold"><?= e(format_money($l['amount'] ?? 0)) ?></td>
                <td><?= e($l['customer_name'] ?? '') ?></td>
                <td class="text-xs"><?= e($l['provider'] ?? '') ?></td>
                <td><span class="badge badge-<?= e($l['status'] ?? 'pending') ?>"><?= e($l['status'] ?? 'pending') ?></span></td>
                <td class="font-mono text-[11px]"><?= e($l['utr'] ?? '-') ?></td>
                <td class="text-xs text-slate-500 dark:text-slate-400 whitespace-nowrap"><?= e(format_date($l['created_at'] ?? 0)) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($adminLinks)): ?><tr><td colspan="8" class="text-center py-8 text-slate-400">No payment links found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php /* ==================== TAB 4: TRANSACTIONS ==================== */ ?>
<?php if ($tab === 'transactions'): ?>
<div class="flex items-center justify-between gap-3 flex-wrap">
    <p class="text-sm text-slate-500 dark:text-slate-400">All successful payments across merchants. Latest <?= count($adminTransactions) ?> shown.</p>
    <a href="<?= e(url('admin.php?tab=transactions&export=csv')) ?>" class="btn-primary !py-2.5"><?= icon('download', 'w-4 h-4') ?> Export CSV</a>
</div>
<div class="card overflow-hidden mt-4">
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Link ID</th><th>Amount</th><th>Commission</th><th>Customer</th><th>Provider</th><th>UPI ID</th><th>UTR</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($adminTransactions as $l): ?>
            <tr>
                <td class="font-mono text-xs"><?= e($l['link_id'] ?? '') ?></td>
                <td class="font-semibold"><?= e(format_money($l['amount'] ?? 0)) ?></td>
                <td class="text-rose-500"><?= e(format_money($l['commission'] ?? 0)) ?></td>
                <td><?= e($l['customer_name'] ?? '') ?></td>
                <td class="text-xs"><?= e($l['provider'] ?? '') ?></td>
                <td class="font-mono text-[11px] text-slate-500 dark:text-slate-400"><?= e($l['upi_id'] ?? '') ?></td>
                <td class="font-mono text-[11px]"><?= e($l['utr'] ?? '-') ?></td>
                <td class="text-xs text-slate-500 dark:text-slate-400 whitespace-nowrap"><?= e(format_date($l['created_at'] ?? 0)) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($adminTransactions)): ?><tr><td colspan="8" class="text-center py-8 text-slate-400">No transactions yet</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php /* ==================== TAB 5: SUPPORT ==================== */ ?>
<?php if ($tab === 'support'): ?>
<?php if ($chatWith !== '' && $activeChat): ?>
    <?php $chatUid = $chatWith; ?>
    <div class="max-w-3xl mx-auto">
        <a href="<?= e(url('admin.php?tab=support')) ?>" class="inline-flex items-center gap-2 text-xs font-semibold text-violet-600 dark:text-violet-300 hover:underline mb-3"><?= icon('arrow-left', 'w-4 h-4') ?> All conversations</a>
        <div class="card overflow-hidden flex flex-col" style="height: min(76vh, 700px)">
            <div class="flex items-center gap-3 px-5 py-4 border-b border-slate-100 dark:border-slate-800">
                <span class="w-11 h-11 rounded-full gradient-bg text-white flex items-center justify-center text-sm font-bold"><?= e(strtoupper(substr((string) ($activeChat['user_name'] ?? 'U'), 0, 1))) ?></span>
                <div class="min-w-0">
                    <p class="font-bold truncate"><?= e($activeChat['user_name'] ?? 'User') ?></p>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate"><?= e($activeChat['user_email'] ?? '') ?></p>
                </div>
            </div>
            <div id="adminChatMessages" class="flex-1 overflow-y-auto px-4 sm:px-6 py-4 space-y-3">
                <?php foreach ($activeChatMessages as $m): $mine = ($m['sender'] ?? 'user') === 'admin'; ?>
                <div class="flex <?= $mine ? 'justify-end' : 'justify-start' ?>">
                    <div class="<?= $mine ? 'chat-user' : 'chat-admin' ?> chat-bubble shadow-sm">
                        <?php if (!empty($m['image'])): ?><img src="<?= e($m['image']) ?>" alt="Attachment" class="rounded-xl max-w-[220px] mb-1.5 border border-black/10"><?php endif; ?>
                        <?php if (($m['text'] ?? '') !== ''): ?><p><?= nl2br(e($m['text'])) ?></p><?php endif; ?>
                        <p class="chat-time <?= $mine ? 'text-violet-100' : '' ?>"><?= e(date('d M, h:i A', (int) ($m['created_at'] ?? 0))) ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($activeChatMessages)): ?><p class="text-center text-sm text-slate-400 py-10">No messages yet.</p><?php endif; ?>
            </div>
            <form method="POST" action="<?= e(url('admin.php?tab=support')) ?>" class="border-t border-slate-100 dark:border-slate-800 p-3 sm:p-4 flex items-center gap-2 bg-white/60 dark:bg-slate-900/60">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="support_reply">
                <input type="hidden" name="chat_uid" value="<?= e($chatUid) ?>">
                <input class="input !rounded-2xl" type="text" name="text" placeholder="Type your reply..." required autocomplete="off" autofocus>
                <button type="submit" class="btn-primary !w-11 !h-11 !p-0 shrink-0 !rounded-2xl" aria-label="Send reply"><?= icon('send', 'w-5 h-5') ?></button>
            </form>
        </div>
    </div>
    <script>
    (function () {
        var box = document.getElementById('adminChatMessages');
        if (box) box.scrollTop = box.scrollHeight;
        var chatUid = <?= json_encode($chatUid) ?>;
        var lastTime = <?= json_encode((int) ((end($activeChatMessages) ?: [])['created_at'] ?? 0)) ?>;
        function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
        function poll() {
            fetch('admin.php?tab=support&action=poll&chat=' + encodeURIComponent(chatUid) + '&since=' + encodeURIComponent(lastTime), { headers: { 'Accept': 'application/json' }, cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !Array.isArray(data.messages) || data.messages.length === 0) return;
                    data.messages.forEach(function (m) {
                        var mine = m.sender === 'admin';
                        var t = new Date((m.created_at || 0) * 1000);
                        var img = m.image ? '<img src="' + m.image + '" alt="Attachment" class="rounded-xl max-w-[220px] mb-1.5 border border-black/10">' : '';
                        var txt = m.text ? '<p>' + esc(m.text).replace(/\n/g, '<br>') + '</p>' : '';
                        box.insertAdjacentHTML('beforeend', '<div class="flex ' + (mine ? 'justify-end' : 'justify-start') + '"><div class="' + (mine ? 'chat-user' : 'chat-admin') + ' chat-bubble shadow-sm">' + img + txt + '<p class="chat-time ' + (mine ? 'text-violet-100' : '') + '">' + esc(t.toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })) + '</p></div></div>');
                        if ((m.created_at || 0) > lastTime) lastTime = m.created_at;
                    });
                    box.scrollTop = box.scrollHeight;
                }).catch(function () {});
        }
        setInterval(poll, 3000);
    })();
    </script>
<?php else: ?>
    <div class="card overflow-hidden">
        <div class="px-5 sm:px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <h3 class="font-bold flex items-center gap-2"><?= icon('headset', 'w-5 h-5 text-violet-500') ?> Support Inbox</h3>
            <span class="text-xs text-slate-400"><?= count($chatList) ?> conversation<?= count($chatList) === 1 ? '' : 's' ?></span>
        </div>
        <?php if (empty($chatList)): ?>
        <div class="p-10 text-center">
            <div class="mx-auto w-16 h-16 rounded-2xl bg-violet-50 dark:bg-violet-500/10 text-violet-400 flex items-center justify-center"><?= icon('message', 'w-8 h-8') ?></div>
            <h4 class="mt-4 font-bold">No conversations yet</h4>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">When merchants write in, threads appear here with unread badges.</p>
        </div>
        <?php else: ?>
        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
            <?php foreach ($chatList as $c): $unread = (int) ($c['unread_admin'] ?? 0); ?>
            <li>
                <a href="<?= e(url('admin.php?tab=support&chat=' . urlencode((string) ($c['id'] ?? '')))) ?>" class="flex items-center gap-3 px-5 py-4 hover:bg-violet-50/50 dark:hover:bg-violet-500/5 transition">
                    <span class="w-11 h-11 rounded-full gradient-bg text-white flex items-center justify-center text-sm font-bold shrink-0"><?= e(strtoupper(substr((string) ($c['user_name'] ?? 'U'), 0, 1))) ?></span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-2"><span class="font-semibold text-sm truncate"><?= e($c['user_name'] ?? 'User') ?></span><?php if ($unread > 0): ?><span class="inline-flex items-center gap-1 text-[10px] font-bold text-white bg-rose-500 rounded-full px-2 py-0.5"><?= icon('bell', 'w-3 h-3') ?> <?= $unread ?></span><?php endif; ?></span>
                        <span class="block text-[11px] text-slate-500 dark:text-slate-400 truncate mt-0.5"><?= e($c['last_message'] ?? '') ?></span>
                    </span>
                    <span class="text-[10px] text-slate-400 shrink-0"><?= e(!empty($c['last_at']) ? date('d M, h:i A', (int) $c['last_at']) : '') ?></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php endif; ?>

<?php /* ==================== TAB 6: WALLET REQUESTS ==================== */ ?>
<?php if ($tab === 'requests'): ?>
<div class="grid lg:grid-cols-2 gap-6">
    <div class="card overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <h3 class="font-bold flex items-center gap-2"><?= icon('clock', 'w-5 h-5 text-amber-500') ?> Pending Requests</h3>
            <span class="badge badge-pending"><?= count($pendingRequests) ?> pending</span>
        </div>
        <?php if (empty($pendingRequests)): ?>
        <div class="p-10 text-center">
            <div class="mx-auto w-14 h-14 rounded-2xl bg-emerald-50 dark:bg-emerald-500/10 text-emerald-500 flex items-center justify-center"><?= icon('check-circle', 'w-7 h-7') ?></div>
            <h4 class="mt-4 font-bold">All caught up</h4>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">No pending top-up requests.</p>
        </div>
        <?php else: ?>
        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
            <?php foreach ($pendingRequests as $req): ?>
            <li class="p-4 sm:p-5 flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-bold"><?= e(format_money($req['amount'] ?? 0)) ?> <span class="text-xs font-medium text-slate-400">to <?= e($req['user_name'] ?? $req['uid'] ?? 'user') ?></span></p>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate"><?= e($req['user_email'] ?? '') ?><?php if (!empty($req['note'])): ?> &middot; <?= e($req['note']) ?><?php endif; ?></p>
                    <p class="text-[10px] text-slate-400 mt-0.5"><?= e(format_date($req['created_at'] ?? 0)) ?></p>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <form method="POST" action="<?= e(url('admin.php?tab=requests')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="request_approve">
                        <input type="hidden" name="request_id" value="<?= e($req['id']) ?>">
                        <button type="submit" class="inline-flex items-center gap-1.5 text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-500 rounded-xl px-3.5 py-2.5 transition"><?= icon('check', 'w-4 h-4') ?> Approve</button>
                    </form>
                    <form method="POST" action="<?= e(url('admin.php?tab=requests')) ?>" data-confirm="Reject this top-up request?" data-confirm-title="Reject request">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="request_reject">
                        <input type="hidden" name="request_id" value="<?= e($req['id']) ?>">
                        <button type="submit" class="inline-flex items-center gap-1.5 text-xs font-bold text-rose-600 dark:text-rose-300 bg-rose-50 dark:bg-rose-500/10 hover:bg-rose-100 dark:hover:bg-rose-500/20 rounded-xl px-3 py-2.5 transition"><?= icon('x', 'w-4 h-4') ?> Reject</button>
                    </form>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>

    <div class="card overflow-hidden h-fit">
        <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-800"><h3 class="font-bold flex items-center gap-2"><?= icon('list', 'w-5 h-5 text-slate-400') ?> Recently Processed</h3></div>
        <?php if (empty($processedRequests)): ?>
        <p class="p-6 text-sm text-slate-400">Nothing processed yet.</p>
        <?php else: ?>
        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
            <?php foreach ($processedRequests as $req): $approved = ($req['status'] ?? '') === 'approved'; ?>
            <li class="p-4 flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-sm font-semibold"><?= e(format_money($req['amount'] ?? 0)) ?> <span class="text-slate-400 font-normal">&middot; <?= e($req['user_name'] ?? '') ?></span></p>
                    <p class="text-[10px] text-slate-400"><?= e(format_date($req['processed_at'] ?? ($req['created_at'] ?? 0))) ?></p>
                </div>
                <span class="badge <?= $approved ? 'badge-success' : 'badge-failed' ?>"><?= $approved ? 'Approved' : 'Rejected' ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php /* ==================== TAB 7: SETTINGS ==================== */ ?>
<?php if ($tab === 'settings'): ?>
<form method="POST" action="<?= e(url('admin.php?tab=settings')) ?>" class="grid lg:grid-cols-2 gap-6 max-w-5xl">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_settings">

    <div class="card p-5 sm:p-6">
        <h3 class="font-bold flex items-center gap-2"><?= icon('zap', 'w-5 h-5 text-violet-500') ?> Commission</h3>
        <label class="label mt-4" for="commission_percent">Commission percent</label>
        <input class="input text-2xl font-extrabold" type="number" id="commission_percent" name="commission_percent" min="0.1" max="50" step="0.1" value="<?= e((string) setting('commission_percent', env('COMMISSION_PERCENT', '2'))) ?>" required>
        <p class="mt-1.5 text-[11px] text-slate-400">Charged to the merchant per successful payment link, deducted from their wallet.</p>
    </div>

    <div class="card p-5 sm:p-6">
        <h3 class="font-bold flex items-center gap-2"><?= icon('mail', 'w-5 h-5 text-indigo-500') ?> SMTP (PHPMailer)</h3>
        <div class="grid grid-cols-2 gap-3 mt-4">
            <div class="col-span-2"><label class="label" for="smtp_host">Host</label><input class="input" type="text" id="smtp_host" name="smtp_host" placeholder="smtp.gmail.com" value="<?= e((string) setting('smtp_host', env('SMTP_HOST', ''))) ?>"></div>
            <div><label class="label" for="smtp_port">Port</label><input class="input" type="number" id="smtp_port" name="smtp_port" value="<?= e((string) setting('smtp_port', env('SMTP_PORT', '587'))) ?>"></div>
            <div><label class="label" for="smtp_user">Username</label><input class="input" type="text" id="smtp_user" name="smtp_user" placeholder="no-reply@yourdomain.com" value="<?= e((string) setting('smtp_user', env('SMTP_USER', ''))) ?>"></div>
            <div class="col-span-2"><label class="label" for="smtp_pass">Password</label><input class="input" type="password" id="smtp_pass" name="smtp_pass" autocomplete="new-password" placeholder="<?= !empty($currentSettings['smtp_pass']) ? '•••••••• (leave blank to keep current)' : 'App password' ?>"></div>
            <div><label class="label" for="smtp_from_email">From email</label><input class="input" type="email" id="smtp_from_email" name="smtp_from_email" value="<?= e((string) setting('smtp_from_email', env('SMTP_FROM_EMAIL', ''))) ?>"></div>
            <div><label class="label" for="smtp_from_name">From name</label><input class="input" type="text" id="smtp_from_name" name="smtp_from_name" value="<?= e((string) setting('smtp_from_name', env('SMTP_FROM_NAME', 'CashPe'))) ?>"></div>
        </div>
    </div>

    <div class="card p-5 sm:p-6 lg:col-span-2">
        <h3 class="font-bold flex items-center gap-2"><?= icon('smartphone', 'w-5 h-5 text-emerald-500') ?> Personal Provider Endpoints</h3>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Base URLs of the personal provider APIs (PhonePe Personal, Paytm Personal, Navi) used for OTP login and transaction feeds. Leave empty to disable those providers.</p>
        <div class="grid sm:grid-cols-3 gap-3 mt-4">
            <div><label class="label" for="provider_phonepe_personal_base">PhonePe Personal base URL</label><input class="input font-mono text-xs" type="url" id="provider_phonepe_personal_base" name="provider_phonepe_personal_base" placeholder="https://..." value="<?= e((string) setting('provider_phonepe_personal_base', env('PHONEPE_PERSONAL_BASE_URL', ''))) ?>"></div>
            <div><label class="label" for="provider_paytm_personal_base">Paytm Personal base URL</label><input class="input font-mono text-xs" type="url" id="provider_paytm_personal_base" name="provider_paytm_personal_base" placeholder="https://..." value="<?= e((string) setting('provider_paytm_personal_base', env('PAYTM_PERSONAL_BASE_URL', ''))) ?>"></div>
            <div><label class="label" for="provider_navi_personal_base">Navi base URL</label><input class="input font-mono text-xs" type="url" id="provider_navi_personal_base" name="provider_navi_personal_base" placeholder="https://..." value="<?= e((string) setting('provider_navi_personal_base', env('NAVI_PERSONAL_BASE_URL', ''))) ?>"></div>
        </div>
    </div>

    <div class="lg:col-span-2 flex justify-end"><button type="submit" class="btn-primary"><?= icon('check', 'w-5 h-5') ?> Save Settings</button></div>
</form>
<?php endif; ?>

</main>

<div id="toastRoot" class="fixed bottom-4 right-4 z-[100] flex flex-col gap-2 w-[min(92vw,360px)]"></div>

<!-- Shared confirm modal -->
<div id="confirmModal" class="fixed inset-0 z-[90] hidden items-center justify-center" role="dialog" aria-modal="true">
    <div class="absolute inset-0 bg-slate-950/60 backdrop-blur-sm" data-confirm-cancel></div>
    <div class="relative glass rounded-2xl max-w-sm w-full mx-4 p-6 shadow-2xl animate-pop">
        <h3 id="confirmTitle" class="text-lg font-bold">Are you sure?</h3>
        <p id="confirmMessage" class="mt-1.5 text-sm text-slate-500 dark:text-slate-400"></p>
        <div class="mt-5 flex gap-3 justify-end">
            <button class="btn-secondary !py-2" data-confirm-cancel>Cancel</button>
            <button id="confirmAccept" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold text-white bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-500 hover:to-fuchsia-500 transition">Confirm</button>
        </div>
    </div>
</div>

<script src="<?= e(url('assets/js/app.js')) ?>" defer></script>
<script>
// Users tab: wallet adjust modal wiring
(function () {
    var modal = document.getElementById('walletAdjustModal');
    if (!modal) return;
    var uidField = document.getElementById('adjustUid');
    var directionField = document.getElementById('adjustDirection');
    var nameEl = document.getElementById('adjustName');
    var titleEl = document.getElementById('walletAdjustTitle');
    var submitBtn = document.getElementById('adjustSubmit');
    function open(direction, uid, name) {
        directionField.value = direction;
        uidField.value = uid;
        nameEl.textContent = name || 'user';
        titleEl.textContent = direction === 'credit' ? 'Credit wallet' : 'Debit wallet';
        submitBtn.textContent = direction === 'credit' ? 'Credit wallet' : 'Debit wallet';
        modal.classList.remove('hidden'); modal.classList.add('flex'); document.body.style.overflow = 'hidden';
    }
    function close() { modal.classList.add('hidden'); modal.classList.remove('flex'); document.body.style.overflow = ''; }
    document.querySelectorAll('[data-wallet-adjust]').forEach(function (btn) {
        btn.addEventListener('click', function () { open(btn.getAttribute('data-wallet-adjust'), btn.getAttribute('data-uid'), btn.getAttribute('data-name')); });
    });
    modal.addEventListener('click', function (ev) { if (ev.target.closest('[data-close-adjust]')) close(); });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') close(); });
})();
</script>
</body>
</html>
