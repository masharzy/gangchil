# Restaurant workflow review

## Fixed in this change
- Replace the bare login form with a responsive staff sign-in screen, labelled fields, password visibility and persistent inline errors.
- Explain account provisioning and password recovery. This is a staff-only POS: owners create accounts; there is no customer/public registration.
- Hide signed-out navigation and guard role-specific routes. Kitchen staff start on Orders.
- Preserve CSRF continuity across logout and subsequent login; clear in-memory order/cart state on logout or expired login.
- Add self-service password changes with current-password verification, session ID/token rotation and a session-based attempt limit.
- Allow owners to reset other staff passwords. Default new staff role to staff rather than owner.
- Lock form submissions until completion. Keep order refresh available after a failed request.
- Show escaped customer names and kitchen notes, include cancelled orders in filters, and cap item quantities at the API's 30-per-line limit.
- Prevent report/staff/audit responses from writing into a screen after navigation.
- Improve touch targets, focus indicators, input font sizes and narrow-screen layouts.

## Remaining release blockers / verification
- Real MySQL integration and live device testing: order creation → physical cash confirmation → preparation → ready → served → paid receipt. Verify two-device updates, reconnects, permissions and duplicate checkout recovery.
- Login throttling currently uses the browser session, so it can be bypassed with a fresh cookie. Add a shared persistent rate limit before public production use.
- Password resets do not revoke already-open sessions on other devices. Add a database-backed session version or revocable session records.
- Browser printing is implemented; silent ESC/POS printing is not. Test the actual printer/tablet pairing.
- Draft carts remain memory-only and are lost on reload. A network-ambiguous checkout should be retried before editing its cart; durable draft/idempotency recovery needs further work.
- Product/category/table management remains basic; editing and archiving need dedicated UI and workflow validation.
- Orders are limited to the latest 150; historical search/pagination is needed for larger operations.
- Test backups and restores, HTTPS/cookie configuration, hosting limits and Pusher delivery on the actual host. InfinityFree remains a demonstration environment as documented in README.

## Validation
`node --check public/app.js`, `node tests/frontend-smoke.cjs`, and `git diff --check` pass locally. The regression suite exercises unauthenticated routing, role guards, quantity limits, escaped kitchen notes and logout/login CSRF continuity using a simulated API. CI runs PHP syntax checks and the same frontend regression suite. Local PHP/MySQL and browser rendering were unavailable in this environment; these checks do not certify a live production deployment.
