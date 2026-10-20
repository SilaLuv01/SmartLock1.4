# Smart Door Lock Web — Vercel package

This folder adapts the existing PHP web application for Vercel Functions using the community PHP runtime `vercel-php@0.9.0` (PHP 8.5). The runtime is community-maintained, not an official Vercel runtime. Vercel documents PHP as a community runtime; the package uses the documented `functions` and `routes` configuration.

## Important database requirement

The existing MariaDB server is on a private campus/XAMPP network address. A Vercel deployment cannot reach that private network directly. Do not expose it to the public internet to make this package work. Use a separately managed database with a public TLS connection and strict network/user permissions, or host the PHP site on the same reachable private network instead of Vercel. This package expects MySQL/MariaDB PDO via `DB_DSN`.

## Deploy

1. Import this `vercel` folder as the Vercel project root (or deploy this folder with the Vercel CLI). `vercel.json` routes the four PHP entry points and static assets; PHP source files under `backend` and `server` are denied direct HTTP access.
2. Create a dedicated empty database on a provider reachable from Vercel. Do not use the XAMPP `root` account. TLS with certificate verification is enforced by this package. Obtain the provider CA PEM and set it in `DB_SSL_CA_PEM`.
3. In Vercel Project Settings → Environment Variables, set `DB_DSN`, `DB_USER`, `DB_PASSWORD`, `DB_SSL_CA_PEM`, and `APP_SECRET` for Production/Preview as needed. Use the template in `.env.example`; leave `APP_BASE_PATH` empty. Set `READ_API_TOKEN_HASH` only if the read-only API is intentionally enabled. Never put secrets in this folder or source control. `APP_SECRET` must be exactly 64 lowercase hex characters, generated with a cryptographically secure random generator.
4. Provision the tables in `backend/schema.sql` once using the database owner. It contains `CREATE TABLE IF NOT EXISTS`; it does not drop tables. For runtime, grant only `SELECT`, `INSERT`, `UPDATE`, and `DELETE` on this database. The added `sl_web_sessions` table stores server-side sessions so login state survives stateless Functions.
5. Create the first administrator with `backend/bin/admin.php` using a secure PHP CLI environment pointed at the same database and a private password file (12–72 UTF-8 bytes). The program stores only a password hash. Do not create the administrator through a public URL.
6. Deploy using the Vercel dashboard or CLI. Verify the login page, login/session persistence across repeated requests, all four menus, API endpoints, CSRF rejection, and logs before treating it as production-ready.

## Local checks

Use PHP 8.2+ with `pdo_mysql`, `openssl`, and `mbstring` to lint the PHP files. The included Vercel runtime is selected only during Vercel deployment. A successful local PHP lint does not prove a Vercel deployment or external database connection.

## Scope

The package does not contain database credentials, an administrator password, or production data. It does not migrate the current MariaDB data from XAMPP or the private server. Export/import data only after deciding where production data will live and securing that database.