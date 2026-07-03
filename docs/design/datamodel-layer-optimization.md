# Data-Model Layer Optimization & Robustness — KYTE-#190

> **Status:** ACTIVE. Brainstorm complete (2026-07-03) — see §6 for locked
> decisions. P1 engine slices 1–2 shipped (projection #114, lazy-load #115).
> Reconstructed 2026-07-01 from a fresh code recon of `master` after the
> original (uncommitted) doc was lost.
>
> **Spans:** kyte-php (`DBI` / `Model` / `ModelObject` / `ModelController` /
> `DataModel` + `ModelAttribute`), kyte-api-js (`KyteTable` / `KyteForm`),
> kyte-shipyard (model designer).

## 1. Why

Kyte's data-model / DB layer carries platform-wide scale debt. It surfaced via
the **KyteActivityLog OOM (#182)** but is **not** an activity-log problem — the
same failure modes hit **user-designed models**:

- Any model with a large `t`/`lt`/blob column + a list view → generic
  `SELECT *` → the DB reads every large column for every row → OOM / slow.
- User tables get **no secondary indexes** today (primary key only), so any
  non-PK filter is a full table scan as data grows.
- List queries are **unbounded by default** (see the pagination bug below).

**#182 shipped a tactical fix, not the engine.** PR #99 added an
`ActivityLogger` write-cap (truncates over-size `request_data`/`changes`) and a
**controller-local response prune** in `KyteActivityLogController` that
`unset()`s two LONGTEXT fields from list responses. That prune does **not**
reduce bytes read from MySQL (the query is still `SELECT *`) and is not reusable
by any other model. The general engine remains greenfield.

## 2. Current state (verified 2026-07-01, `master`)

| Capability | Exists? | Evidence |
|---|---|---|
| Column projection in query engine | **No** | `DBI::select` is `SELECT \`$table\`.*` — `DBI.php:1231`; no field-list param in the `:1205` signature. Same for `count`/`sum`/`group`. |
| Deferred / lazy large columns | **No** | `Model::retrieve` (`Model.php:90`) & `ModelObject::retrieve` (`ModelObject.php:162`) fully hydrate every column via `populate()`. |
| General list projection | **No** | Only an app-level one-off: `KyteActivityLogController.php:198` `unset()`s `request_data`/`changes` post-query. |
| Activity-log write-cap | **Yes (#182)** | `ActivityLogger::capField()` `ActivityLogger.php:247`, cap `KYTE_ACTIVITY_LOG_MAX_FIELD_BYTES` (default 16384). |
| Secondary indexes on user models | **No** | `DBI::createTable` emits PK only (`DBI.php:699`); no `ADD INDEX`/`ADD KEY`/`ADD UNIQUE` anywhere; no `indexed`/`unique` flag on `ModelAttribute`. System tables hand-roll indexes via raw migrations (see `KyteMCPToken.php:22`, `KyteMCPSession.php:28` — "the model framework doesn't declare indexes"). |
| Default pagination cap / max page size | **No** | `page_num` defaults to `0` (falsy), so the `LIMIT` branch in `Model::retrieve:265` is skipped unless the client sends `X-Kyte-Page-Idx` → **unbounded list returns all rows**. No server-side max on `X-Kyte-Page-Size`. |
| Retention framework (activity log or general) | **No** | Deferred to this card per the #182 commit message. |

**Everything in Part A/P1 except the #182 write-cap is greenfield.**

## 3. Part A — concrete plan (phased)

### P1 — Query engine: projection + pagination guardrails (kyte-php only)

1. **Projection in `DBI::select`** — add an optional column-list parameter
   (backward-compatible: `null` ⇒ `SELECT *`). Thread it through
   `count`/`sum`/`group` where relevant.
2. **Projection through the ORM** — optional field-list on `Model::retrieve`
   and `ModelObject::retrieve`; `populate()` tolerates partial rows.
3. **Deferred large columns** — mark `t/tt/mt/lt/b/tb/mb/lb` columns as
   *deferrable*; exclude them from the default projection for list reads;
   lazy-load on property access (or explicit `->load('col')`).
4. **Pagination guardrails** — fix the `page_num=0` falsy bug so lists are
   **always** bounded by a default `LIMIT`; enforce a **server-side max
   page_size** cap (reject/clamp oversized `X-Kyte-Page-Size`).
5. **Retro-fit #182** — replace the `KyteActivityLogController` response prune
   with real projection so the LONGTEXT columns are never read for list views
   (validates the engine against its first real consumer).

*P1 is the highest-leverage slice: it removes the OOM/scan risk for the generic
list path platform-wide and is invisible to existing callers.*

### P2 — User-model coverage + Shipyard + client (kyte-php + kyte-api-js + shipyard)

- Expose deferred-column config on user models (a `ModelAttribute` "deferred /
  lazy" flag) + a Shipyard designer toggle.
- `KyteTable` sends only the fields it displays (client-driven projection);
  `KyteForm` lazy-loads deferred columns on edit.
- Surface pagination caps in `KyteTable`.

### P3 — Retention framework + KyteError (kyte-php, CronWorker)

- Generic, opt-in retention/pruning framework driven by the CronWorker (ties to
  #61). **PK/id-range based — never an unbounded `date_created` full-scan**
  (the ETOM purge burned EBS burst-I/O credits doing exactly that).
- First consumers: `KyteActivityLog`, `KyteError`; opt-in for user models.

## 4. Part B — brainstorm agenda (live working session; **lead with index management**)

Themes to work through with Kenneth, ordered by expected impact:

1. **Index management for user models** — *highest-impact sleeper.*
   `ModelAttribute` `indexed` / `unique` flags → `DBI` `ADD INDEX` / `ADD
   UNIQUE`; composite indexes; the DDL/migration path (via the #325 schema
   tooling); Shipyard UX; retro-fit for existing tables.
2. **Schema migration / evolution safety** — expand→contract ordering, online
   DDL, the `kyte_locked` guard, FK integrity on rename/drop.
3. **Query layer** — N+1 (#171), `count()` cost, pagination ergonomics,
   projection API shape.
4. **Relationships** — FK semantics, cascades, eager vs lazy.
5. **Types + constraints + validation** — DB-level constraints vs app-level.
6. **Large-field strategy** — deferred columns vs external (S3) offload for
   blobs.
7. **Lifecycle / retention** (#61).
8. **Generic-controller robustness** — partial-PUT (#167/#168).
9. **Designer UX** — Shipyard model designer.
10. **Observability** — slow-query + table-size metrics.

## 5. Related cards

#182 (done — tactical write-cap + activity-log prune), #171 (N+1), #61
(CronWorker / retention), #167 / #168 (partial-PUT robustness), #188
(versioning bloat).

## 6. Brainstorm outcomes & locked decisions (2026-07-03)

Full working session with Kenneth. Everything below is decided.

### 6.1 Column projection — generic wiring (P1)

- **New `X-Kyte-Fields` request header** (CSV of field names) — deliberately
  **separate** from `x-kyte-page-search-fields` (search = "which columns to
  LIKE-match"; projection = "which columns to return" — distinct concerns).
  Read in `Api` like other `X-Kyte-*` headers; applied in the generic
  `ModelController::get()` via `Model::select()`.
- **Opt-in on BOTH list and single-GET; never auto-applied.** No header ⇒ full
  object (detail/edit views untouched). Header present ⇒ honored everywhere
  (enables light single-GET: metadata-only fetch, status polling, progressive
  detail loading via `load()`).
- **Base-controller `alwaysInclude`** set (id + FK ids + audit columns) unioned
  with the client's fields before querying — protects response hooks / FK
  expansion from projected-out columns. `id` is force-included regardless.
- **kyte-api-js:** `KyteTable` already derives its field list from `col.data`
  and sends it (as `x-kyte-page-search-fields`, `kyte-source.js:1491-1508`);
  add a sibling `X-Kyte-Fields` from the same list — **one line, zero new dev
  config, projection "just works" from column defs.** Single hand-written
  `kyte-source.js` → `release.sh` → CDN.
- **FK labels** use dotted paths (`client.name`, resolved client-side by
  `getNestedValue`). P1 treats a dotted path as "include the `client` base
  column + existing full FK expansion" (reusing the dotted-path parser
  `Model::retrieve` already has for search/sort joins). **Nested FK
  projection + recursion capping = fast-follow → card #332** (motivated by
  recursive-FK expansion bloat; the client contract is UNCHANGED between P1 and
  the fast-follow, so it's a server-only upgrade — no second SDK release).
  Modes: no header = full expand; `client.name` = expand + project to
  `{id,name}` + cap recursion; bare `client` = expand fully.

### 6.2 Index management for user models (P1 + fast-follow)

- **Auto-index every FK column by default** — biggest win, invisible to users.
- Add `indexed` / `unique` flags to `ModelAttribute` → `DBI::addIndex()` /
  `dropIndex()` via the #325 schema-DDL path; naming `idx_<table>_<col>`;
  idempotent via the `information_schema` guard (MySQL has no
  `ADD INDEX IF NOT EXISTS` — **reuse the v4.15.1 PREPARE/EXECUTE pattern**).
- **Composite (multi-column) indexes = fast-follow → card #331.**
- **Retro-fit approach (a):** new models get indexes going forward + an
  **opt-in, throttled backfill** for existing tables (CronWorker-driven,
  off-peak — PK/id-range, never an unbounded scan; ETOM burst-credit lesson).
  **⚠️ Per-deploy backfill:** once the feature ships, run the backfill for
  EACH install (dev + ORB/ORT + ETOM + TBG) together as a deliberate off-peak
  op. Do not forget.

### 6.3 Pagination guardrails (P1) — grounded in industry research

No mainstream API returns unbounded results by default (Stripe default 10/max
100; GitHub 30/100; Shopify 50/250; DRF configurable + `max_page_size`). Kyte's
"no page header ⇒ whole table" is the outlier.

- **The `page_num=0` behavior is load-bearing internally** (framework
  `Model::retrieve` calls legitimately want all rows) → the guardrail lives at
  the **controller/HTTP layer**, NOT the model layer. `Model::retrieve` stays
  unbounded-capable.
- **`KYTE_MAX_PAGE_SIZE = 100`** (configurable) — clamp `X-Kyte-Page-Size`.
  Matches Stripe/GitHub.
- **Backstop ceiling + truncation logging** on unbounded HTTP list requests —
  set generously at first, **measure who depends on unbounded, then tighten**
  (don't guess the number today). "No silent full-table scans."
- **Explicit "return all" opt-in** for legit bulk (e.g. dropdown loads)
  short-term. Keep internal `Model::retrieve` unbounded.
- **End state:** paginate-by-default like the industry (Kyte's existing
  `PAGE_SIZE = 50` is a textbook default) via a measured migration.
- **Cursor/keyset pagination** = future perf item (offset degrades at depth).

### 6.4 Dropdowns / large FK pickers (kyte-api-js) — card #333

Industry threshold ~100 options: below ⇒ load-all plain select (keep simple);
above ⇒ **async server-side typeahead** (debounced 200–400ms, min-char trigger,
small paginated+projected result, virtualized render, per-query cache, stale-
response guard). Composes the #190 work directly (projection → `{id,label}`;
pagination cap; existing search; FK-auto-index makes it fast). No new server
primitives — `KyteForm::reloadAjax` switches from load-all to typeahead above a
configurable threshold.

### 6.5 Deferred columns (P2) — card #338

**Opt-in, not blanket auto-by-type** (blanket = behavioral change / silent
breakage, same principle as pagination). A `deferred` flag on `ModelAttribute`
excludes that column from default list reads (via the projection engine),
`load()`ed on demand. Surface in Shipyard + the #325 MCP tools. **Plus** an
**off-by-default** global `KYTE_AUTO_DEFER_HUGE` that auto-defers only the two
extreme types (`lt`/`lb`) as a per-install safety valve.

### 6.6 Query-perf wins ("hood's open")

- **Double `COUNT` per list → card #339.** `Model::retrieve` runs two counts
  (`total` + `total_filtered`) every list. Make skippable; check whether the
  unfiltered total is even consumed; long-term `has_more`/cursor.
- **FK-expansion N+1 audit → card #340 (high-pri, refs #171).** Confirm the
  generic `ModelController::get` actually uses `Model::with()` eager-loading;
  if not, every list is silently N+1. Potentially the biggest hidden win.
- **Batch `Model::loadColumn()` → card #341.** Load a deferred column for a
  whole result set in one `WHERE id IN (...)` query (complements
  `ModelObject::load()`), avoiding N+1 when a deferred column is needed
  list-wide.

### 6.7 New cards from this session

#331 composite indexes · #332 nested FK projection · #333 KyteForm typeahead ·
#338 deferred-column flag · #339 count optimization · #340 N+1 eager-load audit
· #341 batch `loadColumn`.
