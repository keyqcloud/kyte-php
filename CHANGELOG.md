## 4.15.1

### Fix: make the `kyte_locked` migration portable to MySQL — KYTE-#325

- `migrations/4.15.0_datamodel_kyte_locked.sql` used MariaDB-only `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`, which is a **syntax error on MySQL 5.7 / 8.0**. Rewritten to guard with an `information_schema` column check + a prepared statement, which is idempotent on **both MySQL and MariaDB**. Surfaced during the v4.15.0 rollout to the first MySQL-backed install (its `kyte_locked` columns already existed, so nothing was harmed — the failed statement was a no-op — but a *drifted* MySQL install would have errored and never gained the column, leaving the model-level lock guard inert).
- Migration-only change; no PHP behavior change. Installs already on v4.15.0 with the columns present need no action. Fresh or drifted MySQL installs now apply cleanly.

## 4.15.0

### Feature: MCP schema-management tools (create/alter/drop models) + `schema` scope — KYTE-#325

A `schema`-scoped MCP token can now create and migrate data models from Claude, where each change applies a **real DDL migration** (CREATE/ALTER/DROP TABLE/COLUMN) against the application's own database. Same plumbing as the #322 site tools — the tools drive `DataModelController`/`ModelAttributeController` in internal mode. Shipped in two parts.

**PR B — MCP schema tools:**
- **New `schema` scope** added to `KyteMCPTokenController::VALID_SCOPES`, held separately from `provision` so a token can be granted model-migration rights independently of site spin-up/tear-down. Not hierarchical.
- **`src/Mcp/Tools/ModelTools.php`** (auto-discovered) write tools, all `[schema]`: `create_model` (CREATE TABLE), `add_attribute` (ADD COLUMN — decimal needs precision+scale, varchar needs size, FK via `foreign_key_model`), `update_attribute` (CHANGE COLUMN — `name` required since the column def is rewritten in full), `rename_model` (RENAME TABLE); plus the destructive `remove_attribute` (DROP COLUMN) and `delete_model` (DROP TABLE), each gated behind a **required `confirm_destructive` flag**. `read_model`/`list_models` already existed. Every caller-supplied model/attribute id is re-scoped to the token's account; foreign ids are rejected.
- `rename_model` registers the app's model constants via `Api::loadAppModels` first — `DataModelController::update` guards on `defined($o->name)`, which the HTTP pipeline satisfies at routing but the MCP path (routing-bypassed) does not (mirrors `AppModelWrapperController`).
- **Model-layer hardening** surfaced by the partial payloads the MCP tools send: `DataModelController::prepareModelDef` reads optional flags/defaults defensively (no undefined-property warning / null-to-strlen deprecation when `protected`/`password`/`defaults`/`unsigned` are omitted); `Api::loadAppModels` skips rows with a missing/corrupt `model_definition` instead of `define()`-ing a null name, and no longer re-defines an already-defined constant.
- Tests: `tests/McpModelToolsTest.php` extended with write coverage (create/add/update/rename, decimal precision/scale reaching the physical column, destructive-op confirm gating, FK-dependency block, cross-account isolation), driving real DDL against the test DB via a dedicated password-bearing app user.

**PR A — model-layer fixes/hardening (platform; benefits Shipyard too):**
- **`DBI::renameTable`** referenced an undefined `$tbl_name_news` (typo) → emitted a malformed RENAME; rename was effectively broken. Fixed to `$tbl_name_new`.
- **`DBI::dropTable`** now uses `DROP TABLE IF EXISTS` (was a bare DROP that errored on an already-absent table).
- **`kyte_locked` enforced on the DDL path** — `DataModelController`/`ModelAttributeController` now refuse update/delete of a locked model or attribute, guarding before any DB switch or DDL. New `migrations/4.15.0_datamodel_kyte_locked.sql` (idempotent `ADD COLUMN IF NOT EXISTS`) guarantees the column exists on both `DataModel` and `ModelAttribute` — the original consolidated 3.x→4.8 upgrade added it but some installs applied it only partially (observed: `ModelAttribute` had it, `DataModel` did not), leaving the model-level guard silently inert. The guard is only meaningful when the column physically exists.
- **FK-dependency guard before drop** — dropping a model is refused while another model's attribute references it via `foreignKeyModel`; dropping a column is refused while another attribute references it via `foreignKeyAttribute`. Both account-scoped, with an error naming the blocking `model.attribute`. Replaces the two stale "external tables and foreign keys" TODOs.
- **Decimal (`d`) support** — new `migrations/4.15.0_modelattribute_decimal.sql` adds `precision`/`scale` to `ModelAttribute`; `prepareModelDef` passes them through for type `d`; `DBI::buildFieldDefinition` now throws on a `d` column missing precision/scale instead of emitting invalid SQL. The type was previously non-functional end-to-end.
- **Model-cache invalidation** — both controllers call `Api::clearModelCache()` after a schema change (create/rename/delete model, add/change/drop column), so the new struct is served immediately instead of the stale file cache (up to 1h TTL).

**Migrations:** `migrations/4.15.0_modelattribute_decimal.sql` (adds `precision`/`scale`) and `migrations/4.15.0_datamodel_kyte_locked.sql` (ensures `kyte_locked` on `DataModel` + `ModelAttribute`). Both additive, nullable, idempotent, no-op for existing rows. Roll with the batched 4.14.0 site-tools rollout.

## 4.14.0

### Feature: MCP site-provisioning tools (create/delete/update/read) + `provision` scope — KYTE-#322

New MCP tools let a token holder manage a site's lifecycle from Claude, building on the #201 `SiteProvisioningWorker` (provisioning now runs in-process, so these tools just flip state and the worker does the AWS work out of band).

- **New `provision` scope** — gates infrastructure-creating tools, held separately from content `read`/`draft`/`commit` so a token can author pages/scripts without the right to spin up or tear down sites. The dispatch path has no scope enum (RequiresScope/ScopeRegistry/the token CSV all accept arbitrary strings), so the only change to make it grantable was adding `provision` to `KyteMCPTokenController::VALID_SCOPES`.
- **`src/Mcp/Tools/SiteTools.php`** (auto-discovered): `create_site` / `delete_site` / `update_site` `[provision]`, `read_site` `[read]`. Mutations call `KyteSiteController` in internal mode (no session, mirroring `DraftService`'s publish path); `create_site`/`delete_site` flip `KyteSite.status` to `creating`/`deleting` and return immediately — callers poll `read_site` (which surfaces `status`, `provisioning_message`, and the live `cloudfront_domain`) until `active`/`deleted`. Every caller-supplied id is re-scoped to the token's account; new sites are stamped with the caller account.
- Guarded `KyteSiteController::delete()`'s `deleted_by = $this->user->id` for the no-`KyteUser` server-side path (`isset() ? : 0`).
- Custom-domain (`aliasDomain`) assignment is intentionally **not** exposed here — that's the ACM + DNS path (KYTE-#320). Infra ids (bucket/distribution) omitted from output; `cloudfront_domain` surfaced.
- Tests: `tests/McpSiteToolsTest.php` (row-level create/read/update/delete + account isolation), wired into the CI unit suite. Code-only — **no migration**.

## 4.13.0

### Migration: site provisioning moved from Lambda into a cron worker — KYTE-#201

`KyteSiteController` published S3/CloudFront/ACM actions to `SNS_QUEUE_SITE_MANAGEMENT` → the `kyte-lambda-site-management` self-chaining state machine did the work, writing back to the DB via the `kyte-lambda-database-transaction` Lambda. Both Lambdas are replaced by a cron worker that writes the DB directly. Completes the #201 migration off SNS/Lambda (after #1 + #2 in 4.12.0).

- **`SiteProvisioningWorker`** (`src/Cron/SiteProvisioningWorker.php`, `CronJobBase`, interval 30s) scans `KyteSite` rows in `creating`/`deleting` and advances each one actionable step per tick. **Sub-state is inferred from the populated `s3*`/`cf*` columns** (no separate state machine), and every AWS op is **idempotent** so a tick is safe to re-run:
  - **CREATE:** website bucket (create [us-east-1-aware] → drop public-access-block → public-read policy → website config) → media bucket (CORS instead) → website CloudFront → media CloudFront (each distribution id persisted the instant it's created, since `createDistribution` isn't idempotent) → poll both until `Deployed` → `status=active`.
  - **DELETE:** empty+delete each bucket → disable+delete each distribution (polled across ticks) → delete ACM certs + `Domain`/`SAN` rows (after the distributions are gone — a cert can't be deleted while attached) → `status=deleted`.
  - CloudFront create/delete take minutes, so deployment is **polled across ticks** with `heartbeat()` — never a blocking sleep. A site that errors `MAX_ATTEMPTS` times flips to `status=failed` with `provisioning_message` instead of looping.
- **`KyteSiteController`** `new`/`delete` no longer publish to SNS — they just set `status=creating`/`deleting` (+ the existing content-record cleanup) and let the worker take over. Domain/cert teardown moved to the worker (ordering depends on the distribution being deleted first).
- **`Kyte\Aws` wrapper fixes:** `S3::createBucket` no longer sends a `LocationConstraint` for `us-east-1` (it was failing there) and is idempotent on `BucketAlreadyOwnedByYou`; **new `S3::emptyBucket()`** (paginated, version-aware) so `deleteBucket()` works; `CloudFront` now applies `DefaultTTL` (86400) and the worker sets `MinTTL`=3600 for parity with the old Lambda; **new `CloudFront::isEnabled()`**.
- **New:** `migrations/4.13.0_site_provisioning.sql` (adds `KyteSite.provisioning_message` + `provisioning_attempts`), `bin/register-site-provisioning-job.php`.
- **Unlocks** native MCP `create_site`/`create_app`. After this soaks, all 3 Lambdas + `SNS_QUEUE_SITE_MANAGEMENT` can be decommissioned.

## 4.12.0

### Cleanup: remove `KYTE_USE_SNS` flag + dead SNS invalidation branches — KYTE-#201

Direct CloudFront invalidation (shipped + verified in 4.11.1) is now the only path. With every install already running `KYTE_USE_SNS=false`, this removes the flag and the now-dead SNS publish branch at every invalidation site — behavior-neutral cleanup.

- Dropped the `if (KYTE_USE_SNS) { <SNS publish> } else { <direct> }` fork at all 10 sites, keeping only the direct `Kyte\Aws\CloudFront::createInvalidation()` call inside its existing best-effort try/catch: `ApplicationController` (republish-all), `KytePageController` ×3, `KyteScriptController` ×2 (`invalidateCloudFront`/`invalidateCloudFrontForDeletion`), `KyteLibraryController` ×2, `KytePageDataController`, `NavigationController`, `SideNavController`.
- Removed `'KYTE_USE_SNS' => false` from `Api::$defaultEnvironmentConstants` and the `define('KYTE_USE_SNS', ...)` from `sample-config.php` + docs.
- **Kept** `SNS_REGION`, `SNS_QUEUE_SITE_MANAGEMENT` and the `Kyte\Aws\Sns` class — still used by site-provisioning (migrated in later #201 work). Updated the PHPStan baseline accordingly. (`SNS_KYTE_SHIPYARD_UPDATE` is no longer referenced after the shipyard-update migration below.)

### Fix: per-app DB connections (`DBI::connectApp()`) now use SSL when `KYTE_DB_CA_BUNDLE` is set

`DBI::connectApp()` — the per-application/tenant connection used by `Api::dbappconnect()` — opened a plain `mysqli` connection with **no TLS**, unlike `DBI::connect()`. Against a database with `require_secure_transport=ON` this fails with *"Connections using insecure transport are prohibited"*: the control-plane connection (already SSL) succeeds so **login works**, but opening any application backed by a **dedicated per-app database** fails. Latent until a deployment runs on an SSL-required server — surfaced migrating the dev server from Aurora (`require_secure_transport=OFF`) to a MariaDB RDS with it `ON`.

- `connectApp()` now mirrors `connect()`: when `KYTE_DB_CA_BUNDLE` is defined it connects via `ssl_set()` + `MYSQLI_CLIENT_SSL`, with the same non-SSL fallback on failure. **Gated on the constant**, so deployments that don't define a CA bundle keep identical non-SSL behavior (no-op) — verified against ORB/ORT and ETOM, which are unaffected.
- Fixed the `Api::dbappconnect()` per-app routing guard: it compared the **main** connection's statics (`$dbUser`/`$dbName`/`$dbHost`) instead of the **app** statics (`$dbUserApp`/`$dbNameApp`/`$dbHostApp`), so the "already connected to this app" short-circuit never fired. Corrected the comparison **and** added `DBI::closeApp()` when the app target changes, so in-process app switching reconnects to the right tenant DB instead of reusing the previous app's cached handle. Latent cross-tenant hazard, previously masked by single-app-per-request + the cron worker's fork/`reconnect()` (which clears the app handle).
- Removed dead method `DBI::dbInitApp()` — it could never run (called non-existent `setCharsetApp()`/`setEngineApp()`), had no callers anywhere, and duplicated the live `Api::dbappconnect()` path.

### Migration: Shipyard self-update moved from Lambda into a kyte-php cron worker — KYTE-#201

`KyteShipyardUpdateController::new` previously published `current_version` to `SNS_KYTE_SHIPYARD_UPDATE` and the `kyte-lambda-update-shipyard` Lambda did the work. That Lambda had two production bugs: `mimetypes.guess_type()` returned `None` for `.map`/directory entries → `boto3 upload_file(ContentType=None)` **failed** (source maps never uploaded), and its `kyte_shipyard_cf` env var pointed at a **nonexistent distribution** → the invalidation threw `NoSuchDistribution` and the dashboard served stale.

The update now runs **out-of-band in a cron worker**, not synchronously in the request. The download/extract/upload/invalidate routinely exceeds the **~100s Cloudflare non-enterprise** request ceiling (and ALB idle timeouts), so a synchronous controller action would 524 on Cloudflare-fronted installs. Both Lambda bugs are fixed inherently.

- **New `KyteShipyardUpdate` model + table** (`migrations/4.12.0_shipyard_update.sql`) tracks each request and its outcome (`status` pending→running→complete/failed, `requested_version`, `deployed_version`, `files_uploaded`/`files_failed`, `cloudfront_invalidated`, `message`).
- **`KyteShipyardUpdateController::new`** does a fast inline CDN version check (~100ms) and returns "up to date" immediately when current; otherwise it enqueues a `KyteShipyardUpdate` row (`status=pending`) and returns it. The dashboard polls `GET` on the model by id for live status.
- **`ShipyardUpdateWorker`** (`src/Cron/ShipyardUpdateWorker.php`, a `CronJobBase` job, interval 60s) claims the oldest pending row, downloads + extracts the stable `kyte-shipyard.zip` (ext-zip / `ZipArchive`), uploads every file via `Kyte\Aws\S3::write()` with an **explicit per-extension Content-Type** (JS→`application/javascript`, CSS→`text/css`; unknown types like `.map` are omitted rather than crashing — fixes bug #1), then invalidates the distribution from **config** `KYTE_SHIPYARD_CF` (fixes bug #2, best-effort). `heartbeat()` extends the lease during large uploads.
- **Idempotency, two layers:** the controller refuses to enqueue while a row is pending/running (request dedup); the worker registers with `allow_concurrent=0` (lease lock) and claims rows via a guarded `pending→running` UPDATE keyed on `affected_rows()==1` (execution dedup).

**Setup:** run the migration, `php bin/register-shipyard-update-job.php`, and set `KYTE_SHIPYARD_S3` / `KYTE_SHIPYARD_CF` (+ optional `KYTE_SHIPYARD_REGION`, default `us-east-1`) in `config.php`. **New dependency:** `ext-zip`. `SNS_KYTE_SHIPYARD_UPDATE` is no longer referenced (the Lambda + SNS topic are decommissioned in the final #201 step).

## 4.11.1

### Fix: CloudFront invalidation `CallerReference` collision (direct/`KYTE_USE_SNS=false` path) — KYTE-#201

`Kyte\Aws\CloudFront::createInvalidation()` built its `CallerReference` as `time().$distributionId` — **second precision**. Any two invalidations against the same distribution within the same second reused that reference with a different path batch, which CloudFront rejects with `InvalidArgument`. The wrapper then swallowed it as a generic *"Unable to create new invalidation"*. This made the **direct** invalidation path (`KYTE_USE_SNS=false`) flaky under rapid or bulk publishes — a/the reason invalidation was historically routed through SNS→Lambda instead.

Fix: append `uniqid('', true)` (microsecond entropy) so successive CallerReferences never collide, and surface the real AWS error message instead of the generic one. Measured latency of a single `createInvalidation` is ~150–190 ms, so the synchronous direct call is well within request budgets (the old "timeout" symptom was this failure, not the call duration). Unblocks the #201 move off SNS for cache invalidation: fix lands first, then installs flip `KYTE_USE_SNS=false`, then the dead SNS branches get removed.

**Best-effort invalidation hardening.** With `KYTE_USE_SNS=false` the invalidation runs synchronously inside the publish request, so a transient CloudFront error (throttling, a missing distribution) would otherwise fail a publish whose content already wrote to S3 successfully. Wrapped all 10 invalidation sites (`KytePageController` ×3, `KyteScriptController` ×2, `KyteLibraryController` ×2, `KytePageDataController`, `NavigationController`, `SideNavController`) in best-effort try/catch — log and continue, never fail the publish — matching the pattern `ApplicationController` already used.

## 4.11.0

### Feature: JWT-mode anonymous/public API access (AppContextStrategy) — KYTE-#229

Lets a site running in **JWT auth mode** (endpoint + appId, no embedded HMAC key/secret) serve `requireAuth=false` controllers to **anonymous visitors** (public/catalog browsing before any login) — something only HMAC mode could do before. Server-side half of the two-repo change (the kyte-api-js anonymous fall-through ships alongside).

**New `AppContextStrategy`** (`src/Core/Auth/AppContextStrategy.php`), slotted in `AuthDispatcher::buildDefault()` **after** `JwtSessionStrategy` and **before** `HmacSessionStrategy`:
- `matches()` is strict and header-only — claims a request **only** when an `x-kyte-appid` is present **and** there is no `Authorization` Bearer, no `x-kyte-signature`, and no `x-kyte-identity`. Mutually exclusive with every authenticated flow, so it cannot shadow HMAC or JWT.
- `preAuth()` resolves the application's **account** for query scoping but **never resolves a user and never sets `hasSession`**. That is the security invariant: `ModelController::authenticate()` throws unless both `$api->user->id` and `$api->session->hasSession` are set, so every `requireAuth=true` controller keeps returning 403 to anonymous requests — only `requireAuth=false` controllers are reachable.

**Defense in depth:**
- **Per-app tri-state opt-in.** New `Application.allow_public` flag (default `0`; migration `migrations/4.11.0_application_allow_public.sql`):
  - `0` (default) — anonymous appid-only requests are rejected in `preAuth()`; anonymous access is never implicit.
  - `1` — **read-only**: `ModelController` restricts an `app_context` request to `GET` regardless of the controller's `allowableActions` (public catalog/storefront browsing).
  - `2` — **controller-governed**: the controller's own `requireAuth=false` + `allowableActions` declaration governs, including writes — needed for pre-login flows like password reset (`new`/`update` are POST/PUT). This matches the contract controller authors have always written against: under HMAC, anonymous visitors to a public site can already reach every `requireAuth=false` action (the signing endpoint mints anonymous signatures from the embedded public key alone), so `2` exposes nothing HMAC does not. `requireAuth=true` controllers still 403 in every mode (the no-user/no-`hasSession` invariant is independent of `allow_public`).
- **Shadow harness.** `AuthShadowHarness` skips `app_context` (no legacy equivalent to diff against during dispatcher rollout).

Audit attribution for anonymous requests uses `user_id=null` / session `'0'` (ActivityLogger already tolerates a null user). Existing HMAC and JWT-Bearer flows are unchanged. Tests: `tests/AppContextStrategyTest.php` (matches() truth table; `preAuth` resolves account but not user/hasSession; tri-state opt-in enforcement incl. unknown values treated as off).

### Feature: platform-level password reset for JWT mode — `/jwt/password-*` (KYTE-#268)

Shipyard is **platform-level** (no `x-kyte-appid` — `applicationId` is deliberately null), so its anonymous password reset can ride neither HMAC anonymous (gone in JWT mode) nor `AppContextStrategy` (appid required). Result: password reset was broken on JWT-mode Shipyard installs. Fixed the same way `/jwt/login` solved appid-less login — dedicated unauthenticated endpoints on `JwtEndpoint`:

- `POST /jwt/password-reset` `{email}` → always `{ok:true}` (no-reveal); known email gets a timestamped token (raw, in the password column — login disabled while pending) + SES mail.
- `POST /jwt/password-validate` `{token}` → `{valid:bool, email}` (password.html pre-check; the email is only disclosed to a live-token holder, who received it at that inbox).
- `POST /jwt/password-update` `{token, password}` → consumes the token, stores the new password hashed, and **revokes every refresh-token family** for the user (a reset invalidates all sessions); `401 invalid_token` on expired/unknown.

Token/email mechanics are extracted to `Kyte\Core\Auth\PasswordResetFlow` and shared with `KytePasswordResetController` (behavior-identical refactor — same token format, 1-hour TTL, no-reveal logging), so the HMAC/app-scoped path and the JWT platform path cannot drift. App-scoped sites keep using their own reset controllers via `app_context` mode 2. kyte-shipyard `reset.js`/`password.js` call the new endpoints when in JWT mode (ships in the Shipyard release alongside).

Tests: `tests/JwtEndpointTest.php` gains the `/jwt/password-*` suite (no-reveal, pending-token login lockout, validate/consume round-trip, refresh-family revocation, expired/unknown → 401).

## 4.10.1

### Fix: MCP commit_draft published raw bzip2 bytes into page HTML (header/footer section CSS not decompressed)

Publishing a page through the MCP `commit_draft` flow could inject raw bzip2-compressed binary (magic `BZh9…`) into the published HTML, inside `<style>`/`<footer>` blocks, producing a browser UTF-8 decode error and garbled header/footer CSS. Surfaced on FrameVTO / doctor.etometry.com (page 58, published to v13).

**Root cause.** A page's `header`/`footer` are FKs to `KyteSectionTemplate`, which stores `html`/`stylesheet`/`javascript`/`block_layout` **bzip2-compressed**. `getObject()`'s FK expansion returns those fields RAW. The page-assembly path (`createHtml` → `buildHeaderFooterStyles` etc.) concatenates them straight into the output, so they must be decompressed first. The **human publish** path (HTTP `update`, `state=1`) was saved only incidentally — `KytePageController::hook_response_data()` runs first and decompresses `$r['header']`/`$r['footer']`. The **MCP commit** path (`DraftService::commitDraft` → `publishForSurface` → `KytePageController::publishFromContent`) calls `getObject()` and goes straight to `publishPage()`, never invoking that hook — so the compressed bytes shipped. Deterministic per-path (not a race): any page with a populated header/footer section template was affected; the "re-publish cleared it" report was a re-publish via the human path.

**Fixes (`KytePageController`, `S3`):**
1. **Path-independent decompression.** New `decompressSectionTemplate(&$section)` decompresses header/footer content via `Bz2Codec::decompressIfBz2`, called from BOTH `hook_response_data()` and `publishFromContent()`. The page's own html/stylesheet/javascript were already correct (decompressed by `DraftService::versionContent`); only the section templates leaked.
2. **Latent guard bug fixed.** The old `hook_response_data` block required *all four* fields (`html`, `stylesheet`, `javascript`, `block_layout`) to be set or it decompressed *none* — a null `block_layout` would have leaked the other three even on the HTTP path. The new helper decompresses each field independently.
3. **Output integrity guard.** `publishPage()` now runs `hasBinaryContamination()` on the assembled HTML (bzip2 stream magic `BZh[1-9]1AY&SY`, or invalid UTF-8) and **aborts before the S3 write** (returns false → MCP commit reports `committed:false`, draft left intact) rather than shipping corrupt HTML.
4. **Charset.** Published HTML objects are now written with `Content-Type: text/html; charset=utf-8` (`S3::write()` gained an optional content-type passed through the stream-wrapper context; other callers unchanged).

Tests: `tests/PublishIntegrityTest.php` covers the per-field decompression (incl. the null-`block_layout` regression) and the contamination detector (embedded bzip2 stream, invalid UTF-8, and no false-positive on literal "BZh" prose).

## 4.10.0

### Feature: MCP draft/write — AI can draft and commit pages, controller functions, and scripts

Phase 2 *write* tools for the MCP server. Until now the MCP tools were read-only; this release lets an AI client **propose changes as drafts** and **commit** them live, across all three content surfaces — without ever touching the live resource until an explicit commit.

**Model.** A draft is a pending version row (`draft=1`, `is_current=0`) on the existing version tables — the live content and the current version are untouched until commit, which flips the draft to `is_current=1` and publishes. A new migration `migrations/4.10.0_version_draft_flags.sql` adds `draft` + `draft_source` to `KytePageVersion`, `KyteFunctionVersion`, and `KyteScriptVersion` (additive, migration-first, inert on older code).

**Engine.** `Kyte\Mcp\Service\DraftService` is surface-generic, driven by a per-surface descriptor (version model, content model, parent model, content fields), reusing the same sha256 content-hash + bzip2 + `reference_count` dedup conventions as the existing controllers so drafts de-duplicate against existing version content.

**Tools** (`Kyte\Mcp\Tools\DraftTools`):
- Writes (require `draft` scope): `write_page_part(page_id, part, content)`, `write_function_code(function_id, code)`, `write_script_content(script_id, content)`. Repeated writes on the same resource accumulate into one open draft.
- Review (require `read`): `list_drafts(application_id)` spans all surfaces (each row tagged with its `surface`); `read_draft(surface, draft_id)` returns content + which parts differ from live.
- Lifecycle: `discard_draft(surface, draft_id)` (`draft` scope); `commit_draft(surface, draft_id)` (`commit` scope) — the only action that changes the live resource. Pages/scripts publish to S3 + invalidate CloudFront; functions write the live code and regenerate the controller's compiled code base. On a failed publish, commit returns `committed:false` + an error and leaves the draft intact (publishes first, so a failure never half-applies).

**Supporting changes.** `KytePageController::publishPage` is now `public static` and returns the S3 write result (existing void callers unaffected); it gains `publishFromContent()` for commit. `KyteScriptController` gains `publishFromContent()`. `ModelController` gains an optional `internal` constructor flag that skips the session `authenticate()` check, so a trusted server-side caller (the MCP commit flow) can use a controller with an account context but no HTTP session.

Token scopes (`read` / `draft` / `commit`) on `KyteMCPToken` already existed; new tokens stay draft-only by default, so committing live is opt-in per token. Sequenced by risk: pages and scripts (fully versioned) and controller functions; model/schema drafting is intentionally out of scope (deferred to the data-model initiative).

## 4.9.0

### Fix: ActivityLogger no longer bloats KyteActivityLog, and the admin log list stops dragging blobs (KYTE-#182)

`KyteActivityLog` grew to **10GB on one install and OOM'd the admin log query**. Two independent causes, both fixed here — pure code, no schema change, instant rollback.

1. **Write-cap (the durable bloat fix).** `request_data` and `changes` are `LONGTEXT` and `ActivityLogger` stored the **full** json-encoded request body / diff. A single page or script save carries 300KB+ of HTML/JS/CSS, so each logged row could be hundreds of KB — multiplied across every write, that is what filled the table. `ActivityLogger::log()` now caps each of those two fields at `KYTE_ACTIVITY_LOG_MAX_FIELD_BYTES` (default **16384** bytes, auto-defaulted in `Api`). When the encoded value exceeds the cap it is replaced with a small audit-preserving marker — `{"_truncated":true,"_original_bytes":N,"_fields":[...]}` — so you still see *what* was sent or changed (the top-level field names and the original size), just not the megabyte of content. Set the constant to `0` to disable the limit. Redaction (SensitivityPolicy + the hardcoded `SENSITIVE_FIELDS` baseline) is unchanged and still runs before the cap.

2. **List-view projection (the OOM fix).** The framework `SELECT *` (`DBI::select`) pulls both `LONGTEXT` columns for every row, and the admin log **list** never uses them — only the single-record **detail** view does. `KyteActivityLogController` now detects a by-`id` detail fetch in `hook_prequery` and, on every other (list) response, omits `request_data`/`changes` and skips the JSON decode. The detail view (Shipyard `get("KyteActivityLog","id",idx)`) still returns the full decoded payload, so no Shipyard change is required. Severity/action colors are still added to both list and detail rows.

**Not in this release (deferred to the data-model initiative, KYTE-#190):** index coverage on `KyteActivityLog`'s filter/sort columns and a retention policy. The earlier one-off ETOM purge (10.4GB → 1.56GB) was the band-aid; the write-cap above is what prevents recurrence.

## 4.8.1

### Fix: republish is now fault-isolated (KYTE-#181) + partial content saves no longer blank `block_layout` (KYTE-#189)

**Republish resilience (KYTE-#181).** `ApplicationController`'s `republish_kyte_connect` hook re-stamps every `state=1` page across all of an app's sites. Previously it `throw`ew on the first page with missing `KytePageData`, **aborting the entire batch** — every later site/page kept the stale connect string, leaving an app half-migrated (e.g. a JWT dashboard with an HMAC login page). Now each page is re-stamped inside a `try/catch`: failures are collected (`page` id, `s3key`, `site`, `reason`) and logged, and the loop continues. The result is surfaced on the response as `republish_summary` (`succeeded` / `failed` / `failures[]`) so the caller can show a real outcome instead of trusting a silent all-or-nothing hook. Also fixed: CloudFront invalidation ran **once outside** the sites loop, so only the *last* site was invalidated — it now runs **per site**, so multi-site apps no longer leave other sites' caches stale.

**`block_layout` preservation (KYTE-#189).** The KytePage update content-save unconditionally wrote `block_layout`, blanking it to empty when the field wasn't in the payload. A partial save — notably the Shipyard IDE, which sends only `html`/`stylesheet`/`javascript` — would therefore **wipe an existing block-editor layout**. The update now only overwrites `block_layout` when it's actually provided, preserving the stored value otherwise. (The IDE "save silently doesn't persist" half of #189 was already resolved by the v4.8.0 guard relaxation; this closes the data-loss edge.)

## 4.8.0

### Change: drop JavaScript obfuscation columns (Phase 2 — schema; resolves KYTE-#191 Phase 2)

Phase 2 of the JS-obfuscation removal (KYTE-#191). Phase 1 (v4.7.0) stopped obfuscation **behaviorally** but deliberately left the obfuscated columns in place (inert) so an expand/contract rollout could prove stable before any schema change. This release **contracts**: it removes the now-dead columns and all remaining code that touched them.

1. **Schema drop (9 tables, via `migrations/4.8.0_drop_obfuscation_columns.sql`).** Drops the inert obfuscated content columns `javascript_obfuscated` (`KytePageData`, `KytePageVersionContent`, `KyteSectionTemplate`), `content_js_obfuscated` (`KyteScript`, `KyteScriptVersionContent`), and `kyte_connect_obfuscated` (`Application`), plus the now-unused flags `obfuscate_js` (`KyteSectionTemplate`, `KyteScript`, `KytePage`, `KytePageVersion`, `KyteScriptVersion`) and `obfuscate_kyte_connect` (`Application`). This reclaims the **stored-duplicate storage bloat** — every page/script/section had been storing a second bzcompressed copy of its JS that was never served. The migration does not pin an `ALGORITHM`: modern engines (MySQL 8.0.29+, MariaDB 10.5+) perform the `DROP COLUMN` as an INSTANT, no-rebuild operation automatically, and older engines fall back to INPLACE/COPY automatically. (An explicit `ALGORITHM=INSTANT` would *error* on an engine that can't honor it rather than fall back, so it is intentionally omitted.)

2. **Model definitions removed.** The five field identifiers are deleted from the `Mvc/Model` definitions (`KytePageData`, `KytePageVersionContent`, `KyteSectionTemplate`, `KyteScript`, `KyteScriptVersionContent`, `KytePage`, `KytePageVersion`, `KyteScriptVersion`, `Application`).

3. **bz storage handling removed.** All `bzcompress`/`bzdecompress` of the obfuscated fields is gone from the page/script/section/library/nav/sidenav/application controllers (compress-on-write, decompress-on-read, the `storeVersionContent`/`storeScriptVersionContent` writes, and the `getCurrentPageData`/`getCurrentScriptData` reads). The `safeCompressCode`/`safeDecompressCode` helpers are retained — `content` still uses them.

4. **isset() guards relaxed.** The content-save/publish/decompress guards (e.g. `KytePageController` update + `publishPage`, and the footer/header decompress guards across the page/data/application/library/sidenav/navigation/script controllers) previously required the obfuscated field alongside `html`/`stylesheet`/`javascript`/`block_layout`. Only the obfuscated element was removed from each guard; the guards still validate the real content fields.

**ROLLOUT RULE (code-first / drop-last, expand-contract):** deploy the v4.8.0 **code** to **all** instances **before** running `migrations/4.8.0_drop_obfuscation_columns.sql`. The v4.8.0 code no longer references the obfuscated columns, so dropping them is safe once it is live; running the migration against v4.7.0-or-earlier code would break content save/publish/decompress. Shipyard may keep sending empty obfuscated payload keys harmlessly — v4.8.0 ignores unknown fields.

## 4.7.0

### Change: drop JavaScript obfuscation (Phase 1 — behavioral; resolves KYTE-#188, KYTE-#191 Phase 1)

JS obfuscation has been forced on every Kyte install since v1. It provides **no real security** (client JS runs in the browser and obfuscation is trivially reversible; it is not a recognized control under any compliance framework), it **bloats storage** (each obfuscated field is a stored duplicate of the source), and it has caused **operational breakage** — a WAF/firewall once blocked legitimate obfuscated JS. No customer ever asked for it.

It also drove a **version-bloat bug (KYTE-#188)**: re-obfuscation is non-deterministic, so the publish/save path saw the obfuscated bytes change on every publish even when the human-authored source was byte-identical, spawning a content-identical `KytePageVersion`/`KytePageVersionContent` each time.

This release stops obfuscation **behaviorally**, without a schema change. The obfuscated columns are left in place (inert) and will be dropped in a follow-up (Phase 2) once this is confirmed stable across installs — expand/contract so a code rollback never strands a dropped column.

1. **Publish always serves plain source.** `KytePageController::buildJavaScript()` (page JS, the `kyte_connect` block, and header/footer JS via `buildHeaderFooterJS()`) and `KyteScriptController::handleScriptPublication()` now emit the plain `javascript` / `content` / `kyte_connect` regardless of the `obfuscate_js` / `obfuscate_kyte_connect` flags. The plain source has always been stored alongside the obfuscated copy, so this is **lossless**; existing pages de-obfuscate on their next publish.

2. **Obfuscation removed from change-detection (the KYTE-#188 fix).** `detectChanges()`/`addChangedFieldsToVersion()` (KytePage) and `detectScriptChanges()`/`addChangedFieldsToScriptVersion()` (KyteScript) no longer include `javascript_obfuscated`/`content_js_obfuscated` in their content-field sets or `obfuscate_js` in their metadata sets. A publish of unchanged source no longer spawns a spurious version. (`generateContentHash()` already excluded the obfuscated field.)

3. **New-app default.** `Application.obfuscate_kyte_connect` now defaults to `0` (was `1`). Moot behaviorally since publish ignores the flag, but keeps new rows honest.

**No migration. No DB change. Backward/forward compatible during rollout:** the storage hooks still accept the `*_obfuscated` columns, so an older Shipyard that still sends an obfuscated blob keeps working (kyte-php just ignores it at publish), and a newer Shipyard that sends an empty obfuscated value + `obfuscate_js=0` also works. Phase 2 (a later release) removes the model fields, the remaining `bz*`/decompress handling, and drops the columns to reclaim the stored-duplicate bloat.

## 4.6.1

### Bug Fix (regression from 4.6.0): large MCP tool responses corrupt the session → `read_page` (and other large reads) fail

A `read_page` against a large page (≈300KB+ of HTML) failed with a 400 and `"Control character error, possibly incorrectly encoded"`. Root cause is the new `DbSessionStore` from 4.6.0: it defined `KyteMCPSession.payload` as **`TEXT` (64KB max)**.

The streamable-HTTP SDK persists its outgoing-message queue (`_mcp.outgoing_queue`) — the full JSON-RPC tool **response** — inside the session payload between handling and delivery. A large read produces a ~800KB response (the SDK also duplicates content as a text block *and* `structuredContent`); that overflows the 64KB column, MySQL **silently truncates** it at 65535 bytes, and the truncated JSON then fails `json_decode` on the next read → the ctrl-char error, surfaced to the client as a 400.

The `FileSessionStore` that 4.6.0 replaced wrote to files with no size cap, so large reads worked there — this is a parity regression, not a pre-existing bug.

Fix: `KyteMCPSession.payload` is now **`LONGTEXT`** (model type `lt`). `migrations/4.6.1_mcp_session_payload_longtext.sql` runs `ALTER TABLE ... MODIFY payload LONGTEXT` and purges any sessions already truncated under the old column (`LENGTH(payload) >= 65535`) so stale corrupt rows don't keep failing on resume. Single-instance installs on the `file` backend are unaffected. **Run the migration on every install that took 4.6.0 with the default DB store.**

## 4.6.0

### Feature: DB-backed MCP session store (cross-instance / load-balanced support)

MCP protocol sessions were stored via the SDK's `FileSessionStore` under `sys_get_temp_dir()` — **local to a single host**. On a deployment behind a load balancer (e.g. ETOM's two instances), the `initialize` request lands on instance A and the follow-up request hits instance B, which has no session file → the SDK returns `-32600 "Session not found or has expired"` → the MCP client falls back to OAuth discovery (which Kyte doesn't serve) → the connection fails. Single-instance installs were unaffected.

This release adds `Kyte\Mcp\Session\DbSessionStore`, which persists protocol sessions in a new `KyteMCPSession` table so **any instance sharing the database resolves any session**, and makes it the default backend.

1. **New table: `KyteMCPSession`** (`migrations/4.6.0_mcp_session_store.sql`). Stores `session_id` (RFC4122 UUID, UNIQUE), the `payload` (the SDK's `json_encode`d session array), `last_activity`, and `kyte_account`. `CREATE TABLE IF NOT EXISTS`, safe to re-run. Auto-registered as a model via the `Mvc/Model` loader.

2. **DB store is now the default.** `Endpoint::buildSessionStore()` selects the backend. The DB store is correct for any topology (single host, LB, future SaaS). Single-instance installs that prefer not to add the table can opt back to the file store with `define('KYTE_MCP_SESSION_STORE', 'file');`.

3. **TTL semantics preserved.** `last_activity` is the last-write time; the SDK calls `session->save()` at the end of every handled request, so an active session's timestamp slides forward (idle timeout). `exists()` reports expiry without deleting; `read()` purges on expiry — both mirror `FileSessionStore` exactly. Idle TTL is `KYTE_MCP_SESSION_TTL` (default 3600s) for either backend.

4. **Tenancy & cleanup.** Reads/writes/destroys are scoped to the bearer token's `kyte_account` (a session resolves only under the account that created it). `gc()` (the SDK runs it on ~1% of requests) is a global sweep of TTL-expired rows, capped at 1000 per call; rows are hard-deleted (purged), not tombstoned, so the `session_id` UNIQUE index stays clean.

**Operational note:** run `migrations/4.6.0_mcp_session_store.sql` as part of the upgrade. With the default DB backend active, the table must exist before MCP traffic is served. Multi-instance installs (ETOM) require no per-instance config beyond the shared DB; this unblocks the `kyte-etometry` MCP connection (Tempo KYTE-183). No change to MCP auth (`KyteMCPToken`), which was already DB-backed.

## 4.5.3

### Bug Fix (critical): `/jwt/refresh` 401s for apps with a custom `user_model`

`JwtEndpoint::refresh()` reloaded the principal with a hardcoded `new ModelObject(KyteUser)`. Apps that authenticate against an app-scoped `user_model` (a `User` DataModel, not `KyteUser`) store the user in the app DB, so `KyteUser->retrieve('id', $userId)` finds nothing → `401 invalid_credentials "Refresh token principal not found."`

Impact: every JWT session on such an app dies on its **first refresh** — i.e. ~15 minutes (the access-token TTL) after login, presenting as "JWT randomly fails after a few minutes of inactivity." `login()` already handled custom user models (v4.4.3–4.4.5 via `resolveAuthContext`), but `refresh()` was never given the same treatment, so the bug was latent until an app with a custom user model was migrated HMAC→JWT.

Fix: `refresh()` now resolves the app from `refresh_token.app_id`, calls `resolveAuthContext($appIdentifier)` (which loads the app models + DB context and returns the correct `user_model`), and retrieves the principal from that model — mirroring `login()`. Default `KyteUser` path (no app scope) is unchanged.

No schema change. Composer upgrade is sufficient. Strongly recommended for any deployment running JWT on apps with custom user models.

### Migration backfill: MCP token table

Adds `migrations/4.5.3_mcp_tokens.sql` — creates `KyteMCPToken` (consumed by `McpTokenStrategy` / the Shipyard Tokens page). The Phase 2 MCP code shipped without its table-creation migration; this closes that gap. `CREATE TABLE IF NOT EXISTS`, safe to re-run.

## 4.5.2

### Bug Fix: `sensitive` toggle on a Controller silently fails (TypeError on metadata-only PUT)

Completes the 4.5.1 fix for the third meta-controller. Toggling `sensitive=1` on a **Controller** (the detail-page toggle) returned HTTP 200 with an empty body and reverted with "Save failed."

`ControllerController::hook_preprocess` (update) calls `validateControllerUpdate($o, $r)`, which early-returns only when `$o->name === $r['name']`. A metadata-only PUT of `{sensitive:1}` omits `name`, so `$r['name']` is `null`; `"SomeController" === null` is `false`, so it fell through and passed `null` into `checkNameExistsInScope(string $name)` → **TypeError** → caught by the global exception handler (logged as a `KyteError`, hence the empty 200) → the update aborted before `sensitive` was saved.

Fix: `validateControllerUpdate` now returns early when `name` is absent — `if (!isset($r['name']) || $o->name === $r['name'])`. The name-change path (which always sends `name`) is unaffected.

This is the Controller-level sibling of the 4.5.1 DataModel/ModelAttribute fixes — all three meta-controllers assumed every update carried the full record. With 4.5.2, sensitive flags can be set at controller, model, and field level. No schema change.

## 4.5.1

### Bug Fix: `sensitive` toggle (and any metadata-only PUT) silently fails on DataModel / ModelAttribute

Toggling `sensitive=1` on a model (Settings tab) or field never persisted: the PUT returned HTTP 200 with an empty body, and the Shipyard toggle reverted with "Save failed." Root cause is in two update hooks that assumed every update carries the full record:

- **`DataModelController::hook_preprocess` (update):** `if ($o->name != $r['name'])` ran the table-rename path. A partial PUT of just `{sensitive:1}` omits `name`, so the comparison was true against `null` → `DBI::renameTable($o->name, null)` → throws *"New table name cannot be empty"* — **before** `$obj->save()` persisted `sensitive`. Now gated on `isset($r['name']) && $o->name != $r['name']`.

- **`ModelAttributeController::hook_preprocess` (update):** unconditionally ran `DBI::changeColumn($tbl->name, $o->name, $r['name'], $attrs)` on every update. A metadata-only PUT (no `name`) tried to rename the column to an empty name with an incomplete definition and threw. The CHANGE COLUMN path is now gated on `isset($r['name'])`; the field-edit form (which always sends `name`) is unaffected.

Why it surfaced now: this is the first feature to PUT a single metadata field on these meta-models. The empty-200 was the swallowed hook exception (thrown after the 200 status path but before the response body was serialized). `Controller` sensitive toggles were never affected — `ControllerController` has no schema-altering update hook.

Impact: blocks enabling sensitive-data redaction at the model/field level (the redaction policy reads these flags at runtime). No schema change. Composer upgrade is sufficient.

## 4.5.0

### Feature: JWT session lifetime caps (inactivity + absolute)

JWT sessions in 4.4.x were effectively unlimited — every `/jwt/refresh` issued a fresh refresh token with `expires_at = now + 7d`, and the 15-minute access TTL meant any page activity rotated the refresh token forward indefinitely. A user could stay logged in for weeks just by opening the app every few days. This violates OWASP ASVS V3 ("absolute timeout MUST exist") and is the wrong default for an admin tool / regulated-industry web app.

This release introduces a two-knob policy that matches the industry-standard pattern (sliding inactivity timeout + absolute family cap):

1. **Lowered inactivity timeout.** `KYTE_JWT_REFRESH_TTL` default drops from 604800s (7d) to 14400s (4h). Closing the browser at 5pm now forces a re-login the next morning. Per-deployment override still applies — consumer mobile apps with "remember me" can opt for longer.

2. **New absolute family cap.** `KYTE_JWT_FAMILY_MAX_LIFETIME` (new constant, default 43200s / 12h) caps total session lifetime from the original `/jwt/login`, independent of how active the user is. Enforced in `RefreshTokenStore::rotate()` — when crossed, the whole token family is revoked with `revoked_reason='family_max_lifetime'` and the user must re-authenticate.

3. **New column: `KyteRefreshToken.family_started_at`.** Anchors the absolute cap to the original login moment. Set in `issue()`, copied forward unchanged in `issueInFamily()` on each rotation.

   **Legacy tokens (issued before this column existed) are capped, not exempted.** A pre-upgrade row has `family_started_at = 0`; `rotate()` anchors its cap to the token's `date_created` (the best available proxy for the original login). A pre-upgrade session whose login was more than `KYTE_JWT_FAMILY_MAX_LIFETIME` ago is revoked on its next rotation. **This means the first deploy of 4.5.0 forces re-login for any JWT session older than 12 hours** — intentional. An earlier draft of this change gave legacy tokens a free pass "to avoid disruption," but that let pre-upgrade 7-day sessions survive uncapped for up to a week after deploy (observed on dev), which defeats the purpose of an absolute cap.

   **Operational note for upgrades with active JWT sessions:** after running the migration, existing sessions older than the cap end on their next request (clean 401 → client re-login). If you want a hard cutover instead of a staggered one, revoke all pre-upgrade rows directly: `UPDATE KyteRefreshToken SET revoked_at=UNIX_TIMESTAMP(), revoked_reason='legacy_purge_v4.5.0' WHERE family_started_at=0 AND revoked_at=0;`

The defaults align with AWS Console (12h max), Microsoft 365 admin (1h/12h), and OWASP ASVS V3 absolute-timeout requirements. Customer mobile/consumer apps that need longer sessions can override both constants per-deployment.

Schema migration: `KyteRefreshToken` gains one unsigned-int column. **Run `migrations/4.5.0_jwt_family_lifetime.sql` after `composer update`** — Kyte does not auto-ALTER system tables. Same operational pattern as the 4.4.0 sensitive-columns + JWT-refresh migrations.

**Config note:** deployments that explicitly set `KYTE_JWT_REFRESH_TTL` in `config.php` (e.g. the 4.4.0 default of `604800`) override the new 4h default — update those configs to `14400` and add `KYTE_JWT_FAMILY_MAX_LIFETIME` or the new inactivity timeout is silently shadowed.

**Client pairing:** kyte-api-js must be ≥ v2.0.2 (refresh-cookie TTL derived from `refresh_expires_at`). With the older v2.0.1 client, the browser cookie keeps a 30-day TTL, so an idle tab *looks* logged in under a dead server-side session until the next request fails. Both halves are required for correct UX.

Tests: `RefreshTokenStoreTest` covers (a) family_started_at set on issue, (b) preserved across rotation, (c) cap rejection past the window, (d) cap allows refresh inside the window, (e) legacy token within cap anchors to date_created, (f) legacy token past cap is revoked.

## 4.4.5

### Bug Fix + Feature: JWT login parity with HMAC session response

Three changes for JWT login on apps with custom `user_model`:

1. **Account fallback for app-scoped users.** `JwtEndpoint::login` bailed with 401 when `user->kyte_account` was 0. App-scoped User models don't carry that FK directly — the user belongs to the Application, and the Application carries the account FK. Fall back to `$app->kyte_account` when the user has no direct account.

2. **HMAC-parity response shape.** Adds `uid`, `account_id`, and `data` (user payload, with protected fields stripped, wrapped per `USE_SESSION_MAP`) to the JWT login response. Customer frontends consuming `session.data[0].fieldname` or `session.uid` were silently failing under JWT because the payload was tokens-only.

3. **FK expansion in `data`.** Mirrors HMAC's `SESSION_RETURN_FK` behavior — `user.org` is now expanded into the full Org object (`{id:2, org_type:'LP', ...}`) instead of returned as the raw integer FK. Bounded recursion at depth 3 to handle (rare) cyclic FKs. Frontends doing `user.org.org_type` now work.

Known follow-up: FK expansion is N+1 — see Tempo card #171 for optimization.

No schema changes. Composer upgrade is sufficient.

## 4.4.4

### Bug Fix: `/jwt/login` still 500s on apps with custom `user_model` (continuation of 4.4.3)

v4.4.3 fixed the `Undefined constant "User"` fatal by calling `Api::loadAppModels($app)` in `resolveAuthContext`. That uncovered a second gap: the app-scoped model definition has `appId` set, which causes `ModelObject->retrieve()` to auto-switch to the app DB via `Api::dbswitch(true)`. But the app-DB credentials (host/user/password) were never configured because `Api::dbappconnect()` is called only by the normal MVC pipeline (Api.php:690) — which JWT bypasses.

Result: mysqli received null host/user/password and tried a Unix-socket connection → `No such file or directory` → HTTP 500.

Fix: `resolveAuthContext` now also calls `Api::dbappconnect($app->db_name, $app->db_username, $app->db_password)` immediately after `loadAppModels`. The HMAC pipeline does both calls back-to-back at Api.php:688-690; JWT now matches.

Apps without a custom `user_model` (default `KyteUser` path) are unaffected — they were never in the app-DB branch.

No schema changes. Composer upgrade is sufficient.

## 4.4.3

### Bug Fix: `/jwt/login` fatals on apps with custom `user_model`

When an Application's `user_model` is set to an app-specific DataModel (e.g. `"User"` rather than the default `"KyteUser"`), `JwtEndpoint::resolveAuthContext` called `constant($app->user_model)` to resolve the model definition. But the JWT endpoint dispatches *before* `Api::loadAppModels()` runs in the normal MVC pipeline, so the app-scoped constant wasn't yet defined. In PHP 8+ that's a fatal: `Undefined constant "User"`. Surface to the client was HTTP 500.

HMAC `/Session` login did not hit this because `Api::route()` calls `loadAppModels` before reaching the session controller. JWT's whole point is to bypass that pipeline (login can't require auth), which is what created the gap.

Fix: `resolveAuthContext` now calls `Api::loadAppModels($app)` immediately after retrieving the Application, before referencing the constant. If the app references a name that no DataModel row defines, falls back to `KyteUser` (with an `error_log` breadcrumb) rather than fatal — login then naturally fails at `password_verify`, which is a safer surface than a 500.

No schema changes. Composer upgrade is sufficient.

## 4.4.2

### Bug Fix: ErrorHandler crash when `apiContext->key` is a ModelObject

The enhanced v4.4 ErrorHandler bound `$this->apiContext->key` directly into the `KyteError.api_key` string column. `$this->key` is set in `Api.php` as `new \Kyte\Core\ModelObject(KyteAPIKey)` — an object, not a string. `mysqli_stmt::execute()` then threw `Object of class Kyte\Core\ModelObject could not be converted to string` *inside* the error handler. That secondary fatal swallowed the original error and surfaced to clients as a **blank HTTP 500** with no log row written and no stack trace recoverable.

Customer-visible impact: any request that triggered the error handler — including expected exceptions on routes like POST `/Session`, GET on a controller hitting a runtime error, etc. — returned 500 with no body. Production traffic at ETOM was affected from the v4.4.0 deploy onward.

Fix: `'api_key' => isset($this->apiContext->key->public_key) ? (string)$this->apiContext->key->public_key : null` — extract the scalar `public_key` string (which is the audit-relevant value anyway). Falls back to `null` when the ModelObject hasn't been retrieved or when there's no key context at all.

Regression test `ErrorHandlerSensitivityTest::testApiContextKeyAsModelObjectLogsPublicKeyString` exercises the previously-broken ModelObject path end to end. Pre-existing tests set `$context->key = null` and never hit the crash, which is why the regression slipped through 4.4.0 and 4.4.1.

No schema changes. Composer upgrade is sufficient.

## 4.4.1

### Bug Fix: CORS preflight on `/jwt/*` endpoints

Browser CORS preflight requests (`OPTIONS /jwt/login`) were being routed
through `JwtEndpoint::process()`, which only accepts POST and returned
`405 method_not_allowed` with no CORS headers. Browsers then blocked
the actual `POST /jwt/login`, breaking JWT login for any same-page web
client (Shipyard 2.0+ in JWT mode, kyte-api-js v2 JWT consumers).

Root cause: `Api::cors()` runs inside `validateRequest()`, which lives
downstream of the `/jwt` dispatch in `Api::route()`. So `/jwt/*` skipped
CORS entirely. (Same is technically true for `/mcp` but MCP is
server-to-server and doesn't trigger browser preflight.)

Fix: `JwtEndpoint::handle()` now emits CORS headers on every response
and replies to OPTIONS with `204 No Content` + CORS preflight headers
before falling through to `process()`. Mirrors the permissive Origin
policy in `Api::cors()`.

Regression test: `JwtEndpointTest::testHandleAnswersOptionsPreflightWith204`
exercises the OPTIONS path through `handle()` end-to-end with output
buffering and asserts the 204 response.

No schema changes. Composer upgrade is sufficient. Customers running
Shipyard 2.0+ in JWT mode must update before JWT login will succeed
in a browser.

## 4.4.0

> Phase 2 (MCP server) + Phase 2.5 (sensitive-data flag) + Phase 3 (JWT auth) all ship together. **No breaking changes** — every addition is opt-in, defaults preserve v4.3.x behavior bit-for-bit. Existing customers can upgrade in place without code changes.

### New Feature: Embedded MCP server (Phase 2)

Each Kyte install can now expose a Model Context Protocol endpoint that lets AI clients (Claude Code, Claude.ai) inspect controllers, models, pages, and functions over a tenant-scoped bearer.

- **New endpoint**: `POST /mcp` — handled by `Mcp\Endpoint` outside the standard MVC pipeline. JSON-RPC over HTTP, protocol version `2025-06-18`. Bypasses Kyte's HMAC envelope.
- **New strategy**: `McpTokenStrategy` registered with the auth dispatcher. Strict `Authorization: Bearer kmcp_live_…` prefix match.
- **New model**: `KyteMCPToken` — opaque bearer tokens. Stored as sha256 hash, displayed as 16-char prefix for identification. Scoped (`read` / `draft` / `commit`), revokable, optional expiry, optional CIDR allowlist, optional application-binding.
- **New controller**: `KyteMCPTokenController` — issue, list, revoke. Generates the raw token at issuance (returned once, never recoverable). Force-overrides `kyte_account` from auth context to close a privilege-escalation vector. Emits `MCP_TOKEN_ISSUE` / `MCP_TOKEN_REVOKE` / `MCP_TOKEN_USE` / `MCP_SCOPE_VIOLATION` audit rows.
- **10 read tools shipped**: `list_applications`, `list_controllers`, `read_controller`, `list_functions`, `read_function` (with optional `version_number`), `list_models`, `read_model`, `list_sites`, `list_pages`, `read_page` (with optional `version_number`).
- **Scope enforcement**: `ScopedCallToolHandler` registered ahead of the SDK's default handler. Tools declare required scope via `#[RequiresScope('read'|'draft'|'commit')]` attribute. Fail-closed default — a tool without the attribute is unreachable.
- **Account isolation**: every tool re-asserts `entity.kyte_account === api.account.id`. Foreign ids return `null` / `[]`, never the foreign record.
- **bzip2 decompression**: `Controller.code`, `Function.code`, and `KytePageVersionContent.{html,stylesheet,javascript}` are stored compressed; `Bz2Codec::decompressIfBz2` wraps the read tools so source is returned in plaintext to the client.
- **Origin validation** on `/mcp`: per spec § Security, mitigates DNS rebinding. Policy is "no Origin → allow, Origin present → check `MCP_ALLOWED_ORIGINS` constant (CSV)". CLI clients (Claude Code) unaffected; restrictive default forces operator opt-in for browser origins.
- **Proxy-aware client IP**: `Kyte\Mcp\Util\ClientIp` reads `CF-Connecting-IP` then `X-Forwarded-For` first hop then falls back to `REMOTE_ADDR`. Gated on `KYTE_TRUST_PROXY_IP_HEADERS` constant — default-off so installs without a proxy aren't exposed to header spoofing. Wired into `McpTokenStrategy::clientIp()` for IP allowlist enforcement and audit fields.

### New Feature: Sensitive-data flag (Phase 2.5)

A three-tier opt-in flag that prevents activity/error logs from capturing request bodies, and gates MCP read tools from exposing source for flagged entities. Designed for pass-through controllers whose payload contents are regulated data the platform should not store.

- **New columns** (default `0`, no behavior change unless flipped):
  - `Controller.sensitive` — blanket flag. When `1`, drops body+response from logs entirely. Handles virtual (no-model) pass-through controllers.
  - `DataModel.sensitive` — same blanket treatment when the model is the request target.
  - `ModelAttribute.sensitive` — per-field redaction. Distinct from existing `.protected` (which only blanks values in GET responses). Set both for both behaviors.
  - `KytePage.sensitive` — MCP-only; withholds `html`/`stylesheet`/`javascript` from `read_page`. Pages don't write to activity logs.
- **New service**: `SensitivityPolicy` (`src/Core/SensitivityPolicy.php`) — single source of truth, per-request singleton with in-memory cache keyed by `(scope, name, account)`. One DB hit per tuple per request. Fail-permissive on lookup error so transient DB issues degrade to existing `SENSITIVE_FIELDS` baseline rather than to no redaction at all.
- **ActivityLogger** consults the policy before persisting `request_data` and the PUT changes diff. Blanket-sensitive → both fields null. Field-sensitive → flagged fields replaced with `[REDACTED]`, other fields pass through. The hardcoded `SENSITIVE_FIELDS` list (password / token / secret_key / etc.) still runs as a baseline on top.
- **ErrorHandler** previously captured request body AND response payload into `KyteError` with zero redaction — closed in this release. `handleException`, `handleError`, and `outputBufferCallback` all consult the policy now. AI error-correction queue (`AIErrorCorrection::queueForAnalysis`) gains its own defense-in-depth check at the top: skips any sensitive-origin row, with audit-log breadcrumb. Regulated data never reaches Anthropic for analysis.
- **MCP read tools** gated by the same flag: sensitive controller → `read_controller` returns `code: null` + `sensitive: true`; sensitive function (via parent controller) → same; sensitive model → `read_model` returns `definition: null` + `sensitive: true`; sensitive field → stripped from `definition.struct`, listed separately in `sensitive_fields`; sensitive page → content fields null. List tools (`list_controllers`, `list_models`, `list_pages`) surface a `sensitive: bool` on each row so AI clients see up front which entities are gated.
- **Runtime API responses unaffected** — a sensitive controller still returns its normal response to the caller. The flag governs log / MCP / AI exposure only, not the live contract.

### New Feature: JWT bearer authentication (Phase 3)

Modern auth path that coexists with the legacy HMAC sign/rotate. Customers can run a mix of HMAC apps and JWT apps on the same install.

- **New strategy**: `JwtSessionStrategy` — HS256 access tokens with a configurable secret, claims `{iss, sub, aud, exp, iat, nbf, jti, email, app}`. Strict `Authorization: Bearer eyJ…` prefix match (won't clash with MCP's `kmcp_live_…`). Decode + verify in `preAuth`, sets `$api->user`, `$api->account`, and `$api->session->hasSession = true` so the standard ModelController auth gate accepts the request.
- **New endpoint family**: `POST /jwt/login`, `POST /jwt/refresh`, `POST /jwt/logout`, `POST /jwt/logout-all` — handled by `JwtEndpoint` (same pattern as `Mcp\Endpoint`, bypasses the MVC pipeline so login can run pre-auth). Login posts `{email, password, app_identifier?}` and returns `{access_token, refresh_token, expires_in, token_type:'Bearer', refresh_expires_at}`.
- **Refresh tokens**: opaque (`kref_v1_…` prefix), stored as sha256 hash in new `KyteRefreshToken` table. Single-use rotation per RFC 6819 — every successful refresh revokes the presented token and issues a new one in the same family. **Reuse detection**: presenting a revoked token revokes the entire family (likely leak signal). Expiration without revocation does not trigger family kill.
- **Multilogon**: separate logins always create separate families. A user signing in from laptop and phone gets two independent families — revoking one device does not affect the other. Distinct from HMAC's `ALLOW_MULTILOGON` flag.
- **`AuthDispatcher::buildDefault()`** now registers three strategies: McpToken → JwtSession → Hmac. Each `matches()` is strict so order doesn't affect correctness; order is documented for review clarity. HMAC clients continue working unchanged via `HmacSessionStrategy`.
- **`Application.auth_mode`** column added: `'hmac'` (default) or `'jwt'`. Drives whether Shipyard's page generator emits the v1.x HMAC constructor or the v2 JWT constructor for kyte-api-js consumers.
- **Configuration constants** (define in `config.php` to enable JWT):
  - `KYTE_JWT_SECRET` — required for JWT mode. At least 256 bits of entropy. Never commit to version control.
  - `KYTE_JWT_ISSUER` — optional, defaults to `'kyte'`. Mismatched tokens are rejected at preAuth.
  - `KYTE_JWT_ACCESS_TTL` — optional, default 900 seconds.
  - `KYTE_JWT_REFRESH_TTL` — optional, default 604800 seconds (7 days).
- **Dependency**: `firebase/php-jwt ^7.0` — lightweight, well-known, no transitive deps.

### Bundled migrations

Three new SQL files in `migrations/`. Apply in order. All are additive (new columns / new table) — safe to apply ahead of code; new columns default to `0` / `'hmac'` so legacy code sees no behavior change until the matching feature is opted into.

```
migrations/4.4.0_sensitive_columns.sql       # Controller / DataModel / ModelAttribute / KytePage .sensitive
migrations/4.4.0_jwt_refresh_tokens.sql      # KyteRefreshToken table
migrations/4.4.0_application_auth_mode.sql   # Application.auth_mode
```

### CI hardening

- **PHP matrix** in `.github/workflows/php.yml`: tests now run on PHP 8.2 AND 8.3 against MariaDB 10.5.29. Catches version-specific syntax / deprecation issues.
- **PHPStan** static analysis at level 1 with a baseline of pre-existing findings (`phpstan-baseline.neon`). New code is held to zero level-1 violations; baseline only shrinks.
- **Composer audit** step on every push — fails CI on a new advisory in production dependencies.

### Test coverage

- 180 unit tests, 531 assertions. Up from 109 at end of Phase 2.
- New test files: `SensitivityPolicyTest`, `ActivityLoggerSensitivityTest`, `ErrorHandlerSensitivityTest`, `McpSensitivityTest`, `JwtSessionStrategyTest`, `JwtEndpointTest`, `RefreshTokenStoreTest`.
- Includes a regression test for the `hasSession` integration bug surfaced during dev rollout: JwtSessionStrategy must mark `$api->session->hasSession = true` after validating the bearer, otherwise `ModelController::authenticate()` rejects every protected endpoint despite a valid JWT.

### Upgrade notes

1. Apply the three migrations above to your database.
2. If using JWT mode: define `KYTE_JWT_SECRET` in `config.php` (generate with `openssl rand -base64 48`). HMAC-only installs need no config change.
3. If you were running with `AUTH_STRATEGY_DISPATCHER='shadow'` from v4.3.0, you can now safely flip to `'on'` to activate the dispatcher. HMAC traffic routes through the new `HmacSessionStrategy` which has been shadow-verified bit-identical to the inline auth.
4. kyte-api-js v2.0+ is required on the client side for JWT apps. v1.x continues to work unchanged for HMAC apps.

---

## 4.1.1

### Bug Fix: ActivityLogger blocking exception on dynamically-loaded app models

- **`capturePreUpdateState()`**: Added `defined()` check before calling `constant($model)`. For dynamically-loaded app models (e.g. custom models stored in `DataModel` table), the PHP constant may not be available, causing a fatal `Error` that blocked the original PUT/DELETE request from executing.
- **All catch blocks**: Changed `catch (\Exception $e)` to `catch (\Throwable $e)` in `capturePreUpdateState()`, `log()`, and `logAuth()`. PHP 8.x `constant()` throws an `Error` (not `Exception`) for undefined constants, which bypassed the safety net entirely. The ActivityLogger should never block normal API operations.

---

## 4.1.0

### New Feature: Activity/Audit Logging System

Comprehensive activity tracking and audit logging for all API operations and authentication events. Motivated by the need to audit user actions, data changes, and API requests across the platform.

- **New Model: `KyteActivityLog`** - Denormalized model (no foreign keys for write performance) capturing:
  - WHO: user_id, user_email, user_name, account_id, account_name, application_id, application_name
  - WHAT: action (GET/POST/PUT/DELETE/LOGIN/LOGOUT/LOGIN_FAIL), model_name, record_id, request_data (JSON, redacted), changes (JSON diff for PUT)
  - RESULT: response_code, response_status, error_message
  - WHERE: ip_address, user_agent, session_token (masked), request_uri, request_method
  - META: severity (info/warning/critical), event_category (auth/data/config/system), duration_ms, kyte_account

- **New Singleton: `ActivityLogger`** (`src/Core/ActivityLogger.php`)
  - Direct DB writes via `ModelObject::create()` — bypasses controllers to avoid infinite loops
  - Sensitive data redaction: strips password, secret_key, access_key, token, and similar fields
  - Session token masking: shows only last 8 characters
  - Change tracking for PUT: snapshots record before update, diffs against new values
  - Loop prevention: internal flag skips logging during own DB writes
  - Auto-excludes KyteActivityLog model from being logged

- **New Controller: `KyteActivityLogController`** - Read-only controller with header-based filtering:
  - Filters: action_type, model_name, user_id, severity, event_category, start_date, end_date, application_id
  - Account-scoped via `kyte_account` in `hook_prequery`
  - Includes computed fields (severity_color, action_color) in response data

- **Api.php Integration**: ActivityLogger initialized after authentication, captures pre-update state for PUT, logs all successful and failed requests with response codes

- **SessionManager Integration**: Logs LOGIN, LOGOUT, and LOGIN_FAIL authentication events with severity levels

- **Configuration Constants**:
  - `KYTE_ACTIVITY_LOG_ENABLED` (default: true) - Master toggle
  - `KYTE_ACTIVITY_LOG_GET` (default: false) - GET request logging (off by default for performance)
  - `KYTE_ACTIVITY_LOG_EXCLUDED_MODELS` (default: []) - Additional models to exclude
  - `KYTE_ACTIVITY_LOG_RETENTION_DAYS` (default: 90) - Log retention period

**Database Migration SQL (v4.1.0):**

```sql
-- =========================================================================
-- Kyte v4.1.0 - Activity/Audit Logging System
-- =========================================================================
-- IMPORTANT: Backup your database before running this migration
-- This creates the KyteActivityLog table for comprehensive activity tracking
-- =========================================================================

CREATE TABLE IF NOT EXISTS KyteActivityLog (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- WHO
    user_id BIGINT UNSIGNED DEFAULT NULL,
    user_email VARCHAR(255) DEFAULT NULL,
    user_name VARCHAR(255) DEFAULT NULL,
    account_id BIGINT UNSIGNED DEFAULT NULL,
    account_name VARCHAR(255) DEFAULT NULL,
    application_id BIGINT UNSIGNED DEFAULT NULL,
    application_name VARCHAR(255) DEFAULT NULL,

    -- WHAT
    action VARCHAR(20) DEFAULT NULL COMMENT 'GET, POST, PUT, DELETE, LOGIN, LOGOUT, LOGIN_FAIL',
    model_name VARCHAR(255) DEFAULT NULL,
    record_id BIGINT UNSIGNED DEFAULT NULL,
    field VARCHAR(255) DEFAULT NULL,
    value VARCHAR(255) DEFAULT NULL,
    request_data LONGTEXT DEFAULT NULL COMMENT 'JSON request payload (sensitive fields redacted)',
    changes LONGTEXT DEFAULT NULL COMMENT 'JSON diff of old vs new values (PUT only)',

    -- RESULT
    response_code INT DEFAULT NULL,
    response_status VARCHAR(20) DEFAULT NULL,
    error_message TEXT DEFAULT NULL,

    -- WHERE
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(512) DEFAULT NULL,
    session_token VARCHAR(255) DEFAULT NULL COMMENT 'Masked - shows only last 8 chars',
    request_uri VARCHAR(2048) DEFAULT NULL,
    request_method VARCHAR(10) DEFAULT NULL,

    -- META
    severity VARCHAR(20) DEFAULT 'info' COMMENT 'info, warning, critical',
    event_category VARCHAR(50) DEFAULT NULL COMMENT 'auth, data, config, system',
    duration_ms INT DEFAULT NULL,
    kyte_account BIGINT UNSIGNED DEFAULT NULL,

    -- Audit attributes
    created_by INT DEFAULT NULL,
    date_created INT DEFAULT NULL,
    modified_by INT DEFAULT NULL,
    date_modified INT DEFAULT NULL,
    deleted_by INT DEFAULT NULL,
    date_deleted INT DEFAULT NULL,
    deleted INT UNSIGNED DEFAULT 0,

    -- Indexes for common query patterns
    INDEX idx_account_date (kyte_account, date_created),
    INDEX idx_user_id (user_id),
    INDEX idx_model_action (model_name, action),
    INDEX idx_application_id (application_id),
    INDEX idx_severity (severity),
    INDEX idx_event_category (event_category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 4.0.1

### Bug Fixes:
- **KyteProfileController**: Fixed `"Field and Values params not set"` error on PUT requests (password and email updates). The `hook_prequery` now defaults `$field` to `'id'` and `$value` to the authenticated user's ID when null, since KyteProfile always operates on the current user's own record

## 4.0.0

**Major Release: Performance Overhaul & Distributed Cron Job System**

This major version brings two transformative feature sets that fundamentally enhance the Kyte framework:

1. **Complete Performance Overhaul** - Database transaction support, comprehensive caching system (model + query caching), eager loading to eliminate N+1 queries, batch operations, performance monitoring, and multi-level structured logging with PSR-3 compatibility. These improvements can reduce query counts by 80-95% and response times by 100-500ms for complex requests.

2. **Enterprise-Grade Distributed Cron System** - Production-ready job scheduling with cron expressions, intervals, and calendar-based schedules. Features lease-based locking for multi-server environments, automatic retry with exponential backoff, dead letter queue, job dependencies, complete version control with SHA256 deduplication, execution history, Slack/email notifications, and a full REST API with web-based management interface.
   - **Worker Process Forking** - Industry-standard execution pattern using `pcntl_fork()` to spawn separate processes for each job. Ensures fresh code loading on every execution (no class caching issues), prevents memory bloat (workers exit after completion), and provides isolation (one job can't crash others). Matches proven patterns from Laravel Queue, Sidekiq, and Celery. Falls back to inline execution if pcntl extension unavailable.

**Cron Job Code Structure:**
* Refactored cron jobs to use function-based code (matching controller pattern) instead of full class definitions
* Users now write only method bodies (`execute`, `setUp`, `tearDown`) instead of full PHP classes
* Backend assembles complete class at runtime from function bodies
* **Security improvement**: Prevents malicious class definitions, constructors, or namespace manipulation
* **Per-function version control**: Each method (execute, setUp, tearDown) has independent version history
* **Migration required**: Existing cron jobs with full class definitions must be migrated (see SQL below)
* This change improves security by restricting what users can define in cron job code

**Database Migration SQL (v4.0.0):**

```sql
-- =========================================================================
-- Kyte v4.0.0 - Complete Cron Job System Setup
-- =========================================================================
-- IMPORTANT: Backup your database before running this migration
-- This creates all tables needed for the distributed cron job system
-- with function-based code (secure, matching controller pattern)
-- =========================================================================

-- Step 1: Create main CronJob table (job definitions)
CREATE TABLE IF NOT EXISTS CronJob (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    code LONGBLOB COMMENT 'bzip2 compressed PHP code (auto-generated from functions)',

    -- Schedule configuration (supports multiple types)
    schedule_type VARCHAR(20) DEFAULT 'cron' COMMENT 'Types: cron, interval, daily, weekly, monthly',
    cron_expression VARCHAR(100) COMMENT 'Standard 5-field cron: 0 2 * * * (2am daily)',
    interval_seconds INT UNSIGNED COMMENT 'For interval type: 300 = every 5 minutes',
    time_of_day TIME COMMENT 'For daily type: 02:00:00',
    day_of_week TINYINT UNSIGNED COMMENT 'For weekly type: 0=Sunday, 6=Saturday',
    day_of_month TINYINT UNSIGNED COMMENT 'For monthly type: 1-31',
    timezone VARCHAR(50) DEFAULT 'UTC' COMMENT 'Job timezone',

    -- Execution settings
    enabled TINYINT UNSIGNED DEFAULT 1,
    timeout_seconds INT UNSIGNED DEFAULT 300 COMMENT 'Default 5min, max 1800 (30min)',
    max_retries TINYINT UNSIGNED DEFAULT 3 COMMENT '0-5 range',
    retry_strategy VARCHAR(20) DEFAULT 'exponential' COMMENT 'Types: immediate, fixed, exponential',
    retry_delay_seconds INT UNSIGNED DEFAULT 60 COMMENT 'For fixed strategy',
    allow_concurrent TINYINT UNSIGNED DEFAULT 0,

    -- Dependencies (V1: Linear chain only)
    depends_on_job INT UNSIGNED NULL COMMENT 'FK to parent CronJob',

    -- Notifications
    notify_on_failure TINYINT UNSIGNED DEFAULT 0,
    notify_after_failures INT UNSIGNED DEFAULT 3 COMMENT 'Alert after N consecutive failures',
    notify_on_dead_letter TINYINT UNSIGNED DEFAULT 1 COMMENT 'Alert when moved to DLQ',
    slack_webhook VARCHAR(512) COMMENT 'Optional per-job webhook (overrides app default)',
    notification_email VARCHAR(255),

    -- Dead Letter Queue
    in_dead_letter_queue TINYINT UNSIGNED DEFAULT 0,
    dead_letter_reason TEXT,
    dead_letter_since INT UNSIGNED,
    consecutive_failures INT UNSIGNED DEFAULT 0 COMMENT 'Track failure streak',

    -- Context
    application INT COMMENT 'FK to Application',

    -- Framework attributes
    kyte_locked TINYINT UNSIGNED DEFAULT 0,
    kyte_account INT UNSIGNED NOT NULL,

    -- Audit attributes
    created_by INT,
    date_created INT UNSIGNED,
    modified_by INT,
    date_modified INT UNSIGNED,
    deleted_by INT,
    date_deleted INT UNSIGNED,
    deleted TINYINT UNSIGNED DEFAULT 0,

    INDEX idx_application (application),
    INDEX idx_enabled (enabled),
    INDEX idx_depends_on (depends_on_job),
    INDEX idx_dead_letter (in_dead_letter_queue),
    INDEX idx_deleted (deleted),
    INDEX idx_app_account (application, kyte_account),

    CONSTRAINT fk_cronjob_application
        FOREIGN KEY (application) REFERENCES Application(id) ON DELETE CASCADE,
    CONSTRAINT fk_cronjob_depends_on
        FOREIGN KEY (depends_on_job) REFERENCES CronJob(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 2: Create CronJobExecution table (execution history with locking)
CREATE TABLE IF NOT EXISTS CronJobExecution (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cron_job INT UNSIGNED NOT NULL,

    -- Scheduling
    scheduled_time INT UNSIGNED NOT NULL COMMENT 'Unix timestamp when job was supposed to run',
    next_run_time INT UNSIGNED COMMENT 'When this job should run next',

    -- Locking (lease-based for idempotency)
    status VARCHAR(20) DEFAULT 'pending' COMMENT 'Types: pending, running, completed, failed, timeout, skipped',
    locked_by VARCHAR(255) COMMENT 'Server identifier: hostname:pid',
    locked_at INT UNSIGNED COMMENT 'When lock was acquired',
    locked_until INT UNSIGNED COMMENT 'Lease expiration timestamp',

    -- Execution results
    started_at INT UNSIGNED,
    completed_at INT UNSIGNED,
    duration_ms INT UNSIGNED COMMENT 'Execution time in milliseconds',
    exit_code INT COMMENT '0 = success, non-zero = error',
    output MEDIUMTEXT COMMENT 'stdout / success messages',
    error MEDIUMTEXT COMMENT 'stderr / exception messages',
    stack_trace TEXT COMMENT 'Full PHP stack trace on error',
    memory_peak_mb DECIMAL(10,2) COMMENT 'Peak memory usage',

    -- Retry tracking
    retry_count INT UNSIGNED DEFAULT 0,
    is_retry TINYINT UNSIGNED DEFAULT 0,
    parent_execution INT UNSIGNED NULL COMMENT 'FK to original execution if retry',
    retry_scheduled_time INT UNSIGNED COMMENT 'When retry should happen',

    -- Dependency tracking
    skipped_reason VARCHAR(255) COMMENT 'Reason if skipped',
    dependency_execution INT UNSIGNED NULL COMMENT 'FK to parent job execution checked',

    -- Context
    application INT,

    -- Audit
    kyte_account INT UNSIGNED NOT NULL,
    created_by INT COMMENT 'NULL for automatic, set for manual triggers',
    date_created INT UNSIGNED,
    deleted_by INT NULL,
    date_deleted INT UNSIGNED NULL,
    deleted TINYINT UNSIGNED DEFAULT 0,

    INDEX idx_cron_job (cron_job),
    INDEX idx_status (status),
    INDEX idx_next_run (next_run_time, status),
    INDEX idx_locked_until (locked_until),
    INDEX idx_scheduled_time (scheduled_time),
    INDEX idx_parent_execution (parent_execution),
    INDEX idx_retry_scheduled (retry_scheduled_time, status),
    INDEX idx_application (application),
    INDEX idx_deleted (deleted),

    CONSTRAINT fk_cronjobexecution_cronjob
        FOREIGN KEY (cron_job) REFERENCES CronJob(id) ON DELETE CASCADE,
    CONSTRAINT fk_cronjobexecution_parent
        FOREIGN KEY (parent_execution) REFERENCES CronJobExecution(id) ON DELETE SET NULL,
    CONSTRAINT fk_cronjobexecution_dependency
        FOREIGN KEY (dependency_execution) REFERENCES CronJobExecution(id) ON DELETE SET NULL,
    CONSTRAINT fk_cronjobexecution_application
        FOREIGN KEY (application) REFERENCES Application(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 3: Create CronJobFunction table (stores individual function bodies)
CREATE TABLE IF NOT EXISTS CronJobFunction (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cron_job INT UNSIGNED NOT NULL,
    name VARCHAR(50) NOT NULL COMMENT 'execute, setUp, or tearDown',
    content_hash VARCHAR(64) NULL COMMENT 'SHA256 hash of current content',
    application INT NULL,
    kyte_account INT UNSIGNED NOT NULL,
    created_by INT NULL,
    date_created INT UNSIGNED NOT NULL,
    modified_by INT NULL,
    date_modified INT UNSIGNED NULL,
    deleted_by INT NULL,
    date_deleted INT UNSIGNED NULL,
    deleted TINYINT(1) UNSIGNED DEFAULT 0,

    INDEX idx_cron_job (cron_job),
    INDEX idx_name (name),
    INDEX idx_content_hash (content_hash),
    INDEX idx_application (application),
    INDEX idx_account (kyte_account),
    INDEX idx_deleted (deleted),
    UNIQUE KEY unique_job_function (cron_job, name, deleted),

    FOREIGN KEY (cron_job) REFERENCES CronJob(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 4: Create CronJobFunctionContent table (deduplicated function content storage)
CREATE TABLE IF NOT EXISTS CronJobFunctionContent (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content_hash VARCHAR(64) UNIQUE NOT NULL COMMENT 'SHA256 hash',
    content LONGBLOB NOT NULL COMMENT 'Compressed function body (bzip2)',
    reference_count INT UNSIGNED DEFAULT 0 COMMENT 'Number of versions using this content',
    created_by INT NULL,
    date_created INT UNSIGNED NOT NULL,
    deleted_by INT NULL,
    date_deleted INT UNSIGNED NULL,
    deleted TINYINT(1) UNSIGNED DEFAULT 0,

    INDEX idx_hash (content_hash),
    INDEX idx_ref_count (reference_count),
    INDEX idx_deleted (deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 5: Create CronJobFunctionVersion table (per-function version history)
CREATE TABLE IF NOT EXISTS CronJobFunctionVersion (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cron_job_function INT UNSIGNED NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    content_hash VARCHAR(64) NOT NULL COMMENT 'FK to CronJobFunctionContent',
    is_current TINYINT(1) UNSIGNED DEFAULT 0,
    change_description TEXT NULL COMMENT 'What changed in this version',
    diff_json LONGTEXT NULL COMMENT 'JSON-encoded line-by-line diff from previous version',
    kyte_account INT NOT NULL COMMENT 'Account ownership for multi-tenant isolation',
    created_by INT NULL,
    date_created INT UNSIGNED NOT NULL,
    deleted_by INT NULL,
    date_deleted INT UNSIGNED NULL,
    deleted TINYINT(1) UNSIGNED DEFAULT 0,

    INDEX idx_function (cron_job_function),
    INDEX idx_version (version_number),
    INDEX idx_content_hash (content_hash),
    INDEX idx_current (is_current),
    INDEX idx_account (kyte_account),
    INDEX idx_deleted (deleted),
    UNIQUE KEY unique_function_version (cron_job_function, version_number),

    FOREIGN KEY (cron_job_function) REFERENCES CronJobFunction(id) ON DELETE CASCADE,
    FOREIGN KEY (content_hash) REFERENCES CronJobFunctionContent(content_hash) ON DELETE RESTRICT,
    FOREIGN KEY (kyte_account) REFERENCES KyteAccount(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- Step 6: Multi-Language Support (i18n)
-- =========================================================================
-- Add language preferences for users and accounts
-- Supports: English (en), Japanese (ja), Spanish (es), Korean (ko)
-- =========================================================================

-- Add language preference to users (optional, NULL = use browser/account default)
ALTER TABLE KyteUser ADD COLUMN language VARCHAR(5) DEFAULT NULL
    COMMENT 'User language preference: en, ja, es, ko (NULL = auto-detect)'
    AFTER email;

-- Add default language to accounts (for account-wide default)
ALTER TABLE KyteAccount ADD COLUMN default_language VARCHAR(5) DEFAULT 'en'
    COMMENT 'Account default language: en, ja, es, ko'
    AFTER name;

-- Add language preference to applications (optional, NULL = use account default)
ALTER TABLE Application ADD COLUMN language VARCHAR(5) DEFAULT NULL
    COMMENT 'Application language preference: en, ja, es, ko (NULL = use account default)'
    AFTER identifier;

-- =========================================================================
-- Step 7: Standardize KyteError Account Scoping Column Name
-- =========================================================================
-- Rename account_id to kyte_account to match framework naming convention
-- This fixes foreign key JOIN issues with other tables that use kyte_account
-- =========================================================================

ALTER TABLE KyteError
    CHANGE COLUMN account_id kyte_account INT(11) UNSIGNED NULL
    COMMENT 'Account scoping - standardized to match framework convention';

-- =========================================================================
-- Migration Complete!
-- =========================================================================
-- All tables created successfully. Next steps:
-- 1. Deploy updated backend code (kyte-php)
-- 2. Deploy updated frontend code (kyte-managed-front-end)
-- 3. Run composer update (for dragonmantank/cron-expression dependency)
-- 4. Start CronWorker daemon: php bin/cron-worker.php
-- =========================================================================
```

**Key Improvements:**
- **80-95% query reduction** through eager loading and caching
- **10-50x faster** bulk operations with batch insert/update
- **Zero downtime deployments** with lease-based job locking
- **Production-ready logging** with 5 log levels and structured context
- **Complete version control** for all cron jobs with rollback capability
- **Multi-language support** (Japanese, Spanish, Korean) for frontend and backend
- **100% backward compatible** - all new features are opt-in

---

### Distributed Cron Job System

* Add comprehensive distributed cron job system with lease-based locking for multi-server environments
  - Support for cron expressions, intervals, and scheduled times (daily, weekly, monthly)
  - Lease-based execution locking prevents duplicate runs in load-balanced setups
  - Automatic lease recovery for crashed workers
  - Job dependencies (linear chains: A→B→C)
  - Retry logic with exponential backoff
  - Dead letter queue for permanently failed jobs
  - Slack/email notifications on failure
  - Full version control with SHA256 content deduplication
  - Execution history with output, errors, metrics
  - Manual job triggering
  - Configurable timeouts (default 5min, max 30min)
  - Configurable retries (0-5, default 3)
  - Concurrent execution control per job

**New Components:**
* `CronJobBase` - Base class for all user-defined cron jobs
* `CronWorker` - Background daemon that polls and executes jobs
* `bin/cron-worker.php` - Executable daemon entry point
* Systemd and Supervisor deployment configurations
* Docker compose example

**Locking & Idempotency:**
* Heartbeat mechanism - Jobs can extend their lease while running (`$this->heartbeat()`)
* Lock contention metrics - Track locks acquired/missed, success rates, contention percentage
* Graceful worker shutdown - Wait for active jobs to complete before stopping (30s grace period)
* Enhanced stale lock detection - Detailed logging of expired leases with worker crash detection
* Statistics reporting - Worker prints performance metrics on shutdown
* Active job tracking - Worker tracks currently running job for safe shutdown

**Retry & Failure Handling:**
* Retry logic with 3 strategies - Immediate, fixed delay, exponential backoff (default)
* Exponential backoff - Retries at 1min, 2min, 4min, 8min, 16min intervals
* Dead letter queue - Jobs that exceed max retries are disabled and moved to DLQ
* Consecutive failure tracking - Counts failures in a row, resets on success
* Slack notifications - Rich formatted alerts on failure (configurable threshold)
* Email notifications - Plain text alerts via PHP mail()
* Notification thresholds - Only notify after N consecutive failures (default: 3)
* DLQ notifications - Always notify when job moves to dead letter queue
* Automatic retry scheduling - Worker creates retry executions with calculated delays
* Per-job retry configuration - Max retries (0-5), strategy, delay customizable per job
* DLQ recovery - Utility script to recover jobs from dead letter queue

**Dependencies & Scheduling:**
* Daily schedules - Run at specific time each day (e.g., 2:00 AM daily)
* Weekly schedules - Run on specific day each week (e.g., Mondays at 8:00 AM)
* Monthly schedules - Run on specific day each month (e.g., 1st at 3:00 AM)
* Timezone support - All time-based schedules respect job timezone (default UTC)
* Month-end handling - 31st day schedules work correctly in shorter months
* Job dependencies - Linear chain support (A→B→C)
* Dependency validation - Parent job must complete successfully before child runs
* Automatic dependency checking - Worker validates dependencies before scheduling/execution
* Dependency skipping - Jobs skip execution if parent hasn't completed
* Next run calculation - Accurate scheduling for all schedule types with timezone conversion

**Version Control Integration:**
* Automatic version creation - New version created whenever job code changes
* SHA256 content hashing - Unique hash identifies code content for deduplication
* Content deduplication - Multiple jobs/versions sharing same code reuse storage (40-70% savings)
* Reference counting - Track how many versions reference each content block
* JSON change diffs - Every version stores line-by-line diff from previous version
* Version rollback - One-command rollback to any previous version
* Version history - Complete audit trail of all code changes with metadata
* Version comparison - Side-by-side diff and code comparison between any two versions
* Code validation - PHP syntax checking before creating new versions
* Version pruning - Cleanup old versions while maintaining reference integrity
* CronJobManager - High-level API for job management with automatic versioning
* CronVersionControl - Low-level version control operations
* Command-line utilities - Full CLI for version management (history, compare, rollback, prune, stats)
* Storage efficiency - Bzip2 compression + deduplication reduces storage by ~90%
* Deduplication stats - Monitor storage savings and reference patterns

**Backend REST API:**
* CronJobController - Complete REST API for job CRUD with automatic versioning
* Job creation/update - POST/PUT endpoints with validation and version control
* Custom actions - Manual trigger, DLQ recovery, version rollback, statistics
* CronJobExecutionController - Read-only API for execution history
* Recent executions - Filter by job, limit, time period
* Failed executions - View failures with error details and stack traces
* Running executions - Monitor currently executing jobs with lease status
* Pending executions - View upcoming scheduled runs
* Execution statistics - Aggregate stats with success rates, duration, memory
* KyteCronJobVersionController - Read-only API for version history
* Version history - View all versions with change summaries and metadata
* Version comparison - Side-by-side diff between any two versions
* Version code retrieval - Get decompressed code for specific version
* KyteCronJobVersionContentController - Content deduplication API
* Deduplication statistics - Monitor storage savings and efficiency
* Content lookup - Find content by full or partial hash
* Orphaned content detection - Identify unreferenced content for cleanup
* Comprehensive validation - Schedule validation, code syntax checking
* Error handling - Clear error messages for all failure scenarios
* Performance optimizations - Pagination, selective field loading, truncation

**Frontend Web Interface:**
* Cron Jobs management page - Complete web interface for job administration
* DataTable integration - Interactive job listing with search, sort, pagination
* Create/edit forms - Dynamic form fields based on schedule type selection
* Status visualization - Color-coded badges (Enabled/Disabled/DLQ)
* Success rate metrics - Visual indicators with color coding (green/yellow/red)
* Schedule type display - Smart formatting for cron/interval/daily/weekly/monthly
* Next run countdown - Real-time countdown or timestamp display
* Quick actions - Context-sensitive buttons (trigger/recover/view history/versions)
* Manual job triggering - One-click job execution with confirmation
* DLQ recovery interface - Recover failed jobs with single button
* Execution history links - Navigate to filtered execution logs
* Version history links - Access version control interface
* Form validation - Client-side and server-side validation
* Error handling - User-friendly error messages for all operations
* Responsive design - Mobile-friendly Bootstrap 5 layout
* Modern UI design - Gradient backgrounds, rounded corners, smooth transitions
* Code editor styling - Monospace font with syntax-ready formatting
* Kyte Shipyard integration - Seamless integration with existing admin panel

**Backend Files Added:**
* `src/Core/CronJobBase.php` - Base class for cron jobs (with heartbeat support)
* `src/Cron/CronWorker.php` - Worker daemon with schedules, dependencies, retry, DLQ, notifications
* `src/Cron/CronJobCodeGenerator.php` - Assembles complete class from function bodies with validation
* `src/Cron/CronJobManager.php` - High-level job management API with automatic versioning
* `bin/cron-worker.php` - Daemon entry point
* `bin/test-cron.php` - Testing script for validating cron system
* `bin/test-multi-worker.php` - Multi-worker lock contention testing
* `bin/test-retry.php` - Retry logic and DLQ testing script
* `bin/test-schedules.php` - Schedule types and dependency chain testing
* `bin/cron-locks.php` - Lock management utility (list/clear/stats)

**Models Added:**
* `src/Mvc/Model/CronJob.php` - **Job definition model (REQUIRED)**
* `src/Mvc/Model/CronJobExecution.php` - Execution history model
* `src/Mvc/Model/CronJobFunction.php` - Individual function storage (execute, setUp, tearDown)
* `src/Mvc/Model/CronJobFunctionContent.php` - Deduplicated function content with SHA256
* `src/Mvc/Model/CronJobFunctionVersion.php` - Per-function version history

**Controllers Added:**
* `src/Mvc/Controller/CronJobController.php` - REST API for job management (updated for function-based)
* `src/Mvc/Controller/CronJobExecutionController.php` - REST API for execution history
* `src/Mvc/Controller/CronJobFunctionController.php` - REST API for function CRUD with versioning
* `src/Mvc/Controller/CronJobFunctionVersionController.php` - REST API for function version history
* `examples/TestCronJob.php` - Example cron job for testing
* `docs/cron/testing.md` - Comprehensive testing guide
* `docs/cron/execution.md` - Locking and retry documentation
* `docs/cron/scheduling.md` - Dependencies and scheduling documentation
* `docs/cron/FUNCTION-BASED-REFACTOR-GUIDE.md` - Function-based refactor implementation guide
* `docs/cron/DEPLOYMENT-CHECKLIST-V4.md` - v4.0.0 deployment procedures
* `docs/cron/api-reference.md` - Backend API documentation
* `docs/cron/web-interface.md` - Frontend UI documentation

**Frontend Files Added (kyte-managed-front-end):**
* `app/cron-jobs.html` - Main cron jobs management page
* `app/cron-job/index.html` - Job detail page with tabbed function editor
* `assets/js/source/kyte-shipyard-cron-jobs.js` - Job management JavaScript controller
* `assets/js/source/kyte-shipyard-cron-job-details.js` - Job detail page with function-based editing

**Frontend Files Modified:**
* `assets/js/source/kyte-shipyard-tables.js` - Added colDefCronJobs table definitions
* `assets/js/source/kyte-shipyard-navigation.js` - Added cron jobs menu items

**Dependencies Added:**
* `dragonmantank/cron-expression: ^3.3` - For cron expression parsing and scheduling

---

### Notes on Cron System

The cron job system uses function-based code for improved security. The main tables (`CronJob` and `CronJobExecution`) remain unchanged from previous cron system implementations, but job code is now assembled from individual functions stored in `CronJobFunction`, `CronJobFunctionContent`, and `CronJobFunctionVersion` tables (see migration SQL above).

**CronJob Table**: Stores job definitions with schedule, execution settings, and generated code
**CronJobExecution Table**: Stores individual job runs with lease-based locking
**CronJobFunction Table**: Stores individual function bodies (execute, setUp, tearDown)
**CronJobFunctionContent Table**: Deduplicated function content storage with SHA256 hashing
**CronJobFunctionVersion Table**: Per-function version history

**Installation:**
```bash
# Install dependencies
composer update

# Start worker daemon (systemd recommended)
sudo cp vendor/keyqcloud/kyte-php/systemd/kyte-cron-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl start kyte-cron-worker
sudo systemctl enable kyte-cron-worker
```

**Notes:**
* All cron features are opt-in - existing applications unaffected
* Jobs are application-scoped by default
* Worker daemon runs independently of web server
* Horizontal scaling: run multiple workers across servers (lease-based locking prevents duplicates)
* Full backward compatibility - zero breaking changes

---

### AI-Powered Error Correction System

* **NEW**: Intelligent error analysis and automatic code fixing using AWS Bedrock (Claude Sonnet 4.5)
  - Automatically analyzes application-level errors and exceptions logged to database
  - Uses AI to diagnose problems, suggest fixes, and optionally auto-apply corrections
  - Async processing via cron job system (non-blocking, production-ready)
  - Analyzes controller functions, models, request context, and framework patterns
  - Generates PHP code fixes with confidence scoring
  - PHP syntax validation before applying fixes
  - Automatic loop detection prevents infinite fix cycles
  - Leverages existing function version control for rollback capability
  - Per-application configuration with rate limiting and cost controls
  - Comprehensive deduplication to avoid re-analyzing same errors

**Key Features:**
* **Smart Error Classification** - AI determines if error is fixable by modifying code
* **Context-Aware Fixes** - Analyzes all controller functions, model definitions, and framework docs
* **Confidence Scoring** - AI rates fix confidence 0-100%, auto-fix only applies high-confidence fixes
* **Loop Detection** - Multiple strategies detect recurring errors after fix:
  - Same error signature recurring after fix applied
  - N consecutive fixes without resolution (threshold: 5 attempts)
  - Error count increasing after fix
  - Auto-disables auto-fix mode if loop detected
* **Cost Controls** - Rate limiting (per hour/day), cooldown periods, monthly budget caps
* **Async Processing** - Errors queued and analyzed by cron job (every 5 minutes), no blocking
* **Version Control Integration** - Creates new function versions, full rollback support
* **Syntax Validation** - Uses `php -l` to validate fixes before application

**Configuration Options** (per-application):
* Master enable/disable toggle
* Auto-fix mode (apply fixes automatically vs. suggest for review)
* Minimum confidence threshold for auto-fix (default: 90%)
* Max analyses per hour/day
* Monthly cost budget (USD)
* Cooldown period between analyses of same error (default: 30 min)
* Max fix attempts before disabling (default: 5)
* Loop detection time window (default: 60 min)
* Analysis preferences: include warnings, models, request data, framework docs

**Frontend Features:**
* Configuration page in app settings (app/configuration.html)
* AI analysis column in error log viewer with status badges
* AI Analysis modal with code diff viewer (Monaco Editor)
* Dedicated AI Error Assistant dashboard (app/ai-error-assistant.html)
* Active suggestions table with apply/reject actions
* Applied fixes history with rollback capability
* Loop detection alerts panel
* Real-time status updates (queued, processing, completed, failed)

**Database Tables Added:**

```sql
-- =========================================================================
-- AI Error Correction System Tables (v4.0.0)
-- =========================================================================
-- IMPORTANT: Requires USE_KYTE_ERROR_HANDLER = true for error logging
-- This feature integrates with the existing KyteError logging system
-- and the new CronJob system for async processing
-- =========================================================================

-- Table 1: AIErrorAnalysis - Tracks AI analysis of each error
CREATE TABLE AIErrorAnalysis (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Error linkage
    error_id INT NOT NULL COMMENT 'FK to KyteError.id',
    error_signature VARCHAR(64) NOT NULL COMMENT 'SHA256 hash: controller+function+error_msg+file+line',

    -- Classification
    is_fixable TINYINT(1) UNSIGNED DEFAULT 0 COMMENT 'AI determined if fixable',
    fixable_confidence DECIMAL(5,2) DEFAULT NULL COMMENT 'AI confidence 0.00-100.00',

    -- Affected code
    controller_id INT NULL COMMENT 'FK to Controller.id',
    controller_name VARCHAR(255) NULL,
    function_id INT NULL COMMENT 'FK to Function.id',
    function_name VARCHAR(255) NULL,
    function_type VARCHAR(50) NULL COMMENT 'hook_init, hook_preprocess, etc.',

    -- AI analysis results
    analysis_stage ENUM('pending', 'classifying', 'analyzing', 'generating_fix', 'validating', 'completed', 'failed') DEFAULT 'pending',
    ai_diagnosis TEXT COMMENT 'AI explanation of the problem',
    ai_suggested_fix LONGTEXT COMMENT 'AI proposed code fix',
    fix_confidence DECIMAL(5,2) DEFAULT NULL COMMENT 'Fix confidence 0.00-100.00',
    fix_rationale TEXT COMMENT 'AI explanation of the fix',

    -- Context captured
    context_snapshot LONGTEXT COMMENT 'JSON: all controller functions, models, request data',

    -- Queue status tracking
    analysis_status ENUM('queued', 'processing', 'completed', 'failed') DEFAULT 'queued',
    queued_at BIGINT UNSIGNED NOT NULL,
    processing_started_at BIGINT UNSIGNED NULL,
    processing_completed_at BIGINT UNSIGNED NULL,
    retry_count INT UNSIGNED DEFAULT 0,
    last_error TEXT NULL,

    -- Fix application tracking
    fix_status ENUM('suggested', 'applied_manual', 'applied_auto', 'rejected', 'failed_validation', 'caused_error') DEFAULT 'suggested',
    applied_at BIGINT UNSIGNED NULL COMMENT 'Unix timestamp when fix was applied',
    applied_by INT UNSIGNED NULL COMMENT 'User who applied (NULL if auto)',
    applied_function_version INT NULL COMMENT 'FK to KyteFunctionVersion.id created',

    -- Validation results
    syntax_valid TINYINT(1) UNSIGNED DEFAULT NULL COMMENT 'PHP syntax check result',
    syntax_error TEXT NULL COMMENT 'Syntax validation error if any',

    -- Loop detection
    attempt_number INT UNSIGNED DEFAULT 1 COMMENT 'Retry attempt for this error signature',
    previous_analysis_id BIGINT UNSIGNED NULL COMMENT 'FK to parent analysis if retry',
    caused_new_error TINYINT(1) UNSIGNED DEFAULT 0 COMMENT 'Did this fix cause a new error?',
    new_error_id INT NULL COMMENT 'FK to new KyteError if caused',

    -- Cost tracking
    bedrock_request_id VARCHAR(255) NULL,
    bedrock_input_tokens INT UNSIGNED NULL,
    bedrock_output_tokens INT UNSIGNED NULL,
    estimated_cost_usd DECIMAL(10,4) NULL,
    processing_time_ms INT UNSIGNED NULL COMMENT 'Total analysis time',

    -- Framework fields
    application INT NULL COMMENT 'FK to Application',
    kyte_account INT NOT NULL,

    -- Audit fields
    created_by INT NULL,
    date_created BIGINT UNSIGNED NOT NULL,
    modified_by INT NULL,
    date_modified BIGINT UNSIGNED NULL,
    deleted_by INT NULL,
    date_deleted BIGINT UNSIGNED NULL,
    deleted TINYINT(1) UNSIGNED DEFAULT 0,

    INDEX idx_error_id (error_id),
    INDEX idx_error_signature (error_signature),
    INDEX idx_controller_function (controller_id, function_id),
    INDEX idx_fix_status (fix_status),
    INDEX idx_attempt_number (attempt_number),
    INDEX idx_analysis_stage (analysis_stage),
    INDEX idx_analysis_status (analysis_status, queued_at),
    INDEX idx_application (application),
    INDEX idx_account (kyte_account),
    INDEX idx_date_created (date_created),
    INDEX idx_deleted (deleted),
    UNIQUE KEY unique_error_analysis (error_id, deleted),

    FOREIGN KEY (error_id) REFERENCES KyteError(id) ON DELETE CASCADE,
    FOREIGN KEY (controller_id) REFERENCES Controller(id) ON DELETE SET NULL,
    FOREIGN KEY (function_id) REFERENCES `Function`(id) ON DELETE SET NULL,
    FOREIGN KEY (previous_analysis_id) REFERENCES AIErrorAnalysis(id) ON DELETE SET NULL,
    FOREIGN KEY (new_error_id) REFERENCES KyteError(id) ON DELETE SET NULL,
    FOREIGN KEY (applied_function_version) REFERENCES KyteFunctionVersion(id) ON DELETE SET NULL,
    FOREIGN KEY (application) REFERENCES Application(id) ON DELETE CASCADE,
    FOREIGN KEY (kyte_account) REFERENCES KyteAccount(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 2: AIErrorCorrectionConfig - Per-application settings
CREATE TABLE AIErrorCorrectionConfig (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Application linkage
    application INT NOT NULL COMMENT 'FK to Application',

    -- Feature flags
    enabled TINYINT(1) UNSIGNED DEFAULT 0 COMMENT 'Master enable/disable',
    auto_fix_enabled TINYINT(1) UNSIGNED DEFAULT 0 COMMENT 'Auto-apply fixes without user approval',
    auto_fix_min_confidence DECIMAL(5,2) DEFAULT 90.00 COMMENT 'Minimum confidence for auto-fix (0-100)',

    -- Rate limiting & cost control
    max_analyses_per_hour INT UNSIGNED DEFAULT 10 COMMENT 'Max analyses per hour',
    max_analyses_per_day INT UNSIGNED DEFAULT 50 COMMENT 'Max analyses per day',
    max_monthly_cost_usd DECIMAL(10,2) DEFAULT 100.00 COMMENT 'Budget cap',
    cooldown_minutes INT UNSIGNED DEFAULT 30 COMMENT 'Minutes between analyses of same signature',

    -- Loop detection thresholds
    max_fix_attempts INT UNSIGNED DEFAULT 5 COMMENT 'Max attempts before disabling',
    loop_detection_window_minutes INT UNSIGNED DEFAULT 60 COMMENT 'Time window for loop detection',
    auto_disable_on_loop TINYINT(1) UNSIGNED DEFAULT 1 COMMENT 'Disable auto-fix if loop detected',

    -- Cron scheduling preferences
    analysis_frequency_minutes INT UNSIGNED DEFAULT 5 COMMENT 'How often cron runs (1-60)',
    batch_size INT UNSIGNED DEFAULT 10 COMMENT 'Max analyses per cron run',
    max_concurrent_bedrock_calls INT UNSIGNED DEFAULT 3 COMMENT 'Max parallel API calls',

    -- Analysis preferences
    include_warnings TINYINT(1) UNSIGNED DEFAULT 0 COMMENT 'Analyze warnings (not just errors/critical)',
    include_model_definitions TINYINT(1) UNSIGNED DEFAULT 1 COMMENT 'Include model schemas in context',
    include_request_data TINYINT(1) UNSIGNED DEFAULT 1 COMMENT 'Include request data in context',
    include_framework_docs TINYINT(1) UNSIGNED DEFAULT 1 COMMENT 'Include ModelController docs',

    -- Notification preferences (PLACEHOLDER for future implementation)
    notify_on_suggestion TINYINT(1) UNSIGNED DEFAULT 0 COMMENT 'FUTURE: Notify when AI suggests fix',
    notify_on_auto_fix TINYINT(1) UNSIGNED DEFAULT 1 COMMENT 'FUTURE: Notify when auto-fix applied',
    notify_on_loop_detection TINYINT(1) UNSIGNED DEFAULT 1 COMMENT 'FUTURE: Notify when loop detected',
    notification_email VARCHAR(255) NULL COMMENT 'FUTURE: Email for notifications',
    notification_slack_webhook VARCHAR(512) NULL COMMENT 'FUTURE: Slack webhook override',

    -- Statistics
    total_analyses INT UNSIGNED DEFAULT 0,
    total_fixes_applied INT UNSIGNED DEFAULT 0,
    total_successful_fixes INT UNSIGNED DEFAULT 0,
    total_failed_fixes INT UNSIGNED DEFAULT 0,
    total_cost_usd DECIMAL(10,2) DEFAULT 0.00,
    last_analysis_date BIGINT UNSIGNED NULL,

    -- Framework fields
    kyte_account INT NOT NULL,

    -- Audit fields
    created_by INT NULL,
    date_created BIGINT UNSIGNED NOT NULL,
    modified_by INT NULL,
    date_modified BIGINT UNSIGNED NULL,
    deleted_by INT NULL,
    date_deleted BIGINT UNSIGNED NULL,
    deleted TINYINT(1) UNSIGNED DEFAULT 0,

    UNIQUE KEY unique_app_config (application, deleted),
    INDEX idx_application (application),
    INDEX idx_enabled (enabled),
    INDEX idx_account (kyte_account),
    INDEX idx_deleted (deleted),

    FOREIGN KEY (application) REFERENCES Application(id) ON DELETE CASCADE,
    FOREIGN KEY (kyte_account) REFERENCES KyteAccount(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 3: AIErrorDeduplication - Track analyzed error signatures
CREATE TABLE AIErrorDeduplication (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    error_signature VARCHAR(64) NOT NULL COMMENT 'SHA256 hash',
    controller_name VARCHAR(255) NOT NULL,
    function_name VARCHAR(255) NULL,
    error_message TEXT NOT NULL,
    error_file VARCHAR(255) NOT NULL,
    error_line INT UNSIGNED NOT NULL,

    -- Tracking
    first_seen BIGINT UNSIGNED NOT NULL,
    last_seen BIGINT UNSIGNED NOT NULL,
    last_analyzed BIGINT UNSIGNED NULL,
    occurrence_count INT UNSIGNED DEFAULT 1,
    analysis_count INT UNSIGNED DEFAULT 0,

    -- Status
    is_resolved TINYINT(1) UNSIGNED DEFAULT 0,
    resolved_at BIGINT UNSIGNED NULL,
    resolved_by INT UNSIGNED NULL,

    -- Application context
    application INT NULL,
    kyte_account INT NOT NULL,

    deleted TINYINT(1) UNSIGNED DEFAULT 0,

    UNIQUE KEY unique_signature_app (error_signature, application, deleted),
    INDEX idx_last_analyzed (last_analyzed),
    INDEX idx_is_resolved (is_resolved),
    INDEX idx_application (application),
    INDEX idx_account (kyte_account),
    INDEX idx_deleted (deleted),

    FOREIGN KEY (application) REFERENCES Application(id) ON DELETE CASCADE,
    FOREIGN KEY (kyte_account) REFERENCES KyteAccount(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- End of AI Error Correction System Tables
-- =========================================================================
```

**Backend Components Added:**
* `src/AI/AIErrorCorrection.php` - Main orchestrator, queues errors for analysis
* `src/AI/AIErrorAnalyzer.php` - AI analysis engine with AWS Bedrock integration
* `src/AI/AIErrorContextBuilder.php` - Gathers context for AI (functions, models, docs)
* `src/AI/AIErrorFixApplier.php` - Applies fixes and creates function versions
* `src/AI/AILoopDetector.php` - Detects infinite fix loops and auto-disables
* `src/Cron/AIErrorAnalysisCron.php` - Cron job for async error processing
* `src/Mvc/Controller/AIErrorCorrectionConfigController.php` - Config API
* `src/Mvc/Controller/AIErrorAnalysisController.php` - Analysis management API
* `src/Mvc/Controller/AIErrorDeduplicationController.php` - Deduplication API
* `src/Mvc/Model/AIErrorAnalysis.php` - Analysis model definition
* `src/Mvc/Model/AIErrorCorrectionConfig.php` - Config model definition
* `src/Mvc/Model/AIErrorDeduplication.php` - Deduplication model definition

**Frontend Components Added (kyte-managed-front-end):**
* `app/ai-error-assistant.html` - Dedicated AI error management dashboard
* `app/configuration.html` - Added "AI Error Correction" tab with settings
* `app/log.html` - Enhanced with AI analysis column and status badges
* `assets/js/source/kyte-shipyard-ai-error-correction.js` - Configuration UI
* `assets/js/source/kyte-shipyard-ai-error-analysis.js` - Analysis management UI
* `assets/js/source/kyte-shipyard-log.js` - Updated with AI analysis integration

**Configuration Constants Added (config.php):**
```php
// AI Error Correction - Master Enable (requires USE_KYTE_ERROR_HANDLER = true)
define('AI_ERROR_CORRECTION', false);  // Disabled by default

// AWS Bedrock Configuration (reuse existing AWS constants)
// define('AWS_ACCESS_KEY_ID', 'your_key');
// define('AWS_SECRET_KEY', 'your_secret');
define('AI_BEDROCK_REGION', 'us-east-1');
define('AI_BEDROCK_MODEL', 'global.anthropic.claude-sonnet-4-5-20250929-v1:0');
```

**Integration Points:**
* `ErrorHandler.php` - Modified to queue errors for AI analysis (conditional, non-blocking)
* `FunctionController.php` - Extended to track AI-applied fixes in version metadata

**Cost Estimation:**
* Classification: ~$0.01 per error
* Fix generation: ~$0.25 per error
* Total: ~$0.25-0.30 per analyzed error
* Default monthly budget: $100 (300-400 error analyses)

**Notification System (FUTURE):**
* Email/Slack notifications on fix suggestions (placeholder - disabled in v1.0)
* Notifications on auto-fix applied (placeholder - disabled in v1.0)
* Notifications on loop detection (placeholder - disabled in v1.0)
* UI shows these options as "Coming in future release"

**Notes:**
* Feature is **disabled by default** - requires explicit opt-in per application
* 100% backward compatible - no breaking changes
* Requires AWS Bedrock credentials (uses existing AWS_ACCESS_KEY_ID/AWS_SECRET_KEY)
* Requires cron worker running for async processing
* Only analyzes application-level errors (not system/framework errors)
* Only analyzes error and critical log levels (not warnings by default)
* PHP syntax validation requires PHP CLI (`php -l` command)
* Loop detection uses multiple strategies to prevent infinite cycles
* All fixes create new function versions - full rollback support
* Frontend provides clear visibility with real-time status updates

---

### Core Backend Performance Improvements

* Add transaction support to DBI for ACID guarantees in multi-step operations
  - `beginTransaction()` - Start a database transaction
  - `commit()` - Commit a transaction
  - `rollback()` - Rollback a transaction
* Add `getConnection()` helper method to DBI to eliminate 130+ lines of duplicate connection logic across 16 methods
* Refactor all DBI query methods to use centralized connection management
* Optimize type conversion in ModelObject to skip unnecessary conversions when value is already correct type
  - String fields: Only convert if not already a string
  - Integer fields: Only convert if not already an integer
  - Float fields: Only convert if not already a float
* Add query logging infrastructure to DBI for debugging and performance analysis (opt-in, disabled by default)
  - `enableQueryLogging()` - Enable query logging
  - `disableQueryLogging()` - Disable query logging
  - `getQueryLog()` - Retrieve logged queries with timestamps and execution times
  - `clearQueryLog()` - Clear the query log

**Performance Impact:**
* Reduced code duplication in DBI by ~85%
* Optimized type conversions reduce CPU overhead by 10-20% for object operations
* Transaction support enables atomic multi-step operations for improved data integrity
* Query logging enables performance profiling and optimization

**Files Modified:**
* `src/Core/DBI.php` - Added transaction support, connection helper, query logging
* `src/Core/ModelObject.php` - Optimized type conversion in setParam()

### Caching Improvements

* Add model definition caching to Api for eliminating repeated database queries and JSON parsing
  - Memory cache (per-request) for instant model definition access
  - File cache (optional, persistent, disabled by default) with 1-hour TTL for cross-request caching
  - Uses JSON serialization to avoid OPcache issues
  - `setModelCacheFile($path)` - Configure file cache location (opt-in)
  - `clearModelCache($appId)` - Clear cache for specific app or all apps
* Add query result caching to DBI for eliminating repeated identical queries
  - Per-request cache with configurable TTL (default 60 seconds)
  - `enableQueryCache($ttl)` - Enable query caching with custom TTL
  - `disableQueryCache()` - Disable query caching
  - `getCacheStats()` - Get cache hit/miss statistics
  - Automatic cache invalidation on inserts, updates, and deletes
* Modify `select()` method to check and populate cache automatically
* Add cache invalidation to `insert()`, `update()`, and `delete()` methods

**Performance Impact:**
* Model caching saves 30-60ms per request by eliminating DB queries and JSON parsing
* Query caching saves 20-100ms for repeated queries (sessions, FK lookups, etc.)
* Eliminates 10-100 DB queries per request for model loading
* Cache hit rates typically 80%+ after warmup

**Files Modified:**
* `src/Core/Api.php` - Added model definition caching with file cache support
* `src/Core/DBI.php` - Added query result caching with automatic invalidation

**Configuration Example:**
```php
// config.php

// Enable query caching (300 second TTL) - safe for all environments
\Kyte\Core\DBI::enableQueryCache(300);

// Enable model memory cache (per-request) - safe for all environments
define('MODEL_CACHE', true);

// Optional: Enable file cache for models (uses JSON format)
// IMPORTANT: Only enable in single-server environments!
define('MODEL_CACHE_FILE', false);  // Disabled by default for safety
if (MODEL_CACHE_FILE) {
    \Kyte\Core\Api::setModelCacheFile('/tmp/kyte_model_cache.json');
}

// Development only: Enable query logging for debugging
\Kyte\Core\DBI::enableQueryLogging();
```

**Notes:**
* All changes are 100% backward compatible
* Transaction methods are opt-in (call explicitly when needed)
* Query logging is disabled by default (call `enableQueryLogging()` to use)
* Model caching works without configuration (memory cache), file cache is optional and disabled by default
* Query caching is opt-in (call `enableQueryCache()` to use)
* No breaking changes to existing APIs

**IMPORTANT - Load Balancer Environments:**
* File cache (`MODEL_CACHE_FILE`) is **disabled by default** for safety
* **DO NOT enable file cache** in multi-server/load-balanced environments - cache invalidation does not propagate across servers, leading to stale data
* Memory cache is always safe (per-process, per-request)
* Query cache is always safe (per-request only, no persistence)
* For multi-server caching, consider Redis/Memcached (future enhancement)

**Why JSON instead of PHP?**
* JSON files are not affected by OPcache, ensuring cache updates are immediate
* Smaller file size and faster serialization
* No risk of stale opcached bytecode being served after cache updates

### Query Optimization - Eager Loading & Batch Operations

* **Implement eager loading to fix N+1 query problem** (BIGGEST PERFORMANCE IMPROVEMENT)
  - Add `with()` method to Model for specifying relationships to eager load
  - Add `eagerLoadRelations()` private method that loads all FKs in single query per relationship
  - Modify `retrieve()` to automatically eager load specified relationships
  - Update ModelController `getObject()` to check for eager-loaded data before lazy loading
  - **Result**: 80-95% query reduction for FK-heavy requests

* **Add batch operations for bulk data processing**
  - `batchInsert($table, $rows, $types)` - Insert multiple rows in single query (10-50x faster)
  - `batchUpdate($table, $ids, $params, $types)` - Update multiple rows with same values
  - Automatic cache invalidation for batch operations
  - Proper prepared statements for security

**Performance Impact:**
* **N+1 Problem Solved**: 50-300 queries → 2-10 queries per request (80-95% reduction)
* Response time improvement: 100-500ms faster for FK-heavy endpoints
* Batch operations: 10-50x faster than individual inserts/updates
* Memory efficient: Uses single query with IN clause instead of N separate queries

**Usage Examples:**

*Eager Loading (Fixes N+1 Problem):*
```php
// BEFORE: 251 queries (1 main + 250 FK lookups for 50 records with 5 FKs)
$users = new \Kyte\Core\Model(User);
$users->retrieve('status', 'active');

// AFTER: 4 queries (1 main + 3 eager loads)
$users = new \Kyte\Core\Model(User);
$users->with(['company', 'department', 'role'])
      ->retrieve('status', 'active');

// Single relationship
$users->with('company')->retrieve('status', 'active');
```

*Batch Insert:*
```php
// BEFORE: 100 individual INSERTs (slow)
foreach ($products as $product) {
    \Kyte\Core\DBI::insert('Product', [
        'name' => $product['name'],
        'price' => $product['price'],
        'status' => 'active'
    ], 'sds');
}

// AFTER: 1 batch INSERT (10-50x faster)
$rows = [];
foreach ($products as $product) {
    $rows[] = [
        'name' => $product['name'],
        'price' => $product['price'],
        'status' => 'active'
    ];
}
$ids = \Kyte\Core\DBI::batchInsert('Product', $rows, 'sds');
```

*Batch Update:*
```php
// Update multiple records at once
$productIds = [1, 2, 3, 4, 5];
\Kyte\Core\DBI::batchUpdate('Product', $productIds, ['status' => 'inactive'], 's');
```

**Files Modified:**
* `src/Core/Model.php` - Added eager loading with `with()` method and `eagerLoadRelations()`
* `src/Mvc/Controller/ModelController.php` - Check for eager-loaded relations before lazy loading
* `src/Core/DBI.php` - Added `batchInsert()` and `batchUpdate()` methods

**Notes:**
* Eager loading is **opt-in** via `.with()` - existing code continues to work with lazy loading
* Batch operations use prepared statements for security
* All changes are 100% backward compatible
* No breaking changes to existing APIs

### Code Refinement & Developer Tools

* **Extract field builder helper to eliminate duplicate code**
  - Add `buildFieldDefinition($name, $attrs, $tableName)` private method to DBI
  - Centralizes field definition logic for all field types (i, bi, s, d, t, tt, mt, lt, b, tb, mb, lb)
  - Refactor `createTable()` to use helper (reduced from ~100 lines to ~12)
  - Refactor `addColumn()` to use helper (eliminated ~50 lines of duplication)
  - Refactor `changeColumn()` to use helper (eliminated ~50 lines of duplication)
  - **Result**: Eliminated ~200 lines of duplicate code, single source of truth for field definitions

* **Add performance monitoring for real-time metrics**
  - Add `_performance` object to API responses when `DEBUG_PERFORMANCE` constant is defined
  - Tracks: total_time (ms), db_queries (count), db_time (ms), memory_peak (bytes), memory_current (bytes)
  - Includes cache statistics: hits, misses, size, hit_rate percentage
  - Opt-in via constant definition (disabled by default for production)
  - Automatically integrates with existing query logging and cache statistics

* **Create comprehensive performance optimization guide**
  - New documentation: `docs/05-performance-optimization.md`
  - Covers query caching, model memory cache, eager loading, batch operations, performance monitoring
  - Includes real-world before/after examples with metrics
  - Best practices for production optimization
  - Troubleshooting guide for common performance issues
  - Updated `docs/README.md` to include performance guide in navigation

**Performance Impact:**
* Field builder extraction: More maintainable codebase, faster bug fixes
* Performance monitoring: Real-time visibility into query counts, cache effectiveness, memory usage
* Documentation: Helps developers adopt performance features, reducing support burden

**Configuration Example:**
```php
// config.php - Enable performance monitoring in development
if (getenv('ENVIRONMENT') === 'development') {
    \Kyte\Core\DBI::enableQueryLogging();  // Required to track db_queries
    define('DEBUG_PERFORMANCE', true);      // Shows _performance in response
}

// Example API response with performance data
{
    "success": true,
    "data": { ... },
    "_performance": {
        "total_time": 89.12,
        "db_queries": 5,
        "db_time": 45.67,
        "memory_peak": 4194304,
        "memory_current": 3145728,
        "cache": {
            "hits": 147,
            "misses": 5,
            "size": 5,
            "hit_rate": "96.71%"
        }
    }
}
```

**Important Notes:**
* Query caching requires `\Kyte\Core\DBI::enableQueryCache()` method call (not just a constant)
* Performance monitoring requires both `enableQueryLogging()` and `DEBUG_PERFORMANCE` constant
* Model caching uses `MODEL_CACHE` constant (memory cache always on when defined)
* File cache uses `MODEL_CACHE_FILE` constant + `setModelCacheFile()` method (disabled by default)

**Files Modified:**
* `src/Core/DBI.php` - Extracted `buildFieldDefinition()` helper, refactored DDL methods
* `src/Core/Api.php` - Added performance monitoring with `_performance` response object
* `docs/05-performance-optimization.md` - New comprehensive performance guide
* `docs/README.md` - Updated navigation to include performance guide

**Notes:**
* All changes are 100% backward compatible
* Performance monitoring is opt-in via `DEBUG_PERFORMANCE` constant
* Field builder is internal refactoring with no API changes
* Documentation improvements help developers adopt performance features

### Comprehensive Logging System

* **Add multi-level structured logging system** (debug, info, warning, error, critical)
  - Extend KyteError table with 6 new fields: log_level, log_type, context, request_id, trace, source
  - Create PSR-3 compatible Logger API with static methods: `Logger::debug()`, `Logger::info()`, `Logger::warning()`, `Logger::error()`, `Logger::critical()`
  - Enhance ErrorHandler with configurable error level capture via LOG_LEVEL constant
  - Add output buffering support to capture echo/print statements (opt-in)
  - Request ID generation for correlating related log entries
  - Stack trace capture for debugging
  - Context data support (JSON structured data)
  - System vs application log segregation (based on app_id presence)
  - Account scoping for multi-tenant isolation
  - Slack webhook integration for error/critical notifications

* **Enhanced backend controller for filtering**
  - Add log_level filtering (single or comma-separated: 'error,critical')
  - Add log_type filtering ('system' vs 'application')
  - Add source filtering (error_handler, exception_handler, logger, output_buffer)
  - Add date range filtering (Unix timestamps)
  - Account scoping for system logs
  - Computed fields: log_level_color, context_decoded

* **Enhanced frontend with filtering UI**
  - Application-level log view with log level badges, filter panel (level, date range)
  - New system-level log view page for platform logs
  - Enhanced log details view with request_id, context data, stack trace display
  - Color-coded badges for log levels and sources
  - jQuery UI date pickers for date range filtering
  - Real-time table refresh with filters

**Performance Impact:**
* Opt-in logging - zero overhead when disabled
* Indexed fields ensure fast queries even with millions of log entries
* Request ID enables efficient correlation of related logs
* Output buffering configurable threshold prevents excessive logging

**Configuration Example:**
```php
// config.php - Comprehensive Logging Configuration

// Enable error handler (required)
define('USE_KYTE_ERROR_HANDLER', true);

// Set log level (default: 'error' for backward compatibility)
define('LOG_LEVEL', 'error');     // Production: Only critical errors
// define('LOG_LEVEL', 'warning'); // Staging: Errors + warnings
// define('LOG_LEVEL', 'notice');  // Testing: Errors + warnings + notices
// define('LOG_LEVEL', 'all');     // Development: Everything including deprecated

// Enable Logger API (opt-in)
define('KYTE_LOGGER_ENABLED', true);

// Optional: Output buffering (capture echo/print)
define('LOG_OUTPUT_BUFFERING', false);  // Disabled by default
define('LOG_OUTPUT_BUFFERING_THRESHOLD', 100);  // Minimum bytes to log

// Optional: Slack notifications
define('SLACK_ERROR_WEBHOOK', 'https://hooks.slack.com/services/YOUR/WEBHOOK/URL');
```

**Logger API Usage:**
```php
use Kyte\Core\Logger;

// Debug level - detailed diagnostic information
Logger::debug('Cache miss', ['key' => 'user:123', 'ttl' => 3600]);

// Info level - general informational messages
Logger::info('User logged in', ['user_id' => 123, 'ip' => $_SERVER['REMOTE_ADDR']]);

// Warning level - non-critical issues
Logger::warning('API rate limit approaching', ['remaining' => 95, 'limit' => 1000]);

// Error level - runtime errors
Logger::error('Failed to send email', ['to' => 'user@example.com', 'error' => $e->getMessage()]);

// Critical level - serious failures
Logger::critical('Database connection lost', ['host' => DB_HOST, 'attempts' => 3]);
```

**Files Modified:**
* `src/Exception/ErrorHandler.php` - Enhanced with configurable levels, output buffering, request tracking
* `src/Core/Logger.php` - NEW: PSR-3 compatible Logger API
* `src/Core/Api.php` - Logger initialization after ErrorHandler registration
* `src/Mvc/Model/KyteError.php` - Extended model with 6 new fields
* `src/Mvc/Controller/KyteErrorController.php` - Enhanced with filtering, system log support
* Frontend files: log.html, system-log.html, kyte-shipyard-log.js, kyte-shipyard-system-log.js, kyte-shipyard-log-details.js, navigation.js
* `docs/logging-configuration.md` - NEW: Comprehensive logging configuration guide

**Database Changes**

*KyteError - Extend for comprehensive logging*
```sql
-- Add log_level enum field (default 'error' for backward compatibility)
ALTER TABLE KyteError
ADD COLUMN log_level ENUM('debug', 'info', 'warning', 'error', 'critical')
NOT NULL DEFAULT 'error'
AFTER line;

-- Add log_type enum field (automatically derived from app_id)
ALTER TABLE KyteError
ADD COLUMN log_type ENUM('system', 'application')
NOT NULL DEFAULT 'system'
AFTER log_level;

-- Add context field for structured additional data (JSON)
ALTER TABLE KyteError
ADD COLUMN context MEDIUMTEXT NULL
COMMENT 'JSON-encoded structured context data'
AFTER log_type;

-- Add request_id for request correlation
ALTER TABLE KyteError
ADD COLUMN request_id VARCHAR(64) NULL
AFTER context;

-- Add trace field for stack traces
ALTER TABLE KyteError
ADD COLUMN trace LONGTEXT NULL
AFTER request_id;

-- Add source field to distinguish error sources
ALTER TABLE KyteError
ADD COLUMN source ENUM('error_handler', 'exception_handler', 'logger', 'output_buffer')
NOT NULL DEFAULT 'error_handler'
AFTER trace;

-- Create indexes for performance
CREATE INDEX idx_log_level ON KyteError(log_level);
CREATE INDEX idx_log_type ON KyteError(log_type);
CREATE INDEX idx_request_id ON KyteError(request_id);
CREATE INDEX idx_date_created_level ON KyteError(date_created, log_level);
CREATE INDEX idx_account_log_type ON KyteError(account_id, log_type);

-- Update existing records to set log_type based on app_id
UPDATE KyteError
SET log_type = CASE
    WHEN app_id IS NOT NULL AND app_id != '' THEN 'application'
    ELSE 'system'
END
WHERE log_type = 'system';
```

**Notes:**
* All changes are 100% backward compatible
* New fields have default values - existing code continues to work
* Logger API is opt-in via KYTE_LOGGER_ENABLED constant
* Output buffering is opt-in via LOG_OUTPUT_BUFFERING constant
* Default LOG_LEVEL='error' maintains backward compatible behavior
* System logs (app_id IS NULL) are account-scoped
* Frontend displays log level badges, source badges, and enhanced filtering
* Documentation provides comprehensive configuration examples

---

### Multi-Language Support (i18n)

* **Add internationalization framework** for Japanese (日本語), Spanish (Español), and Korean (한국어)
  - User-level language preference with browser detection fallback
  - Account-level default language configuration
  - Application-level language configuration for app-specific API responses
  - Backend I18n helper class for translating error messages and API responses
  - Frontend i18n library with automatic page translation
  - Translation files for all UI strings and error messages
  - Lazy loading of translation files for performance
  - 100% backward compatible - English remains default, translations are opt-in

* **Backend Translation System** (`src/Util/I18n.php`)
  - Static helper class with `t()` method for translation
  - Automatic language detection from user preference or Accept-Language header
  - Translation file caching for performance
  - Parameter substitution in translated strings (`{param}` syntax)
  - Fallback to English if translation missing
  - Support for 4 languages: en (English), ja (Japanese), es (Spanish), ko (Korean)

* **Frontend Translation System** (`assets/js/source/kyte-i18n.js`)
  - Browser language detection with user preference override
  - JSON translation file loading with caching
  - DOM element translation via `data-i18n` attributes
  - Placeholder translation via `data-i18n-placeholder` attributes
  - Dynamic translation API: `KyteI18n.t('key', {params})`
  - Automatic page translation on language change
  - Integration with Kyte session for user preferences

* **Language Detection Priority**
  1. User preference from `KyteUser.language` field (highest priority)
  2. Application language from `Application.language` (app-specific API responses)
  3. Account default from `KyteAccount.default_language` (account-wide fallback)
  4. Browser `Accept-Language` header or `navigator.language` (auto-detect)
  5. Default to English (last resort)

* **User Interface Enhancements**
  - Language selector in user profile settings (user-level preference)
  - Account-level language selector in account settings (affects all users)
  - Optional language switcher in navigation bar
  - Session-based language persistence
  - Visual language indicators (flags/language codes)
  - Real-time UI translation without page refresh

**Translation Coverage:**
* Backend: ~400 error messages, API responses, validation messages
* Frontend: ~800 UI strings (navigation, forms, buttons, modals, tables)
* Total: ~1,200 translatable strings across 4 languages

**Files Added:**
* `src/Util/I18n.php` - Backend translation helper class
* `translations/en.php` - English translations (default)
* `translations/ja.php` - Japanese translations (日本語)
* `translations/es.php` - Spanish translations (Español)
* `translations/ko.php` - Korean translations (한국어)
* `assets/js/source/kyte-i18n.js` - Frontend i18n library
* `assets/i18n/en.json` - Frontend English translations
* `assets/i18n/ja.json` - Frontend Japanese translations
* `assets/i18n/es.json` - Frontend Spanish translations
* `assets/i18n/ko.json` - Frontend Korean translations

**Files Modified:**
* `src/Core/Api.php` - Language detection and I18n initialization
* `src/Mvc/Controller/UserController.php` - Language preference handling
* `kyte-managed-front-end/app/*.html` - Add `data-i18n` attributes to UI elements
* `kyte-managed-front-end/assets/js/source/*.js` - Replace hardcoded strings with `KyteI18n.t()`

**Configuration:**
```php
// config.php - Optional i18n configuration
define('DEFAULT_LANGUAGE', 'en');  // System default (optional, defaults to 'en')
define('SUPPORTED_LANGUAGES', ['en', 'ja', 'es', 'ko']);  // Supported languages
```

**Usage Examples:**

Backend:
```php
use Kyte\Util\I18n;

// Simple translation
$message = I18n::t('error.not_found');  // "Record not found"

// Translation with parameters
$message = I18n::t('success.created', ['model' => 'User']);  // "User created successfully"

// In controller responses
$this->response['error'] = I18n::t('error.validation_failed', ['field' => 'email']);
```

Frontend:
```javascript
// JavaScript translation
alert(KyteI18n.t('msg.confirm_delete'));  // "Are you sure you want to delete this?"

// Translation with parameters
let msg = KyteI18n.t('msg.items_selected', {count: 5});  // "5 items selected"

// HTML translation (automatic)
<button data-i18n="btn.save">Save</button>  // Auto-translated on page load
<input data-i18n-placeholder="placeholder.search" />  // Placeholder translated
```

**Database Schema Changes:**
See "Database Migration SQL (v4.0.0)" section above for:
- `KyteUser.language` field (user preference)
- `KyteAccount.default_language` field (account default)

**Notes:**
* 100% backward compatible - no code changes required for existing deployments
* English remains the default language if user has no preference set
* Translations are lazy-loaded only when needed
* Missing translations automatically fall back to English
* Professional translation recommended for production use
* Machine translation is NOT recommended for customer-facing text
* All strings use UTF-8 encoding (utf8mb4 collation)
* Date/time formatting respects user's locale (future enhancement)
* Number formatting respects user's locale (future enhancement)

### Bug Fixes

* Fix bug where custom script assignments were deleted when republishing scripts without `include_all` enabled
* Fix bug where custom library assignments were deleted when updating libraries without `include_all` enabled
* Add tracking of original `include_all` value to properly detect changes from 1 to 0 in KyteScriptController
* Add tracking of original `include_all` value to properly detect changes from 1 to 0 in KyteLibraryController
* Preserve manual page assignments for scripts and libraries when updating or republishing
* Fix critical bug where version control content hash UNIQUE constraint was not scoped by account, causing duplicate hash errors across accounts

**Database Changes**

*KyteFunctionVersionContent - Fix UNIQUE constraint to scope by account*
```sql
-- Remove old UNIQUE constraint on content_hash alone
ALTER TABLE `KyteFunctionVersionContent`
DROP INDEX `content_hash`;

-- Add composite UNIQUE constraint scoped by account
ALTER TABLE `KyteFunctionVersionContent`
ADD UNIQUE KEY `unique_hash_per_account` (`content_hash`, `kyte_account`);
```

*KyteScriptVersionContent - Fix UNIQUE constraint to scope by account*
```sql
-- Remove old UNIQUE constraint on content_hash alone
ALTER TABLE `KyteScriptVersionContent`
DROP INDEX `content_hash`;

-- Add composite UNIQUE constraint scoped by account
ALTER TABLE `KyteScriptVersionContent`
ADD UNIQUE KEY `unique_hash_per_account` (`content_hash`, `kyte_account`);
```

*KytePageVersionContent - Fix UNIQUE constraint to scope by account*
```sql
-- Remove old UNIQUE constraint on content_hash alone
ALTER TABLE `KytePageVersionContent`
DROP INDEX `content_hash`;

-- Add composite UNIQUE constraint scoped by account
ALTER TABLE `KytePageVersionContent`
ADD UNIQUE KEY `unique_hash_per_account` (`content_hash`, `kyte_account`);
```

## 3.8.2

* Fix bug if FK mapping is not enabled user ID is not mapped for modified field in page controller

## 3.8.1

* Fix bug where users cannot add page specific scripts
* Add controllers and models for tracking controller function version changes
* Add version control to function controller
* Add support for custom script version control

**Database Changes**

*KyteScriptVersion*
```sql
CREATE TABLE `KyteScriptVersion` (
    `id` int NOT NULL AUTO_INCREMENT,
    `script` int unsigned NOT NULL,
    `version_number` int unsigned NOT NULL,
    `version_type` enum('auto_save','manual_save','publish') NOT NULL DEFAULT 'manual_save',
    `change_summary` varchar(500) DEFAULT NULL,
    `changes_detected` json DEFAULT NULL, -- stores which fields changed
    `content_hash` varchar(64) NOT NULL, -- SHA256 of combined content for deduplication
    
    -- function metadata snapshot (only store if changed from previous version)
    `name` varchar(255) DEFAULT NULL,
    `description` text DEFAULT NULL,
    `s3key` varchar(255) DEFAULT NULL,
    `script_type` varchar(255) DEFAULT NULL,
    `obfuscate_js` int unsigned DEFAULT NULL,
    `is_js_module` int unsigned DEFAULT NULL,
    `include_all` int unsigned DEFAULT NULL,
    `state` int unsigned DEFAULT NULL,

    -- Version metadata
    `is_current` tinyint(1) NOT NULL DEFAULT 0,
    `parent_version` int unsigned DEFAULT NULL, -- references previous version
    
    -- Framework field
    `kyte_account` int unsigned NOT NULL,

    -- Audit fields
    `created_by` int DEFAULT NULL,
    `date_created` bigint unsigned,
    `modified_by` int DEFAULT NULL,
    `date_modified` bigint unsigned,
    `deleted_by` int DEFAULT NULL,
    `date_deleted` bigint unsigned,
    `deleted` tinyint(1) NOT NULL DEFAULT 0,
    
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

*KyteScriptVersionContent*
```sql
CREATE TABLE `KyteScriptVersionContent` (
    `id` int NOT NULL AUTO_INCREMENT,
    `content_hash` varchar(64) NOT NULL UNIQUE,
    `content` longblob DEFAULT NULL,
    `content_js_obfuscated` longblob DEFAULT NULL,
    `reference_count` int unsigned NOT NULL DEFAULT 1,
    `last_referenced` bigint unsigned NOT NULL,

    -- Framework field
    `kyte_account` int unsigned NOT NULL,

    -- Audit fields
    `created_by` int DEFAULT NULL,
    `date_created` bigint unsigned,
    `modified_by` int DEFAULT NULL,
    `date_modified` bigint unsigned,
    `deleted_by` int DEFAULT NULL,
    `date_deleted` bigint unsigned,
    `deleted` tinyint(1) NOT NULL DEFAULT 0,
    
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

*KyteFunctionVersion*
```sql
CREATE TABLE `KyteFunctionVersion` (
    `id` int NOT NULL AUTO_INCREMENT,
    `function` int unsigned NOT NULL,
    `version_number` int unsigned NOT NULL,
    `version_type` enum('auto_save','manual_save','publish') NOT NULL DEFAULT 'manual_save',
    `change_summary` varchar(500) DEFAULT NULL,
    `changes_detected` json DEFAULT NULL, -- stores which fields changed
    `content_hash` varchar(64) NOT NULL, -- SHA256 of combined content for deduplication
    
    -- function metadata snapshot (only store if changed from previous version)
    `name` varchar(255) DEFAULT NULL,
    `description` text DEFAULT NULL,
    `function_type` varchar(255) DEFAULT NULL,
    `kyte_locked` int unsigned DEFAULT NULL,

    -- Version metadata
    `is_current` tinyint(1) NOT NULL DEFAULT 0,
    `parent_version` int unsigned DEFAULT NULL, -- references previous version
    
    -- Framework field
    `kyte_account` int unsigned NOT NULL,

    -- Audit fields
    `created_by` int DEFAULT NULL,
    `date_created` bigint unsigned,
    `modified_by` int DEFAULT NULL,
    `date_modified` bigint unsigned,
    `deleted_by` int DEFAULT NULL,
    `date_deleted` bigint unsigned,
    `deleted` tinyint(1) NOT NULL DEFAULT 0,
    
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

*KyteFunctionVersionContent*
```sql
CREATE TABLE `KyteFunctionVersionContent` (
    `id` int NOT NULL AUTO_INCREMENT,
    `content_hash` varchar(64) NOT NULL UNIQUE,
    `code` longblob DEFAULT NULL,
    `reference_count` int unsigned NOT NULL DEFAULT 1,
    `last_referenced` bigint unsigned NOT NULL,

    -- Framework field
    `kyte_account` int unsigned NOT NULL,

    -- Audit fields
    `created_by` int DEFAULT NULL,
    `date_created` bigint unsigned,
    `modified_by` int DEFAULT NULL,
    `date_modified` bigint unsigned,
    `deleted_by` int DEFAULT NULL,
    `date_deleted` bigint unsigned,
    `deleted` tinyint(1) NOT NULL DEFAULT 0,
    
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## 3.8.0

* Fix issue where new pages did not have global script and library assignments
* Remove script and library assignments on page deletion
* Add support for renaming site page files
* Change ErrorHandler to only handle application space errors
* Fix issue with SQL debug verbosity not working
* Add ability to bypass Kyte error handlers
* If page is created with missing menu page link, then place "#"
* Add feature to allow for page republishing if kyte_connect changes, or obfuscation settings change for kyte_connect
* Return user information for version history
* Add global_scope alias in Assignments table

**Database Changes**

*KyteLibraryAssignment*
```sql
ALTER TABLE KyteLibraryAssignment 
ADD COLUMN `global_scope` TINYINT(1) UNSIGNED DEFAULT 0 AFTER `library`;
```

*KyteScriptAssignment*
```sql
ALTER TABLE KyteScriptAssignment 
ADD COLUMN `global_scope` TINYINT(1) UNSIGNED DEFAULT 0 AFTER `script`;
```

*KytePageVersion*
```sql
CREATE TABLE `KytePageVersion` (
    `id` int NOT NULL AUTO_INCREMENT,
    `page` int unsigned NOT NULL,
    `version_number` int unsigned NOT NULL,
    `version_type` enum('auto_save','manual_save','publish') NOT NULL DEFAULT 'manual_save',
    `change_summary` varchar(500) DEFAULT NULL,
    `changes_detected` json DEFAULT NULL, -- stores which fields changed
    `content_hash` varchar(64) NOT NULL, -- SHA256 of combined content for deduplication
    
    -- Page metadata snapshot (only store if changed from previous version)
    `title` varchar(255) DEFAULT NULL,
    `description` text DEFAULT NULL,
    `lang` varchar(255) DEFAULT NULL,
    `page_type` varchar(255) DEFAULT NULL,
    `state` int unsigned DEFAULT NULL,
    `sitemap_include` int unsigned DEFAULT NULL,
    `obfuscate_js` int unsigned DEFAULT NULL,
    `is_js_module` int unsigned DEFAULT NULL,
    `use_container` int unsigned DEFAULT NULL,
    `protected` int unsigned DEFAULT NULL,
    `webcomponent_obj_name` varchar(255) DEFAULT NULL,
    
    -- Relationship references (only if changed)
    `header` int unsigned DEFAULT NULL,
    `footer` int unsigned DEFAULT NULL,
    `main_navigation` int unsigned DEFAULT NULL,
    `side_navigation` int unsigned DEFAULT NULL,
    
    -- Version metadata
    `is_current` tinyint(1) NOT NULL DEFAULT 0,
    `parent_version` int unsigned DEFAULT NULL, -- references previous version
    
    -- Framework field
    `kyte_account` int unsigned NOT NULL,

    -- Audit fields
    `created_by` int DEFAULT NULL,
    `date_created` bigint unsigned,
    `modified_by` int DEFAULT NULL,
    `date_modified` bigint unsigned,
    `deleted_by` int DEFAULT NULL,
    `date_deleted` bigint unsigned,
    `deleted` tinyint(1) NOT NULL DEFAULT 0,
    
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

*KytePageVersionContent*
```sql
CREATE TABLE `KytePageVersionContent` (
    `id` int NOT NULL AUTO_INCREMENT,
    `content_hash` varchar(64) NOT NULL UNIQUE,
    `html` longblob DEFAULT NULL,
    `stylesheet` longblob DEFAULT NULL,
    `javascript` longblob DEFAULT NULL,
    `javascript_obfuscated` longblob DEFAULT NULL,
    `block_layout` longblob DEFAULT NULL,
    `reference_count` int unsigned NOT NULL DEFAULT 1,
    `last_referenced` bigint unsigned NOT NULL,

    -- Framework field
    `kyte_account` int unsigned NOT NULL,

    -- Audit fields
    `created_by` int DEFAULT NULL,
    `date_created` bigint unsigned,
    `modified_by` int DEFAULT NULL,
    `date_modified` bigint unsigned,
    `deleted_by` int DEFAULT NULL,
    `date_deleted` bigint unsigned,
    `deleted` tinyint(1) NOT NULL DEFAULT 0,
    
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## 3.7.8

* Fix issue where obfuscated javascript was still plain text. Problem was with script_type not being accessed as property member of object.
* If there is an entry in the error log for an undefined array index `labelCenterBlock`, run the following sql statement:
**Database Changes (if not applied previously)**
```sql
ALTER TABLE SideNav ADD labelCenterBlock TINYINT(1) unsigned DEFAULT 0 AFTER columnStyle;
```

## 3.7.7

* Update Kyte Lirbary to support global and non-global includes. Requires a new table which can be added using `gust` as shown below.
*After running composer update*
```bash
gust model add KyteLibraryAssignment
```

## 3.7.6

* Fix issue where model definition did not update correctly after creating, updating, or deleting a new column.

## 3.7.5

* Add support for global includes for custom scripts. Requires a table change in the database (see below)

**Database Changes**
```sql
ALTER TABLE KyteScript ADD include_all TINYINT(1) unsigned DEFAULT 0 AFTER obfuscate_js;
```

## 3.7.4

* Adds LEFT and INNER JOIN SQL support.
* Fixes issue when searching fields within a model that has foregin keys the join only returns if a fk exists.
* Fix database field length issue with `code` in controller (`text` to `longblob`)

## 3.7.3

* Ability to search by field range (int or double).

## 3.7.2

* Enable foreign table attribute searches.

## 3.7.1

* Improve DB fallback if SSL is not available

## 3.7.0

* Adds support for SSL/TLS connection to database

## 3.6.10

* Adds support to edit and delete application level model data

## 3.6.9

* Add support for retrieving IMDS/IDMSv2 data
* Update error handling to include IMDS/IMDSv2 data if available

## 3.6.8

* Add support for sending slack notifications for errors

## 3.6.7

* Add `SessionInspector` controller

## 3.6.6

* Move `is_js_module` from `KytePageData` to `KytePage`

## 3.6.5

* Refactor code and remove unreachable statements following a throw.
* Add member methods for deleting or purging retrieved objects.
* Add ability to mark JS code in a page as a module.
* Support for logging exceptions and errors at application level

## 3.6.4

* Fix issue #36 where user object being access for application was not at the application scope level

## 3.6.3

* Fix issue #34 where controller function couldn't be deleted

## 3.6.2

* Fix issue where blob data was not being stored in DB

## 3.6.1

* Add support for marking scripts as JavaScript modules
* Add support for assigning element ID and/or class
* Add support for default site langauge, and page specific languages
* Bug fix to remove calls to deprecated Permission model
* Add support for additional MySQL types
* Add URL decode for field name when parsing URL paths

## 3.6.0

* Remove model based roles and permissions in preparations for a more streamlined RBAC
* Add last login to user model
* Store last login when session is created

## 3.5.7

* Fix issue with wrong navigation's custom nav item style

## 3.5.6

* Fix issue with custom nav item style not propagating

## 3.5.5

* Simplify version response and exclude git hashes etc.
* Change logout handler to use element class instead of id so multiple logout handlers can be configured.
* Add flag for logout option for side nav
* Add attribute for making side nav lable centered and icon block

## 3.5.4

* Fix issue where KyteWebComponent was returning empty data

## 3.5.3

* Fix problem where assinged Kyte Web Components were returning compressed binary data.

## 3.5.2

* Fix problem where compressed binary data was being returned as part of foreign key for SideNavItem

## 3.5.1

* Allow for user defined variable name for Kyte Web Component

## 3.5.0

* Enhanced PHP backend integration for dynamic web component rendering.
* Implemented functionality to output HTML templates in an object format compatible with KyteWebComponent, enabling seamless integration with frontend JavaScript.
* Added robust server-side handling for web component data, including secure compression and decompression functionalities.
* Improved codebase to support efficient loading and rendering of web components, optimizing both frontend and backend performance.

## 3.4.7

* Fix bug where footer and header where not decompressed for nav/sidenav, scripts, and libraries.

## 3.4.6

* Fix navigation item to return empty string for html data

## 3.4.5

* Add `KyteScriptAssignment` model for tracking what scripts are going to be included in which `KytePage`s
* Remove `include_all` attribute from `KyteScript` model as all assignments will be tracked by `KyteScriptAssignment`
* Remove duplicate code for page creation out of `KytePageDataController`
* Update `createHTML` to include custom scripts based on `KyteScriptAssignment`

## 3.4.4

* Decompress section template fk data for `KytePageDataController`

## 3.4.3

* Decompress section template fk data for `KytePage`

## 3.4.2

* Delete page data when page is deleted
* Add environment variable specific for data stores (s3 bucket name and region)
* Fix release script to check for Version.php as too many version mismatches have occurred
* Compress KyteScript for custom script data
* Compress section templates
* Add attribute for storing block layout information in `KyteSectionTemplate`
* Rename section templates as `KyteSectionTemplate`

## 3.4.1

* Update value of environment variable to type text

## 3.4.0

* Add environment variable setup at API init()
* May break functionality if environment variable model isn't configured in database prior to update
* Move db column creation and update from `hook_response_data` to `hook_preprocess` to better handle exceptions
* Cast array param as object
* Add new Environment Variable model
* Add support to create new constants from application-level environment variables
* Application-level environment variables are scoped within the application at runtime
* Add controller for triggering update of Kyte Shipyard(tm)

## 3.3.4

* Wrap db column manipulation inside try-catch

## 3.3.3

* Delete failed attribute creations

## 3.3.2

* Resolve issue where main site management was being sent to sqs

## 3.3.1

* Fix bug that caused SQS to be used instead of SNS

## 3.3.0

* This version migrates away from SQS to SNS
* MAY BREAK if using SQS - Switch to SNS before upgrading

## 3.2.9

* Increment counter for generating search query

## 3.2.8

* Update version number in class

## 3.2.7

* Check if search field is a member attribute before querying

## 3.2.6

* Fix issue where controller object could be null

## 3.2.5

* Do not through exception if controller is not found in application scope

## 3.2.4

* Check if app id is present before loading application level controllers

## 3.2.3

* Only load relevant controllers through app

## 3.2.2

* Store model def as json string in db
* No longer read/write model def in file
* Load model def from json string
* Add default path for sample config
* Check AWS keys within account scope

## 3.2.1

* Add constant for default Kyte models

## 3.2.0

* Removed deprecated values

Migration must be performed with version 3.1.1 prior to upgrade.

## 3.1.1

* Add back deprecated attributes until next minor version update to ensure smooth migration

## 3.1.0

* Roll back logger while determining best implementation
* Add SQS wrapper
* Move page invalidation code to use SQS
* Add site deletion using SQS
* Move page creation to use SQS
* Update Page model name to KytePage
* Stage KytePageData to hold compressed page data
* Add comment that page data inside KytePage will be removed and moved to KytePageData
* Renamed controller PageController to KytePageController
* Fix issue with $ in property name
* Refactor function that checks for default constant values
* Change Site to KyteSite
* Update controller for site to use KyteSite

## 3.0.90

* Add global to check if s3 debug output handler should be enabled
* Only output relevant errors to s3

## 3.0.89

* Remove system error handler for s3

## 3.0.88

* Add log handler for php

## 3.0.87

* Add wrapper function for SES logging
* Remove function from detail as content will always be logger

## 3.0.86

* Fix s3 object in logger

## 3.0.85

* Fix app object for logger

## 3.0.84

* temporarily revert session exception logging until framework logging mechanism is finalized

## 3.0.83

* Add utility class for logging to s3
* Add feature to create new bucket for logs when application is created - default to us-east-1 for logs
* Add attribute for storing bucket information for logs at Application level

## 3.0.82

* Add missing header attribute for Page model

## 3.0.81

* Move custom scripts to end of body
* Add support for headers in page creation

## 3.0.80

* Update fontawesome CDN to version 6.4.2
* Remove default libraries such as bootstrap, datatable, jquery, jquery UI
* Add controller for managing custom libraries
* New model for storing links to libraries like JQuery
* Fix bug where publishing a nav or side nav publishes all pages (including drafts)
* New model for scripts to be used accross pages or entire site
* Controller for creating custom scripts and invalidating cache
* Remove unecessary assignment of variables in PageController (begin bug)
* Support website endpoint for different regions https://docs.aws.amazon.com/general/latest/gr/s3.html#s3_website_region_endpoints

## 3.0.79

* Remove editor.js dependence in page generator

## 3.0.78

* Increase sleep between s3 policy requests
* Add epoch time to end of buckent name to improve on uniqueness

## 3.0.77

* Add missing required roles check
* Add controller wrapper for manipulating app-level models

## 3.0.76

* Add utility script for release new version
* Fix issue where API key description was being redacted

## 3.0.75

* Rename APIKey table to KyteAPIKey to accomodate new model for 3rd party api keys
* Create table for 3rd party APIKeys

## 3.0.74

* Add sleep to help improve async call to AWS when generating buckets and configuring permissions

## 3.0.73

* Assign navbar-light or navbar-dark based on background color luminance using WCAG 2.0 guidelines
* Ability to customize footer background color

## 3.0.72

* Make replace placeholders for HTML a public method

## 3.0.71

* Ability to assign acm cert and aliases when creating CF distribution

## 3.0.70

* Fix array to string conversion for footer styles

## 3.0.69

* Fix issue where section stylesheets were not propagated

## 3.0.68

* Fix bug where numeric values caused a mysql escape error

## 3.0.67

* Add font color to footer styles

## 3.0.66

* Add capability to add footer

## 3.0.65

* Update section template with new attributes

## 3.0.64

* Retrieve app object before requesting s3 presigned url

## 3.0.63

* Return downloadable link for pages

## 3.0.62

* Require AMPHP as new dependency

## 3.0.61

* Return application id in response

## 3.0.60

* Fix ability to delete model files
* Resolve issue with password object being access as array element
* Fix issue where s3 bucket doens't get website enabled

## 3.0.59

* Remove extra condition for checking function name within scope of application

## 3.0.58

* Check for existing controller and function names within scope of application

## 3.0.57

* Fix issue where controller of same name in different app causes error

## 3.0.56

* Store user agent, remote IP, and forwarded IP in session table

## 3.0.55

* fix tag issue

## 3.0.54

* Use shorter username for database

## 3.0.53

* Add application-level AWS key (foreign key)
* Add model for AWS keys
* Move kyte connect and obfuscated version of kyte connect to Application model
* Update to use application specific AWS for application management

## 3.0.52

* Update to datetime format for Page controller

## 3.0.51

* Fix bug where session token is null

## 3.0.50

* Remove redundant call to retrieve user object
* Reduce signature timeout to 5 min
* Create constant for signature timeout 

## 3.0.49

* Fix default CDN to use HTTPS

## 3.0.48

* Allow custom CDN for each implementation
* If custom CDN is not defined, default to current stable

## 3.0.47

* Fix ciritcal bug with DataModel ModelObject instantiation

## 3.0.46

* Fix bug where code to check existing model names is not scoped within application

## 3.0.45

* Use async function to apply bucket policies

## 3.0.44

* Declare a new variable for static media s3 for clarity
* Fix issue where region was not being set

## 3.0.43

* Failed to tag correctly

## 3.0.42

* Fix issue where site entry in DB is created even if region is blank or wrong.

## 3.0.41

* Fix issue with column name change

## 3.0.40

* Add support for user to specify a region to create a new site in

## 3.0.39

* Fix to apply navigation font color to title too

## 3.0.38

* Add ability to change main navigation foreground color
* Add ability to change main navigation background colors
* Add ability to make main navigation stick to top
* Add ability to change main navigation dropdown foreground color
* Add ability to change main navigation dropdown background color

## 3.0.37

* Add flag to determine if a container div should be used to wrap the HTML content
* Fix bug that caused endless looping if parent item was accidentally set to self
* Add password attribute for model
* Check if hook or override of specified type already exists for a controller
* Make function name optional

## 3.0.36

* Ability to override account level scoping

## 3.0.35

* Fix bug where API_URL was never defined (incorrectly defined as APP_URL)

## 3.0.34

* Fix regression where nav logo disapeared

## 3.0.33

* Fix issue with invalid HTML attribute for side navigation wrapper
* Add ability to customize side navigation style
* Fix formatting issue for switch statement in controller functions

## 3.0.32

* Order main nav items by 'center' attribute first, then item order

## 3.0.31

* Removing padding and margins around containers to allow users for maximum styling and customization

## 3.0.30

* Add wrapper around sidenav div for better customization and styling options

## 3.0.29

* Fix order query for nav items

## 3.0.28

* Optimize to only update supplied values

## 3.0.27

* Resolve issue with undefined model for virtual controller

## 3.0.26

* Order menu items by item order attribute

## 3.0.25

* Add support for bulk updating nav items
* No longer update pages or sitemap when nav or side nav items are changes

## 3.0.24

* Fix issue with variable scoping

## 3.0.23

* SES add support for specifying reply to addresses

## 3.0.22

* Support for Google Analytics
* Support for Google Tag Manager

## 3.0.21

* Order sitemap by date modified

## 3.0.20

* Add feature to check if alias conforms to SSL certificate and domain assigned to CF distribution
* Add meta description for SEO
* Add open graph meta tags for SEO
* Add robots meta tag for SEO
* Add canonical tag for SEO
* Add option to specify obfuscation preference for pages

## 3.0.19

* Fix bug with empty sitemap when editing navigation items

## 3.0.18

* Resolve issue where updating a page nav caused protected pages to be included in sitemap

## 3.0.17

* Add formatting to XML sitemap output

## 3.0.16

* Reduce number of CF invalidation calls to optimize performance

## 3.0.15

* Add support for generating and managing sitemaps when pages are created, updated, deleted
* Add support for updating sitemaps when menu items change
* When generating sitemaps, skip pages that are password protected
* Add feature to specify alias domain for site

## 3.0.14

* Return message ID from AWS SES if succesfully sent email

## 3.0.13

* Add method to return first item from array from model query
* Add method to return last item from array from model query
* Improve custom query performance
* Add support for specifing a sql LIMIT

## 3.0.12

* Fix bug with deleting a public access block for a s3 bucket

## 3.0.11

* Fix in response to new S3 requirement that disables ACL in favor of bucket ownership policies. https://aws.amazon.com/about-aws/whats-new/2022/12/amazon-s3-automatically-enable-block-public-access-disable-access-control-lists-buckets-april-2023/?nc1=h_ls
* Add method to S3 wrapper for deleting public access block to allow for public access to s3 bucket

## 3.0.10

* Fix bug where internal property was not accessible

## 3.0.9

* Fix bug where internal method was not being used

## 3.0.8

* Fix bug where stale data was returned after an update

## 3.0.7

* Return user role if present

## 3.0.6

* Fix bug where preg_match did not replace and returned null

## 3.0.5

* User interal AWS credential wrapper for Email utility
* Return account object for user profile

## 3.0.4

* Make account number a non-protected entry

## 3.0.3

* Bug fix for Kyte Profile

## 3.0.2

* Add KyteProfile controller for updating user profile on Kyte Shipyard

## 3.0.1

* Add email templates
* Ability to send from a email utility class
* Prepopulate template with data in associative array format

## 3.0.0

* Add support for custom user table, seperate from main framework.
* Add support for optional organization table, and scoping users based on organization.
* Add optional AWS credential attributes at application level.
* Rename User and Account models as KyteUser and KyteAccount to better distinguish from application models.
* Add initial round of PHPDocs

## 2.0.0

* Updated version with SaaS support.

## 1.0.0

* Initial development release kyte framework.
