# Luminous Labs Senior PHP Developer Assessment

This repository packages three unrelated client tickets in one Laravel application and one MySQL database so an assessor can install and review them together. Each ticket has separate business logic and tests. This is assessment packaging, not a recommendation that unrelated clients share a production application or database.

## Delivery status

| Ticket | Delivered | Production input still required |
|---|---|---|
| A — Fenwick Retail | Signed payment webhook, durable payment uniqueness, retry behavior, separate security/processing records, inspection command, and extensive tests | Actual provider payload/signing contract and alert owner/destination |
| B — Northgate Logistics | Strict source-specific parser, atomic CSV import, retained source evidence, and read-only historical audit | Original files, affected batches, confirmed office formats, record mapping, and correction approval |
| C — Marlow Events | Deterministic next-10 query, limited response fields, fictional data, and access guard | Intended audience, approved public fields, authentication approach, and business timezone |

## Review in five minutes

This is a short review path once the prerequisites below are installed; it was not timed on a clean machine.

```bash
git clone https://github.com/moniruzzaman17/luminous-labs-senior-php-assessment.git
cd luminous-labs-senior-php-assessment
composer install --no-interaction --prefer-dist
cp .env.example .env
php artisan key:generate
mysql -u root -p -e "CREATE DATABASE luminous_assessment CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE luminous_assessment_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan migrate:fresh --seed
composer check
php artisan serve
```

In PowerShell, replace the copy command with:

```powershell
Copy-Item .env.example .env
```

Before migrating or testing, set `DB_USERNAME` and `DB_PASSWORD` in `.env` and for the test process if they differ from the local defaults in `phpunit.xml`. The database-name guard refuses destructive tests unless the selected database ends in `_test`.

## Prerequisites

- Git 2.x.
- PHP 8.3 or newer with `ctype`, `dom`, `fileinfo`, `filter`, `hash`, `mbstring`, `openssl`, `pdo_mysql`, `session`, `tokenizer`, and `xml`.
- Composer 2.x.
- MySQL 8.0 or newer. MariaDB can be used for local development, but it does not replace MySQL-specific verification.

Initial runtime and database downloads are outside the short review path. I asked to omit Docker from this delivery.

## Installation

Install the exact dependency versions recorded in `composer.lock`:

```bash
composer install --no-interaction --prefer-dist
```

Copy the environment template and generate an application key:

```bash
cp .env.example .env
php artisan key:generate
```

PowerShell equivalent:

```powershell
Copy-Item .env.example .env
php artisan key:generate
```

## Environment configuration

`.env.example` documents every application-specific setting. Configure the development connection without committing `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=luminous_assessment
DB_USERNAME=your_local_user
DB_PASSWORD=your_local_password

PAYMENT_WEBHOOK_SECRET=local-demo-secret-change-me
PAYMENT_SIGNATURE_TOLERANCE=300
UPCOMING_EVENTS_PUBLIC_ENABLED=false
```

The included webhook secret is fictional and suitable only for the local demonstration. Use a generated secret in any shared environment.

## Dedicated MySQL databases

Create isolated development and test databases. The command prompts for the local MySQL password and does not place it in shell history:

```bash
mysql -u root -p -e "CREATE DATABASE luminous_assessment CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE luminous_assessment_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Do not point the test process at an existing database. When test credentials differ from `phpunit.xml`, set process environment variables before running tests.

```bash
export DB_USERNAME=your_test_user
export DB_PASSWORD=your_test_password
```

```powershell
$env:DB_USERNAME = 'your_test_user'
$env:DB_PASSWORD = 'your_test_password'
```

## Migrations and fictional seed data

Run migrations only after confirming `.env` names the dedicated development database:

```bash
php artisan migrate:fresh --seed
```

The seeder creates 12 fictional public events to demonstrate the endpoint limit and two fictional historical shipment rows to demonstrate mismatch and missing-evidence audit results.

## Application start

```bash
php artisan serve
```

The application listens on `http://127.0.0.1:8000`. Stop it with `Ctrl+C`. No queue worker or frontend build is required.

## Tests

```bash
composer check
```

The combined command clears cached configuration, runs the complete MySQL-backed test suite, and checks Laravel Pint formatting. The individual commands are:

```bash
php artisan test
vendor/bin/pint --test
```

PowerShell formatter command:

```powershell
vendor\bin\pint.bat --test
```

## Ticket A demonstration

`POST /webhooks/payment-provider` accepts a deliberately small Stripe-style contract; it is not claimed to be Stripe API compatible. The example uses integer minor units for money:

```json
{
  "id": "evt_demo_1001",
  "type": "payment.succeeded",
  "data": {
    "object": {
      "id": "pay_demo_1001",
      "amount": 1299,
      "currency": "GBP",
      "paid_at": "2026-09-08T09:55:00+00:00"
    }
  }
}
```

The signature covers the original request bytes:

```text
HMAC-SHA256(secret, timestamp + "." + raw_request_body)
Payment-Signature: t=<unix-seconds>,v1=<lowercase-hex-digest>
```

The secret comes from the environment, comparison is constant-time, and timestamps outside the configured five-minute tolerance are rejected. With the server running, send the same correctly signed payload twice through the real HTTP endpoint:

```bash
php scripts/send-sample-webhook.php
php scripts/send-sample-webhook.php
php artisan tinker --execute="echo App\\Models\\Order::where('payment_id', 'pay_demo_1001')->count().PHP_EOL;"
```

The responses are `201 created` and `200 duplicate`; the count is `1`. PowerShell users may alternatively run `scripts\send-sample-webhook.ps1` twice.

An invalid signature receives `401` and creates no order:

```bash
curl -i -X POST http://127.0.0.1:8000/webhooks/payment-provider -H "Content-Type: application/json" -H "Payment-Signature: t=0,v1=00" --data-binary @examples/payment-succeeded.json
```

Use `curl.exe` in PowerShell. A verified but permanently invalid event is recorded and acknowledged as `rejected`, preventing an unexplained permanent retry loop:

```bash
php scripts/send-sample-webhook.php http://127.0.0.1:8000/webhooks/payment-provider local-demo-secret-change-me examples/payment-invalid.json
php artisan webhooks:failures
```

The default command shows only verified provider events that require attention, which is the normal operational check. Security rejections and a combined diagnostic view are explicit:

```bash
php artisan webhooks:failures --type=processing
php artisan webhooks:failures --type=security
php artisan webhooks:failures --type=all
```

Security rejections are warning-level records in `storage/logs/webhook-security-*.log`; supplied signatures, raw bodies, untrusted event IDs, and payment details are never retained. Verified processing failures are error-level records in `storage/logs/webhook-processing-*.log` and may contain validated event/payment IDs. Both use 14-day daily retention and retain PHP `error_log` fallback behavior if Laravel logging fails.

Temporary processing failures return `503` with `Retry-After: 30`; success is returned only after the order transaction commits. A generic endpoint rate limit was deliberately omitted because an unexpected but legitimate provider burst could be rejected and amplify retries. Production security-volume control and aggregation should be agreed at the gateway, firewall, or monitoring layer after the provider's real delivery behavior is known. The processing stream should then alert the agreed payments on-call contact; no external integration is claimed here.

## Ticket B demonstration

Known sources map to exactly one confirmed format in `config/imports.php`. The two visually identical `03/04/2026` values in the sample become different dates because their sources are explicit:

```bash
php artisan shipments:import examples/shipments.csv
php artisan shipments:import examples/shipments.csv --commit
php artisan shipments:import examples/shipments-invalid.csv
php artisan shipments:audit-existing
```

The first command validates without writing, the second atomically inserts new rows and retains `source`, `import_batch`, and the raw value, and the third rejects an unknown source. An existing external ID aborts the whole import instead of overwriting historical data. The audit is read-only: fictional seeded rows demonstrate both a proposed mismatch and a record that cannot be resolved without original evidence. Historical correction requires the original files, affected batch references, confirmed office-to-format mapping, a reliable row-to-record mapping, and client approval.

## Ticket C demonstration

The endpoint is deliberately closed by default. For local review, set `UPCOMING_EVENTS_PUBLIC_ENABLED=true` in `.env`, then run:

```bash
php artisan config:clear
curl http://127.0.0.1:8000/api/events/upcoming
```

It returns at most 10 fictional eligible events ordered by `starts_at`, then `id`, with only `id`, `title`, `starts_at`, and `location`. Times are stored and returned in UTC. Past, cancelled, draft, and private events are excluded. Production always returns `403`, even if the feature flag is enabled.

Client clarification draft, not sent: “Should the upcoming events endpoint be publicly accessible, and which event details are approved for public display?” After the intended consumers are known, the smallest suitable policy would be either public access, existing session authentication, or a partner credential at this route boundary.

## Project structure

```text
app/
├── Console/Commands/
├── Http/Controllers/
├── Models/
└── Services/
    ├── Payments/
    └── Shipments/
database/
├── migrations/
└── seeders/
examples/
routes/
scripts/
tests/
├── Feature/
└── Unit/
README.md
PROGRESS.md
DECISIONS.md
STATUS_REPORT.md
AI_NOTES.md
```

| Component | Responsibility | Ticket |
|---|---|---|
| `WebhookSignatureVerifier` | Verifies timestamped raw-body signatures | A |
| `PaymentWebhookProcessor` | Commits an order and resolves only relevant uniqueness conflicts | A |
| `WebhookFailureReporter` / `webhooks:failures` | Separates security noise from verified processing failures and provides read-only inspection | A |
| `ShipmentDateParser` / shipment commands | Strict source parsing, atomic import, and read-only historical audit | B |
| `UpcomingEventController` | Applies access, eligibility, field, limit, and ordering policy | C |

## Request and processing flow

```text
Payment provider -> raw-body signature check -> sanitized security warning or continue
                 -> envelope/payment validation -> processing record if attention is needed
                 -> MySQL transaction + unique payment key -> created/duplicate
                 -> rejected (permanent) or 503 (temporary)

Shipment CSV -> exact source format -> validate every row -> dry run or one transaction
Existing shipments -> compare retained evidence -> report only, never mutate

Events request -> feature/production guard -> eligible UTC query -> four public fields
```

## Testing strategy

Testing effort follows business risk. Ticket A covers the real raw-body verification path, missing/malformed/stale signatures, body tampering, validation, unrelated events, sequential retries, different events for one payment, conflicts, failure and recovery, security/processing separation, sensitive-data exclusion, inspection modes, and four overlapping MySQL processes. Ticket B covers every configured format, ambiguity, impossible and leap-year dates, whitespace, unknown sources, atomic dry-run/import, and historical rows without evidence. Ticket C covers access policy, every eligibility filter, stable ordering, equal times, the 10-item limit, UTC boundary, empty results, and exact response fields.

## Assumptions and limitations

- A single provider account is represented, so `payment_id` and `provider_event_id` are globally unique. A multi-provider deployment must add provider/account identity to both keys.
- The real provider contract, rotation policy, retry schedule, and operational alert destination remain unknown.
- Gateway or firewall aggregation for hostile security traffic remains an operational decision; no generic application rate limit is imposed on legitimate provider delivery.
- The synchronous webhook is appropriate for assessment scale; high sustained volume would require measured capacity work and likely a durable inbox plus queue.
- Existing shipment dates are not automatically repaired because the necessary source evidence and approval were not supplied.
- Event visibility uses UTC and four conservative fields. Audience and authentication are unresolved, so production access remains disabled in code.
- A clean-machine installation was not available; verification used the current Windows workspace, native MySQL, and MariaDB as documented in `DECISIONS.md`.

## Required documents

- [Prioritisation and interrupt handling](PROGRESS.md)
- [Assumptions, AI review, scale, and omissions](DECISIONS.md)
- [Three client-facing updates](STATUS_REPORT.md)
- [Truthful AI work record](AI_NOTES.md)
