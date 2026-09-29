# Vercel Deployment Notes

This project is Vercel-container ready through `Dockerfile.vercel`, `Caddyfile`, and `vercel.json`.

Do not put local SQL Server credentials in the repository. Configure `ARTIC_DB_*` environment variables in Vercel.

The production database must be externally reachable over TCP. Local `localhost\\SQLEXPRESS03` is only for local development.

Large PSD/image uploads should use durable object storage such as Vercel Blob; the current PHP filesystem upload path is retained for local use but is not persistent on Vercel.

## Account Profiles & Commission Portfolio

The current build also supports:
- Client and artist account profiles with editable display name, bio, and profile picture.
- Public profile routes at `#/profile/<id>` and artist storefront routes at `#/artist/<id>`.
- Artist portfolio shown on public artist profiles.
- Commission detail pages now display the assigned artist's profile and portfolio, with a link to the full public profile.

Profile pictures are stored through the same application upload layer. For Vercel production, move profile/listing/portfolio/message/deliverable file storage to durable object storage such as Vercel Blob before relying on uploads across deployments.
