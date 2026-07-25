# Administration

PromoGuard provides one WordPress-native workspace under **PromoGuard**. The
available sections follow explicit role capabilities, and every data-heavy view
loads only when opened.

## Roles and sections

| Section | Capability | Administrator | Shop Manager |
|---|---|---:|---:|
| Dashboard | `view_promoguard_reports` | Yes | Yes |
| Campaigns | `manage_promoguard` | Yes | Yes |
| Usage history | `view_promoguard_reports` | Yes | Yes |
| Decisions | `view_promoguard_reports` | Yes | Yes |
| Settings | `manage_promoguard_settings` | Yes | No |
| Tools | `run_promoguard_tools` | Yes | Yes |

Custom roles need `manage_promoguard` to enter the workspace. Report, settings,
and tool access is then controlled independently inside it. REST routes enforce
the same capability boundaries as the visible controls.

## Dashboard and history

The dashboard reports campaign and usage lifecycle counts from PromoGuard-owned
tables. It does not scan WooCommerce orders. Use **Refresh dashboard** when a
fresh snapshot is needed.

Usage history records reservations, consumption, release, and restoration.
Filter by exact campaign ID, order ID, or lifecycle status. Decision history
contains persisted denial explanations and can be filtered by exact campaign
ID, order ID, or reason code. Both histories are paginated at 20 rows per page,
and private identifier hashes and diagnostic payloads are not returned.

## Settings

**Delete PromoGuard data when the plugin is uninstalled** is explicit consent
for uninstall cleanup. It is off by default. Deactivating the plugin never
removes campaign, customer, usage, or decision data.

Storage engine health is read-only. PromoGuard requires InnoDB for lock-safe
customer state and usage transitions; repair an unsupported database before
using protected promotions.

## Historical indexing

Historical indexing reconstructs PromoGuard usage from existing WooCommerce
orders. It runs in bounded Action Scheduler batches and supports HPOS and legacy
order storage through WooCommerce CRUD APIs.

- Leave **Order IDs** blank to scan all eligible historical orders.
- Enter up to 100 unique, comma-separated positive order IDs for a targeted job.
- Choose a batch size from 1 to 100; the default is 50.
- **Pause** is available for queued or running jobs.
- **Resume** is available for paused jobs.
- **Retry failures** is available for a failed job or a completed job with
  retryable order errors.
- **Restart job** resets progress for a paused, completed, or failed job.

Starting a full scan and restarting a job require confirmation. The current job
view exposes bounded counters and safe error summaries. Refreshing status is a
single read; mutations use their returned job snapshot and do not perform a
redundant follow-up request.

## Accessibility and responsive behavior

Workspace navigation uses visible current-page state and preserves deep links in
the URL hash. Loading and mutation feedback is announced through polite status
regions, controls expose visible keyboard focus, motion respects the reduced
motion preference, and history tables become labelled record cards on narrow
screens.
