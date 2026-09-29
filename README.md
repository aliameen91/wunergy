# Wunergy Heat Loss Estimator

A framework-free PHP 8 + vanilla JS tool for UK heat-pump installers:

EPC lookup (optional) → room-by-room geometry entry → heat-loss calculation (W/room, kW total) → equipment recommendation → installed-cost estimate with Boiler Upgrade Scheme (BUS) grant deduction → CSV export.

No framework, no Composer, no build step. Production runs on nothing but PHP with the `curl` and `json` extensions.

## Which file is live

**`index.php` is the deployed application.** It is self-contained: on `GET` it renders the whole widget (HTML + CSS + JS inline), and on `POST` it acts as its own JSON API for EPC lookups against the gov.uk "Get Energy Performance Data" API.

The other two files are **legacy and are not deployed** — CI excludes them from every release (see `.rsyncignore`):

| File | Status |
|---|---|
| `index.php` | **Live.** Edit this one. |
| `wunergy-epc-lookup.php` | Legacy. An older WordPress plugin fragment targeting the retired `epc.opendatacommunities.org` API. Kept for reference only. |
| `heat-loss-calculator.html` | Legacy. The original static prototype, superseded by `index.php`. No EPC integration. |

If you are changing calculation logic or UI copy, it goes in `index.php`.

## Running locally

```bash
export EPC_API_KEY='your-bearer-token'
php -S localhost:8000
```

Then open <http://localhost:8000>.

Requires PHP 8.0 or newer (`index.php` uses `str_contains()`).

## Configuration

One environment variable, read at `index.php:23`:

| Variable | Required | Purpose |
|---|---|---|
| `EPC_API_KEY` | For EPC lookup only | Bearer token for the gov.uk EPC API. Register at <https://get-energy-performance-data.communities.gov.uk/> via GOV.UK One Login. |

If it is unset, the app still loads and the manual room-by-room path works — only the EPC lookup fails. It is never hardcoded, and it is **not** a CI secret: it lives in the php-fpm pool environment on the server, and deploys do not touch it.

## Deployment

Automated. Merging to `main` runs the CI syntax gate, then rsyncs to EC2, health-checks the live URL, and rolls back if the check fails. See `.github/workflows/ci.yml`.

Do not scp files to the server by hand — that reintroduces drift between `main` and what is actually running.

## Known issues

See [`CODE_REVIEW.md`](CODE_REVIEW.md) for a full review (2026-09-07). The open items worth knowing about:

- No CSRF protection, caching, or rate limiting on the EPC proxy endpoint (`index.php:140-154`).
- U-value reference tables are duplicated across files and have already drifted.
- No automated test coverage of the calculation or EPC-mapping functions.