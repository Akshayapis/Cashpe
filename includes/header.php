<?php
/**
 * CashPe - Page header / layout opener.
 * Expects: $pageTitle (string), $activePage (string), $user (array, from auth_require_verified)
 */
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/csrf.php';
$pageTitle = $pageTitle ?? 'Dashboard';
$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="en" class="">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> - <?= e(APP_NAME) ?></title>
<meta name="description" content="<?= e(APP_TAGLINE) ?>">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%236d28d9'/%3E%3Cpath d='M36 8 L16 36 H30 L26 56 L46 27 H33 Z' fill='%23fff'/%3E%3C/svg%3E">
<script>
// Apply saved theme before first paint
try {
    if (localStorage.getItem('cashpe-theme') === 'dark') {
        document.documentElement.classList.add('dark');
    }
} catch (e) {}
</script>
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
    darkMode: 'class',
    theme: {
        extend: {
            fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
            colors: { brand: { indigo: '#6366f1', violet: '#8b5cf6', fuchsia: '#d946ef' } },
            boxShadow: { glow: '0 10px 40px -10px rgba(124,58,237,.45)' }
        }
    }
};
</script>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
</head>
<body class="min-h-screen bg-slate-100 dark:bg-slate-950 text-slate-800 dark:text-slate-100 antialiased">
<div class="app-shell flex min-h-screen" data-active="<?= e($activePage ?? '') ?>">

<?php require __DIR__ . '/sidebar.php'; ?>

<div class="flex-1 flex flex-col min-w-0 lg:pl-72">
    <!-- Top bar -->
    <header class="sticky top-0 z-30 backdrop-blur-xl bg-white/70 dark:bg-slate-900/70 border-b border-slate-200/70 dark:border-slate-800/70">
        <div class="h-16 px-4 sm:px-6 flex items-center justify-between gap-3">
            <div class="flex items-center gap-3 min-w-0">
                <button id="sidebarToggle" class="lg:hidden icon-btn" aria-label="Open menu">
                    <?= icon('menu', 'w-6 h-6') ?>
                </button>
                <div class="min-w-0">
                    <p class="text-[10px] uppercase tracking-[.18em] text-slate-400 dark:text-slate-500 font-semibold">CashPe</p>
                    <h1 class="text-base sm:text-lg font-bold truncate -mt-0.5"><?= e($pageTitle) ?></h1>
                </div>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <button id="darkToggle" class="icon-btn" aria-label="Toggle dark mode">
                    <span class="dark:hidden"><?= icon('moon', 'w-5 h-5') ?></span>
                    <span class="hidden dark:inline"><?= icon('sun', 'w-5 h-5') ?></span>
                </button>
                <a href="<?= e(url('pay.php')) ?>" class="hidden sm:inline-flex btn-secondary !py-2 !px-3 text-xs" title="Preview your public payment page">
                    <?= icon('external', 'w-4 h-4') ?><span class="hidden md:inline">Pay Page</span>
                </a>
            </div>
        </div>
        <?php if ($flash): ?>
        <div class="px-4 sm:px-6 pb-3">
            <div class="flash flash-<?= e($flash['type']) ?>" role="alert"><?= e($flash['message']) ?></div>
        </div>
        <?php endif; ?>
    </header>

    <main class="flex-1 p-4 sm:p-6 lg:p-8 max-w-[1400px] w-full mx-auto">
