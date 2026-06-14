# ClearGate FX Production Deployment Plan

## 1. Target Architecture

Deploy the application as a Laravel API on Linux using:

- Nginx.
- PHP 8.5 FPM with the required Laravel extensions.
- MySQL 8.4 or a managed MySQL service.
- Redis for cache and queues.
- Supervisor to keep queue workers running.
- TLS/HTTPS through the hosting provider or Let's Encrypt.
- Persistent report storage, preferably S3 when running multiple servers.

Laravel Cloud or Forge may be used instead of managing the server manually. The
current `compose.yaml` is intended for local Laravel Sail development and should
not be used as the production configuration.

## 2. Production Readiness Gates

Complete these items before opening the application to users:

1. Ensure the test suite passes:

   ```bash
   php artisan test --compact
   ```

2. Use PHP 8.5 consistently in CI and on the production server.
3. Configure the application name, timezone, and locale for production. The
   current timezone is hard-coded to `UTC` in `config/app.php`; update it to read
   an environment variable before relying on `APP_TIMEZONE`.
4. Never run `AdminSeeder` with its default `password` credential.
5. Move administrator credentials from direct `env()` calls in the seeder to a
   configuration file, or run the seeder before `config:cache` with explicit
   system environment variables.
6. Define a retention policy for files under
   `storage/app/private/exports`.
7. Configure `GenerateReportJob` so the worker timeout remains lower than
   `retry_after`, and test a large report to ensure jobs are not executed twice.
8. Review CORS, `SANCTUM_STATEFUL_DOMAINS`, and `SESSION_DOMAIN` for the frontend
   domain.
9. Prevent public access to `.env`, `storage`, and the project root. Nginx must
   serve only the `public` directory.

## 3. One-Time Infrastructure Setup

### Server

- Create a non-root deployment user.
- Install Nginx, PHP 8.5 FPM, Composer 2, and a Node.js version compatible with
  Vite 8.
- Install the required PHP extensions, particularly `ctype`, `curl`, `dom`,
  `fileinfo`, `filter`, `mbstring`, `openssl`, `pdo_mysql`, `session`,
  `tokenizer`, `xml`, `zip`, `gd`, `intl`, `redis`, `pcntl`, and `posix`.
- Grant the PHP user write access to `storage` and `bootstrap/cache`.
- Enable the firewall and allow only SSH, HTTP, and HTTPS.

### Database and Redis

- Create a dedicated database and least-privilege application user.
- Do not expose MySQL or Redis ports to the public internet.
- Enable encrypted daily database backups and regularly test restoration.
- Use separate Redis instances or prefixes for cache and queues.

### Release Layout

Use immutable releases with an atomic symbolic link:

```text
/var/www/cleargate-fx/
├── current -> releases/20260608123000
├── releases/
└── shared/
    ├── .env
    └── storage/
```

Link every release to `shared/.env` and `shared/storage`. Retain the latest three
to five releases for rollback.

## 4. Production Environment

Suggested core values:

```dotenv
APP_NAME="ClearGate FX"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.example.com
# Effective after config/app.php is updated to read this variable:
APP_TIMEZONE=Asia/Gaza

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=private-db-host
DB_PORT=3306
DB_DATABASE=cleargate_fx
DB_USERNAME=cleargate_fx
DB_PASSWORD=strong-secret

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

REDIS_HOST=private-redis-host
REDIS_PASSWORD=strong-secret
REDIS_PORT=6379

FILESYSTEM_DISK=local
```

Additional requirements:

- Generate `APP_KEY` once and store it in a secret manager:

  ```bash
  php artisan key:generate --show
  ```

- Do not rotate `APP_KEY` after encrypted data or production sessions exist
  without a planned key-rotation process.
- Configure a real mail provider instead of `MAIL_MAILER=log` when email delivery
  is required.
- Report creation and download currently use `Storage::disk('local')`
  explicitly. Update them to use a configurable disk before moving to S3 or
  multiple application servers. Setting `FILESYSTEM_DISK=s3` alone will not
  affect the current report services.
- Store all secrets in Laravel Cloud, the platform secret manager, or protected
  CI/CD variables, never in Git.

## 5. Nginx and PHP

- Point the domain document root to `current/public`.
- Pass PHP requests to PHP 8.5 FPM.
- Enable HTTPS and redirect HTTP traffic to HTTPS.
- Add `X-Frame-Options`, `X-Content-Type-Options`, and HSTS after HTTPS is fully
  verified.
- Configure upload limits and timeouts for the application's expected payloads.
- Disable Nginx and PHP version disclosure.
- Enable OPcache for production and reload PHP-FPM after each release.

## 6. Queue Worker

The application uses queued PDF and Excel report generation. Production is not
operational without a permanent worker. Configure Supervisor to run a command
similar to:

```bash
php artisan queue:work redis --sleep=3 --tries=3 --timeout=300 --max-time=3600
```

`REDIS_QUEUE_RETRY_AFTER` must be greater than `--timeout` with a reasonable
margin, for example `360` seconds for a `300` second worker timeout.

After every release, run:

```bash
php artisan reload
```

Supervisor should restart the worker automatically. Monitor `failed_jobs` and
document the retry procedure:

```bash
php artisan queue:failed
php artisan queue:retry <id>
```

There are currently no scheduled tasks in `routes/console.php`. When scheduled
tasks are introduced, add one cron entry that runs `php artisan schedule:run`
every minute.

## 7. Release Procedure

Automate these steps through CI/CD:

1. Check out a specific commit or tag into a new release directory.
2. Install PHP dependencies:

   ```bash
   composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
   ```

3. Install and build frontend assets:

   ```bash
   npm ci
   npm run build
   ```

4. Link the shared `.env` and `storage`, then apply the correct permissions.
5. Confirm that the application boots:

   ```bash
   php artisan about
   ```

6. Enable maintenance mode when a migration is incompatible with the previous
   release:

   ```bash
   php artisan down --retry=60
   ```

7. Take a restorable snapshot or backup before sensitive migrations.
8. Run migrations:

   ```bash
   php artisan migrate --force
   ```

9. Run only explicitly required seeders. Do not run `DatabaseSeeder`
   automatically on every release unless every seeder is idempotent and the
   administrator credentials are configured securely.
10. Optimize Laravel:

    ```bash
    php artisan optimize
    ```

11. Atomically switch the `current` link to the new release.
12. Reload PHP-FPM and long-running Laravel services:

    ```bash
    php artisan reload
    ```

13. Disable maintenance mode:

    ```bash
    php artisan up
    ```

14. Run smoke tests against `/up`, login, public settings, and a queued report
    using a dedicated monitoring account.

Prefer building assets and running tests in CI, then deploy an immutable
artifact to avoid differences between build and production environments.

## 8. Suggested CI/CD Pipeline

### Pull Request Stage

- Run `composer validate`.
- Run `php artisan test --compact`.
- Run `vendor/bin/pint --test` as a CI-only formatting check.
- Run `npm ci && npm run build`.
- Audit Composer and npm dependencies for vulnerabilities.

### Deployment Stage

- Deploy to staging first.
- Run migrations and smoke tests on staging.
- Require manual approval for production.
- Deploy an immutable tag to production.
- Use a deployment lock to prevent concurrent releases.

## 9. Monitoring and Operations

- Monitor `GET /up` externally every minute.
- Add a separate deep health check for MySQL and Redis without exposing
  sensitive information.
- Centralize logs and redact passwords, tokens, and sensitive financial data.
- Monitor HTTP 5xx rate, API latency, disk space, CPU, memory, MySQL connections,
  queue depth, failed jobs, and oldest queued-job age.
- Alert when `/up` fails, the worker stops, a backup fails, or disk space becomes
  low.
- Delete expired report files after 72 hours. The current cache expiration does
  not delete stored files automatically.
- Test backup restoration in an isolated environment at least monthly.

## 10. Rollback

When smoke tests fail:

1. Stop further deployments and enable maintenance mode when necessary.
2. Point `current` back to the last healthy release.
3. Run:

   ```bash
   php artisan optimize
   php artisan reload
   php artisan up
   ```

4. Verify `/up`, login, and queue processing.

Do not run `migrate:rollback` automatically because it may destroy data written
after deployment. Design migrations using the expand-and-contract pattern:

- First release: add backward-compatible columns or tables.
- Deploy code that uses the new structure.
- Later release: remove the old structure after the rollback window closes.

## 11. Deployment Success Criteria

A deployment is successful only when:

- `/up` returns HTTP 200 over HTTPS.
- `APP_ENV=production` and `APP_DEBUG=false`.
- All migrations are complete with none pending.
- Sanctum authentication works.
- The queue worker generates a test report successfully.
- The report can only be downloaded by its authorized user.
- No new log errors or increase in HTTP 5xx responses is observed.
- The latest backup succeeded and is restorable.

## 12. Implementation Priority

1. **P0:** Secrets, administrator password, HTTPS, backups, and disabling debug.
2. **P0:** Queue worker and persistent report storage.
3. **P1:** Staging, CI/CD, and smoke tests.
4. **P1:** Monitoring, alerting, and rollback procedure.
5. **P2:** Redis/S3 migration and horizontal scaling when demand requires it.
