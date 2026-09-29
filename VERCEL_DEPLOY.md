# Deploy Artic on Vercel

This build is prepared for Vercel's current Docker/FrankenPHP container deployment model. Vercel detects `Dockerfile.vercel` at the project root and routes requests to the container service declared in `vercel.json`.

## Important architecture requirement

The current Artic app uses Microsoft SQL Server. Vercel cannot connect to your Windows laptop's `GABIRU\\SQLEXPRESS03` instance. The database must be hosted on a server reachable from the public internet, using TCP (preferably a fixed port such as 1433). Windows Authentication is not suitable for this deployment; use SQL Server Authentication or another internet-reachable SQL Server credential.

The connection environment variables are listed in `VERCEL_ENV.example`.

## Deploy

### Option A — GitHub

1. Put the contents of this directory in a GitHub repository.
2. Import the repository in Vercel.
3. Keep the project root at the directory containing `Dockerfile.vercel` and `vercel.json`.
4. In Vercel Project Settings → Environment Variables, add the `ARTIC_DB_*` values.
5. Deploy.

### Option B — Vercel CLI

Install the Vercel CLI, authenticate, then run from this directory:

    vercel link
    vercel env add ARTIC_DB_SERVER
    vercel env add ARTIC_DB_PORT
    vercel env add ARTIC_DB_NAME
    vercel env add ARTIC_DB_USER
    vercel env add ARTIC_DB_PASS
    vercel env add ARTIC_DB_TRUSTED_CONNECTION
    vercel env add ARTIC_DB_ENCRYPT
    vercel env add ARTIC_DB_TRUST_SERVER_CERTIFICATE
    vercel env add ARTIC_DB_LOGIN_TIMEOUT
    vercel env add ARTIC_DB_ALLOW_WINDOWS_FALLBACK
    vercel deploy --prod

## Database firewall

Your external SQL Server must allow inbound TCP connections from Vercel. Vercel custom container deployments do not currently provide a static outbound IP feature for allowlisting, so a database that only accepts a fixed private/local IP is not a drop-in fit for this deployment model.

## Database schema

Run `schema.sql` and/or `REPAIR_EXISTING_ARTIC_DB.sql` against the production `artic` database before using the site.

## Health check

After deployment, open `/health.php`. It should report `pdo_sqlsrv_loaded: true` and `ready: true`.

## Uploads and PSD files

The current application writes uploaded images/PSDs under `uploads/`. That works locally, but Vercel container filesystems are ephemeral. Production portfolio, listing, message, and deliverable files should be moved to durable object storage, preferably Vercel Blob. Vercel also documents a 4.5 MB request-body limit for server uploads, so the existing 50 MB PHP upload flow is not suitable for large PSDs on Vercel; use Vercel Blob client uploads for files above that limit.

## Sessions

The current application uses PHP sessions. Vercel containers are stateless and may scale to multiple instances, so production-grade deployment should move session state to durable shared storage (or replace it with a stateless signed-session design) before relying on horizontal scaling.

