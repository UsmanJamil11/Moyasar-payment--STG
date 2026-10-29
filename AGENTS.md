# Moyasar-payment--STG: instructions for AI coding tools

Moyasar payment adapter for **Serve The Galaxy (STG)** subscription plans (card, mada; wallets only when
configured). Requires the **stg-core** plugin. This is the entry point for Codex, Claude Code, Cursor and
similar tools. It is written to be safe if public: no keys, customer data or machine-specific paths here.

## Read the shared knowledge base first

The project's full knowledge lives in `wp-content/claude/`, two folders up from this repo:
[`../../claude/`](../../claude/README.md). It is **private and Git-ignored**. When it is present, read:

1. [`../../claude/WORKING-RULES.md`](../../claude/WORKING-RULES.md) and
   [`../../claude/SHARED-CONTEXT.md`](../../claude/SHARED-CONTEXT.md) (current handoff)
2. [`../../claude/19-payments.md`](../../claude/19-payments.md): integration design, modes, webhook security
3. [`../../claude/23-moyasar-integration-qa.md`](../../claude/23-moyasar-integration-qa.md): what was
   verified against Moyasar's real API, what was not, and the next test plan
4. `16-decisions.md` (search "Moyasar") before changing any payment rule

If `../../claude/` is missing (a fresh clone), ask the owner for the private copy before non-trivial work.

## What belongs in this repo

Provider-specific code only: Moyasar API client, checkout form, payment intents and attempts, webhook,
settlement and reconciliation, credentials and the payments admin screens. Plan rules, prices and
entitlements belong in **stg-core**; theme layout in **hello-stg**.

| Path | Holds |
|---|---|
| `stg-moyasar-payments.php`, `src/Plugin.php` | Bootstrap; namespace `STG\Moyasar`; `VERSION` |
| `src/Api/`, `src/Gateway/` | Moyasar HTTP client (`Fake_Client` is the scripted test provider) and gateway |
| `src/Payments/` | Intents, settlement, reconciliation, payment status, payment mail |
| `src/Rest/` | Webhook endpoint |
| `src/Credentials.php`, `src/Settings.php`, `src/Mode.php` | Keys, settings, test/live mode |
| `src/Admin/`, `views/` | Payments settings and staff screens |
| `src/Frontend/`, `templates/`, `assets/js/checkout.js` | Checkout and return screens |

## Invariants: do not break

- Never trust a browser-reported result. A payment is settled only by re-fetching it from Moyasar with the
  site's own key; settlement is idempotent; amount and currency mismatches are flagged, not accepted.
- Webhooks: verify the signature; apply the test/live mode check only when the body carries `live`.
- Credentials are never shown back, logged or written to files; a saved key is removed before replacing.
- Only send Moyasar's form the payment methods that are configured (unconfigured wallets stop the form).
- Agents never submit payments on a hosted site (beta or live), even with test cards; hand that to the owner.
- Strings are translatable in `stg-moyasar-payments`, in both EN and AR.

## Checks

Tests live in `wp-content/tools/`, outside this repo. Read a script before running it.

- `php tools/lint.php plugins/stg-moyasar-payments` (from `wp-content`)
- `node --check assets/js/checkout.js assets/js/admin.js`
- Suites: `tools/t-payments.php`, `t-subscription.php`, `t-subs.php`, `t-currency.php`. `t-payments`
  refuses to run while a live key is saved locally; see the QA notes for the current state.
- `composer check` (PHPCS + PHPStan) needs `composer install`; `vendor/` is not present by default.

## Git and release

- This folder is its own Git repo (branch `main`). Run Git commands inside it. The outer `app/public`
  repo still tracks these files and has no remote; do not commit there unless the owner asks.
- Never commit, push or deploy without the owner's explicit instruction. Short, human-written commit
  messages with no AI attribution lines. Never commit keys, backups, logs or customer data.
- Production zip: top folder `stg-moyasar-payments/`; leave out dotfiles, `AGENTS.md`, `CLAUDE.md`,
  `composer.json`, `phpstan.neon.dist`, `.po` and `.pot`.
