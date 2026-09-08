# Decisions

## 1. What was missing, what was assumed, and what needs confirmation?

### Ticket A — Fenwick Retail

The brief did not include the provider's real event shape, signing method, account scope, retry schedule, timestamp tolerance, response contract, or monitoring owner. For this assessment I defined a small Stripe-style envelope, a timestamped raw-body HMAC contract with a five-minute tolerance, integer minor units, and one provider account where payment and event identifiers are globally unique. This is reasonable because it demonstrates the required security and integrity boundaries without pretending to implement an unknown provider. Before production, Fenwick and the provider must confirm the actual contract, secret rotation, identifier scope, retry rules, reconciliation process, and the person and monitoring service that receive alerts.

### Ticket B — Northgate Logistics

The legacy importer, original files, office identifiers, affected batches, retained source values, and approved correction rules were not supplied. I implemented a representative CSV boundary where each known source maps to one explicit format, all rows validate before an atomic insert, and raw value/source/batch evidence is retained. An existing external ID aborts the import instead of changing a historical row. Existing data has a separate read-only audit and is never mass-swapped. This stops demonstrated future corruption while avoiding invented historical repairs. Before production, Northgate must supply the original files, affected batch references, confirmed office mapping, reliable source-row-to-record keys, and approval for a reviewed correction plan.

### Ticket C — Marlow Events

The brief did not define the audience, authentication, visibility rules, timezone, cancellation behavior, or approved public fields. I assumed UTC, future-or-current events that are public, published, and not cancelled, stable order by time then ID, and four conservative response fields. Access is closed by default in every environment. Deliberately setting `UPCOMING_EVENTS_PUBLIC_ENABLED=true` represents approval to expose only those fields; an open endpoint then allows anyone with its address to read them. Marlow must answer: “Should the upcoming events endpoint be publicly accessible, and which event details are approved for public display?” If access must be restricted, the intended consumers must be known before choosing an existing session or small partner-credential check.

The least-confident assumption is Marlow's intended audience. If it is wrong, approved information could be exposed too broadly or intended users could be blocked. That consequence justifies default denial until Marlow confirms access and fields; the explicit flag can then apply the approved public decision consistently in any environment.

## 2. How was AI used on Ticket A, and what was corrected?

### Direction I provided

I selected Laravel with MySQL, one reviewable assessment application with ticket-specific responsibilities, controllers for HTTP transport, services for business rules, database constraints for payment identity, raw-body signature verification, and risk-based tests weighted toward Ticket A. I required real MySQL and overlapping-process evidence, a reproducible signed request, visible failures, simple reviewer setup, and no frontend, queue, generic repository layer, or invented provider integration. This was AI-assisted implementation under my engineering direction and review; I did not manually author the generated source.

### What AI produced

AI produced `WebhookSignatureVerifier::verify()`, `PaymentWebhookController::__invoke()`, `PaymentWebhookProcessor::process()`, `WebhookFailureReporter`, the order schema, sample senders, inspection command, and the Ticket A feature and concurrency tests. The resulting design verifies the original request bytes before trusting fields, stores integer minor units, and uses unique MySQL indexes on both `orders.payment_id` and `orders.provider_event_id` as the concurrency authority.

### What the first output missed

The first draft omitted the integer cast in `Order::casts()`, so a MySQL-hydrated string could make a valid retry look conflicting. Later AI-assisted review found that permanently invalid verified events could trigger useless retries, exception messages could expose internal details, provider event identity lacked an independent constraint, and the test-database guard initially ran after Laravel's database traits. These were AI review findings, not discoveries I independently made. The resulting fixes are visible in `Order::casts()`, `PaymentWebhookController::__invoke()`, the assessment-table migration, and `Tests\TestCase::createApplication()`.

### Where I redirected or overrode it

A later AI-assisted version routed invalid-signature traffic through the same operational stream as verified provider-processing failures. During my review I identified that public untrusted traffic could bury genuine payment failures. I rejected that design and required `WebhookFailureReporter::reportSecurityRejection()` and `reportProcessingFailure()` to use separate bounded destinations, with processing as the default `webhooks:failures` view. I also required that signatures, secrets, raw bodies, untrusted event IDs, and exception messages never be retained. I did not add a generic webhook rate limit because it could reject legitimate provider bursts and amplify retries.

### How I verified the final behavior

`PaymentWebhookTest` exercises valid, forged, stale, tampered, malformed, unrelated, duplicate, conflicting, and retry-after-failure paths. `WebhookFailureInspectionTest` uses real HTTP requests, log files, and the executable command to prove reporting separation and unsafe-data exclusion. `WebhookConcurrencyTest` starts four overlapping PHP processes against MySQL, while the unique indexes in the assessment migration enforce one order per payment. I accepted the final behavior only after the complete MySQL suite, focused Ticket A tests, concurrency test, formatter, routes, signed HTTP demonstrations, one-order query, and inspection commands passed.

## 3. What probably fails first at 100 times Fenwick's volume, and how would we detect it?

The current endpoint verifies, validates, and commits synchronously. At 100 times volume, PHP and MySQL connection capacity would probably saturate first; rising transaction waits would lengthen responses, the provider would time out and retry, and those retries would amplify load. The unique payment index still protects order count, but it does not protect latency or connection capacity. A measured next step would be a durable webhook inbox committed quickly and queued processing with bounded retries, while retaining the payment uniqueness invariant. That infrastructure is not justified by the assessment's unknown volume.

I would instrument webhook duration (p50/p95/p99), status counts, provider retry count, duplicate count, permanently rejected count, temporary-failure count, MySQL connection utilisation, transaction lock time, deadlocks, and slow queries. Initial alert hypotheses would be non-2xx above 1% for five minutes, p95 above two seconds or p99 above four seconds for five minutes, database connections above 80% for ten minutes, any sustained rise in deadlocks, or temporary failures/retries exceeding twice their seven-day same-hour baseline. These thresholds require load testing and production baselines before adoption. A daily provider-to-order reconciliation would catch missing orders independently of request monitoring.

## 4. What was deliberately not built, and why?

- No Stripe SDK or claim of Stripe compatibility: the real provider contract is missing, and pretending otherwise would increase payment risk.
- No queue, Redis, microservice, webhook dashboard, or alert-vendor package: they would exceed the 3–4 hour scope without measured load or an agreed operations owner. A file channel, PHP fallback, retry response, and read-only command cover the assessment failure path.
- No generic application rate limit on the public webhook: an uninformed limit could reject legitimate provider bursts and amplify retries. Gateway/firewall aggregation and monitoring thresholds require the provider's delivery profile and an operational owner.
- No separate processed-event table: the order stores the provider event ID, and one transaction makes the required durable state atomic. A durable inbox becomes useful only with asynchronous processing or broader event types.
- No automatic historical date repair: the original evidence and client approval are absent, so mass changes could deepen existing corruption.
- No invented authentication or user system: Marlow has not identified the consumers. The endpoint is closed by default; explicit enablement represents approval of the four-field public response, while restricted access still requires Marlow to identify its users.
- No frontend, administration panel, repository abstraction, Swagger generator, or extra packages: they do not reduce the material risks being assessed.
- No Docker configuration: I explicitly removed it from scope; the native PHP, Composer, and MySQL path is documented and verified.
- No shared production deployment recommendation: one application/database exists only to package the three unrelated tickets for assessment review.
