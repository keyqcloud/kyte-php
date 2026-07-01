# Data-Model Layer Optimization & Robustness — KYTE-#190

> **Status:** scoping (reconstructed 2026-07-01). The original scoping doc was
> never committed and was lost; this rebuilds it and is grounded in a fresh
> code recon of `master` (see "Current state", verified 2026-07-01).
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
