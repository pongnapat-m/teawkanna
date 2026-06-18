# Teawkanna deployment

This project is currently designed to run under the `/tkn` URL path.

## Server requirements

- Apache 2.4 with `mod_rewrite` and `mod_headers`
- PHP 8.0+ with `mysqli`, `pdo_mysql`, `curl`, `openssl`, `fileinfo`, `json`
- MySQL/MariaDB
- HTTPS certificate
- Writable directories:
  - `handlers/uploads/avatars`
  - `handlers/uploads/shop_pics`
  - `handlers/uploads/activity_pics`
  - `handlers/uploads/slips`

## Before uploading

1. Revoke and rotate every credential that was previously stored in source:
   Google OAuth, LINE, Omise, Google service account and any Supabase key.
2. Copy `.env.example` to `.env` and enter the new credentials.
3. Generate `ROUTE_KEY` with at least 32 random bytes.
4. Keep the Google service-account JSON outside the public web directory and
   set `GOOGLE_APPLICATION_CREDENTIALS` to its absolute server path.
5. Do not upload local-only files:
   - `.env.example` (optional)
   - `service_account.json`
   - `*.sql`, `*.log`, `*_debug.txt`
   - `test.php`, `test_curl.php`, `admin/test_omise.php`
   - `component_diagrams.pptx`, `PATCH_README.txt`, `scripts/`
   - existing files inside upload directories unless they are required data

Run `powershell -ExecutionPolicy Bypass -File scripts/build-deploy.ps1` to create
`teawkanna-deploy.zip` containing only deployable application files and empty,
protected upload directories.

## Deploy

1. Upload the application as `<document-root>/tkn`.
2. Import `teawkanna (10).sql` through a private database tool, then remove the
   SQL file from the server.
3. Set the document permissions:
   files `0644`, directories `0755`, upload directories writable by PHP.
4. Verify Apache allows `.htaccess` overrides (`AllowOverride FileInfo Options`).
5. Set `APP_ENV=production`, `APP_DEBUG=false`, `BASE_URL=/tkn`.
6. Register production callback URLs in Google/Facebook/LINE/Omise dashboards.
7. Test login, registration, uploads, booking, payment return and webhooks.

## Root-domain deployment

Many templates still contain literal `/tkn/...` URLs. Deploying at the domain
root requires replacing those references and changing `RewriteBase` to `/`.
Until that refactor is completed, use `https://your-domain.example/tkn`.

## Production checks

- `https://your-domain.example/tkn/.env` returns 403/404.
- SQL, JSON credentials, logs and test endpoints return 403/404.
- Directory browsing is disabled.
- PHP cannot execute from either uploads directory.
- Errors are written to the server log and are not shown to visitors.
- HTTPS redirects and secure cookies are enforced by the host/proxy.
