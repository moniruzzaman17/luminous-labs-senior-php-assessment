# Progress and triage

> These timestamps simulate the interrupt sequence described in the assignment. They represent my prioritization decisions, not Git commit timestamps or measured development telemetry.

- **T+00:00 - Start Ticket A.** I started with the main task because the brief allocated about 65% of the time to it and payment authenticity, retries, duplicate orders, and missing or incorrect orders carried the greatest business risk.
- **T+00:25 - Bound the urgent Ticket B interruption.** Dates were already being stored incorrectly, so another import could extend the corruption. I paused A after defining its core integrity approach and limited B to stopping new incorrect imports, rejecting uncertain dates, retaining source and batch evidence, adding focused tests, and identifying a separate historical audit requirement. I deliberately avoided guessing corrections for existing rows.
- **T+00:50 - Return to Ticket A.** B's forward-looking safeguard was reviewable, while A still needed retry, failure, conflict, and overlapping-request behavior verified. Those unfinished payment paths remained the highest risk, so A received the deepest testing.
- **T+02:00 - Assess Ticket C without displacing payment verification.** The endpoint was read-only, smaller, and explicitly lower urgency. Its main risk was the undefined public-versus-restricted access decision, so I kept access closed by default and made deliberate public enablement possible without inventing an authentication system.
- **T+02:15 - Complete cross-ticket review.** I checked that B's historical problem remained explicit, C's access decision remained visible, and no unfinished client input was hidden by completing the code.

## Resulting delivery order

Ticket B could finish first even though Ticket A started first because a small, bounded change immediately stopped further date corruption. Historical repair remained separate and dependent on authoritative source data and client approval.

Ticket A then received the largest implementation and verification effort because forged requests, retries, conflicts, storage failures, and concurrent delivery could create missing or incorrect orders. Ticket C finished last because it was smaller and read-only; its endpoint is closed by default until Marlow approves public access and the limited fields, or identifies the intended users for restricted access.
