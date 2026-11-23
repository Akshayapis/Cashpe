<?php
/**
 * CashPe - Firestore data access helpers. Every query goes through here
 * so pages stay small and errors are handled uniformly.
 */

require_once dirname(__DIR__) . '/config/firebase.php';

/** Get a document: returns ['id' => ..., 'data' => [...]] or null when missing. */
function fs_get(string $collection, string $id): ?array
{
    try {
        $snap = firestore()->collection($collection)->document($id)->snapshot();
        if (!$snap->exists()) {
            return null;
        }
        $data = $snap->data() ?? [];
        $data['id'] = $snap->id();
        return $data;
    } catch (Throwable $e) {
        error_log('fs_get failed: ' . $e->getMessage());
        throw new RuntimeException('Database read failed. Please try again.');
    }
}

/** Merge into a document, creating it when missing. */
function fs_set(string $collection, string $id, array $data, bool $merge = true): void
{
    try {
        firestore()->collection($collection)->document($id)->set($data, ['merge' => $merge]);
    } catch (Throwable $e) {
        error_log('fs_set failed: ' . $e->getMessage());
        throw new RuntimeException('Database write failed. Please try again.');
    }
}

/** Replace a document fully. */
function fs_replace(string $collection, string $id, array $data): void
{
    try {
        firestore()->collection($collection)->document($id)->set($data);
    } catch (Throwable $e) {
        error_log('fs_replace failed: ' . $e->getMessage());
        throw new RuntimeException('Database write failed. Please try again.');
    }
}

/** Update specific fields only. */
function fs_update(string $collection, string $id, array $data): void
{
    if ($data === []) {
        return;
    }
    try {
        $fields = [];
        foreach ($data as $key => $value) {
            $fields[] = ['path' => $key, 'value' => $value];
        }
        firestore()->collection($collection)->document($id)->update($fields);
    } catch (Throwable $e) {
        error_log('fs_update failed: ' . $e->getMessage());
        throw new RuntimeException('Database update failed. Please try again.');
    }
}

/** Create a document with a random id; returns the id. */
function fs_add(string $collection, array $data): string
{
    $id = generate_token(16);
    fs_set($collection, $id, $data, false);
    return $id;
}

function fs_delete(string $collection, string $id): void
{
    try {
        firestore()->collection($collection)->document($id)->delete();
    } catch (Throwable $e) {
        error_log('fs_delete failed: ' . $e->getMessage());
        throw new RuntimeException('Database delete failed. Please try again.');
    }
}

/**
 * Query a collection.
 * $wheres: list of [field, operator, value]
 * Returns list of ['id' => ..., ...data]. Sorting is done in PHP so no
 * composite indexes are required.
 */
function fs_query(string $collection, array $wheres = [], ?int $limit = null, ?string $orderBy = null, string $direction = 'desc'): array
{
    try {
        $query = firestore()->collection($collection);
        foreach ($wheres as [$field, $operator, $value]) {
            $query = $query->where($field, $operator, $value);
        }
        if ($limit !== null) {
            $query = $query->limit($limit);
        }
        $documents = $query->documents();
        $rows = [];
        foreach ($documents as $document) {
            if (!$document->exists()) {
                continue;
            }
            $data = $document->data() ?? [];
            $data['id'] = $document->id();
            $rows[] = $data;
        }
        if ($orderBy !== null) {
            usort($rows, function ($a, $b) use ($orderBy, $direction) {
                $av = $a[$orderBy] ?? 0;
                $bv = $b[$orderBy] ?? 0;
                $cmp = $av <=> $bv;
                return $direction === 'desc' ? -$cmp : $cmp;
            });
        }
        return $rows;
    } catch (Throwable $e) {
        error_log('fs_query failed on ' . $collection . ': ' . $e->getMessage());
        throw new RuntimeException('Database query failed. Please try again.');
    }
}

/** Collection group query (used for magic link tokens). */
function fs_collection_group_query(string $collectionGroup, array $wheres = [], ?int $limit = null): array
{
    try {
        $query = firestore()->collectionGroup($collectionGroup);
        foreach ($wheres as [$field, $operator, $value]) {
            $query = $query->where($field, $operator, $value);
        }
        if ($limit !== null) {
            $query = $query->limit($limit);
        }
        $documents = $query->documents();
        $rows = [];
        foreach ($documents as $document) {
            if (!$document->exists()) {
                continue;
            }
            $data = $document->data() ?? [];
            $data['id'] = $document->id();
            $data['__path'] = $document->reference()->path();
            $rows[] = $data;
        }
        return $rows;
    } catch (Throwable $e) {
        error_log('fs_collection_group_query failed: ' . $e->getMessage());
        throw new RuntimeException('Database query failed. Please try again.');
    }
}

function fs_delete_path(string $path): void
{
    try {
        firestore()->document($path)->delete();
    } catch (Throwable $e) {
        error_log('fs_delete_path failed: ' . $e->getMessage());
    }
}

// ----- Users -----

function user_find_by_email(string $email): ?array
{
    $rows = fs_query('users', [['email', '==', strtolower(trim($email))]], 1);
    return $rows[0] ?? null;
}

function user_find_by_google_id(string $googleId): ?array
{
    $rows = fs_query('users', [['google_id', '==', $googleId]], 1);
    return $rows[0] ?? null;
}

function user_find_by_api_key(string $apiKey): ?array
{
    if ($apiKey === '') {
        return null;
    }
    $rows = fs_query('users', [['api_key', '==', $apiKey]], 1);
    return $rows[0] ?? null;
}

function user_find_by_callback_token(string $token): ?array
{
    if ($token === '') {
        return null;
    }
    $rows = fs_query('users', [['callback_token', '==', $token]], 1);
    return $rows[0] ?? null;
}

function user_get(string $uid): ?array
{
    return fs_get('users', $uid);
}

function user_update(string $uid, array $data): void
{
    fs_update('users', $uid, $data);
}

function user_providers(string $uid): array
{
    return fs_query('users/' . $uid . '/providers', [], null, 'created_at', 'asc');
}

function user_active_providers(string $uid): array
{
    return array_values(array_filter(user_providers($uid), function ($p) {
        return ($p['status'] ?? 'active') === 'active';
    }));
}

// ----- Wallet transactions -----

function wallet_transactions_for(string $uid, int $limit = 50): array
{
    return fs_query('wallet_transactions', [['uid', '==', $uid]], $limit, 'created_at', 'desc');
}

// ----- Settings (Firestore-backed, env fallback) -----

/** Returns the merged settings map: cached for the duration of the request. */
function app_settings(): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }
    $settings = [];
    try {
        $doc = fs_get('settings', 'app');
        if (is_array($doc)) {
            $settings = $doc;
        }
    } catch (Throwable $e) {
        error_log('app_settings fallback to env: ' . $e->getMessage());
    }
    return $settings;
}

function setting(string $key, mixed $default = null): mixed
{
    $settings = app_settings();
    if (array_key_exists($key, $settings) && $settings[$key] !== '' && $settings[$key] !== null) {
        return $settings[$key];
    }
    return $default;
}

/** Effective commission percent (Firestore override, else env, else 2). */
function commission_percent(): float
{
    $value = setting('commission_percent', env('COMMISSION_PERCENT', '2'));
    $percent = (float) $value;
    return ($percent > 0 && $percent <= 50) ? $percent : 2.0;
}

function commission_for(float $amount): float
{
    return round($amount * commission_percent() / 100, 2);
}

// ----- Webhook logs -----

function webhook_log(string $uid, string $webhookUrl, int $statusCode, bool $success, string $detail): void
{
    try {
        fs_add('webhook_logs', [
            'uid' => $uid,
            'webhook_url' => $webhookUrl,
            'status_code' => $statusCode,
            'success' => $success,
            'detail' => substr($detail, 0, 500),
            'created_at' => time(),
        ]);
    } catch (Throwable $e) {
        error_log('webhook_log failed: ' . $e->getMessage());
    }
}
