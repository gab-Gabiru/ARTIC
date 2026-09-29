# Artic V22 - Vercel deployment fix

This build uses Vercel's single Dockerfile.vercel container deployment.

IMPORTANT: do not add a `services` block for this project. The entire app,
including `api/`, is inside the single container. Vercel's Services mode can
exclude the `api/` directory unless it is declared as its own service.

Repository root must contain:
- Dockerfile.vercel
- Caddyfile
- index.php
- api/

Deploy with Vercel CLI:

    vercel deploy --prod

Then test:

    https://YOUR-DOMAIN/
    https://YOUR-DOMAIN/health.php

Do not commit real database or PayPal secrets. Put them in Vercel Project
Environment Variables.
