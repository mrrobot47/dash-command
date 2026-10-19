# dash-command Behat coverage

Suite for `ee dash` (EasyDash integration), based on `upstream/develop` (`83f20d1`). Features: `dash.feature` (command surface, validation, OS checks), `dash-server.feature` (server lookup/registration, SSH key, hostname, API errors), `dash-sites.feature` (site sync payloads and edge cases).

## How the suite stays off the real service and the host

- **Stub API:** `Given an EasyDash stub is running` starts `php -S 127.0.0.1:<free port>` with `bootstrap/easydash-stub-router.php`. It records every request (method, path, headers, JSON body) and answers like EasyDash's Frappe API (`{"message": …}`). It is stateful: `add_server`/`add_site` show up in later `get_server_list`/`get_site_list` answers, so "run twice" scenarios are realistic. Per-endpoint overrides set a status, a body or a delay.
- **Private config:** sandboxed runs set `EE_CONFIG_PATH` to a copy of `/opt/easyengine/config/config.yml` whose `ed-api-url` points at the stub (or at a closed port, or a sub-path). The global config is never modified (core and nested `ee` calls write config only to `EE_CONFIG_PATH`). The context refuses any `ed-api-url` whose host isn't `127.0.0.1` and re-parses the private file with Spyc to check that `ed-api-url` resolves to the intended value, since an unparsable value would make init fall back to the real `https://dash.easyengine.io/api/method/`.
- **Proxy guard:** sandboxed runs also get `http_proxy`/`https_proxy` pointing at a closed local port, with `no_proxy=127.0.0.1,localhost,.test`. libcurl honours these (verified on php:8.5-cli), so any request not aimed at the stub or a test site, i.e. the real EasyDash or `api.ipify.org`, fails at once instead of leaving the host. The context requires the curl extension, because Requests' fsockopen fallback ignores proxy variables.
- **Private mount namespace:** `When I run … in the EasyDash sandbox` runs the command under `unshare --mount --propagation private`, with a scratch dir bind-mounted over `/root/.ssh` and a recording shim bind-mounted over `hostnamectl` (also first on PATH). The real `authorized_keys` and hostname are never touched; the scenarios assert on the sandboxed copies. The sandbox script checks each bind mount with `-ef` and then writes a marker file; a run without the marker (e.g. `unshare` itself failed with EPERM, which exits 1 like a validation error) fails the step instead of passing vacuously. Private propagation keeps the mounts off the host even when `/` is a shared mount (verified in a privileged container with `/` made rshared). The only host-side write is creating an empty `/root/.ssh` (0700) as a mount point when it is missing.
- **Non-test sites guard:** every sandboxed run first checks the sites table and refuses to run (the step fails, nothing is executed) when any site outside `bh-dash-command-*` exists.
- **PATH shims:** `ee` → the checkout's `bin/ee` (init shells out to plain `ee` for `site enable/disable` and `ee shell`; without the shim that would be the installed stable phar) with every nested call logged; `lsb_release` → a fake distribution when a scenario asks for one.
- **STDIN:** every sandboxed run gets an explicit STDIN (empty or the scenario's text), since `run-behat.sh` runs Behat under `script` (a pty) and init prompts.
- `--ip` is always passed: the auto-detection calls `https://api.ipify.org`, which has no config knob (see gaps).

## Environment requirements

- Root, `unshare`/`mount` with `CAP_SYS_ADMIN` (plain host run, as `run-behat.sh` does), and a free local port.
- **No non-test sites on the host.** `ee dash init` walks *all* sites (`Site::all()`): it would `ee site enable`/`disable` other people's disabled sites, run `ee shell` in WP/`latest` sites, and post them to the stub. The context enforces this (see the guard above): with a foreign site present, every sandboxed scenario fails before running anything.
- Fixture sites: `bh-dash-command-1.test` … `bh-dash-command-8.test` (plus aliases `bh-dash-command-5-alias.test`, `bh-dash-command-5-alt.test`). `@AfterFeature` deletes only sites whose name starts with `bh-dash-command-` (the upstream context deleted every site; fixed here).
- No `@le`, `@remote` or `@destructive` scenarios: nothing needs public DNS, rclone or global state. The only shared files read are `/opt/easyengine/logs/ee.log` (read-only) and the sites DB (`php_version` of `bh-dash-command-7.test` is tweaked in one scenario).
- Fixture changes (DB column tweaks, a moved `docker-compose.yml`, a disabled site, mailhog, HTTP auth) are reverted by an `@AfterScenario` undo stack, so a failing scenario doesn't leave a broken fixture for the next ones.

## Estimated runtime

About 20–22 minutes: `dash.feature` ~1.5 min, `dash-server.feature` ~2.5 min (one 10 s client timeout), `dash-sites.feature` ~15 min (2 WordPress + 6 HTML/PHP fixture sites, ~23 init runs, each walking every site with `ee shell` calls for WP/`latest` sites and a 10 s client timeout).

## Inventory and coverage

Legend: ✔ covered, ✘ not covered (reason), KB = `@known-bug` scenario that fails until the bug is fixed.

### Commands, arguments, config

| Item | Covered by |
|---|---|
| `ee dash` (namespace help, usage) | ✔ dash: "ee help dash describes…", "ee dash without a subcommand…" |
| Unknown subcommand | ✔ dash: "An unknown dash subcommand is rejected" |
| `ee dash init` help/synopsis | ✔ dash: "ee help dash init documents every option"; KB DASH-9 (example lacks `--org`) |
| `--api=<api-key>` required, trimmed, sent as `Token` header | ✔ dash: missing / whitespace-only; dash-server: "Option values are trimmed", header checks on every endpoint; bare `--api` / `--org` rejected (DASH-2 refuted) |
| `--org=<org-name>` required, trimmed, sent in every body | ✔ dash: missing / whitespace-only; dash-server: trimmed, lookup body; dash-sites: `organization` field |
| `--ip=<ip-address>` | ✔ dash-server: payload `public_ipv4`, trimmed, server match by IP; KB DASH-13 (not validated) |
| `--ip` omitted → `api.ipify.org` lookup | ✔ dash-server: "Without --ip the public IP is looked up at api.ipify.org…" (the proxy guard makes it fail locally) |
| `--ip` omitted, lookup fails → interactive IP prompt | KB DASH-4 ("An unreachable IP lookup falls back…") |
| `--ip` omitted, lookup succeeds | ✘ hard-coded HTTPS URL; answering it would need TLS interception |
| `--hostname=<hostname>` valid → no confirmation | ✔ dash-server: "A valid --hostname skips…"; KB DASH-1 (value discarded) |
| `--hostname` invalid → confirmation prompt | ✔ dash-server: "An invalid --hostname falls back…" |
| Unknown option | ✔ dash: "init rejects an unknown option" |
| Validation order (api → org → OS) | ✔ dash: "no options at all…", "Argument validation runs before the OS check" |
| Config `ed-api-url` (via `EE_CONFIG_PATH`) | ✔ every sandboxed scenario |
| `ed-api-url` without trailing slash | ✔ dash-server: "…without a trailing slash is normalised" |
| `ed-api-url` under a sub-path ending in `/api/method/` | ✔ dash-server: "…under a sub-path…" |
| `ed-api-url` not ending in `/api/method/` | KB DASH-11 |
| Default `ed-api-url` (`https://dash.easyengine.io`) | ✘ deliberately never contacted |

### Behaviour of `ee dash init`

| Item | Covered by |
|---|---|
| OS check: non-Ubuntu/Debian refused | ✔ dash: "init refuses an unsupported distribution" |
| OS check: Ubuntu < 22.04 refused, 22.04 accepted | ✔ dash: "…older than 22.04", "…Ubuntu 22.04, the minimum…" |
| OS check: Debian | KB DASH-10 |
| Hostname confirm "y" keeps `gethostname()` | ✔ dash-server: "…confirming the machine hostname keeps it" |
| Hostname "n" → FQDN prompt loop, invalid-answer warning, lower-casing, `hostnamectl set-hostname` | ✔ dash-server: "Declining the hostname…" (hostnamectl shimmed) |
| Hostname prompt on closed STDIN | KB DASH-3 (endless loop) |
| `get_server_list`: POST path, headers, body | ✔ dash-server: "The server lookup posts…" |
| Server already listed → skip registration, no SSH key | ✔ dash-server: "A server already known…" |
| Server list with other IPs / non-2xx / non-JSON → register | ✔ dash-server: three scenarios |
| API unreachable (connection refused) | ✔ dash-server: "An unreachable EasyDash API aborts…"; KB DASH-4 (intended warning fallback is dead code) |
| SSH key appended to `/root/.ssh/authorized_keys`, existing lines kept, not duplicated | ✔ dash-server: three scenarios (sandboxed file) |
| `add_server`: POST path, headers, payload (`hostname`, `public_ipv4`, `organization`) | ✔ dash-server: "A new server is registered…" |
| `add_server` payload `ee_version` | KB REL-4 (only on `main`) |
| Successful registration exits 0 before the site sync | ✔ dash-server: "A new server is registered…" (no `get_site_list`/`add_site`) |
| Idempotency (second run skips registration) | ✔ dash-server: "Running init twice…" |
| `add_server` non-2xx → warnings (body, status), continue | ✔ dash-server: "A rejected registration only warns…"; KB DASH-5 (key left), KB DASH-8 (sync continues, exit 0) |
| `add_server` timeout | ✔ dash-server: "A registration that times out…" |
| Site sync walks every site | ✔ dash-sites (all scenarios) |
| `get_site_list`: POST path, body, token | ✔ dash-sites: HTML scenario |
| Site already listed → skipped, others posted | ✔ dash-sites: "A site EasyDash already lists…" |
| `get_site_list` non-2xx → treated as missing | ✔ dash-sites: "A failing site lookup…" |
| `get_site_list` transport error | ✘ same `http_request()` halt path as the covered `add_site`/`get_server_list` timeouts; not duplicated |
| Idempotency of the site sync | ✔ dash-sites: "Running init twice posts each site only once" |
| `add_site` success message | ✔ dash-sites: HTML scenario |
| `add_site` non-2xx → error, abort | ✔ dash-sites: "A rejected site aborts…"; KB DASH-12 (remaining sites skipped) |
| `add_site` timeout | ✔ dash-sites: "A site registration that times out…" |
| Disabled site: posted with `enabled: false`, left disabled | ✔ dash-sites: two disabled-site scenarios (payload, DB `site_enabled`) |
| Disabled site started only to read DB values | KB DASH-14 ("A disabled site is not started…") |
| Disabled site that can't be started aborts the sync | KB DASH-14 ("A disabled site that cannot be started…", `docker-compose.yml` moved away) |
| Enabled sites aren't restarted | ✔ dash-sites: "Enabled sites are not restarted by the sync" |
| Missing `docker-compose.yml` → warning, skip | ✔ dash-sites: "A site without docker-compose.yml…" |
| `get_site_info()` empty → warning | ✘ unreachable: `get_site_info()` exits on a missing site (DASH-17) |
| PHP < 8.1 (non-HTML) → warning, skip | ✔ dash-sites: "A site on PHP older than 8.1…" |
| PHP `latest` → real version via `ee shell … php -v`, DB unchanged | ✔ dash-sites: "A site on PHP \"latest\"…" |
| PHP `latest` with unparsable `php -v` → fallback 8.2 | ✘ needs a broken php container; low value (DASH-15 notes the message/value mismatch) |
| WP `table_prefix` via `ee shell … wp config get table_prefix` | ✔ dash-sites: WordPress scenario (`--dbprefix=bhd_`), multisite scenario (`wp_`) |

### `add_site` payload fields

| Field | Covered by |
|---|---|
| `domain`, `server`, `site_type`, `organization` | ✔ HTML scenario (+ type in PHP/WP) |
| `enabled` true / false | ✔ HTML scenario / disabled-site scenario |
| `alias_domains` empty / list without main domain | ✔ HTML scenario / "Alias domains are posted…" |
| `ssl` true (self-signed) / false | ✔ HTML scenario / PHP scenario |
| `http_basic_auth` true / false (site auth added then removed) | ✔ "HTTP basic auth on a site is reported" |
| `admin_tools` 0 | ✔ HTML scenario |
| `admin_tools` 1 | ✘ `ee admin-tools enable` downloads tools from the internet; the field is a plain DB passthrough |
| `mailhog` 0 / 1 | ✔ HTML scenario / "Mailhog on a site is reported" |
| `php_version` explicit / resolved `latest` / HTML (null) | ✔ PHP / `latest` scenario; HTML value not asserted |
| `public_directory` empty / custom | ✔ HTML / PHP (`--public-dir=public`) |
| `enable_database` false / true | ✔ HTML / PHP with DB, WP |
| `redis_cache` 0 / 1 | ✔ HTML / WP `--cache` |
| `multisite` 0 (html, wp) / `subdir` | ✔ HTML, WP / multisite scenario; KB DASH-7 (PHP+DB reported as `mysql`); `subdom` ✘ (same code path as `subdir`, avoids a wildcard-host fixture) |
| `table_prefix` empty / custom / default | ✔ HTML, PHP / WP / multisite |

### Logging and security

| Item | Covered by |
|---|---|
| API token not logged by the server lookup's debug line | regular: DASH-6 (`"Token":"…"` in `ee.log`), masked by core since v4.13.2 (easyengine#1944) |
| API token not written to `ee.log` at all | regular: LOG-1 (argv logging in core), fixed in v4.13.2 |
| HTTP auth password not printed by the "Existing auths" log line | ✔ HTTP auth scenario (models serialise to `{}`, so nothing leaks today) |

**Estimate:** 65 of the 71 inventory rows are exercised (≈92%); 9 of them only through `@known-bug` scenarios that fail until the bug is fixed, and several covered rows carry an extra KB check. The 6 uncovered rows (plus multisite `subdom`) are listed above with reasons.

Regular scenarios assert only behaviour that should survive the bug fixes; the current buggy behaviour (exit 0 after a failed registration, the sync stopping at the first rejected site, disabled sites being started, nested plain `ee` calls) lives only in the `@known-bug` scenarios.

## Scenario counts

| Feature | Scenarios | `@known-bug` |
|---|---|---|
| dash.feature | 19 | 3 |
| dash-server.feature | 30 | 10 |
| dash-sites.feature | 22 | 4 |
| **Total** | **71** | **17** |

No `@le`, `@remote` or `@destructive` scenarios. Run without the known bugs: `vendor/bin/behat --tags='~@known-bug'`.

## Bugs documented or suspected (proposed IDs for issues.md)

| ID | Location (`src/Dash_Command.php`) | Issue | Scenario |
|---|---|---|---|
| DASH-1 | `init()` L89–95 | `--hostname` is validated, then overwritten by `gethostname()`; EasyDash always gets the machine hostname. | KB |
| DASH-2 | `init()` L52–63 | Refuted on CI: `SynopsisValidator` warns "--api parameter needs a value" and unsets a valueless `--api`/`--org` before `init()` runs, so the empty-value check fires. | regular scenarios (bare `--api`, bare `--org`) |
| DASH-3 | `init()` L109–115 | The FQDN prompt loops forever when STDIN is closed (`EE::input()` returns `""` on EOF), flooding warnings. Any non-interactive run without a valid `--hostname` hits it, since the confirmation also reads EOF as "no" (e.g. EasyDash firing init over SSH without `--hostname`). | KB |
| DASH-4 | all `try/catch (\RuntimeException)` blocks | `http_request()` defaults to `halt_on_error`, so transport errors call `EE::error()` inside it; the transport-error fallbacks ("assume the server doesn't exist", `get_external_ip()` → IP prompt) and the "Error connecting to EasyDash API…" messages are dead code. | KB (API lookup, IP lookup) |
| DASH-5 | `init()` L127–130 | The EasyDash root SSH key is appended before `add_server` and stays authorised when registration fails. `/root/.ssh` is hard-coded and a missing dir fails silently. | KB |
| DASH-6 | `server_exists_on_easydash()` L325–326 | The request headers, including the API token, are logged at debug level, which always goes to `ee.log` (on top of LOG-1's argv logging). | masked by core since v4.13.2 (the debug line remains, with the token as `******`) |
| DASH-7 | `init()` L247–251 | `multisite` is `app_sub_type` unless it is wp/php/html/empty, so PHP sites with a DB are sent as `"mysql"`. | KB |
| DASH-8 | `init()` L149–155 | A failed `add_server` only warns; the run syncs all sites for a server EasyDash doesn't know and exits 0. | KB |
| DASH-9 | docblock | The only example (`ee dash init --api=xxx`) omits the required `--org`. | KB |
| DASH-10 | `pre_checks()` | Debian passes the distribution check but its release is compared with `22.04`, so Debian is always refused. | KB |
| DASH-11 | `init()` L74, lookups L313/L367 | Lookups use `ed_base_url . '/api/method/…'`, registration uses `ed_api_url . …`; an `ed-api-url` not ending in `/api/method/` sends them to different paths (`/proxy//api/method/…` vs `/proxy/…`). | KB |
| DASH-12 | `init()` L291–297 | One rejected or failed `add_site` (`EE::error`) stops the sync; later sites are never sent. | KB |
| DASH-13 | `init()` L53 | `--ip` isn't validated (the ipify path validates, the flag doesn't). | KB |
| DASH-14 | `init()` L165–182 | Disabled sites are started (`ee site enable`) only to pass `get_site_info()`'s enabled check, although every value read comes from the DB. If the enable fails, `get_site_info()` `EE::error`s and aborts the whole sync; the `continue` after the `docker-compose.yml` check skips the re-disable. | KB (two scenarios) |
| DASH-15 | `init()` L210–217 | The debug message says "Using default version 8.1" but the code sets 8.2. | not covered |
| DASH-16 | `init()` L166, L174, L191, L204, L211, L221 | Nested calls use plain `ee` from PATH (the stable phar on a dev box, or nothing if `ee` isn't on PATH) instead of `EE::launch_self()`/the running binary; site URLs are interpolated into shell commands unescaped (validated names, so low risk). | covered indirectly (the suite has to shim `ee`); not asserted, so a fix doesn't break the suite |
| DASH-17 | `init()` L169–177 | The `empty( $site_data )` branch is unreachable: `get_site_info()` exits on a missing site. | not covered (dead code) |
| DASH-18 | `init()` L188, L366 | `get_site_list` is fetched once per site (N requests for N sites) instead of once per run. | observable in the stub log, not asserted |
| DASH-19 | `init()` L238–239 | "Existing auths: [{}]" logs useless output: models don't serialise their fields. | not asserted |
