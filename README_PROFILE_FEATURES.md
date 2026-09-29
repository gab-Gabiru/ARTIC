# Artic Profile & Commission Portfolio Update

This build adds account profiles for clients and artists, including profile picture, display name, and bio.

## Public profiles
- `#/profile/<id>` displays a public profile.
- `#/artist/<id>` now resolves to the same public artist profile.
- Artist profiles show commission tiers and portfolio pieces.

## Commission detail
When a user opens a commission, the commission page now includes the assigned artist profile and portfolio.

## Database
Run `REPAIR_EXISTING_ARTIC_DB.sql` (or `schema.sql` for a fresh install) to add:
- `users.profile_image_url`
- `users.profile_image_name`
- `users.profile_image_mime`
- `users.profile_image_size`

## Uploads
Localhost stores profile pictures under `uploads/profiles`. The Vercel build retains the same interface, but production uploads should use durable object storage such as Vercel Blob because Vercel container filesystems are ephemeral.
