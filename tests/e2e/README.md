# Babbel end-to-end tests

This suite validates the complete WordPress-to-Babbel synchronization flow in
isolated Docker containers. It runs WordPress 7.0.2 on PHP 8.3, a real Babbel
API built from a local checkout, separate MySQL databases, Action Scheduler,
the official OpenAI provider plugin, and a deterministic HTTP stub for the
WordPress AI Client.

## Run locally

Prerequisites:

- Docker with Docker Compose
- Composer dependencies installed in this plugin repository
- Node.js dependencies and the Playwright Chromium browser installed
- A local `zwfm-babbel` checkout

From the plugin root:

```bash
composer install
npm install
npx playwright install --with-deps chromium
BABBEL_PATH=../zwfm-babbel tests/e2e/run.sh
```

`BABBEL_PATH` must contain Babbel's `Dockerfile` and
`migrations/001_complete_schema.sql`. The runner creates a unique Compose
project, removes it after the run, and writes combined container logs to
`tests/e2e/artifacts/docker.log` on failure.

## Covered scenarios

The scenario catalog (E2E-001 through E2E-024) lives in the `run()` method of
`suite.php`; the runner prints each scenario ID and title during execution.

Before the PHP scenarios, Playwright drives the real WordPress admin UI in
Chromium. The browser coverage saves and tests plugin settings, publishes a
post, and disables and restores Babbel synchronization. Lifecycle edge cases
remain in the faster PHP scenarios instead of repeating WordPress-owned editor
flows in the browser. The browser suite uses the classic WordPress post editor
to keep the plugin-owned metabox flow deterministic. The PHP and browser suites
share test-only editor and queue controls loaded as an MU plugin.

The runner invokes `npm run test:e2e:browser` with the discovered WordPress and
Babbel URLs. To run Playwright against already-running services, set
`PLAYWRIGHT_BASE_URL` to the WordPress origin and `PLAYWRIGHT_BABBEL_URL` to the
Babbel `/api/v1` URL before running that npm command.

The credentials in the Compose file and suite are isolated test fixtures only.
