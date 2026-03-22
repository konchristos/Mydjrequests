# Request, Vote, Boost, and Monthly Activity Review

## Purpose
This document explains how MyDJRequests currently:
- records song requests
- records votes
- records boosts
- stores monthly rollups
- feeds those numbers into the DJ page, patron page, dashboards, and reports
- calculates current popularity / activity ordering

This is intended as a handoff document for ChatGPT before changing the weighting of requests, votes, and boosts.

## Executive Summary
MyDJRequests now has a shared weighted scoring layer for request ranking, but raw counters and monthly rollups are still stored separately.

Current live behavior is:
- Requests are counted in `song_requests` and rolled up into monthly and per-event stats.
- Votes are counted in `song_votes` and rolled up into monthly and per-event stats.
- Boosts are counted in `event_track_boosts` and rolled up into monthly and per-event boost stats.
- A shared computed score now exists:
  - `score = (request_count * 2) + (vote_count * 1) + (boost_count * 10)`
- DJ page ordering now uses `score`.
- Patron page ordering now uses `score`.
- Reports and report-style rankings now use `score`.
- Dashboard and event list UI now expose boosts more consistently, but dashboard KPI rollups are still raw counts / revenue summaries rather than one single weighted scoreboard.
- There is still no dedicated monthly "stars" accumulation model for requests / votes / boosts. Monthly persistence remains raw counters, not a monthly weighted score history.

In short:
- **raw counts are still the source of truth**
- **weighted score is now the live ranking layer**
- **monthly stats are still count-based, not score-based**

## Primary Tables

### Raw activity tables
- `song_requests`
  - one row per request submission
- `song_votes`
  - one row per guest vote
- `event_track_boosts`
  - one row per successful track boost payment

### Event rollup tables
- `event_request_stats`
  - stores total requests per event
- `event_vote_stats`
  - stores total votes per event
- `event_tracks`
  - projection table keyed by `(event_id, track_identity_id)`
  - stores:
    - `request_count`
    - `vote_count`
    - `boost_count`
    - request timestamps
- `event_tip_stats`
- `event_track_boost_stats`

### Monthly rollup tables
- `song_request_stats_monthly`
  - `dj_id`, `year`, `month`, `total_requests`
- `song_vote_stats_monthly`
  - `dj_id`, `year`, `month`, `total_votes`
- `event_tip_stats_monthly`
  - monthly tip counts and amount by DJ and currency
- `event_track_boost_stats_monthly`
  - monthly boost counts and amount by DJ and currency

## 1. How Requests Are Tracked
Source: [api/public/submit_song.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/api/public/submit_song.php)

When a patron submits a request:
1. The endpoint validates event state and request payload.
2. It inserts a row into `song_requests`.
3. It increments DJ monthly request stats in `song_request_stats_monthly`.
4. It increments event request stats in `event_request_stats`.
5. If a `track_identity_id` is available, it updates the `event_tracks` projection.

### Monthly request rollup
`submit_song.php` calls an upsert pattern equivalent to:
- `song_request_stats_monthly.total_requests += 1`

### Event request rollup
It also updates:
- `event_request_stats.total_requests += 1`

### Event projection update
Via [app/helpers/event_tracks_projection.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/app/helpers/event_tracks_projection.php):
- `event_tracks.request_count += 1`

## 2. How Request Deletion Is Tracked
Source: [api/public/delete_my_request.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/api/public/delete_my_request.php)

If a patron deletes their own request:
- request deletion is blocked if the request is already:
  - played
  - boosted
  - voted
- if deletion proceeds:
  - `song_requests` row is deleted
  - `event_tracks.request_count` is decremented
  - `event_request_stats.total_requests` is decremented
  - `song_request_stats_monthly.total_requests` is decremented for the request month

This means request rollups are intended to stay in sync with request removal.

## 3. How Votes Are Tracked
Source: [api/public/vote_song.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/api/public/vote_song.php)

When a patron votes:
1. The endpoint inserts a row into `song_votes` using `INSERT IGNORE`.
2. If the insert is new, it increments:
  - `song_vote_stats_monthly.total_votes`
  - `event_vote_stats.total_votes`

### Monthly vote rollup
- `song_vote_stats_monthly.total_votes += 1`

### Event vote rollup
- `event_vote_stats.total_votes += 1`

## 4. How Vote Removal Is Tracked
Source: [api/public/unvote_song.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/api/public/unvote_song.php)

When a patron unvotes:
- matching vote row is deleted from `song_votes`
- if deletion succeeded:
  - `song_vote_stats_monthly.total_votes -= 1`
  - `event_vote_stats.total_votes -= 1`

## 5. How Boosts Are Tracked
Primary source: [api/stripe/webhook.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/api/stripe/webhook.php)

Boosts are not created by a simple page POST like requests or votes.
They are finalized through the Stripe webhook flow.

When a track boost payment succeeds:
1. A row is inserted into `event_track_boosts`.
2. Event boost stats are incremented in `event_track_boost_stats`.
3. Monthly boost stats are incremented in `event_track_boost_stats_monthly`.
4. Finance / ledger tables are also updated for reporting.

### Event boost rollup
- `event_track_boost_stats.total_boosts_count += 1`
- `event_track_boost_stats.total_boosts_amount += amount`

### Monthly boost rollup
- `event_track_boost_stats_monthly.total_boosts_count += 1`
- `event_track_boost_stats_monthly.total_boosts_amount += amount`

### Important note
Boosts are currently rolled up financially and event-level, but they are **not part of the main popularity formula** used by the request lists.
They are displayed and can be filtered, but they do not currently increase the numeric `popularity` used on the DJ/patron request lists.

## 6. How Chargebacks / Reversals Affect Boost Stats
Source: [api/stripe/webhook.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/api/stripe/webhook.php)

Boost and tip stats are adjusted through dispute / reversal handling.
This means boost totals are not purely append-only.
They can be decremented by the Stripe webhook if a payment is reversed.

## 7. Event Projection Layer (`event_tracks`)
Source: [app/helpers/event_tracks_projection.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/app/helpers/event_tracks_projection.php)

`event_tracks` is the projection table intended to aggregate event-level track activity by identity.

It contains:
- `request_count`
- `vote_count`
- `boost_count`
- `first_requested_at`
- `last_requested_at`

### Important note
The projection schema includes vote and boost counters, but the request insertion/deletion helpers we reviewed only explicitly update `request_count` in this helper file.
The main DJ request query currently still joins vote and boost counts separately instead of relying fully on projection counters.

So, today:
- `event_tracks` is an important event-level aggregation table
- but requests, votes, and boosts are not yet fully centralized into one scoring source of truth

## 8. Patron Page Wiring
Sources:
- [api/public/get_event_requests.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/api/public/get_event_requests.php)
- [request/index.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/request/index.php)
- [request_v2/index.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/request_v2/index.php)

### Raw data returned to patron page
`get_event_requests.php` builds grouped rows per track using:
- `request_count = COUNT(DISTINCT sr.id)`
- `vote_count = COUNT(DISTINCT sv_all.guest_token)`
- `popularity_count = request_count + vote_count`
- `has_boosted` is included separately
- `is_played` is included separately

### Patron page grouping
In `request/index.php` and `request_v2/index.php`, client-side JS groups tracks again by normalized title / artist and computes:
- `group.total_count += row.request_count`
- `group.vote_count += row.vote_count`
- `group.popularity_count = total_count + vote_count`

### Patron page sort behavior
Default patron-page ordering is:
- `popularity_count DESC`
- then `last_requested_at DESC`

### Important note about boosts on patron page
Boosts are now part of the patron-page ranking score.

The patron page still keeps the legacy `popularity_count` field around for compatibility/display, but ordering now follows:
- `score = (request_count * 2) + (vote_count * 1) + (boost_count * 10)`

## 9. DJ Page Wiring
Sources:
- [api/dj/get_requests.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/api/dj/get_requests.php)
- [dj/dj.js](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/dj/dj.js)

### Raw data returned to DJ page
`api/dj/get_requests.php` builds rows from:
- `event_tracks` projection when available
- fallback to legacy `song_requests` grouping when needed

For each row it joins:
- request count
- vote count
- boost count

It computes:
- `popularity = request_count + vote_count`

Specifically, the SQL uses:
- `COALESCE(v.vote_count, 0)`
- `COALESCE(b.boost_count, 0)`
- `(r_group.request_count + COALESCE(v.vote_count, 0)) AS popularity`

### DJ page client grouping
In [dj/dj.js](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/dj/dj.js):
- rows are grouped by normalized title + artist
- grouped values are merged with:
  - `group.popularity += row.popularity`
  - `group.request_count += row.request_count`
  - `group.vote_count += row.vote_count`
  - `group.boost_count += row.boost_count`

### DJ page default sorting
The DJ page now ranks by weighted score first:
- `score DESC`
- then `popularity DESC`
- then `last_requested_at DESC`

### DJ page detail panel
The center track detail panel shows:
- `Popularity Score`
- `Requests`
- `Votes`
- a separate Boost section if boosts exist

### Important note about boosts on DJ page
Boosts are now part of DJ-page ranking through the weighted score.

The DJ page also now surfaces that more clearly in the UI:
- boosted rows get special styling
- there is a `Boosted` filter
- boost count is carried through group state
- the request tile now shows `Popularity Score`
- the center detail tile now shows `Popularity Score`

The legacy `popularity` field still exists for compatibility, but it is no longer the primary ranking signal.

## 10. Top Patron Logic on DJ Page
Sources:
- [api/dj/get_event_insights.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/api/dj/get_event_insights.php)
- [dj/dj.js](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/dj/dj.js)
- [dj/index.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/dj/index.php)

### How Top Patron is calculated
Per guest token, the API computes:
- `request_count`
- `vote_count`
- `total_actions = request_count + vote_count`

Top patrons are sorted by:
1. `total_actions DESC`
2. `request_count DESC`
3. `vote_count DESC`
4. `patron_name ASC`

### Important note
Boosts are **still not included** in Top Patron ranking.
Top Patron remains a requests-plus-votes activity rank.

This is one of the main remaining intentional inconsistencies after the weighted scoring rollout.

## 11. Dashboard Wiring
Source: [dj/dashboard.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/dj/dashboard.php)

The DJ dashboard uses monthly rollup tables for speed.

### Request stats on dashboard
From `song_request_stats_monthly`:
- `lifetime_requests = SUM(total_requests)`
- `this_month_requests = SUM(total_requests for current year/month)`

### Vote stats on dashboard
From `song_vote_stats_monthly`:
- `lifetime_votes = SUM(total_votes)`
- `this_month_votes = SUM(total_votes for current year/month)`

### Boost stats on dashboard
From `event_track_boost_stats` and `event_track_boost_stats_monthly`:
- lifetime boost amount/count
- this-month boost amount/count

### Dashboard engagement metric
Dashboard computes:
- `voteEngagementRate = round((lifetimeVotes / lifetimeRequests) * 100)`

So dashboard does **not** currently use one single weighted score for top-level KPIs.
It still uses separate request, vote, and boost summaries.

However, recent UI alignment work did change a few surfaces:
- the dashboard no longer shows a boost badge on the upcoming-event banner, because boosts only make sense once an event is live
- the My Events page now shows requests, votes, and boosts per event card

## 12. Reports Wiring
Source: [dj/reports.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/dj/reports.php)

Reports use several separate query models depending on the report tab.

### Performance report
Uses raw event activity plus weighted score:
- requests
- votes
- boosts
- messages
- connected patrons

It now computes and displays:
- `total_requests`
- `total_votes`
- `total_boosts`
- `score`

Ordering now follows weighted score.

### Top Requested Songs
This report now aggregates:
- requests
- votes
- boosts

It computes:
- `request_count`
- `vote_count`
- `boost_count`
- `score`

Ordering now follows:
- `score DESC`
- then `request_count DESC`

### Top Activity report
Patron activity ranking now includes boosts and weighted score.

It computes:
- `request_count`
- `vote_count`
- `boost_count`
- `score`

Ordering now follows weighted score both:
- per event
- in combined patron activity views

### Repeat patron activity
Advanced analytics / repeat patron breakdown now includes boosts and weighted score.

It computes:
- `total_requests`
- `total_votes`
- `total_boosts`
- `score`

Per-track repeat-patron breakdown also computes:
- `request_count`
- `vote_count`
- `boost_count`
- `popularity`
- `score`

### Revenue report
Boosts are handled in the monetary reporting layer, not the popularity layer.
The revenue report separately summarizes:
- `tip_count`
- `tip_amount`
- `boost_count`
- `boost_amount`

### Important note
Reports are now mostly aligned with the weighted ranking model used by DJ/patron lists.

The remaining distinction is:
- revenue views still treat boosts as money events
- KPI/monthly rollup views still store raw counts rather than persisted weighted scores

## 13. Event Details Wiring
Source: [dj/event_details.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/dj/event_details.php)

The event details page reads:
- `event_request_stats.total_requests`
- `event_vote_stats.total_votes`
- boost summary/history from `event_track_boosts`
- tip summary/history from `event_tips`

This page surfaces the event counters but does not appear to define any special weighted popularity formula.

## 14. Admin / Event List Wiring
Source: [admin/get_events.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/admin/get_events.php)

Admin event summaries currently surface:
- `total_requests`
- `total_votes`

This is event-level reporting, not weighted ranking.

## 15. What "Monthly Stars" Currently Means in Code
There is still no dedicated monthly "stars" accumulation model for requests, votes, and boosts.

What *does* exist monthly is:
- `song_request_stats_monthly.total_requests`
- `song_vote_stats_monthly.total_votes`
- `event_track_boost_stats_monthly.total_boosts_count`
- `event_track_boost_stats_monthly.total_boosts_amount`
- `event_tip_stats_monthly.total_tips_count`
- `event_tip_stats_monthly.total_tips_amount`

There is also a separate concept of track ratings / stars from Rekordbox import, but that is unrelated to request/vote/boost monthly scoring.
Those imported stars are about library track metadata, not audience activity.

So if ChatGPT is asking to "change the scoring weight of requests, votes, and boosts," that would now mean:
- changing the **shared live weighted scoring layer**
- not changing any existing monthly star engine, because one still does not exist

## 16. Current Scoring Formulas

### Shared weighted score
- `score = (request_count * 2) + (vote_count * 1) + (boost_count * 10)`

This formula is now implemented centrally through:
- [app/helpers/scoring.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/app/helpers/scoring.php)

### DJ page ordering
- sorted by `score DESC`
- legacy `popularity` still exists as:
  - `popularity = request_count + vote_count`

### Patron page ordering
- sorted by `score DESC`
- legacy `popularity_count` still exists for compatibility/display

### Reports
- main ranking-focused report views now use `score`

### Top Patron ranking
- still uses:
  - `total_actions = request_count + vote_count`
- boosts are still excluded there

### Dashboard / monthly stats
- still raw-count driven
- not persisted as monthly weighted score

## 17. Practical Implication For Weight Changes
Changing weights is much safer now than it was before the scoring rollout, because the shared score helper exists.

Primary scoring helper:
- [app/helpers/scoring.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/app/helpers/scoring.php)

Main places already wired to that model:
- [api/public/get_event_requests.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/api/public/get_event_requests.php)
- [request/index.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/request/index.php)
- [request_v2/index.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/request_v2/index.php)
- [api/dj/get_requests.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/api/dj/get_requests.php)
- [dj/dj.js](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/dj/dj.js)
- [dj/reports.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/dj/reports.php)

Places that would still need explicit product decisions if weights change:
- [api/dj/get_event_insights.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/api/dj/get_event_insights.php) if Top Patron should start using boosts
- dashboard KPI summaries if monthly/lifetime weighted score should become visible
- any future admin exports that should expose weighted score directly

## 18. Recommended Refactor Before Changing Weights Again
The scoring rollout solved the biggest inconsistency, but there are still some follow-up decisions worth making before another weighting change:
1. decide whether Top Patron should move to weighted score or remain requests+votes only
2. decide whether monthly weighted scores should be persisted for historical analytics
3. decide whether dashboards should expose weighted score directly, or remain raw-count KPI views
4. keep raw counters unchanged, and continue deriving weighted score separately

The target model is still:
- `weighted_score = (request_weight * request_count) + (vote_weight * vote_count) + (boost_weight * boost_count)`

The difference now is that the app already has a shared implementation path for this.

## 19. Review Notes / Risks

### A. Monthly unvote decrement uses current month
Source: [api/public/unvote_song.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/api/public/unvote_song.php)

`unvote_song.php` decrements `song_vote_stats_monthly` using the current server month/year, not the original vote timestamp.
That means if a guest votes in one month and unvotes in a later month, monthly vote history can drift.

### B. `event_tracks` is not yet the sole source of truth
Source: [app/helpers/event_tracks_projection.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/app/helpers/event_tracks_projection.php) and [api/dj/get_requests.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/api/dj/get_requests.php)

The schema includes `request_count`, `vote_count`, and `boost_count`, but the live DJ query still joins vote and boost counts separately.
So a future weighting refactor should decide whether to:
- fully trust `event_tracks`, or
- keep composing counts from multiple sources

### C. Top Patron still lags behind the rest of the scoring model
Boosts now affect:
- patron page ordering
- DJ page ordering
- report rankings

But boosts still do **not** affect:
- Top Patron ranking

That is the biggest remaining product decision if ChatGPT suggests deeper scoring alignment.

## 20. Short Version for ChatGPT
The system stores raw audience actions in separate tables and monthly rollups, and it now uses a shared weighted score for most ranking surfaces:
- `score = (request_count * 2) + (vote_count * 1) + (boost_count * 10)`

This weighted score is now live in:
- patron page ordering
- DJ page ordering
- major report rankings

Raw counters still remain the source of truth, and monthly tables still store raw counts rather than monthly weighted scores.

The main intentional inconsistency that remains is:
- Top Patron still uses `requests + votes`
- not the weighted score with boosts

So if ChatGPT is going to suggest the next scoring change, the key questions are:
1. should Top Patron switch to weighted score
2. should dashboards expose weighted score directly
3. should monthly weighted history be persisted, or continue to be computed live

## 21. Phase Rollout Summary

### Phase 1 - Shared scoring helper
- Added shared scoring helper in:
  - [app/helpers/scoring.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/app/helpers/scoring.php)
- Added computed `score` into API/report payloads without changing ordering yet.

### Phase 2 - DJ scoring activation
- DJ page ordering switched to weighted score.
- DJ grouped request logic now carries `score`.

### Phase 3 - Patron scoring activation
- Patron page ordering switched to weighted score.
- Patron grouping logic now carries `boost_count` and `score`.

### Phase 4 - Reports, dashboard, and UI alignment
- Reports ranking aligned to weighted score.
- Dashboard/event surfaces updated to expose boosts more consistently.
- DJ request tile and center detail panel now visibly show `Popularity Score`.
- Boosted + played DJ tile state now uses a combined visual treatment.
