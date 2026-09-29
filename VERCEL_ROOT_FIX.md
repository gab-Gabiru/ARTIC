# Vercel root/routing fix

This build is intentionally a single Vercel container app.

IMPORTANT: the GitHub repository root must contain `Dockerfile.vercel` and `index.php` directly:

api/
index.php
Dockerfile.vercel
Caddyfile
script.js
style.css
...

Do not put the project inside another folder, and do not upload only the ZIP file to GitHub.

Vercel detects `Dockerfile.vercel` at the project root and routes incoming traffic to the container. No custom `vercel.json` is needed for this single-container application.

After deployment, test `/health.php`.
