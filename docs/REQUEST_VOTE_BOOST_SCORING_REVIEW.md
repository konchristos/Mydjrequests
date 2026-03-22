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
Current behavior is not driven by one central scoring engine.

Instead, the app uses a few separate patterns:
- Requests are counted in `song_requests` and rolled up into monthly and per-event stats.
- Votes are counted in `song_votes` and rolled up into monthly and per-event stats.
- Boosts are counted in `event_track_boosts` and rolled up into monthly and per-event boost stats.
- The main request ordering logic on the patron page and DJ page currently uses:
  - `popularity = request_count + vote_count`
- Boosts are tracked and displayed, but are **not currently part of the numeric popularity score** used by the main request lists.
- I could not find a dedicated "monthly stars" accumulation model for requests / votes / boosts. Monthly persistence today is simple monthly counters, not a weighted star score.

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
Boosts are shown and bubbled up to the grouped track state (`hasBoosted`), but the patron request list score is still:
- `requests + votes`

Boosts do **not** currently add to `popularity_count` on the patron page.

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
Default sort is:
- `popularity DESC`

### DJ page detail panel
The center track detail panel shows:
- `Popularity`
- `Requests`
- `Votes`
- a separate Boost section if boosts exist

### Important note about boosts on DJ page
Boosts are visible and filterable:
- boosted rows get special styling
- there is a `Boosted` filter
- boost count is carried through group state

But boosts are **not part of the numeric popularity score** used for default ordering.

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
Boosts are **not included** in Top Patron ranking.
Top Patron is currently a requests-plus-votes activity rank.

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

So dashboard does **not** currently use a unified star score either.
It uses separate request, vote, and boost summaries.

## 12. Reports Wiring
Source: [dj/reports.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/dj/reports.php)

Reports use several separate query models depending on the report tab.

### Performance report
Uses raw `song_requests`, `messages`, and `event_page_views` grouped per event.
This is more of an event-performance view than a scoring engine.

### Top songs / activity report
For patron combined activity:
- `combined_total = request_count + vote_count`
- sorted descending by `combined_total`

### Repeat patron activity
In advanced analytics / repeat patron breakdown:
- `total_activity = total_requests + total_votes`

### Song popularity inside repeat patron analysis
- `popularity = request_count + vote_count`

### Revenue report
Boosts are handled in the monetary reporting layer, not the popularity layer.
The revenue report separately summarizes:
- `tip_count`
- `tip_amount`
- `boost_count`
- `boost_amount`

### Important note
Reports are currently consistent with the DJ/patron pages in one important way:
- requests and votes are combined into activity/popularity
- boosts are tracked separately, usually for money/revenue views

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
I could not find a dedicated monthly "stars" accumulation model for requests, votes, and boosts.

What *does* exist monthly is:
- `song_request_stats_monthly.total_requests`
- `song_vote_stats_monthly.total_votes`
- `event_track_boost_stats_monthly.total_boosts_count`
- `event_track_boost_stats_monthly.total_boosts_amount`
- `event_tip_stats_monthly.total_tips_count`
- `event_tip_stats_monthly.total_tips_amount`

There is also a separate concept of track ratings / stars from Rekordbox import, but that is unrelated to request/vote/boost monthly scoring.
Those imported stars are about library track metadata, not audience activity.

So if ChatGPT is asking to "change the scoring weight of requests, votes, and boosts," that would be a **new weighted scoring layer**, not a small tweak to an existing monthly star engine.

## 16. Current Scoring Formulas

### Patron page popularity
- `popularity = request_count + vote_count`

### DJ page popularity
- `popularity = request_count + vote_count`

### Top Patron ranking
- `total_actions = request_count + vote_count`

### Reports combined activity
- `combined_total = request_count + vote_count`

### Boost treatment today
- boost count is tracked
- boost count is displayed
- boost filters and boost history exist
- boost revenue is reported
- boost is **not currently added into the popularity / activity score** in the main request-ranking logic we reviewed

## 17. Practical Implication for Weight Changes
If ChatGPT wants to change the weighting of requests, votes, and boosts, there is **not one single place** to edit.
The current score behavior is duplicated across several surfaces.

At minimum, the weighting decision would likely need to be updated in:
- [api/public/get_event_requests.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/api/public/get_event_requests.php)
- [request/index.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/request/index.php)
- [request_v2/index.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/request_v2/index.php)
- [api/dj/get_requests.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/api/dj/get_requests.php)
- [dj/dj.js](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/dj/dj.js)
- [api/dj/get_event_insights.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/api/dj/get_event_insights.php) if Top Patron should change
- [dj/reports.php](/Users/konchristopoulos/Documents/Codex/MyDjRequests/public_html/dj/reports.php) if reports should match the new scoring model

## 18. Recommended Refactor Before Changing Weights
Before changing weights, the safest architecture would be:
1. define one shared score formula in one helper or SQL expression builder
2. decide explicitly whether boosts should affect:
  - patron-page ordering
  - DJ-page ordering
  - Top Patron ranking
  - reports / leaderboards
3. decide whether monthly weighted scores should be persisted or computed live
4. keep raw counters unchanged, and derive weighted score separately

A cleaner target model would be something like:
- `weighted_score = (request_weight * request_count) + (vote_weight * vote_count) + (boost_weight * boost_count)`

Then all pages can reuse the same score source instead of each page implementing its own `requests + votes` logic.

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

### C. Boosts are business-critical but score-neutral
Boosts matter financially and visually, but they currently do not affect:
- patron page popularity
- DJ page popularity
- Top Patron
- activity leaderboards

That is an intentional behavior today, but it is the biggest product decision to revisit if ChatGPT wants new scoring weights.

## 20. Short Version for ChatGPT
The system currently stores raw audience actions in separate tables and monthly rollups, but popularity is still mostly defined as:
- `requests + votes`

Boosts are tracked and displayed, but they are not yet part of the main popularity score.
There is no dedicated monthly weighted star system for requests/votes/boosts right now.
If weighting is going to change, the code should be refactored to introduce one shared scoring formula used consistently across:
- patron page
- DJ page
- Top Patron
- reports
- dashboards if desired
