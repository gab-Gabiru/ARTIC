# Artic V16 — Portfolio, Tier Images & Demo Finance

## New features

- Tier listings can now have a cover image or PSD attachment.
- Artist portfolio supports JPG, JPEG, PNG, GIF, WEBP, SVG and validated PSD files up to 50 MB.
- Public artist storefront displays the artist portfolio.
- Artist portal has an Add Portfolio Piece workflow.
- Demo invoice is generated automatically when a client uses the simulated payment flow.
- Client and artist can view the same demo invoice from the commission page.
- A demo payout record is created for the artist after simulated payment; it becomes payable after the client releases the demo payment.
- Artist can use **Simulate Payout** after release. This records a fake payout only; no real payment processor or money movement exists.
- Existing messaging, PSD attachment and scatter-graph analytics features are preserved.

## Database repair

Run `setup.php` and use Install / Repair Database. The schema adds:

- `listings.cover_image_*`
- `artist_portfolio`
- `invoices`
- `payouts`

The repair is idempotent and does not remove existing Artic data.

## Demo payment flow

1. Artist accepts a commission.
2. Client clicks **Simulate Payment & Generate Invoice**.
3. Artic marks the commission as paid and creates a demo invoice plus pending payout record.
4. Artist can work and deliver normally.
5. Client releases the demo payment after delivery.
6. Artist clicks **Simulate Payout** to mark the demo payout as paid.

This is intentionally a prototype simulation. It does not process real money.
