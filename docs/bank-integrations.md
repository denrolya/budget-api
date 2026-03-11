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
```

**Flow — incoming webhook:**

```
Bank POST /api/webhooks/{provider}
  → BankWebhookController::receive()
    → BankWebhookService::handle()
      → provider->parseWebhookPayload()  → DraftTransactionData|null
        → create Transaction (or skip if null)
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

## Providers

### Wise

| Item | Value |
|---|---|
| Enum | `BankProvider::Wise` |
| Slug | `wise` |
| Webhook URL | `/api/webhooks/wise` |
| Webhook events | `balances#update`, `balances#credit` |
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

**Webhook registration caveat:** Personal Wise API tokens do not have webhook management permission. `registerWebhook()` throws `\LogicException` on 403, which the refresh command treats as SKIP (exit 0). **Register webhooks manually** in Wise UI:

> Wise → Settings → Developer tools → Webhooks

Register these two webhooks (same URL, different event):

| Name | URL | Event (UI label) | Wise `trigger_on` |
|---|---|---|---|
| Budget debit/credit | `https://<domain>/api/webhooks/wise` | Account deposit events | `balances#update` |
| Budget credit | `https://<domain>/api/webhooks/wise` | Account deposit events | `balances#credit` |

> If only one dropdown option is available for balance events, pick **Account deposit events**. The payload's `transaction_type` field distinguishes credits from debits at runtime.

**Env vars:**
```
WISE_API_KEY=<personal_api_token>
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

WISE_API_KEY=...
MONOBANK_API_KEY=...
```

> **Why port 443?** Wise rejects delivery URLs with non-standard ports.

### 2. Configure nginx / reverse proxy

The webhook endpoint must be reachable at `WEBHOOK_BASE_URL/api/webhooks/{provider}` over HTTPS on port 443. Proxy to PHP-FPM or the app server as appropriate.

### 3. Register webhooks

```bash
# For Monobank (automated):
vendor/bin/dep app:bank:webhooks:refresh production

# For Wise (manual — see Provider notes above):
# Register via Wise UI, then re-run to confirm Monobank is OK.
```

### 4. Verify

Check exit code and output:
```
OK   #N (monobank): https://your-domain.com/api/webhooks/monobank
SKIP #N (wise): ...      ← expected if using personal token
Summary: ok=1, skipped=N, failed=0
```

`failed=0` with exit code 0 = all good.

---

## CLI Reference

### `app:bank:webhooks:refresh`

Re-registers webhooks for all active integrations. Safe to run repeatedly (providers skip already-registered URLs).

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

**Log file** (when run via Deployer task):
```
/var/www/api/shared/var/log/bank-webhooks-refresh.log
```

---

## Adding a new provider

1. Create `src/Bank/Provider/YourBankProvider.php` implementing `BankProviderInterface`.
2. Add `WebhookCapableInterface` if the bank delivers transactions via webhooks.
3. Add a case to `BankProvider` enum with the correct slug.
4. Register the provider as a Symfony service and tag it (or add to `BankProviderRegistry`).
5. Add env vars and configure a scoped HTTP client in `config/packages/framework.yaml`.
6. Run tests: `composer test`.
