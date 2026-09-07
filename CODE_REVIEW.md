# Code Review — heatcalc (Wunergy Heat Loss Estimator)

Reviewed 2026-09-07. Read-only review; no code changed.

## Project Summary

Framework-free **PHP 8 + vanilla JS** tool for a UK heat-pump installer: optional EPC (Energy Performance Certificate) lookup → room-by-room geometry entry → heat-loss calculation (W/room, kW total) → equipment recommendation from a curated catalogue → installed-cost estimate with Boiler Upgrade Scheme (BUS) grant deduction → CSV export.

No framework, no Composer, no test suite, no README. Three overlapping generations of the same product coexist in the repo, and it's unclear from the repo which is actually deployed:

- `index.php` (70KB, newest) — current, self-contained version; also serves as its own JSON API (POST to itself) for EPC lookups against the newer gov.uk **"Get Energy Performance Data"** API (Bearer token).
- `wunergy-epc-lookup.php` (15KB) — a WordPress plugin/theme fragment (`add_action('wp_ajax_...')`) doing the same EPC mapping against the older, likely-deprecated `epc.opendatacommunities.org` API (HTTP Basic auth), with response caching via WP transients.
- `heat-loss-calculator.html` — an earlier, EPC-less static prototype (manual room entry only), superseded by `index.php`.

## Findings

### High

1. **EPC validity cutoff contradicts the UI copy** — `index.php:25` vs `:650, 827`
   `define('CERT_MAX_AGE_YEARS', 30)` governs `is_certificate_valid()` (`index.php:186-191`), but the UI text tells users the cutoff is 10 years: "less than 10 years old" (`:650`) and "This certificate is over 10 years old" (`:827`). The real, government-recognized EPC validity period is 10 years. As shipped, EPCs 10–30 years old are accepted as "valid," auto-fill construction data, and are labeled "Valid EPC" (`:781`) — directly affecting heat-loss accuracy and BUS-grant eligibility. `wunergy-epc-lookup.php:41` correctly uses `10`, suggesting `30` in `index.php` is an unintentional regression/typo.

2. **Engineering reference data duplicated across 2–3 files and already drifted** — `index.php:44-108`, `wunergy-epc-lookup.php:254-321`, `heat-loss-calculator.html:231-239`
   The age-band U-value table and the free-text EPC description → U-value keyword rules are copy-pasted verbatim into up to three files with no shared source of truth. They've already diverged: `wunergy-epc-lookup.php`'s `AGE_BAND_U_VALUES` (`:254-262`) is missing the `door` key that `index.php`'s copy (`:44-52`) has. Any future correction to these constants (which drive the quoted kW figure) must be manually kept in sync everywhere, with nothing to catch drift.

### Medium

3. **No CSRF protection, caching, or rate limiting on the EPC proxy endpoint** — `index.php:140-154`
   Unlike `wunergy-epc-lookup.php`, which validates a WordPress nonce (`check_ajax_referer`, `:56/113`) and caches results for a day (`get_transient`/`set_transient`, `:65-69/104, 120-124/138`), `index.php`'s POST handler has no CSRF/origin check and no caching. Any third-party page can trigger hidden cross-site POSTs with an arbitrary postcode/certificate number, burning the site owner's metered gov.uk API quota with no throttling — a regression versus the WP version's caching.

4. **Legacy EPC data source may be dead code** — `wunergy-epc-lookup.php:39`
   Targets `epc.opendatacommunities.org`, the older EPC open-data API the UK government has been retiring in favor of the newer API `index.php` already uses (`index.php:15, 24`). Worth confirming whether this file is still deployed anywhere; if not, remove it (also resolves Finding 2's triplication).

5. **No access control on certificate lookups** — `index.php:371-381` / `wunergy-epc-lookup.php:112-140`
   `handle_epc_get()` / `ajax_get_certificate()` accept any `lmk_key`/certificate number from an anonymous, unauthenticated caller and return full mapped certificate detail — not limited to certificates the same session just found via postcode search. UK EPC data is itself a public register, so this is low-severity information exposure, but it means the app can be used as a free, unthrottled bulk-lookup proxy for the underlying API key, compounding Finding 3.

6. **Brittle substring-based free-text EPC parsing with no test coverage** — `index.php:197-211`, duplicated in `wunergy-epc-lookup.php:179-195`
   `infer_u_value()` matches raw EPC free-text (e.g. "Cavity wall, as built, insulated (assumed)") against a hand-maintained, order-dependent list of `str_contains()` patterns (`index.php:54-108`). The ordering-matters comment (`wunergy-epc-lookup.php:264-266`) acknowledges the fragility, but there's no fixture/test asserting real EPC strings map to the intended U-value — a new real-world phrasing (or a reordering during a future edit) can silently fall through to the unmatched default without anyone noticing.

### Low

7. **No automated tests anywhere in the repo.** `find . -iname "*test*"` returns nothing. The core calculation functions (`calculateRoom()`, `matchEquipment()`, `estimateCost()`, EPC mapping) are pure/deterministic and would be cheap to cover.

8. **Non-deterministic/opaque equipment recommendation tie-break** — `index.php:1000-1011` (`matchEquipment`), catalogue at `:113-125`
   When multiple catalogue entries share the same smallest-fitting `capacity_kw`, the "primary" recommended unit is whichever appears first in `EQUIPMENT_CATALOG` — not the cheapest or highest-SCOP option. Samsung entries are listed first throughout the catalogue, so Samsung always wins ties over Vaillant/Daikin regardless of price or efficiency. Looks like unintentional brand bias baked into iteration order rather than a deliberate policy — worth confirming.

9. **`heat-loss-calculator.html` appears fully superseded** — no EPC integration, standalone U-value table, left in the repo alongside its successor `index.php`. Dead weight a future maintainer could mistake for the current version and edit by accident.

10. **PHP 8.1+ deprecation risk** — `strtotime($row['registrationDate'])` / `strtotime($row['lodgement-date'])` (`index.php:353`, `wunergy-epc-lookup.php:89`) will emit a deprecation warning if the key is ever missing/null, since `strtotime()` expects `string`, not `?string`. Low impact (warning only), easy fix: `?? ''`.

11. **Missing null-guard on client render** — `renderEpcResultArea()` (`index.php:774-775`) calls `en.u_value.toFixed(2)` directly on values from the JSON API response with no null-guard; if `map_epc_element_group()` ever returned a null `u_value`, this would throw a JS `TypeError` and break the whole results render. Easy fix: `(en.u_value ?? 0).toFixed(2)`.

## Positive Observations

- **No hardcoded secrets.** Both the standalone (`getenv('EPC_API_KEY')`, `index.php:23`) and WordPress (`WUNERGY_EPC_EMAIL`/`WUNERGY_EPC_API_KEY` constants, per docblock at `wunergy-epc-lookup.php:26-31`) versions correctly source credentials from environment/config, failing gracefully if unset.
- **Consistent XSS hygiene** — `escapeHtml()` (`index.php:640`) is applied to all user- and API-derived strings interpolated into the DOM (addresses, descriptions, room names).
- **Thoughtful defensive parsing of the EPC API's inconsistent response shapes** — `epc_number()`/`epc_text()` (`index.php:226-233`) explicitly handle the API sometimes wrapping a value in `{value, currency}` and sometimes returning it bare, with a comment explaining why.
- **Real UK postcode regex validation** (`index.php:340`, `wunergy-epc-lookup.php:61`) before any outbound API call.
- **Clear, honest user-facing caveats** (`index.php:827, 1080, 1088`) that this is an indicative, non-MCS-certified estimate.
- **Reasonable low-ceremony single-file architecture** for a tool this size, with comments consistently explaining *why*, not just *what*.

## Suggested Next Steps

- Fix `CERT_MAX_AGE_YEARS` (Finding 1) — highest impact, one-line change.
- Decide which of the three files is actually deployed and retire the others (Findings 2, 4, 9).
- Add CSRF/rate-limiting to `index.php`'s EPC endpoint (Finding 3).
- Add a small test suite around the calculation/mapping functions (Findings 6, 7).