# Bank Integrations

## Architecture

```
BankProviderInterface          — every provider implements this (fetchAccounts, fetchExchangeRates)
WebhookCapableInterface        — optional; providers that push transactions via webhooks
BankProviderRegistry           — service locator; maps BankProvider enum → provider instance
BankWebhookRegistrationService — builds webhook URL, delegates to provider.registerWebhook()
BankWebhookService             — handles incoming webhook POST → creates transaction
BankWebhookController          — public endpoint: POST /api/webhooks/{provider}
BankWebhooksRefreshCommand     — CLI: re-register webhooks across all active integrations
TransactionCategorizationService — fuzzy-matching categorization from historical transaction index
```

**Flow — incoming webhook:**

```
Bank POST /api/webhooks/{provider}
  → BankWebhookController::receive()
    → BankWebhookService::handle()
      → provider->parseWebhookPayload()  → DraftTransactionData|null
        → match BankCardAccount by externalAccountId
        → deduplicate (same account + amount + minute)
        → TransactionCategorizationService::suggest()  → CategorizationResult
          → create draft Transaction (or skip if null/duplicate)
```

**Flow — webhook registration:**

```
app:bank:webhooks:refresh
  → for each active BankIntegration where provider implements WebhookCapableInterface
    → BankWebhookRegistrationService::register()
      → resolveWebhookUrl()  (uses WEBHOOK_BASE_URL env or request host)
      → provider->registerWebhook(credentials, url)
        OK   → webhook active
        LogicException → SKIP (manual registration required; see provider notes)
        RuntimeException → FAIL (API error; command exits 1)
```

---

## Categorization

All incoming transactions (polling and webhook) are auto-categorized using `TransactionCategorizationService` before being persisted as drafts.

**Algorithm:**
1. **Index build** (once per sync run / per webhook event): DBAL raw query — last 2 years of confirmed (non-draft) transactions, grouped by normalized note, dominant category_id per note wins.
2. **normalize(note)**: lowercase → strip trailing dates/long numeric refs → strip `* # @ |` → strip `.com .io .net .org .ua` suffixes → collapse whitespace.
3. **Exact match** → confidence 1.0, use historical display note.
4. **Fuzzy token-set ratio** (`similar_text`, threshold ≥ 0.82) → confidence = score, use historical display note.
5. **Fallback** → Unknown category; raw bank string preserved as note.

**Excluded from index:** Transfer, Debt, Transfer Fee categories (prevents false auto-classification of internal movements).

---

## Providers

### Wise

| Item | Value |
|---|---|
| Enum | `BankProvider::Wise` |
| Slug | `wise` |
| Webhook URL | `/api/webhooks/wise` |
| Webhook events | `balances#update`, `balances#credit` |
| Webhook schema version | `3.0.0` |
| Exchange rates | Yes (`/v1/rates`, 24 h cache) |
| API base | `https://api.wise.com` |
| Auth | Bearer token via scoped HTTP client `wise_client` |

**Handled webhook events:**

| Event | What it means | `transaction_type` in payload |
|---|---|---|
| `balances#update` | Any balance change (credit or debit) | `credit` / `debit` |
| `balances#credit` | Money added to a balance | always `credit` |

Sign of the resulting transaction is determined by `data.transaction_type` in the payload (positive for credit, negative for debit). `balances#debit` is **not** a valid Wise API trigger type — do not attempt to register it.

Other events (`transfers#state-change`, etc.) are received but return `null` from `parseWebhookPayload` → silently ignored.

**Webhook registration:** `registerWebhook()` manages subscriptions via `GET/POST/DELETE /v3/profiles/{id}/subscriptions`. It automatically:
- Skips subscriptions already registered with the correct URL and schema version (`3.0.0`)
- Replaces stale subscriptions (wrong schema version) — deletes and recreates with `3.0.0`
- Requires a **Full Access** Wise API token (not Read-Only) to manage subscriptions

If the token lacks webhook permission, `registerWebhook()` throws `\LogicException` on 403, which the refresh command treats as SKIP. In that case register manually via Wise UI (see below).

**Wise UI manual registration (fallback):**

> Wise → Settings → Developer tools → Webhooks

| URL | Wise `trigger_on` | Coverage |
|---|---|---|
| `https://<domain>/api/webhooks/wise` | `balances#update` | credits and debits ✅ |

If you also subscribe to `balances#credit`, you may receive duplicate credit notifications — optional and not needed for transaction capture.

**Env vars:**
```
WISE_API_KEY=<full_access_api_token>
WISE_BASE_URL=https://api.wise.com   # use https://api.sandbox.transferwise.tech for sandbox
```

---

### Monobank

| Item | Value |
|---|---|
| Enum | `BankProvider::Monobank` |
| Slug | `monobank` |
| Webhook URL | `/api/webhooks/monobank` |
| Webhook events | All `StatementItem` events |
| Exchange rates | Yes (`/bank/currency`, 24 h cache) |
| API base | `https://api.monobank.ua` |
| Auth | `X-Token` header via `MONOBANK_API_KEY` |

**Webhook registration:** done automatically via `app:bank:webhooks:refresh`. Duplicate credentials (multiple integrations sharing the same API key) are detected and skipped.

**Amounts:** Monobank sends amounts in minor units (kopecks). The provider divides by 100.

**Env vars:**
```
MONOBANK_API_KEY=<token_from_monobank_app>
```

---

## Setup — new environment

### 1. Configure env vars

In `.env.local` (local) or `.env.production` (server):

```dotenv
WEBHOOK_BASE_URL=https://your-domain.com   # must be publicly reachable; no trailing slash; port 443 only

WISE_API_KEY=...        # Full Access token (not Read-Only)
MONOBANK_API_KEY=...
```

> **Why port 443?** Wise rejects delivery URLs with non-standard ports.

### 2. Configure nginx / reverse proxy

The webhook endpoint must be reachable at `WEBHOOK_BASE_URL/api/webhooks/{provider}` over HTTPS on port 443. Proxy to PHP-FPM or the app server as appropriate.

### 3. Register webhooks

```bash
vendor/bin/dep app:bank:webhooks:refresh production
```

Expected output:
```
OK   #N (wise): https://your-domain.com/api/webhooks/wise       ← subscription created/reused
OK   #N (monobank): https://your-domain.com/api/webhooks/monobank
Summary: ok=2, skipped=0, failed=0
```

`failed=0` with exit code 0 = all good.

### 4. Diagnose Wise (optional)

```bash
vendor/bin/dep run production -- 'php bin/console app:wise:diagnose'
```

Shows: API connectivity, balance accounts vs DB, subscription status (event, schema version, UUID, URL).

### 5. Verify end-to-end

```bash
# Simulate a transaction through the full pipeline (no real money):
vendor/bin/dep run production -- 'php bin/console app:wise:test-webhook --balance-id=YOUR_BALANCE_ID --amount=5.00 --currency=EUR --type=debit'

# Watch logs live:
vendor/bin/dep app:bank:logs:follow production
```

Expected log output:
```
[...] bank.INFO: [BankWebhook] Received wise: event=balances#update amount=5 EUR
[...] bank.INFO: [BankWebhook] Transaction #XXXXX created: debit 5.0 EUR for account #X
```

---

## CLI Reference

### `app:bank:webhooks:refresh`

Re-registers webhooks for all active integrations. Safe to run repeatedly (providers skip already-registered URLs with correct schema).

```bash
bin/console app:bank:webhooks:refresh [options]
```

| Option | Description |
|---|---|
| `-i <id>` | Process only integration with this database ID |
| `-p <provider>` | Filter by provider slug (`wise`, `monobank`). Repeatable. |
| `--include-inactive` | Include inactive integrations |
| `--dry-run` | Print what would run without calling provider APIs |

**Exit codes:** `0` = ok or all skipped, `1` = at least one FAIL.

---

### `app:wise:diagnose`

Checks Wise API connectivity, lists balance accounts vs DB records, shows subscription status.

```bash
bin/console app:wise:diagnose
```

Run this after any API token change or to verify webhook subscriptions.

---

### `app:wise:test-webhook`

Simulates a Wise webhook event through the full pipeline without making a real HTTP call.

```bash
bin/console app:wise:test-webhook [options]
```

| Option | Default | Description |
|---|---|---|
| `--balance-id` | `0` | Wise balance ID (= `BankCardAccount.externalAccountId`) |
| `--amount` | `1.00` | Transaction amount (always positive) |
| `--currency` | `EUR` | Currency code |
| `--type` | `credit` | `credit` or `debit` |
| `--schema` | `update-v3` | `update-v3`, `credit-v2`, or `credit-v3` |

Returns transaction ID on success, or explains why it returned null (account not found, duplicate, non-transaction payload).

---

### `app:bank:sync`

Polling-based sync. Wise does **not** support polling (SCA/2FA blocks personal token access). Monobank works via webhooks. This command is effectively a no-op for current providers but remains for future use.

```bash
bin/console app:bank:sync [--integration=<id>]
```

---

## Logs

All bank-related log entries go to a single file:

```
/var/www/api/shared/var/log/bank.log
```

**Format:** `[datetime] bank.LEVEL: message {context} []` (readable line format, not JSON)

**Live tailing:**
```bash
vendor/bin/dep app:bank:logs:follow production
```

**Dump last 200 lines:**
```bash
vendor/bin/dep app:bank:logs production
```

**Log entry examples:**
```
[2026-03-11T14:06:50.000Z] bank.INFO: [BankWebhook] Received wise: event=balances#update amount=10 EUR [] []
[2026-03-11T14:06:50.050Z] bank.INFO: [BankWebhook] Transaction #16220 created: debit 10.0 EUR for account #3 [] []
[2026-03-11T14:06:50.100Z] bank.WARNING: [BankWebhook] Duplicate transaction skipped for account #3 [] []
[2026-03-11T14:06:50.200Z] bank.WARNING: [BankWebhook] No account found for externalAccountId 0 [] []
```

---

## Adding a new provider

1. Create `src/Bank/Provider/YourBankProvider.php` implementing `BankProviderInterface`.
2. Add `WebhookCapableInterface` if the bank delivers transactions via webhooks.
3. Add a case to `BankProvider` enum with the correct slug.
4. Register the provider as a Symfony service and tag it (or add to `BankProviderRegistry`).
5. Add env vars and configure a scoped HTTP client in `config/packages/framework.yaml`.
6. Run tests: `composer test`.
