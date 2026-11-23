<?php
/**
 * CashPe - Styled error page used by hard failures (CSRF, maintenance).
 * Expects $title and $message to be set by the caller.
 */
if (!defined('CASHPE_ROOT')) {
    require dirname(__DIR__) . '/config/config.php';
}
$title = $title ?? 'Something went wrong';
$message = $message ?? 'An unexpected error occurred.';
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> - <?= e(APP_NAME) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%236d28d9'/%3E%3Cpath d='M36 8 L16 36 H30 L26 56 L46 27 H33 Z' fill='%23fff'/%3E%3C/svg%3E">
<script src="https://cdn.tailwindcss.com"></script>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="min-h-screen flex items-center justify-center bg-slate-100 p-6" style="background:radial-gradient(1200px 600px at 50% -10%, rgba(99,102,241,.25), transparent), radial-gradient(800px 500px at 100% 110%, rgba(217,70,239,.18), transparent), #f1f5f9;font-family:Inter,sans-serif">
  <div class="glass rounded-3xl max-w-md w-full p-8 text-center shadow-2xl">
    <div class="mx-auto mb-5 w-16 h-16 rounded-2xl bg-gradient-to-br from-indigo-600 via-violet-600 to-fuchsia-600 flex items-center justify-center shadow-lg shadow-violet-600/30">
      <svg class="w-8 h-8 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.7 3.86a2 2 0 0 0-3.4 0z"/></svg>
    </div>
    <h1 class="text-xl font-bold text-slate-900"><?= e($title) ?></h1>
    <p class="mt-2 text-sm text-slate-600 leading-relaxed"><?= e($message) ?></p>
    <a href="<?= e(url('index.php')) ?>" class="btn-primary inline-flex items-center gap-2 mt-6 px-6 py-3 rounded-xl text-sm font-semibold text-white">Back to CashPe</a>
  </div>
</body>
</html>
