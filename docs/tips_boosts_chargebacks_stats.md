# Tips, Boosts, Chargebacks, and Stats

This document describes how payments and payment-related reporting currently work in MyDJRequests, including Stripe dispute (chargeback) handling.

## Scope

- Tip and boost payment creation (patron side)
- Stripe webhook settlement handling
- Event and monthly stats aggregation
- Dispute/chargeback lifecycle and accounting adjustments
- What tables are source-of-truth vs summary

## Key Files

- `/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/api/public/create_tip_checkout.php`
- `/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/api/public/create_boost_checkout.php`
- `/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/api/public/create_tip_intent.php`
- `/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/api/public/create_boost_intent.php`
- `/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/api/stripe/webhook.php`
- `/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/dj/event_details.php`
- `/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/dj/dashboard.php`
- `/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/dj/reports.php`

## Data Model

### Payment Transaction Tables (immutable event history)

- `event_tips`
- `event_track_boosts`

These hold individual successful payment records. They should be treated as immutable payment history records.

### Stats Tables (aggregated totals)

- `event_tip_stats`
- `event_tip_stats_monthly`
- `event_track_boost_stats`
- `event_track_boost_stats_monthly`

These are summary/rollup tables used by dashboards and reports. They are incremented/decremented by webhook accounting logic.

### Dispute / Webhook Control Tables

Created and maintained by `/api/stripe/webhook.php`:

- `stripe_webhook_events`
  - Stores processed Stripe webhook `event_id` for idempotency.
- `stripe_disputes`
  - One row per Stripe dispute (`dispute_id`).
  - Stores status, reason, amount, mapping to event/DJ/payment type, and funds movement flags.
- `stripe_dispute_events`
  - Audit log of dispute-related Stripe webhooks linked to each dispute.

## Payment Flow (Tips / Boosts)

1. Patron initiates payment from request page.
2. Stripe Checkout/Intent is created with metadata including:
   - payment type (`dj_tip` or `track_boost`)
   - `dj_user_id`
   - event identifiers
   - optional guest token/name
3. Stripe sends `payment_intent.succeeded` webhook.
4. Webhook inserts one row into:
   - `event_tips` for tips
   - `event_track_boosts` for boosts
5. Webhook updates summary tables:
   - event-level stats
   - monthly stats

## Idempotency Model

Webhook idempotency is handled by `stripe_webhook_events`.

- Each Stripe webhook `event.id` is inserted with `INSERT IGNORE`.
- If insertion fails (already exists), webhook exits 200 without reprocessing.
- This protects against duplicate delivery from Stripe.

## Chargeback / Dispute Handling

### Events handled

- `charge.dispute.created`
- `charge.dispute.updated`
- `charge.dispute.closed`
- `charge.dispute.funds_withdrawn`
- `charge.dispute.funds_reinstated`

### Mapping dispute to original payment

- Webhook resolves payment using `payment_intent_id` and searches:
  - `event_tips.stripe_payment_intent_id`
  - `event_track_boosts.stripe_payment_intent_id`

If found, dispute is linked to:
- `payment_type`
- `event_id`
- `dj_user_id`
- base payment amount/currency

### Dispute persistence

- Upsert into `stripe_disputes` by `dispute_id`.
- Insert audit row into `stripe_dispute_events` keyed by (`dispute_id`, `webhook_event_id`).

### Accounting adjustments

Only funds movement events change financial summaries:

- `charge.dispute.funds_withdrawn`
  - Applies negative amount adjustment to summary tables.
- `charge.dispute.funds_reinstated`
  - Applies positive reversal to summary tables.

#### Count adjustment rule

- Amount is always adjusted by disputed amount.
- Count is adjusted only for full-amount disputes (`disputed_amount_cents >= original_payment_amount_cents`):
  - withdrawn: count `-1`
  - reinstated: count `+1`
- Partial disputes do not change count; amount only.

## Important Behavioral Notes

1. Original payment rows are not deleted or rewritten
   - Chargebacks are modeled as separate accounting events.
2. Summary tables can go down as well as up
   - This is expected when disputes are withdrawn.
3. Dispute tables are the source of truth for dispute lifecycle
   - Use `stripe_disputes` and `stripe_dispute_events` for audit/ops.

## Reporting Impact

- Dashboard totals (`dj/dashboard.php`) read summary tables.
- Event details totals (`dj/event_details.php`) read transaction tables (successful rows).
- Reports (`dj/reports.php`) use event transaction data and/or summary depending on report section.

Because dispute adjustments are applied to summary tables, aggregated financial views can differ from raw succeeded transaction counts if disputes occurred.

## Operations Checklist

1. Monitor Stripe disputes regularly.
2. Review `stripe_disputes` for open statuses and evidence deadlines.
3. Validate that dispute mappings resolve to known payment intents.
4. Confirm `stripe_webhook_events` is preventing duplicates.
5. Reconcile net totals periodically:
   - gross succeeded payments
   - minus funds withdrawn
   - plus funds reinstated

## Stripe Sandbox Test Plan

Run these in test mode:

1. Successful tip payment
   - Verify `event_tips` and tip stats increment.
2. Successful boost payment
   - Verify `event_track_boosts` and boost stats increment.
3. Dispute created
   - Verify row in `stripe_disputes`.
4. Funds withdrawn
   - Verify negative summary adjustment.
5. Funds reinstated
   - Verify reversal summary adjustment.
6. Duplicate webhook replay
   - Verify no duplicate processing.
7. Partial dispute
   - Verify amount adjustment only, no count delta.

## Future Enhancements (recommended)

- Add admin/DJ dispute UI (open, won, lost, due date).
- Add optional notification/email on dispute.created and evidence deadlines.
- Add reconciliation report (gross vs net after disputes) by event and month.
- Add explicit feature flag for dispute accounting adjustments in production rollout.
