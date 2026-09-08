# Decisions

## 1. What was missing, what was assumed, and what needs confirmation?

### Ticket A — Fenwick Retail

The brief did not include the provider's real event shape, signing method, account scope, retry schedule, timestamp tolerance, response contract, or monitoring owner. For this assessment I defined a small Stripe-style envelope, a timestamped raw-body HMAC contract with a five-minute tolerance, integer minor units, and one provider account where payment and event identifiers are globally unique. This is reasonable because it demonstrates the required security and integrity boundaries without pretending to implement an unknown provider. Before production, Fenwick and the provider must confirm the actual contract, secret rotation, identifier scope, retry rules, reconciliation process, and the person and monitoring service that receive alerts.

### Ticket B — Northgate Logistics

The legacy importer, original files, office identifiers, affected batches, retained source values, and approved correction rules were not supplied. I implemented a representative CSV boundary where each known source maps to one explicit format, all rows validate before an atomic insert, and raw value/source/batch evidence is retained. An existing external ID aborts the import instead of changing a historical row. Existing data has a separate read-only audit and is never mass-swapped. This stops demonstrated future corruption while avoiding invented historical repairs. Before production, Northgate must supply the original files, affected batch references, confirmed office mapping, reliable source-row-to-record keys, and approval for a reviewed correction plan.

### Ticket C — Marlow Events

The brief did not define the audience, authentication, visibility rules, timezone, cancellation behavior, or approved public fields. I assumed UTC, future-or-current events that are public, published, and not cancelled, stable order by time then ID, and four conservative response fields. The endpoint works locally behind an explicit flag and is denied in production. This keeps the query reviewable without accidental disclosure. Marlow must answer: “Should the upcoming events endpoint be publicly accessible, and which event details are approved for public display?” The answer determines whether the route stays public, uses existing user sessions, or requires a small partner credential check.

The least-confident assumption is Marlow's intended audience. If it is wrong, private event information could be disclosed or intended users could be blocked. That consequence justifies the production deny rule until Marlow confirms access and fields.

## 2. How was AI used on Ticket A, and what was corrected?

I asked AI to propose and implement the Laravel structure, raw-body signature verification, transactional payment processing, schema, reproducible sender, and risk-based tests. I did not manually author the source. Ownership of each finding is stated below: the earlier defects were found during agent self-review, while I identified and directed the later reporting separation.

The first implementation hydrated `amount_minor` without an integer cast while strict duplicate comparison expected an integer. MySQL-family PDO hydration could therefore classify a valid retry as a conflict. `App\Models\Order::casts()` now casts it, and the repeated-delivery tests verify the correction.

The final review found three further weaknesses. First, verified but permanently invalid events returned non-2xx responses, which could cause endless provider retries. `App\Http\Controllers\PaymentWebhookController::__invoke()` now records safe context and returns `200 {"status":"rejected"}`, while only temporary processing failures return `503` with `Retry-After`. Second, exception messages were included in failure logs; these were replaced with exception classes so SQL details or credentials cannot leak. Third, event identity was not independently constrained. The `orders.provider_event_id` unique index and `App\Services\Payments\PaymentWebhookProcessor::process()` now distinguish a retry for the same payment from an event ID reused for another payment.

Tests verify raw-body tampering, missing/malformed/stale signatures, signed malformed JSON, invalid fields, unrelated events, repeated delivery, different events for one payment, reused event IDs, temporary failure and recovery, unrelated failure propagation, safe failure inspection, and four overlapping processes against MySQL. The final code is demonstrated by `WebhookSignatureVerifier::verify()`, `PaymentWebhookController::__invoke()`, `PaymentWebhookProcessor::process()`, the assessment-table migration, and `PaymentWebhookTest`/`WebhookConcurrencyTest`/`WebhookFailureInspectionTest`.

### My review and override

The initial AI-assisted implementation routed invalid-signature requests through the same reporting path as verified provider-processing failures. It made rejected requests visible, but during my review I identified that a public webhook could receive large volumes of untrusted traffic, causing genuine payment failures to be buried in operational noise.

I rejected that reporting design and directed a separation between security rejections and verified processing failures. Invalid requests now record only a sanitized rejection category and reason through `WebhookFailureReporter::reportSecurityRejection()`, while `reportProcessingFailure()` retains only the safe verified identifiers and failure details needed for investigation. `InspectWebhookFailures::handle()` defaults to the processing view and requires an explicit `--type=security` or `--type=all` for other views. I did not add a generic webhook rate limit because it could reject legitimate provider bursts and amplify retries. `WebhookFailureInspectionTest` verifies the separation, severity, sensitive-data exclusion, default priority, all-view behavior, and invalid option handling through real log files and the executable command.

This was my decision and an AI-assisted correction. I identified the operational risk, selected the separation policy, and defined the acceptance criteria. I used AI to implement the change and generate supporting tests, and I accepted the result only after the behavior I requested was demonstrated.

## 3. What probably fails first at 100 times Fenwick's volume, and how would we detect it?

The current endpoint verifies, validates, and commits synchronously. At 100 times volume, PHP and MySQL connection capacity would probably saturate first; rising transaction waits would lengthen responses, the provider would time out and retry, and those retries would amplify load. The unique payment index still protects order count, but it does not protect latency or connection capacity. A measured next step would be a durable webhook inbox committed quickly and queued processing with bounded retries, while retaining the payment uniqueness invariant. That infrastructure is not justified by the assessment's unknown volume.

I would instrument webhook duration (p50/p95/p99), status counts, provider retry count, duplicate count, permanently rejected count, temporary-failure count, MySQL connection utilisation, transaction lock time, deadlocks, and slow queries. Initial alert hypotheses would be non-2xx above 1% for five minutes, p95 above two seconds or p99 above four seconds for five minutes, database connections above 80% for ten minutes, any sustained rise in deadlocks, or temporary failures/retries exceeding twice their seven-day same-hour baseline. These thresholds require load testing and production baselines before adoption. A daily provider-to-order reconciliation would catch missing orders independently of request monitoring.

## 4. What was deliberately not built, and why?

- No Stripe SDK or claim of Stripe compatibility: the real provider contract is missing, and pretending otherwise would increase payment risk.
- No queue, Redis, microservice, webhook dashboard, or alert-vendor package: they would exceed the 3–4 hour scope without measured load or an agreed operations owner. A file channel, PHP fallback, retry response, and read-only command cover the assessment failure path.
- No generic application rate limit on the public webhook: an uninformed limit could reject legitimate provider bursts and amplify retries. Gateway/firewall aggregation and monitoring thresholds require the provider's delivery profile and an operational owner.
- No separate processed-event table: the order stores the provider event ID, and one transaction makes the required durable state atomic. A durable inbox becomes useful only with asynchronous processing or broader event types.
- No automatic historical date repair: the original evidence and client approval are absent, so mass changes could deepen existing corruption.
- No invented authentication or user system: Marlow has not identified the consumers. Production denial is a smaller and safer interim decision.
- No frontend, administration panel, repository abstraction, Swagger generator, or extra packages: they do not reduce the material risks being assessed.
- No Docker configuration: I explicitly removed it from scope; the native PHP, Composer, and MySQL path is documented and verified.
- No shared production deployment recommendation: one application/database exists only to package the three unrelated tickets for assessment review.
