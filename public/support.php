<?php
/**
 * CashPe - Support chat (WhatsApp-style). Messages live in
 * support_chats/{uid}/messages and sync to the admin panel.
 */
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/firebase_helper.php';
require_once dirname(__DIR__) . '/includes/icons.php';

$user = auth_require_verified();
$uid = (string) ($user['uid'] ?? $user['id']);

// ---------------------------------------------------------------
// JSON endpoints (polled / posted by the chat UI)
// ---------------------------------------------------------------
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'ui');

if ($action === 'poll') {
    header('Cache-Control: no-store');
    $since = (int) ($_GET['since'] ?? 0);
    try {
        $messages = fs_query('support_chats/' . $uid . '/messages', [], 200, 'created_at', 'asc');
        $fresh = array_values(array_filter($messages, fn($m) => (int) ($m['created_at'] ?? 0) > $since));
        // Mark admin messages as read by the user
        fs_set('support_chats', $uid, ['unread_user' => 0], true);
        $out = array_map(fn($m) => [
            'id' => $m['id'] ?? '',
            'sender' => $m['sender'] ?? 'user',
            'text' => $m['text'] ?? '',
            'image' => $m['image'] ?? null,
            'created_at' => (int) ($m['created_at'] ?? 0),
        ], $fresh);
        json_response(['messages' => $out, 'server_time' => time()]);
    } catch (Throwable $e) {
        json_response(['messages' => [], 'error' => $e->getMessage()], 500);
    }
}

if ($action === 'send') {
    header('Cache-Control: no-store');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['ok' => false, 'error' => 'POST required'], 405);
    }
    csrf_validate();
    $raw = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = $_POST;
    }
    $text = trim((string) ($body['text'] ?? ''));
    $image = (string) ($body['image'] ?? '');

    if ($text === '' && $image === '') {
        json_response(['ok' => false, 'error' => 'Type a message or attach an image'], 422);
    }
    if (mb_strlen($text) > 2000) {
        $text = mb_substr($text, 0, 2000);
    }
    if ($image !== '' && (!str_starts_with($image, 'data:image/jpeg;base64,') && !str_starts_with($image, 'data:image/png;base64,') && !str_starts_with($image, 'data:image/webp;base64,'))) {
        json_response(['ok' => false, 'error' => 'Unsupported image format'], 422);
    }
    if (strlen($image) > 900000) { // ~650 KB binary
        json_response(['ok' => false, 'error' => 'Image too large. Please attach an image under 600 KB.'], 422);
    }

    try {
        $msgId = fs_add('support_chats/' . $uid . '/messages', [
            'sender' => 'user',
            'text' => $text,
            'image' => $image !== '' ? $image : null,
            'created_at' => time(),
        ]);
        $chat = fs_get('support_chats', $uid);
        $unread = (int) ($chat['unread_admin'] ?? 0);
        fs_set('support_chats', $uid, [
            'uid' => $uid,
            'user_name' => (string) ($user['name'] ?? ''),
            'user_email' => (string) ($user['email'] ?? ''),
            'last_message' => $text !== '' ? $text : '[image]',
            'last_at' => time(),
            'unread_admin' => $unread + 1,
            'unread_user' => 0,
        ], true);
        json_response(['ok' => true, 'id' => $msgId]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// ---------------------------------------------------------------
// UI mode
// ---------------------------------------------------------------
$dbError = null;
try {
    $initial = fs_query('support_chats/' . $uid . '/messages', [], 100, 'created_at', 'asc');
    fs_set('support_chats', $uid, ['unread_user' => 0], true);
} catch (Throwable $e) {
    $initial = [];
    $dbError = $e->getMessage();
}

$pageTitle = 'Support';
$activePage = 'support';
require dirname(__DIR__) . '/includes/header.php';
?>
<?php if ($dbError): ?><div class="flash flash-error mb-4"><?= e($dbError) ?></div><?php endif; ?>

<div class="card max-w-4xl mx-auto overflow-hidden flex flex-col" style="height: min(78vh, 720px)">
    <!-- Chat header -->
    <div class="flex items-center gap-3 px-5 py-4 border-b border-slate-100 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-900/40">
        <span class="relative w-12 h-12 rounded-2xl gradient-bg text-white flex items-center justify-center shrink-0">
            <?= icon('headset', 'w-6 h-6') ?>
            <span class="absolute -bottom-0.5 -right-0.5 w-3.5 h-3.5 rounded-full bg-emerald-400 border-2 border-white dark:border-slate-900"></span>
        </span>
        <div class="min-w-0">
            <p class="font-extrabold flex items-center gap-2">CashPe Support <span class="text-[9px] uppercase tracking-[.18em] text-emerald-600 dark:text-emerald-400 font-bold">Online</span></p>
            <p class="text-[11px] text-slate-500 dark:text-slate-400">Usually replies within a few minutes</p>
        </div>
    </div>

    <!-- Messages -->
    <div id="chatMessages" class="flex-1 overflow-y-auto px-4 sm:px-6 py-4 space-y-3 bg-[radial-gradient(circle_at_1px_1px,rgba(100,116,139,.12)_1px,transparent_0)] [background-size:22px_22px]">
        <?php if (empty($initial)): ?>
        <div id="chatEmpty" class="h-full flex flex-col items-center justify-center text-center px-6 py-10">
            <div class="w-16 h-16 rounded-2xl bg-violet-50 dark:bg-violet-500/10 text-violet-400 flex items-center justify-center"><?= icon('message', 'w-8 h-8') ?></div>
            <h3 class="mt-4 font-bold">How can we help?</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400 max-w-xs">Send us a message about account, wallet, settlement or refunds. Attach a screenshot if you have one.</p>
        </div>
        <?php endif; ?>
        <?php foreach ($initial as $m): $mine = ($m['sender'] ?? 'user') === 'user'; ?>
        <div class="flex <?= $mine ? 'justify-end' : 'justify-start' ?>" data-msg-time="<?= (int) ($m['created_at'] ?? 0) ?>">
            <div class="<?= $mine ? 'chat-user' : 'chat-admin' ?> chat-bubble shadow-sm">
                <?php if (!empty($m['image'])): ?><img src="<?= e($m['image']) ?>" alt="Attachment" class="rounded-xl max-w-[220px] mb-1.5 border border-black/10"><?php endif; ?>
                <?php if (($m['text'] ?? '') !== ''): ?><p><?= nl2br(e($m['text'])) ?></p><?php endif; ?>
                <p class="chat-time <?= $mine ? 'text-violet-100' : '' ?>"><?= e(date('d M, h:i A', (int) ($m['created_at'] ?? 0))) ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Composer -->
    <form id="chatForm" class="border-t border-slate-100 dark:border-slate-800 p-3 sm:p-4 flex items-end gap-2 bg-white/60 dark:bg-slate-900/60 backdrop-blur">
        <input type="file" id="chatImage" accept="image/jpeg,image/png,image/webp" class="hidden">
        <button type="button" id="attachBtn" class="icon-btn !w-11 !h-11 shrink-0" aria-label="Attach image"><?= icon('paperclip', 'w-5 h-5') ?></button>
        <div class="flex-1 min-w-0">
            <div id="imagePreview" class="hidden mb-2 relative inline-block">
                <img alt="Attachment preview" class="h-16 rounded-xl border border-slate-200 dark:border-slate-700">
                <button type="button" id="removeImage" class="absolute -top-2 -right-2 w-6 h-6 rounded-full bg-rose-500 text-white flex items-center justify-center shadow" aria-label="Remove image"><?= icon('x', 'w-4 h-4') ?></button>
            </div>
            <textarea id="chatText" class="input !rounded-2xl" rows="1" maxlength="2000" placeholder="Type a message..." required></textarea>
        </div>
        <button type="submit" id="sendBtn" class="btn-primary !w-11 !h-11 !p-0 shrink-0 !rounded-2xl" aria-label="Send"><?= icon('send', 'w-5 h-5') ?></button>
    </form>
</div>

<script>
(function () {
    var messagesEl = document.getElementById('chatMessages');
    var form = document.getElementById('chatForm');
    var textInput = document.getElementById('chatText');
    var imageInput = document.getElementById('chatImage');
    var attachBtn = document.getElementById('attachBtn');
    var preview = document.getElementById('imagePreview');
    var removeImage = document.getElementById('removeImage');
    var sendBtn = document.getElementById('sendBtn');
    var emptyState = document.getElementById('chatEmpty');
    var csrfToken = <?= json_encode(csrf_token()) ?>;
    var pendingImage = null;
    var lastTime = <?= json_encode((int) ((end($initial) ?: [])['created_at'] ?? 0)) ?>;
    var seen = new Set();
    document.querySelectorAll('[data-msg-time]').forEach(function (el) { /* server-rendered bubbles tracked by time below */ });

    function scrollBottom() { messagesEl.scrollTop = messagesEl.scrollHeight; }
    scrollBottom();

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function bubble(m) {
        var mine = m.sender === 'user';
        var time = new Date((m.created_at || 0) * 1000);
        var timeStr = time.toLocaleDateString('en-IN', { day: '2-digit', month: 'short' }) + ', ' + time.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' });
        var image = m.image ? '<img src="' + m.image + '" alt="Attachment" class="rounded-xl max-w-[220px] mb-1.5 border border-black/10">' : '';
        var text = m.text ? '<p>' + escapeHtml(m.text).replace(/\n/g, '<br>') + '</p>' : '';
        return '<div class="flex ' + (mine ? 'justify-end' : 'justify-start') + '"><div class="' + (mine ? 'chat-user' : 'chat-admin') + ' chat-bubble shadow-sm">' + image + text + '<p class="chat-time ' + (mine ? 'text-violet-100' : '') + '">' + escapeHtml(timeStr) + '</p></div></div>';
    }

    function appendMessages(list) {
        var added = 0;
        list.forEach(function (m) {
            var key = (m.created_at || 0) + '|' + (m.sender || '') + '|' + (m.text || '').slice(0, 32);
            if (seen.has(key)) return;
            seen.add(key);
            if (emptyState) { emptyState.remove(); emptyState = null; }
            messagesEl.insertAdjacentHTML('beforeend', bubble(m));
            added++;
            if ((m.created_at || 0) > lastTime) lastTime = m.created_at;
        });
        if (added > 0) scrollBottom();
    }

    function poll() {
        fetch('support.php?action=poll&since=' + encodeURIComponent(lastTime), { headers: { 'Accept': 'application/json' }, cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) { if (data && Array.isArray(data.messages)) appendMessages(data.messages); })
            .catch(function () {});
    }
    setInterval(poll, 3000);

    // Attach image (compressed client-side to keep Firestore docs small)
    attachBtn.addEventListener('click', function () { imageInput.click(); });
    imageInput.addEventListener('change', function () {
        var file = imageInput.files && imageInput.files[0];
        if (!file) return;
        if (file.size > 8 * 1024 * 1024) { window.toast('Image too large (max 8 MB before compression).', 'error'); return; }
        var reader = new FileReader();
        reader.onload = function () {
            var img = new Image();
            img.onload = function () {
                var scale = Math.min(1, 900 / Math.max(img.width, img.height));
                var canvas = document.createElement('canvas');
                canvas.width = Math.max(1, Math.round(img.width * scale));
                canvas.height = Math.max(1, Math.round(img.height * scale));
                canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                pendingImage = canvas.toDataURL('image/jpeg', 0.72);
                var previewImg = preview.querySelector('img');
                previewImg.src = pendingImage;
                preview.classList.remove('hidden');
            };
            img.src = reader.result;
        };
        reader.readAsDataURL(file);
    });
    removeImage.addEventListener('click', function () {
        pendingImage = null;
        imageInput.value = '';
        preview.classList.add('hidden');
    });

    // Auto-grow textarea
    textInput.addEventListener('input', function () {
        textInput.style.height = 'auto';
        textInput.style.height = Math.min(120, textInput.scrollHeight) + 'px';
    });
    textInput.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); form.requestSubmit(); }
    });

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var text = textInput.value.trim();
        if (!text && !pendingImage) return;
        sendBtn.disabled = true;
        fetch('support.php?action=send', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({ text: text, image: pendingImage })
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data && data.ok) {
                appendMessages([{ sender: 'user', text: text, image: pendingImage, created_at: Math.floor(Date.now() / 1000) }]);
                textInput.value = '';
                textInput.style.height = 'auto';
                pendingImage = null;
                imageInput.value = '';
                preview.classList.add('hidden');
            } else {
                window.toast((data && data.error) || 'Could not send. Please try again.', 'error');
            }
        }).catch(function () {
            window.toast('Network error. Please try again.', 'error');
        }).finally(function () {
            sendBtn.disabled = false;
            textInput.focus();
        });
    });
})();
</script>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
