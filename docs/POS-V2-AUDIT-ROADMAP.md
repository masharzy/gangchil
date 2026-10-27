# GANGCHIL POS V2 — Restaurant operations redesign

## Deployment isolation
Feature branch feat/industry-pos-v2. Existing InfinityFree deployment on master remains untouched until acceptance. Never overwrite live SQL database or installer. Real-device tests before production cutover.

## Confirmed operating constraints
- ONE shared tablet enters orders serially; customers do not self-order.
- A few authenticated manager, chef, waiter devices receive events.
- Cash only. Manager confirms cash before order enters the kitchen.
- PHP + MySQL + vanilla HTML/CSS/JS; Pusher private channel plus one-click AJAX Refresh; no timed polling.
- Mobile/tablet-first; 80mm receipt printing; staff roles, permissions and audit.
- Existing sequence: submit -> pending_payment -> new -> preparing -> ready -> served.

## Current gaps confirmed by source review
1. Cashier: pay action only toggles an order status; no tendered cash, change, register, ledger or drawer shift.
2. Kitchen: generic order list; no dedicated KDS New/Preparing/Ready queues, timers or urgent tickets.
3. Tables: table name/active only; no occupied/free state or active sessions.
4. Cart: basic menu and separate cart; no fast split-pane POS, search, held drafts, modifiers.
5. Paid corrections: no refund path; cancellation limited to unpaid orders; no reason.
6. Reports: two numbers (paid orders and sales), plus top items; no cash reconciliation or filtered ledger.
7. Staff: shared screens and no role-tailored home despite backend RBAC.
8. History: only latest 150 orders, filtered in browser; no durable search/pagination.
9. Audit: partial trail and no reason, previous value, refund reference, or reprint audit.
10. Delivery: event sent best-effort after commit, no transactional outbox or retry for transient Pusher failures.
11. Ops: no documented end-to-end test suite, backup restore drill, staff device check or deploy migration rehearsal.
12. Security: installer file may remain publicly reachable; login failures tracked per session (not resilient across new sessions).

## V2 Workspaces
- Owner: Overview, POS, All Orders, Cash Desk, Kitchen, Service, Reports, Menu & Tables, Team, Audit.
- Manager: POS, Orders, Cash Desk, Reports, Menu & Tables.
- Chef: Kitchen board, with role-limited transitions.
- Waiter: Ready-to-serve board and POS only when permitted.
- Staff tablet: POS and Order lookup.
- Only allowed destinations should be visible.
- Desktop sidebar; tablet dual-column POS (products / ticket); phone compact views with no horizontal overflow.
- Cash and correction actions must be backed by transactions, not UI-only animations.

## Planned phases
P1. Role-tailored application shell, POS workstation, dedicated KDS, cashier and waiter boards.
P2. Additive MySQL migrations: payments, register shifts, adjustments, durable outbox; never reset existing data.
P3. Cash tender/change, safe paid refunds/void approvals, receipt and register reconciliation.
P4. Detailed reports, searchable history, backups, performance, security and hardware integration tests.

## Production exit criteria
- On-device checkout -> manager cash -> kitchen -> ready -> served works across independent staff logins.
- Double tap, retry and concurrent confirm cannot duplicate orders or double-count cash.
- All cash, void, refund, discount and reprint actions are attributable, reasoned and reversible by approved entries.
- Opening cash + receipts - payouts = expected close; counted cash variance is visible.
- Offline/reconnect retrieves latest MySQL state, and manual Refresh operates without whole-page reload.
- PHP / MySQL migration is safe on existing live DB, with a tested backup and rollback.
- Authorization and critical business transition tests pass.
- Printed 80mm receipts are tested on actual tablet + printer.
- The simple UI remains compact, while underlying accounting and operations become comprehensive.

Phase P1 on its own is not production ready. This document is a tracked plan, not a claim of completed implementation.
