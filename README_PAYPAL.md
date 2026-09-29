# PayPal Checkout integration

Artic now supports PayPal Checkout through the PayPal JavaScript SDK + Orders REST API.
Development defaults to the PayPal Sandbox. No PayPal secret is stored in the source tree.

## Localhost
Add these entries to `config.local.php` or export them as environment variables:
- PAYPAL_MODE=sandbox
- PAYPAL_CLIENT_ID=...
- PAYPAL_CLIENT_SECRET=...
- PAYPAL_CURRENCY=USD

Then run the database repair (`setup.php` or `REPAIR_EXISTING_ARTIC_DB.sql`) so `paypal_transactions` exists.

## Vercel
Add the four PAYPAL_* variables in Project Settings → Environment Variables. Keep the secret out of GitHub.

## Flow
1. Client opens an accepted commission.
2. PayPal button creates a server-side order from the commission amount.
3. PayPal approves the order in the browser.
4. The server captures the order and verifies currency + amount.
5. Artic marks the commission paid, creates/updates its invoice, and creates the artist payout record.

The existing demo payment button remains available for development when PayPal is not configured.
