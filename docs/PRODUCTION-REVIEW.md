# Current release: simplified menu and Bangladesh mobile sign-in

The customer menu displays the logo and name above centred search/category controls, with one product per row. It has no public Staff access link. Staff use the direct `/staff/` URL.

Dining-table features are removed from customer checkout, staff order entry, navigation, dashboard, order endpoints, receipts and new installations. Existing historical database columns and records are retained without destructive deletion.

Staff login, account creation, profile and access management use Bangladesh mobile numbers. Phone values are validated and normalized; old email identifiers are no longer accepted by login. An additive schema migration gives existing accounts nullable phone values until their owner assigns them. The current owner must save their own number using their authenticated account and current password before signing out. Staff accounts can be assigned numbers by an owner. No phone numbers are invented or copied from unrelated records.

Checks include frontend regression tests, PHP syntax and Bangladesh phone normalization tests. Live browser checks cover public menu, checkout layout and the preserved authenticated owner account; no real order/payment/password change is submitted by these UI checks.

Remaining production checks: end-to-end order/payment/kitchen/receipt hardware testing, durable login throttling, cross-device session revocation, durable cart recovery and historical order pagination.
