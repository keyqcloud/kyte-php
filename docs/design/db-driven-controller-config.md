# DB-Driven Controller Configuration

> **Status:** scoping (2026-07-05). A strategic initiative surfaced by KYTE-#190
> (the projection opt-in needs a per-controller toggle). Home for migrating
> controller behavior out of hardcoded `hook_init()` PHP into DB-driven,
> Shipyard-togglable flags — a pillar of the no-code direction.
>
> **Spans:** kyte-php (`ModelController` / `DataModel` / `Controller`),
> kyte-shipyard (model + controller settings UI).

## 1. Why

Today, changing how a controller behaves — require auth, expand FKs, cascade
deletes, page size, column projection — means **writing or editing PHP** in the
controller's `hook_init()`. That's a coding task, which cuts against Kyte's
no-code direction (Shipyard builds apps visually; models are DB-defined).

This initiative makes those behaviors **configurable from the Shipyard UI**,
stored in the DB, read at runtime — so an operator can toggle controller
behavior without touching code. It complements the MCP schema tools (#325,
Claude configures *models*) by letting the *UI* configure *controller behavior*.

The forcing function is **KYTE-#190 column projection**: it can't be on globally
(rich controllers whose response hooks read undisplayed columns break — e.g.
`KytePageController` reads `s3key` / `site.region` to build S3 links). It needs a
per-controller opt-in — which is exactly the first flag this initiative should
own.

## 2. Resolution chain (most-specific wins)

```
global default (config constant)
  -> DataModel.controller_config        (baseline; the generic/no-custom-controller
                                          path reads this, and it is the inheritable
                                          default for custom controllers)
    -> Controller.controller_config     (per-controller override — Controller.dataModel
                                          is a non-unique, optional FK, so N controllers
                                          can point at one model, each configured
                                          differently: e.g. a public catalog controller
                                          vs an admin controller on the same model)
      -> code override                  (a hand-written PHP controller can still force
                                          behavior in hook_init)
```

The effective config for a request = deep-merge of the layers present, in that
order.

## 3. Storage

- **`controller_config` JSON column on `Controller`** — primary, per-controller.
- **`controller_config` JSON column on `DataModel`** — the baseline the generic
  controller path uses (most simple user models have **no** `Controller` row) and
  the default custom controllers inherit.
- **Same JSON shape in both.** Greenfield JSON so adding a new flag needs no
  migration — just a new key + a Shipyard toggle.

```json
{
  "allow_projection": true,
  "require_account": false,
  "expand_fk": true,
  "max_page_size": 100
}
```

## 4. Flag set (migrate incrementally)

Controller-level (currently in `hook_init` / `ModelController` properties):

| flag | today |
|------|-------|
| **`allow_projection`** | KYTE-#190 — **deliverable #1** (default off) |
| `require_account` | `$this->requireAccount` |
| `require_auth` | `$this->requireAuth` |
| `expand_fk` | `$this->getFKTables` |
| `get_external_tables` | `$this->getExternalTables` |
| `cascade_delete` | `$this->cascadeDelete` |
| `fail_on_null` | `$this->failOnNull` |
| `dateformat` | `$this->dateformat` |
| `max_page_size` / `default_page_size` | per-model override of the #190 pagination constants |

Attribute-level (already carded, same DB-driven + Shipyard pattern — not part of
`controller_config`, but the same "config in DB, toggled in UI" philosophy):
`deferred` (#338), `indexed` / `unique` (#331, #190 index management).

## 5. Runtime wiring

- The generic controller resolves its effective `controller_config` at
  construction (alongside `model_definition`, and cached the same way) and sets
  its behavior properties from it — replacing the hardcoded defaults where a
  flag is present.
- Custom controllers still run `hook_init()`, so a code override always wins.
- No behavior change for any model that has no `controller_config` (every flag
  falls back to today's default).

## 6. Shipyard UI

- **Model settings**: the `DataModel` baseline toggles.
- **Controller settings**: per-controller toggles (for models with custom
  `Controller` rows).
- Rendered from a known flag schema (name, type, description, default) so new
  flags surface automatically.

## 7. Deliverable #1 — `allow_projection` (KYTE-#190)

Unblocks the client-driven column projection feature safely:
- Plain user model turns it **on** in model settings → the generic controller
  honors `X-Kyte-Fields` → large columns stay out of list reads (the OOM fix).
- A model with a public + an admin controller sets it **per controller**.
- Framework / rich controllers (`KytePage` etc.) stay **off** → full rows, safe.

Scope for #1: the `controller_config` column(s) + migration; generic-controller
resolution reading `allow_projection` into `$allowClientProjection`; the Shipyard
toggle. (The kyte-php opt-in flag already exists as a code default of `false` —
this initiative turns that default into a DB/UI-driven value.)

## 8. Related

KYTE-#190 (data-model layer — the forcing function), #325 (MCP schema tools —
Claude-side model config), #338 / #331 (attribute-level DB/UI flags). Part of the
broader no-code / low-code controller direction.
