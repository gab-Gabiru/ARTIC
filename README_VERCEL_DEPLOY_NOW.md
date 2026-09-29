# Deploy Artic on Vercel

1. Put the CONTENTS of this folder at the root of the GitHub repository.
2. The repository root must contain `Dockerfile.vercel`, `index.php`, `api/`, and `Caddyfile`.
3. In Vercel, import that repository and leave Root Directory as `.`.
4. Deploy. Vercel auto-detects `Dockerfile.vercel` for a container deployment.
5. Add the SQL Server and PayPal environment variables in Vercel.
6. Open `/health.php` on the deployed domain.

Do not use a nested `Artic_MSSQL_Reliable_Fixed/` directory as the Vercel project root unless you also change the Vercel Root Directory setting to that directory.
