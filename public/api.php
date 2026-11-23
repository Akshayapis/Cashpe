<?php
/**
 * CashPe - API access + documentation page.
 *
 * When called with ?action=... this file behaves as the JSON REST API
 * (authenticated via the X-API-Key header):
 *   POST ?action=create_link   { amount, customer_name, provider_id? ... }
 *   GET  ?action=status&id=    { status, utr, amount }
 *   GET  ?action=wallet        { balance }
 *   GET  ?action=transactions  [ ... ]
 *
 * Otherwise it renders the dashboard page (API key, webhook, docs).
 */
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/firebase_helper.php';
require_once dirname(__DIR__) . '/includes/wallet_helper.php';
require_once dirname(__DIR__) . '/includes/provider_helper.php';
require_once dirname(__DIR__) . '/includes/icons.php';

$apiAction = (string) ($_GET['action'] ?? '');

// ===========================================================================
// JSON API mode
// ===========================================================================
if ($apiAction !== '') {
    header('Cache-Control: no-store');

    // API key from header (getallheaders may not exist everywhere)
    $apiKey = '';
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strtolower((string) $name) === 'x-api-key') {
                $apiKey = trim((string) $value);
            }
        }
    }
    if ($apiKey === '') {
        $apiKey = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    }

    try {
        $apiUser = user_find_by_api_key($apiKey);
    } catch (Throwable $e) {
        json_response(['error' => 'Database unavailable'], 503);
    }
    if (!$apiUser) {
        json_response(['error' => 'Invalid API key'], 401);
    }
    if (($apiUser['status'] ?? 'active') === 'blocked') {
        json_response(['error' => 'Account suspended'], 403);
    }
    $uid = (string) ($apiUser['uid'] ?? $apiUser['id']);

    // Optional IP whitelist
    $whitelist = (array) ($apiUser['ip_whitelist'] ?? []);
    $whitelist = array_values(array_filter(array_map('trim', $whitelist)));
    if ($whitelist !== [] && !in_array(client_ip(), $whitelist, true)) {
        json_response(['error' => 'IP address not allowed'], 403);
    }

    // Rate limit: 60 requests per minute per key
    $window = date('YmdHi');
    $rlId = 'api_' . sha1($apiKey . '|' . $window);
    try {
        $rl = fs_get('api_ratelimits', $rlId);
        $count = $rl ? (int) ($rl['count'] ?? 0) : 0;
        if ($count >= 60) {
            header('Retry-After: 60');
            header('X-RateLimit-Limit: 60');
            header('X-RateLimit-Remaining: 0');
            json_response(['error' => 'Rate limit exceeded (60 requests per minute)'], 429);
        }
        fs_set('api_ratelimits', $rlId, ['count' => $count + 1, 'window' => $window, 'created_at' => time()], false);
        header('X-RateLimit-Limit: 60');
        header('X-RateLimit-Remaining: ' . max(0, 59 - $count));
    } catch (Throwable $e) {
        error_log('Rate limit check failed: ' . $e->getMessage()); // fail open, logged
    }

    // ---- create_link ----
    if ($apiAction === 'create_link') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_response(['error' => 'Use POST'], 405);
        }
        $raw = file_get_contents('php://input') ?: '';
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $body = $_POST;
        }
        $amount = (float) ($body['amount'] ?? 0);
        $customerName = trim((string) ($body['customer_name'] ?? ''));
        $customerEmail = trim((string) ($body['customer_email'] ?? ''));
        $customerPhone = preg_replace('/\D/', '', (string) ($body['customer_phone'] ?? ''));
        $note = trim((string) ($body['note'] ?? ''));
        $providerId = (string) ($body['provider_id'] ?? '');

        if ($amount <= 0 || $amount > 1000000) {
            json_response(['error' => 'Invalid amount'], 422);
        }
        if ($customerName === '') {
            json_response(['error' => 'customer_name is required'], 422);
        }
        try {
            // Pick provider: requested one, or the first active provider
            $provider = null;
            if ($providerId !== '') {
                $provider = fs_get('users/' . $uid . '/providers', $providerId);
                if (!$provider || ($provider['status'] ?? '') !== 'active') {
                    json_response(['error' => 'Provider not found or inactive'], 422);
                }
            } else {
                $active = user_active_providers($uid);
                $provider = $active[0] ?? null;
                if (!$provider) {
                    json_response(['error' => 'No provider connected. Connect one in the dashboard first.'], 422);
                }
                $providerId = (string) $provider['id'];
            }
            $commission = commission_for($amount);
            $balance = wallet_balance_fresh($uid);
            if ($balance < $commission) {
                json_response(['error' => 'Insufficient wallet balance for 2% commission', 'required' => $commission, 'balance' => $balance], 402);
            }
            $upiId = (string) (($provider['type'] ?? 'business') === 'personal' ? ($provider['selected_upi'] ?? '') : ($provider['upi_id'] ?? ''));
            $linkId = generate_link_id();
            $docId = generate_token(12);
            fs_set('payment_links', $docId, [
                'link_id' => $linkId,
                'uid' => $uid,
                'amount' => round($amount, 2),
                'commission' => $commission,
                'customer_name' => mb_substr($customerName, 0, 80),
                'customer_email' => mb_substr($customerEmail, 0, 120),
                'customer_phone' => $customerPhone,
                'note' => mb_substr($note, 0, 200),
                'provider_id' => $providerId,
                'provider' => (string) ($provider['provider'] ?? ''),
                'upi_id' => $upiId,
                'status' => 'pending',
                'utr' => null,
                'merchant_name' => (string) ($apiUser['name'] ?? 'Merchant'),
                'created_at' => time(),
            ], false);
            json_response([
                'payment_url' => url('pay.php?id=' . $linkId),
                'link_id' => $linkId,
                'amount' => round($amount, 2),
                'commission' => $commission,
            ]);
        } catch (Throwable $e) {
            error_log('API create_link failed: ' . $e->getMessage());
            json_response(['error' => 'Could not create the link'], 500);
        }
    }

    // ---- status ----
    if ($apiAction === 'status') {
        $id = trim((string) ($_GET['id'] ?? ''));
        if ($id === '') {
            json_response(['error' => 'id parameter is required'], 422);
        }
        try {
            $rows = fs_query('payment_links', [['link_id', '==', $id]], 1);
            $link = $rows[0] ?? null;
            if (!$link || ($link['uid'] ?? '') !== $uid) {
                json_response(['error' => 'Link not found'], 404);
            }
            $link = link_refresh_status($link);
            json_response(['status' => $link['status'] ?? 'pending', 'utr' => $link['utr'] ?? null, 'amount' => $link['amount'] ?? null, 'link_id' => $id]);
        } catch (Throwable $e) {
            json_response(['error' => 'Lookup failed'], 500);
        }
    }

    // ---- wallet ----
    if ($apiAction === 'wallet') {
        json_response(['balance' => wallet_balance_fresh($uid)]);
    }

    // ---- transactions ----
    if ($apiAction === 'transactions') {
        try {
            $rows = fs_query('payment_links', [['uid', '==', $uid]], 50, 'created_at', 'desc');
            $out = array_map(function ($l) {
                return [
                    'link_id' => $l['link_id'] ?? '',
                    'amount' => $l['amount'] ?? 0,
                    'customer_name' => $l['customer_name'] ?? '',
                    'status' => $l['status'] ?? 'pending',
                    'utr' => $l['utr'] ?? null,
                    'provider' => $l['provider'] ?? '',
                    'created_at' => $l['created_at'] ?? 0,
                ];
            }, $rows);
            json_response($out);
        } catch (Throwable $e) {
            json_response(['error' => 'Lookup failed'], 500);
        }
    }

    json_response(['error' => 'Unknown action'], 400);
}

// ===========================================================================
// Dashboard UI mode
// ===========================================================================
$user = auth_require_verified();
$uid = (string) ($user['uid'] ?? $user['id']);

$flash = flash_get();
$error = null;
$info = null;
if ($flash) {
    $flash['type'] === 'error' ? $error = $flash['message'] : $info = $flash['message'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'regenerate') {
        try {
            user_update($uid, ['api_key' => generate_api_key()]);
            flash_set('success', 'API key regenerated. Update your integrations.');
        } catch (Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        redirect('api.php');
    }

    if ($action === 'save_webhook') {
        $webhook = trim((string) ($_POST['webhook_url'] ?? ''));
        if ($webhook === '' || filter_var($webhook, FILTER_VALIDATE_URL) && (str_starts_with($webhook, 'https://') || preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?/#i', $webhook))) {
            try {
                user_update($uid, ['webhook_url' => $webhook]);
                flash_set('success', $webhook === '' ? 'Webhook URL cleared.' : 'Webhook URL saved.');
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage());
            }
        } else {
            flash_set('error', 'Webhook must be a valid https URL.');
        }
        redirect('api.php');
    }

    if ($action === 'save_whitelist') {
        $lines = preg_split('/\r\n|\r|\n/', (string) ($_POST['ip_whitelist'] ?? '')) ?: [];
        $ips = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (filter_var($line, FILTER_VALIDATE_IP)) {
                $ips[] = $line;
            } else {
                $error = 'Invalid IP in whitelist: ' . $line;
                break;
            }
        }
        if ($error === null) {
            try {
                user_update($uid, ['ip_whitelist' => $ips]);
                flash_set('success', 'IP whitelist saved (' . count($ips) . ' address' . (count($ips) === 1 ? '' : 'es') . ').');
                redirect('api.php');
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage());
            }
        }
    }
    $user = user_get($uid) ?? $user;
}

$apiKey = (string) ($user['api_key'] ?? '');
$webhookUrl = (string) ($user['webhook_url'] ?? '');
$ipWhitelist = implode("\n", (array) ($user['ip_whitelist'] ?? []));
$baseUrl = APP_URL;

$pageTitle = 'API & Webhooks';
$activePage = 'api';
require dirname(__DIR__) . '/includes/header.php';
?>

<?php if ($error): ?><div class="flash flash-error mb-4"><?= e($error) ?></div><?php endif; ?>
<?php if ($info): ?><div class="flash flash-success mb-4"><?= e($info) ?></div><?php endif; ?>

<!-- API key card -->
<div class="card p-5 sm:p-6">
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-5">
        <div class="min-w-0">
            <h2 class="font-extrabold text-lg tracking-tight flex items-center gap-2"><?= icon('key', 'w-5 h-5 text-violet-500') ?> Your API Key</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Authenticate with the <code class="font-mono text-violet-600 dark:text-violet-300">X-API-Key</code> header. Keep it secret - regenerate any time.</p>
            <div class="mt-3 flex items-center gap-2 bg-slate-100 dark:bg-slate-800/70 rounded-2xl px-4 py-3 max-w-xl">
                <code class="flex-1 font-mono text-xs sm:text-sm break-all select-all"><?= e($apiKey) ?></code>
                <button type="button" class="icon-btn !w-9 !h-9 shrink-0" data-copy="<?= e($apiKey) ?>" aria-label="Copy API key"><?= icon('copy', 'w-5 h-5') ?></button>
            </div>
        </div>
        <form method="POST" action="<?= e(url('api.php')) ?>" class="shrink-0" data-confirm="Regenerate your API key? Your old key stops working immediately." data-confirm-title="Regenerate API key">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="regenerate">
            <button type="submit" class="btn-secondary"><?= icon('refresh', 'w-4 h-4') ?> Regenerate Key</button>
        </form>
    </div>
</div>

<div class="grid lg:grid-cols-3 gap-6 mt-6">
    <!-- Webhook + whitelist -->
    <div class="card p-5 sm:p-6 h-fit">
        <h3 class="font-bold flex items-center gap-2"><?= icon('send', 'w-5 h-5 text-fuchsia-500') ?> Webhook</h3>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">CashPe POSTs <code class="font-mono">payment.success</code> events here, signed with HMAC-SHA256 of your API key (header <code class="font-mono">X-CashPe-Signature</code>).</p>
        <form method="POST" action="<?= e(url('api.php')) ?>" class="mt-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_webhook">
            <label class="label" for="webhook_url">Webhook URL</label>
            <input class="input font-mono text-xs" type="url" id="webhook_url" name="webhook_url" placeholder="https://yourapp.com/webhooks/cashpe" value="<?= e($webhookUrl) ?>">
            <button type="submit" class="btn-primary w-full mt-3"><?= icon('check', 'w-4 h-4') ?> Save Webhook</button>
        </form>

        <form method="POST" action="<?= e(url('api.php')) ?>" class="mt-6 pt-5 border-t border-slate-100 dark:border-slate-800">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_whitelist">
            <label class="label" for="ip_whitelist">IP whitelist (optional)</label>
            <textarea class="input font-mono text-xs" id="ip_whitelist" name="ip_whitelist" rows="3" placeholder="203.0.113.10&#10;198.51.100.24"><?= e($ipWhitelist) ?></textarea>
            <p class="mt-1.5 text-[11px] text-slate-400">One IP per line. Empty = allow from anywhere. Applied to all API calls.</p>
            <button type="submit" class="btn-secondary w-full mt-3"><?= icon('shield', 'w-4 h-4') ?> Save Whitelist</button>
        </form>

        <div class="mt-6 pt-5 border-t border-slate-100 dark:border-slate-800 text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
            <p class="flex items-center gap-1.5 font-bold text-slate-700 dark:text-slate-200"><?= icon('info', 'w-4 h-4') ?> Rate limit</p>
            <p class="mt-1">60 requests per minute per API key. Responses include <code class="font-mono">X-RateLimit-Limit</code> and <code class="font-mono">X-RateLimit-Remaining</code> headers.</p>
        </div>
    </div>

    <!-- Docs -->
    <div class="card p-5 sm:p-6 lg:col-span-2" x-data="{ tab: 'curl' }">
        <h3 class="font-bold flex items-center gap-2"><?= icon('file-text', 'w-5 h-5 text-indigo-500') ?> API Documentation</h3>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Base URL: <code class="font-mono text-violet-600 dark:text-violet-300"><?= e($baseUrl) ?>/api.php</code></p>

        <div class="mt-4 flex flex-wrap gap-2 border-b border-slate-100 dark:border-slate-800 pb-3">
            <button class="api-tab" :class="{ active: tab === 'curl' }" @click="tab = 'curl'">cURL</button>
            <button class="api-tab" :class="{ active: tab === 'php' }" @click="tab = 'php'">PHP</button>
            <button class="api-tab" :class="{ active: tab === 'node' }" @click="tab = 'node'">Node.js</button>
            <button class="api-tab" :class="{ active: tab === 'python' }" @click="tab = 'python'">Python</button>
        </div>

        <div class="mt-4 space-y-5">
            <div x-show="tab === 'curl'" x-cloak class="space-y-4">
                <div><p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Create a payment link</p>
<pre class="code-block">curl -X POST <?= e($baseUrl) ?>/api.php?action=create_link \
  -H <span class="str">"X-API-Key: <?= e($apiKey) ?>"</span> \
  -H <span class="str">"Content-Type: application/json"</span> \
  -d <span class="str">'{"amount": 499, "customer_name": "Rahul Sharma", "note": "Order #1042"}'</span>

<span class="cm"># Response: {"payment_url":"<?= e($baseUrl) ?>/pay.php?id=pl_...","link_id":"pl_...","amount":499,"commission":9.98}</span></pre></div>
                <div><p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Check status</p>
<pre class="code-block">curl <?= e($baseUrl) ?>/api.php?action=status&amp;id=pl_xxx \
  -H <span class="str">"X-API-Key: <?= e($apiKey) ?>"</span>

<span class="cm"># Response: {"status":"success","utr":"412345678901","amount":499,"link_id":"pl_xxx"}</span></pre></div>
                <div><p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Wallet balance &amp; transactions</p>
<pre class="code-block">curl <?= e($baseUrl) ?>/api.php?action=wallet -H <span class="str">"X-API-Key: <?= e($apiKey) ?>"</span>
<span class="cm"># {"balance": 1250.5}</span>
curl <?= e($baseUrl) ?>/api.php?action=transactions -H <span class="str">"X-API-Key: <?= e($apiKey) ?>"</span></pre></div>
            </div>

            <div x-show="tab === 'php'" x-cloak class="space-y-4">
                <div><p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Create a payment link</p>
<pre class="code-block"><span class="kw">&lt;?php</span>
$ch = curl_init(<span class="str">'<?= e($baseUrl) ?>/api.php?action=create_link'</span>);
curl_setopt_array($ch, [
    CURLOPT_POST =&gt; <span class="kw">true</span>,
    CURLOPT_RETURNTRANSFER =&gt; <span class="kw">true</span>,
    CURLOPT_HTTPHEADER =&gt; [
        <span class="str">'X-API-Key: <?= e($apiKey) ?>'</span>,
        <span class="str">'Content-Type: application/json'</span>,
    ],
    CURLOPT_POSTFIELDS =&gt; json_encode([
        <span class="str">'amount'</span> =&gt; 499,
        <span class="str">'customer_name'</span> =&gt; <span class="str">'Rahul Sharma'</span>,
        <span class="str">'note'</span> =&gt; <span class="str">'Order #1042'</span>,
    ]),
]);
$response = json_decode(curl_exec($ch), <span class="kw">true</span>);
<span class="kw">echo</span> $response[<span class="str">'payment_url'</span>];</pre></div>
                <div><p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Verify a webhook signature</p>
<pre class="code-block">$payload = file_get_contents(<span class="str">'php://input'</span>);
$signature = hash_hmac(<span class="str">'sha256'</span>, $payload, <span class="str">'<?= e($apiKey) ?>'</span>);
<span class="kw">if</span> (!hash_equals($signature, $_SERVER[<span class="str">'HTTP_X_CASHPE_SIGNATURE'</span>] ?? <span class="str">''</span>)) {
    http_response_code(401); <span class="kw">exit</span>;
}</pre></div>
            </div>

            <div x-show="tab === 'node'" x-cloak>
                <div><p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Create a payment link</p>
<pre class="code-block"><span class="kw">const</span> res = <span class="kw">await</span> fetch(<span class="str">'<?= e($baseUrl) ?>/api.php?action=create_link'</span>, {
  method: <span class="str">'POST'</span>,
  headers: {
    <span class="str">'X-API-Key'</span>: process.env.CASHPE_API_KEY,
    <span class="str">'Content-Type'</span>: <span class="str">'application/json'</span>,
  },
  body: JSON.stringify({ amount: 499, customer_name: <span class="str">'Rahul Sharma'</span> }),
});
<span class="kw">const</span> data = <span class="kw">await</span> res.json();
console.log(data.payment_url);</pre></div>
            </div>

            <div x-show="tab === 'python'" x-cloak>
                <div><p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Create a payment link</p>
<pre class="code-block"><span class="kw">import</span> requests

res = requests.post(
    <span class="str">"<?= e($baseUrl) ?>/api.php?action=create_link"</span>,
    headers={<span class="str">"X-API-Key"</span>: <span class="str">"<?= e($apiKey) ?>"</span>},
    json={<span class="str">"amount"</span>: 499, <span class="str">"customer_name"</span>: <span class="str">"Rahul Sharma"</span>},
    timeout=30,
)
print(res.json()[<span class="str">"payment_url"</span>])</pre></div>
            </div>
        </div>

        <div class="mt-5 flex flex-wrap gap-2 text-[10px] font-semibold">
            <span class="rounded-lg bg-indigo-50 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-300 px-2.5 py-1.5">POST create_link</span>
            <span class="rounded-lg bg-indigo-50 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-300 px-2.5 py-1.5">GET status</span>
            <span class="rounded-lg bg-indigo-50 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-300 px-2.5 py-1.5">GET wallet</span>
            <span class="rounded-lg bg-indigo-50 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-300 px-2.5 py-1.5">GET transactions</span>
        </div>
    </div>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
