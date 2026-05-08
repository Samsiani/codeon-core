# Payment Gateway Hardening

**Mandatory patterns for every CodeOn payment micro-plugin** (TBC Card, TBC Installments, BOG Card, BOG Installments, Credo, Flitt, future). These rules came out of the v0.2.0 audit pass on `codeon-bog-card-payment` — they apply equally to all banks.

> Read this *before* you scaffold a new gateway plugin. Half the items here are silent: skip them and the plugin still works in dev — but it's exploitable, racy, or financially wrong in production.

---

## 1. Webhook security — the 7-layer defence stack

Banks in this suite generally **do not** sign callbacks cryptographically. We compensate with a layered model. **Every layer is mandatory** unless explicitly noted.

```
1. IP allowlist (filterable, default open)
2. Locate WC order via external_order_id
3. Confirm payment_method matches our gateway id
4. Bind: hash_equals(payload bank-order-id, stored bank-order-id)   ← critical
5. Match amount (exact, integer minor units via Money::equals)
6. Match currency (case-insensitive, when payload provides it)
7. Trust-but-verify: re-fetch the receipt on every "money landed" event
```

### 1.1 Layer 4 — bind to the *bank-side* order id

The single most-missed defence. **The WC order ID is public.** It leaks via:

- order-confirmation emails
- thank-you / receipt page URLs (`?order-received=1234&key=…`)
- structured-data JSON-LD on order pages
- accountant exports
- abandoned-cart recovery tools

If your only "is this real" check is `external_order_id + amount + currency`, an attacker who orders once knows all three for any future order id by simple incrementation. They POST a forged "captured" payload to your callback URL, your handler matches all three, and the order flips to processing.

**Fix:** at `process_payment()` time, store the bank's order id (`META_ORDER_ID` convention) on the WC order. The bank's id is **not** public. In the webhook handler, after the payment-method check:

```php
$storedBankOrderId = (string) $order->get_meta(MyGateway::META_ORDER_ID);
if ($storedBankOrderId === '' || !hash_equals($storedBankOrderId, $payload['bank_order_id'])) {
    $logger->error('callback bank-order-id mismatch', [
        'order_id' => $order->get_id(),
        'received' => $payload['bank_order_id'],
    ]);
    return new WP_REST_Response(null, 200);
}
```

`hash_equals` is constant-time. The value isn't strictly secret, but the discipline propagates through the codebase consistently.

**Reference impl:** `codeon-bog-card-payment`'s `BogWebhookHandler::handle()` (v0.2.0+).

### 1.2 Layer 7 — trust but verify

The webhook is a *notification*. The bank's receipt endpoint is the *source of truth*. For any transition that releases goods or money (`OrderStatusMapper::RESULT_SUCCESS` and `RESULT_REFUND`), make a server-to-server `getOrder()` / `getReceipt()` call before applying:

```php
$result = OrderStatusMapper::fromMyBankEvent($payload['event'], $payload['status']);

if ($result === OrderStatusMapper::RESULT_SUCCESS) {
    $verify = $this->verifyWithBank($order, $storedBankOrderId, $logger);
    if ($verify === 'retry')    return new WP_REST_Response(null, 503); // bank retries
    if ($verify === 'mismatch') return new WP_REST_Response(null, 200);
}
```

`verifyWithBank()`:

- transient transport error → return `'retry'` so the handler responds **503** (banks retry)
- amount/currency don't match the bank's own record → `'mismatch'`, 200, and bail
- bank reports a non-success status despite the webhook saying success → `'mismatch'`, 200, and bail
- ok → `'ok'`

Cost: one extra HTTPS round-trip per successful webhook. Worth it.

### 1.3 Layer 1 — IP allowlist (filter, not config)

Don't ship a UI for it. Ship a filter and let paranoid merchants populate it:

```php
$allowed = (array) apply_filters('codeon_<plugin_slug>_webhook_allowed_ips', [], $request);
if (!empty($allowed) && !in_array($remoteIp, $allowed, true)) {
    return new WP_REST_Response(null, 403);
}
```

Default open. Filter-only because:

- bank egress IPs change without notice; locking them in plugin config produces silent outages
- merchants who don't know the bank's IPs can't fill in the field correctly anyway
- on-call engineers can drop a one-line `add_filter` mu-plugin to lock down without redeploying

### 1.4 Signed callbacks (RSA) — when the bank gives you a public key

Some banks (notably Bank of Georgia's new Payments API at `api.bog.ge/payments/v1/...`) RSA-sign every callback body and ship the public key in their docs. When that's the case, the signature is **the** authenticity check. It comes BEFORE every other layer in §1 — verify first, parse second. A request that fails RSA verification has no business being parsed at all.

**Use the framework's verifier** rather than rolling your own:

```php
use CodeOn\Framework\Http\RsaCallbackSignature;

$verifier = new RsaCallbackSignature(
    self::CALLBACK_PUBLIC_KEY_PEM,   // class constant on your plugin's Bog client
    new Logger($pluginSlug),
    'Callback-Signature',            // header name; default is fine
);

$rawBody  = $request->get_body();
$sig      = (string) $request->get_header($verifier->headerName());

if (!$verifier->verify($rawBody, $sig)) {
    // Hard 401 — this surfaces in the bank's dashboard so misconfigured
    // keys get caught fast. Returning 200 here would silently drop real
    // callbacks AND silently accept attacker callbacks; the failure mode
    // matters.
    return new WP_REST_Response(null, 401);
}
```

**Fail-closed by contract.** `RsaCallbackSignature::verify()` returns `false` when:
- the configured PEM is empty or malformed (deployment bug; logged as `error`),
- the signature header is missing or empty (logged as `warning`),
- the signature is not valid base64 (logged as `warning`),
- `openssl_verify(...) !== 1` — i.e. the signature does not match (NOT logged: this is the attack surface; quiet refusal prevents the log from becoming a probing oracle).

It never throws, never returns `true` on error, never falls back to "skip verification". If the gate is broken every callback is rejected — better than letting bad callbacks through.

**RSA replaces the `extra` HMAC defense** when both options exist on the same API. Card-V1 and the legacy installments API used a merchant-computed HMAC stuffed into an `extra` field as a poor-man's authenticity check; the new Payments API has no `extra` field and uses RSA instead. Don't try to combine the two — RSA is strictly stronger and the `extra` slot doesn't exist in the new request body.

**Bank-order-id binding (§1.1) still applies on top** — RSA proves the bank sent the body, but binding via `hash_equals(stored_bog_order_id, payload.body.order_id)` is what stops a replay against the wrong WC order if a payload from a different merchant accidentally targets your callback URL.

**Pass the raw body**, not a re-encoded version. `wp_json_encode($request->get_json_params())` will not produce the same byte sequence the bank signed — key order, whitespace, and Unicode escaping all differ. `WP_REST_Request::get_body()` is the only correct source.

**Where to store the public key:** as a class constant on the plugin's HTTP client (e.g. `BogPaymentsClient::CALLBACK_PUBLIC_KEY_PEM`). Don't load it from a file and don't accept it as a configurable option — the key is API-version-scoped, ships with the plugin, and rotates when the bank rotates the API. A merchant override would only let an attacker substitute their own key.

---

## 2. Idempotency + concurrency

Two paths can transition the same order at the same time: the **webhook** (pushed by the bank) and the **reconcile cron** (pulls from the bank's receipt endpoint to recover dropped webhooks). Both can land within the same millisecond.

### 2.1 Canonical event id

Both paths must use the **same** key shape when calling `WebhookEvents::record()`. Otherwise the framework's idempotency table sees them as different events and both succeed → both call `OrderStatusMapper::apply()`.

Pattern: a static helper on the webhook handler, called from both paths.

```php
public static function canonicalEventId(string $bankOrderId, string $status): string
{
    return $bankOrderId . ':' . strtolower($status === '' ? 'unknown' : $status);
}
```

**Don't include `event` in the key** if `event` and `status` can both be present. Pick one — `status` is usually the more reliable one across vocabularies. Lower-case it so casing variations between webhook ("Completed") and receipt ("completed") collide on the same row.

### 2.2 Per-order critical section

Even with a canonical event id, the *application* of a transition isn't atomic — `OrderStatusMapper::apply()` does multiple WC writes (status, notes, payment_complete) and isn't a database transaction. Wrap it in a per-order lock:

```php
public static function withOrderLock(int $orderId, callable $fn, int $ttlSeconds = 30): mixed
{
    $key = 'codeon_<slug>_lock_' . $orderId;
    $group = 'codeon-<slug>';

    if (wp_using_ext_object_cache()) {
        if (!wp_cache_add($key, 1, $group, $ttlSeconds)) {
            return null; // someone else is mid-apply
        }
        try { return $fn(); } finally { wp_cache_delete($key, $group); }
    }

    // Transient fallback for sites without an object cache.
    $optionKey = '_' . $key;
    $now = time();
    if ((int) get_option($optionKey, 0) > $now) return null;
    if (!add_option($optionKey, $now + $ttlSeconds, '', 'no')) {
        update_option($optionKey, $now + $ttlSeconds, false);
    }
    try { return $fn(); } finally { delete_option($optionKey); }
}
```

Why both paths:

- **`wp_cache_add`** is genuinely atomic on Redis/Memcached. Use when `wp_using_ext_object_cache()` is true.
- **Transient fallback** for shared-hosting installs without a persistent object cache. `add_option` returns false if the key already exists, so it's a usable single-flight primitive even though it's slower.

Both webhook and cron paths pass `OrderStatusMapper::apply()` through this lock. Lock losers return `null`; the caller logs and moves on (the winner already applied or will).

**Reference impl:** `BogWebhookHandler::withOrderLock()` (v0.2.0+).

---

## 3. Reconcile cron — fan out, don't loop

The hourly cron tick **must not** make outbound HTTP calls inline. Worst case = `LIMIT × bank_timeout` ≈ `50 × 15s` = 12 minutes per tick, which exceeds `max_execution_time` on most hosts.

### 3.1 Pattern: one async action per stuck order

```php
final class MyVerifyJob
{
    public const ACTION_RECONCILE_ONE = 'codeon_<slug>/reconcile_one';

    public function reconcile(): void
    {
        $stuck = $this->findStuckOrders();
        if (empty($stuck)) return;

        $hasAS = function_exists('as_schedule_single_action')
              && function_exists('as_has_scheduled_action');

        foreach ($stuck as $order) {
            if (!$hasAS) {
                self::runOne((int) $order->get_id());   // fallback
                continue;
            }
            $args = ['order_id' => (int) $order->get_id()];
            if (as_has_scheduled_action(self::ACTION_RECONCILE_ONE, $args, 'codeon-<slug>')) {
                continue;
            }
            as_schedule_single_action(time(), self::ACTION_RECONCILE_ONE, $args, 'codeon-<slug>');
        }
    }
}
```

`Routes::register()` then maps the action to `runOne($orderId)`. Action Scheduler is shipped with WooCommerce, so it's always available in this suite — but the inline fallback above keeps the plugin functioning if AS is somehow unloaded.

### 3.2 Bound the lookback window

A pending order from 6 months ago will never get captured. Sweeping it every hour wastes API calls and clutters logs. Cap the date range:

```php
private const STUCK_THRESHOLD_SECONDS = HOUR_IN_SECONDS;          // older than 1h
private const STUCK_LOOKBACK_SECONDS  = 10 * DAY_IN_SECONDS;       // but newer than 10d

$args['date_modified'] = $lookbackDate . '...' . $thresholdDate;
```

10 days is the convention across the suite. If you need longer, justify it in the plugin's CHANGELOG.

### 3.3 Resolve gateway via WC, don't `new` it

Always:

```php
$gateways = WC()->payment_gateways()->payment_gateways();
$gateway  = $gateways[MyGateway::ID] ?? null;
```

Never `new MyGateway()` from a webhook or cron job. WC stores settings on the registered instance; a new instance won't have them, and it bypasses the framework's license/recovery gating.

---

## 4. OAuth token caching — single-flight mutex

The naive cache pattern (read → expired? → fetch → write) breaks at scale. On token expiry, every concurrent checkout makes its own OAuth call to the bank. Banks rate-limit per client_id; you can lock the entire site out of payments for the duration of the rate-limit window.

```php
public function accessToken(): string
{
    $cacheKey = $this->tokenCacheKey();
    $cached = get_transient($cacheKey);
    if (is_array($cached) && ($cached['expires_at'] ?? 0) > time()) {
        return (string) $cached['token'];
    }

    $lockKey = $cacheKey . '_lock';
    $haveLock = wp_cache_add($lockKey, 1, 'codeon-<slug>', 30);

    if (!$haveLock) {
        usleep(150_000);                                  // ~150ms backoff
        $retry = get_transient($cacheKey);
        if (is_array($retry) && ($retry['expires_at'] ?? 0) > time()) {
            return (string) $retry['token'];               // refresher won
        }
        // refresher crashed — fall through and refresh ourselves
    }

    try {
        // …wp_remote_post to OAuth endpoint, decode, set_transient…
        return $token;
    } finally {
        if ($haveLock) wp_cache_delete($lockKey, 'codeon-<slug>');
    }
}
```

Apply a **TTL safety margin** to the cached token (subtract ~60s from the bank's stated `expires_in`) so the cache flips to "expired" before the bank actually rejects requests.

**Reference impl:** `BogClient::accessToken()` (v0.2.0+).

---

## 5. Money discipline

### 5.1 Always go through `Money`

Any amount that crosses the wire to the bank, or comes back from the bank, runs through `CodeOn\Framework\WooCommerce\Payments\Money`:

| Direction | Helper |
|---|---|
| Compare amounts | `Money::equals($a, $b, $currency)` — operates in integer minor units, immune to FP drift |
| Format for API | `Money::toApiDecimal($x, $currency)` — rounds to currency precision |
| Float ↔ minor units | `Money::toMinor($x, $currency)` / `Money::fromMinor($n, $currency)` |
| Currency support | `Money::isSupported($currency)` — gate `process_payment` |

Direct float comparison (`$a == $b`) and direct stringification (`(string) $float`) are **forbidden** for amounts that hit the wire.

### 5.2 String formatting for JSON bodies

`(string) 12.3` looks fine. `(string) 12.300000000000004` does not. PHP's `serialize_precision` setting affects what comes out of the cast, and you cannot rely on it being well-behaved on every host.

The `BogClient` v0.2.0+ pattern:

```php
public static function formatAmountForApi(float $amount, string $currency): string
{
    $rounded  = Money::toApiDecimal($amount, $currency);
    $decimals = Money::isGel($currency) ? 2 : 2;        // GEL/USD/EUR all 2dp
    return number_format($rounded, $decimals, '.', '');
}
```

Use this (or copy the helper) in every plugin that submits amounts in JSON. Never `(string) $amount`.

---

## 6. Partial-capture accounting

Pre-auth gateways (BOG card MANUAL capture, TBC card pre-auth, Credo with merchant confirmation) all support **partial** captures: hold X, capture Y where `Y ≤ X`, the bank releases the rest.

The naive WC implementation calls `$order->payment_complete($txnId)` after the partial capture and walks away. **This marks the full order total as paid in WC's books**, even though only `$amount` actually moved. Reports lie, accounting lies, every downstream integration lies.

The fix is not "don't call payment_complete" — you do want stock to deplete, completion emails to fire, and the order to enter `processing`. The fix is to also record the *un-captured remainder* as a non-API refund:

```php
case 'PARTIAL_COMPLETE':
    $order->update_meta_data(self::META_CAPTURED, 'partial');
    $order->update_meta_data(self::META_CAPTURED_AMOUNT, (string) $amount);
    $order->payment_complete($bankTxnId);

    $remainder = (float) $order->get_total() - $amount;
    if ($remainder >= 0.01 && function_exists('wc_create_refund')) {
        wc_create_refund([
            'order_id'       => $order->get_id(),
            'amount'         => $remainder,
            'reason'         => __('Partial capture — pre-auth remainder released by bank', '<text-domain>'),
            'refund_payment' => false,        // already happened bank-side
            'restock_items'  => false,
        ]);
    }
    break;
```

`refund_payment=false` is critical: the money was *never charged* in the first place (it was a held pre-auth that got voided), so we must not call the bank's refund endpoint. We're just making WC's ledger match reality.

Net effect:

- Order total: still the original amount
- Order status: `processing` / `completed` (depending on virtual flag)
- Net paid (WC ledger): `$amount` ✅
- Stock: deducted ✅
- Customer email: sent ✅

**Reference impl:** `OrderMetaBox::applySuccess()` in `codeon-bog-card-payment` (v0.2.0+).

---

## 7. Logging discipline

### 7.1 Never log raw bank responses

Bank responses can contain PII (payer name, last-4 of card, masked PAN, billing address). Whitelist what you actually need:

```php
// Bad — leaks the whole payload
$this->logError('unexpected response', ['response' => $response]);

// Good — diagnostics only
$this->logError('unexpected response', [
    'order_id'      => $order->get_id(),
    'response_keys' => array_keys($response),
    'has_redirect'  => isset($response['_links']['redirect']['href']),
    'has_id'        => isset($response['id']),
]);
```

### 7.2 Never log credentials or tokens

`client_id`, `client_secret`, `access_token`, request `Authorization` headers — never. Use the framework's `Logger` consistently and never pass these into the context array.

### 7.3 Log at the right level

- `info` — happy path landmarks (order created, webhook accepted)
- `warning` — recoverable anomalies (transport timeout, 401 → re-auth)
- `error` — security or data-integrity violations (mismatched amounts, mismatched bank order id, verification failures)

Never `debug`-log payment payloads outside of explicit debug mode (`$this->debugEnabled()` from `AbstractGateway`).

---

## 8. Admin handler ordering

Three checks, in this order, in every `admin_post_*` handler:

```php
public function handleSubmit(): void
{
    // 1. Capability — cheapest, gives correct 403 to unauth probes.
    if (!current_user_can('edit_shop_orders')) {
        wp_die(esc_html__('Insufficient permissions.', '<text-domain>'));
    }

    // 2. Input shape — die early on garbage.
    $orderId = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
    if ($orderId <= 0) wp_die(esc_html__('Invalid order.', '<text-domain>'));

    // 3. Nonce — scoped per-resource so it can't be replayed across orders.
    check_admin_referer(self::NONCE . '_' . $orderId);

    // 4. Now do the work.
}
```

**Cap before nonce, not the other way round.** Nonce is cheaper to fail in a tight loop, but capability is the right answer for unauth probes. The cost difference is negligible; the clarity is worth it.

---

## 9. Anti-patterns to avoid

### 9.1 "Defence-in-depth" code that defends nothing

If you compute something for security purposes and never *check* it, delete it. The BOG card v1 callback never echoes the `extra` field, so storing a `META_HMAC` on the order without ever verifying it on the way back is purely cosmetic — and worse, it implies a layer of defence that isn't there.

The framework's `AbstractGateway::callbackHmac()` *does* verify-on-return for banks that echo `extra` (TBC TPay does). For banks that don't, store nothing.

### 9.2 Inline cron loops with bank HTTP calls

See §3. Even with `LIMIT 10`, `10 × 15s = 150s` is still over PHP's default `max_execution_time`. Action Scheduler or bust.

### 9.3 `(string) $float` for amounts crossing the wire

See §5.2.

### 9.4 Resolving the gateway by `new`

See §3.3.

### 9.5 Returning 200 on transient errors

When the bank's verification endpoint fails *transiently* (timeout, 5xx, transport error), return **503**. Banks treat 503 as "retry me." Returning 200 silently drops the webhook and the reconcile cron has to clean up — wastes time and risks double-application.

Return 200 only when:
- the payload is malformed and can never succeed
- amount/currency mismatched (forged or buggy bank, no point in retrying)
- the webhook has already been processed (idempotency hit)

---

## 10. Test plan checklist for any new gateway

A new payment gateway is "done" only after every box ticks:

- [ ] Successful checkout → webhook arrives → order goes to processing/completed
- [ ] Successful checkout → webhook **does not arrive** → reconcile cron picks it up within 1h
- [ ] Webhook with **forged WC order id but real total** → rejected (test §1.1)
- [ ] Webhook with valid order id but **wrong amount** → order note added, no transition
- [ ] Webhook for an order paid with a **different gateway** → rejected
- [ ] Two simultaneous webhooks for the same order → exactly one transition + note (idempotency + lock)
- [ ] Webhook **and** cron racing on same order → exactly one transition + note (lock §2.2)
- [ ] OAuth token expires under 10 concurrent checkouts → exactly one OAuth call to bank (§4)
- [ ] Refund path → bank API called with `number_format(…, 2, '.', '')`-ed amount (§5.2)
- [ ] Partial capture → WC ledger shows `captured_amount` paid + remainder refunded (§6)
- [ ] Admin capture/cancel form → fails for non-`edit_shop_orders` users **before** nonce check
- [ ] Logger output on a failed checkout contains **no** payer PII, **no** access token

---

## 11. Reference implementation

`codeon-bog-card-payment` v0.2.0+ implements every pattern in this doc. Use it as the reference when scaffolding a new gateway:

| Concern | File | Symbol |
|---|---|---|
| Webhook 7-layer stack | `includes/Webhooks/BogWebhookHandler.php` | `handle()` |
| Trust-but-verify | same | `verifyWithBog()` |
| Canonical event id | same | `canonicalEventId()` |
| Per-order lock | same | `withOrderLock()` |
| IP allowlist | same | `ipAllowed()` / `remoteIp()` |
| Reconcile fan-out | `includes/Webhooks/Cron/BogVerifyPaymentJob.php` | `reconcile()` / `runOne()` |
| Token mutex | `includes/Api/BogClient.php` | `accessToken()` |
| Float→string helper | same | `formatAmountForApi()` |
| Partial-capture accounting | `includes/Admin/OrderMetaBox.php` | `applySuccess()` |
| Whitelisted logging | `includes/Gateways/BogCardGateway.php` | `process_payment()` failure path |

Copy the symbol, rename `Bog` → your bank, rewire the constants. Don't reinvent these patterns per plugin.
