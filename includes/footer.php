<?php
/** CashPe - Layout closer. */
?>
    </main>

    <footer class="border-t border-slate-200/70 dark:border-slate-800/70 py-4 px-4 sm:px-6 flex flex-col sm:flex-row items-center justify-between gap-2 text-[11px] text-slate-400 dark:text-slate-500">
        <p>&copy; <?= date('Y') ?> CashPe. All rights reserved.</p>
        <p class="flex items-center gap-1.5"><?= icon('shield-check', 'w-3.5 h-3.5 text-emerald-500') ?> Secured payments &middot; <?= icon('zap', 'w-3.5 h-3.5 text-violet-500') ?> Instant settlement</p>
    </footer>
</div>
</div>

<!-- Toast root -->
<div id="toastRoot" class="fixed bottom-4 right-4 z-[100] flex flex-col gap-2 w-[min(92vw,360px)]"></div>

<!-- Shared confirm modal -->
<div id="confirmModal" class="fixed inset-0 z-[90] hidden items-center justify-center" role="dialog" aria-modal="true">
    <div class="absolute inset-0 bg-slate-950/60 backdrop-blur-sm" data-confirm-cancel></div>
    <div class="relative glass rounded-2xl max-w-sm w-full mx-4 p-6 shadow-2xl animate-pop">
        <div class="w-12 h-12 rounded-xl bg-rose-100 dark:bg-rose-500/10 text-rose-600 dark:text-rose-400 flex items-center justify-center mb-4">
            <?= icon('alert-triangle', 'w-6 h-6') ?>
        </div>
        <h3 id="confirmTitle" class="text-lg font-bold">Are you sure?</h3>
        <p id="confirmMessage" class="mt-1.5 text-sm text-slate-500 dark:text-slate-400">This action cannot be undone.</p>
        <div class="mt-5 flex gap-3 justify-end">
            <button class="btn-secondary !py-2" data-confirm-cancel>Cancel</button>
            <button id="confirmAccept" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold text-white bg-gradient-to-r from-rose-600 to-red-600 hover:from-rose-500 hover:to-red-500 transition">Confirm</button>
        </div>
    </div>
</div>

<script src="<?= e(url('assets/js/app.js')) ?>" defer></script>
</body>
</html>
