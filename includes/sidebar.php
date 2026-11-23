<?php
/**
 * CashPe - Sidebar navigation (SVG icons only).
 * Active item gets a gradient pill. Collapses to a drawer on mobile.
 * Expects $activePage and $user to be set by the caller.
 */
$activePage = $activePage ?? 'dashboard';
$initials = '';
if (!empty($user['name'])) {
    $parts = preg_split('/\s+/', trim((string) $user['name'])) ?: [];
    $initials = strtoupper(substr($parts[0] ?? 'U', 0, 1) . substr($parts[1] ?? '', 0, 1));
}

$nav = [
    'dashboard' => ['url' => 'dashboard.php', 'label' => 'Dashboard', 'icon' => 'home'],
    'wallet' => ['url' => 'wallet.php', 'label' => 'Wallet', 'icon' => 'wallet'],
    'payment-link' => ['url' => 'payment-link.php', 'label' => 'Payment Links', 'icon' => 'link'],
    'connect' => ['url' => 'connect.php', 'label' => 'Connect Provider', 'icon' => 'credit-card'],
    'transactions' => ['url' => 'transactions.php', 'label' => 'Transactions', 'icon' => 'list'],
    'api' => ['url' => 'api.php', 'label' => 'API', 'icon' => 'key'],
    'support' => ['url' => 'support.php', 'label' => 'Support', 'icon' => 'headset'],
];
?>
<!-- Mobile overlay -->
<div id="sidebarOverlay" class="fixed inset-0 z-40 bg-slate-950/60 backdrop-blur-sm hidden transition-opacity duration-200 lg:hidden"></div>

<aside id="sidebar" class="fixed inset-y-0 left-0 z-50 w-72 -translate-x-full lg:translate-x-0 transition-transform duration-200 ease-out flex flex-col bg-white/90 dark:bg-slate-900/90 backdrop-blur-2xl border-r border-slate-200/70 dark:border-slate-800/70">
    <!-- Brand -->
    <div class="h-16 px-5 flex items-center justify-between border-b border-slate-200/70 dark:border-slate-800/70">
        <a href="<?= e(url('dashboard.php')) ?>" class="flex items-center gap-3">
            <?= cashpe_logo('w-9 h-9', 'w-5 h-5') ?>
            <span class="leading-tight">
                <span class="block text-lg font-extrabold tracking-tight gradient-text">CashPe</span>
                <span class="block text-[9px] font-semibold uppercase tracking-[.16em] text-slate-400 dark:text-slate-500">Merchant Console</span>
            </span>
        </a>
        <button id="sidebarClose" class="lg:hidden icon-btn" aria-label="Close menu"><?= icon('x', 'w-6 h-6') ?></button>
    </div>

    <!-- Nav -->
    <nav class="flex-1 overflow-y-auto px-4 py-5">
        <p class="px-3 mb-2 text-[10px] font-bold uppercase tracking-[.2em] text-slate-400 dark:text-slate-500">Menu</p>
        <ul class="space-y-1.5">
            <?php foreach ($nav as $key => $item): $active = ($activePage === $key); ?>
            <li>
                <a href="<?= e(url($item['url'])) ?>"
                   class="sidebar-link <?= $active ? 'sidebar-link-active' : '' ?>">
                    <?= icon($item['icon'], 'w-5 h-5 shrink-0') ?>
                    <span><?= e($item['label']) ?></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>

        <p class="px-3 mt-6 mb-2 text-[10px] font-bold uppercase tracking-[.2em] text-slate-400 dark:text-slate-500">Account</p>
        <ul class="space-y-1.5">
            <li>
                <a href="<?= e(url('logout.php')) ?>" class="sidebar-link sidebar-link-danger">
                    <?= icon('logout', 'w-5 h-5 shrink-0') ?>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </nav>

    <!-- User card -->
    <div class="p-4 border-t border-slate-200/70 dark:border-slate-800/70">
        <div class="flex items-center gap-3 rounded-2xl bg-slate-100 dark:bg-slate-800/60 p-3">
            <div class="w-11 h-11 rounded-full bg-gradient-to-br from-indigo-500 via-violet-600 to-fuchsia-600 flex items-center justify-center text-sm font-bold text-white shrink-0"><?= e($initials ?: 'U') ?></div>
            <div class="min-w-0">
                <p class="text-sm font-semibold truncate"><?= e($user['name'] ?? 'Merchant') ?></p>
                <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate"><?= e($user['email'] ?? '') ?></p>
            </div>
        </div>
    </div>
</aside>
