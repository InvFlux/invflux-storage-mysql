# InvFlux MySQL Storage

`nandan108/invflux-storage-mysql` is the MySQL/MariaDB persistence adapter for InvFlux.

It implements the contracts from `nandan108/invflux-core` and turns persist commands into strict, transactional updates over relational state and ledgers.

This package is not an ORM and not a DB-agnostic abstraction. It is a concrete write/read engine for InvFlux on MySQL-family databases.

## Licensing

InvFlux MySQL Storage is dual-licensed:

- `GPL-2.0-or-later` for open-source use under GPL-compatible terms
- a separate commercial license for proprietary or otherwise non-GPL-compatible use

See [LICENSE](LICENSE), [LICENSE-GPL-2.0-or-later](LICENSE-GPL-2.0-or-later), and [LICENSE-InvFlux-Commercial](LICENSE-InvFlux-Commercial).

## What It Implements

The adapter currently covers:

- schema bootstrap
- slot-space metadata persistence
- inventory state persistence
- inventory ledger persistence
- configuration state persistence
- configuration ledger persistence
- movement type and actor type registries
- guarded transactional writes
- in-transaction projection orchestration
- storage-backed flow execution

Main entry point:

- [`MysqlInventoryStore.php`](src/MysqlInventoryStore.php)

## Table Model

The adapter currently manages these core tables:

- `invflux_dimensions`
- `invflux_dimension_values`
- `invflux_slotspace`
- `invflux_inventory_state`
- `invflux_inventory_ledger`
- `invflux_config_state`
- `invflux_schema_ledger`
- `invflux_movement_types`
- `invflux_actor_types`

### Slot-space metadata

Dimensions and values are stored relationally.

`invflux_slotspace` stores concrete slots with one physical `dim_<name>` column per dimension, plus an `active` flag.

`invflux_config_state.active_dimensions_json` mirrors the currently active ordered dimensions as a snapshot. The authoritative source of truth remains the dimension tables.

### Inventory side

Authoritative inventory data is split into:

- `invflux_inventory_state` for current state
- `invflux_inventory_ledger` for append-only movement history

This adapter treats:

- state as current truth
- ledger as durable audit/history

### Config side

Schema configuration data mirrors the same state-plus-ledger shape:

- `invflux_config_state` for current schema state (dimensions, slots, hierarchy)
- `invflux_schema_ledger` for schema-change audit history (dimension lifecycle,
  scale changes, schema snapshots)

This symmetry is intentional. (Plugin settings, when needed, will live in a
separate `invflux_settings` table — not here.)

## Quantities and Scale

Quantities are stored as integers.

`invflux_config_state.quantity_scale` defines how those integers should be interpreted:

- `0` means plain integer quantities
- values above `0` mean fixed-scale minor units

The adapter supports:

- reading current scale
- setting scale before any data exists
- migrating scale transactionally

Scale decreases are rejected when precision would be lost.

This package enforces integer storage. Higher layers may still speak in `int|float`, but persisted quantities are normalized to integer minor units here.

## Transaction Model

The adapter uses one outer transaction boundary per logical write operation.

Internally, [`MysqlInventoryStore.php`](src/MysqlInventoryStore.php) coordinates this through `transactional()`:

- nested calls reuse the outer transaction
- only the outermost call commits or rolls back
- unknown runtime failures are normalized to `PersistenceException`

This adapter explicitly configures `PDO::ERRMODE_EXCEPTION` in the constructor and relies on exception-based transaction control flow.

This means:

- `beginTransaction()` and `commit()` are treated as throwing operations on failure
- rollback happens only when the outer transaction was not successfully committed
- nested write helpers can compose without trying to create independent DB transactions

## Write-Time Guards

Persist commands may carry resolved per-slot quantity guards:

- minimum allowed post-decrement quantity
- maximum allowed post-increment quantity

The adapter does not store or resolve business policy. Instead:

- the application/platform layer computes effective guards
- the adapter enforces them atomically at commit time

If any guarded row would violate its constraint:

- the entire write is rolled back
- no inventory ledger rows are written
- structured conflicts are returned to the caller

This keeps persistence strict while leaving retry, replanning, and manual review decisions above the storage layer.

## Write Path

The write path is centered around `persistDeltaRows()` in [`MysqlInventoryStore.php`](src/MysqlInventoryStore.php).

At a high level:

1. Resolve the persist command into exact `(subject_key, slot_id)` delta rows.
2. Stage those deltas in a temporary `MEMORY` table.
3. Insert missing zero-quantity rows into `invflux_inventory_state`.
4. Lock target inventory rows in deterministic `(subject_key, slot_id)` order.
5. Detect guard conflicts from locked current quantities plus staged deltas.
6. Abort and return conflicts if any row cannot be applied safely.
7. Apply all inventory deltas in one bulk `UPDATE ... JOIN`.
8. Run registered projection participants inside the same transaction.
9. Insert inventory ledger rows.
10. Commit.

This design keeps:

- authoritative locking on real inventory rows
- guard enforcement close to the commit point
- staging cheap
- partial writes impossible in normal conflict cases

## Storage-Backed Flow Execution

Beyond `persist()` and `persistBatch()`, the adapter also supports executing a flow directly from authoritative DB state through:

- `MysqlInventoryStore::executeBatchFlowFromStorage()`

This method accepts a core request object:

- [`StorageBatchFlowRequest`](../invflux/src/Mutation/StorageBatchFlowRequest.php)

At a high level it:

1. selects subjects from current DB state
2. loads active slot balances for those subjects
3. builds a `QuantityStateBatch`
4. executes a `string|Flow` through SlotFlow
5. persists the resulting batch through the normal guarded write path

This is useful for workflows where the storage adapter should derive the current working set itself, for example:

- reservations
- releases
- booking
- partial dispatch
- schema-driven state migration

### Quantity resolution

`StorageBatchFlowRequest` supports three quantity modes:

1. default
   - sum all matching selected quantity from loaded rows
2. explicit `quantitiesBySubject`
   - caller supplies requested quantity per subject
3. custom `quantityResolver`
   - caller derives requested quantity from loaded per-subject rows

### executionContext vs ledgerContext

The request also splits:

- `executionContext`
  - passed to SlotFlow execution only
- `ledgerContext`
  - persisted onto resulting inventory-ledger rows

This avoids repeating large operation-level payloads on every resulting ledger row.

## Why a Temporary `MEMORY` Table?

The adapter uses a connection-scoped temporary `MEMORY` table for staged deltas because:

- it is only a computation/staging structure
- authoritative correctness comes from the locked inventory rows
- it avoids per-row write loops
- it allows bulk insertion of missing rows and bulk application of deltas

Important detail:

- temporary tables are connection-scoped, not per-call

For that reason, the orchestrator clears the staging table before use and again in a `finally` block after use.

## Deterministic Locking

The adapter is designed around deterministic row locking.

Inventory rows are locked in `(subject_key, slot_id)` order before any authoritative update is applied.

This is central to:

- predictable concurrent behavior
- minimizing deadlock risk
- keeping projection participants aligned with the same transaction discipline

The adapter should be understood as striving for deterministic locking and safe retry behavior, not as promising that a relational database can never deadlock under any external interference.

## Schema Change Execution

Schema changes that need quantity movement are executed through the same shared batch persistence path rather than a separate stock-write implementation.

Current example:

- `drainAndDisableDimensionValue()`

The operation:

1. writes one `schema_ledger` event
2. executes a storage-backed flow over authoritative rows using the internal movement type `InvFlux:VALMIG`
3. writes the resulting inventory-ledger rows with `ref_id = NULL` (the
   originating `schema_ledger` row's INT id doesn't fit `inventory_ledger.ref_id`
   which is BINARY(16) UUID-only; the audit link lives on the `schema_ledger` row itself
   via dimension/source/target context)
4. disables the dimension value
5. syncs slotspace/config snapshots

Important detail:

- after a successful drain, zero-quantity source rows are deleted before the value is disabled

This keeps schema-driven quantity migration aligned with:

- the normal locking model
- projection participants
- the normal ledger path

## Projection Participants

The adapter supports trusted in-transaction projection participants registered through:

- `MysqlInventoryStore::registerProjectionParticipant()`

The SPI contract itself lives in the core package:

- `Nandan108\InvFlux\Projection\ProjectionParticipant`
- `Nandan108\InvFlux\Projection\ProjectionContext`
- `Nandan108\InvFlux\Projection\ProjectionLockTarget`

The MySQL adapter adds one backend-specific runtime helper:

- [`MysqlProjectionRuntime.php`](src/Projection/MysqlProjectionRuntime.php)

It is exposed through `ProjectionContext::$runtime` so participants can access backend-specific facilities such as the current `PDO` connection without making the SPI itself storage-specific.

### Projection lifecycle

Inside one successful write, the adapter does this:

1. build a `ProjectionContext` from the staged deltas and locked inventory rows
2. collect lock targets from participants
3. ask participants to lock those targets
4. apply authoritative inventory state changes
5. ask participants to apply their projection writes
6. insert inventory ledger rows
7. commit

If a participant throws during lock or apply:

- the transaction is rolled back
- authoritative inventory state remains unchanged

### Intended usage

Projection participants are meant for trusted first-party infrastructure components, not open marketplace hooks.

Typical examples:

- WooCommerce `_stock` projection
- denormalized operational read models
- compatibility mirrors for external systems

Duplicate projection participant keys are rejected at registration time.

## Adapter-Specific API Surface

These methods are meaningful MySQL-adapter features rather than core contract features:

- `registerProjectionParticipant(...)`
- `executeBatchFlowFromStorage(...)`

They build on core concepts, but their actual execution semantics are adapter-owned because they depend on:

- SQL row selection
- transaction orchestration
- deterministic locking
- projection runtime behavior

## Reference IDs and Exactness

`ref_id` values are stored as `BIGINT UNSIGNED`.

The adapter validates and preserves unsigned bigint-compatible digit strings exactly. It does not cast them through PHP `int`, which would silently corrupt values above `PHP_INT_MAX`.

This matters for:

- large platform ids
- cross-system references
- future-proof audit records

## Timestamp Semantics

Persist commands carry one stable resolved `recordedAt` value per logical operation.

The adapter reuses that same timestamp consistently across:

- projection context
- inventory ledger rows
- returned persist results

This avoids timestamp drift inside one persist call.

## Exceptions and Failure Semantics

Ordinary commit-time guard conflicts are represented as data in the returned persist result, not as thrown exceptions.

Exceptions are reserved for cases such as:

- invalid configuration
- unknown movement or actor types
- schema problems
- true persistence/runtime failures

This distinction is intentional:

- conflicts are part of expected concurrent behavior
- exceptions represent unexpected or invalid conditions

## Operational Assumptions

This adapter assumes:

- MySQL/MariaDB semantics with InnoDB-backed authoritative tables
- connection-scoped temporary tables
- row-level locking via `SELECT ... FOR UPDATE`
- `PDO::ERRMODE_EXCEPTION`

It does not try to hide those backend assumptions behind a fake DB-agnostic layer. They are part of how correctness is achieved here.

## Testing

The package is tested against a real MariaDB instance rather than only mocked unit scenarios.

The test suite currently covers:

- bootstrap
- schema/config state initialization
- quantity scale changes
- single and batch writes
- guard conflicts
- rollback paths
- projection participant behavior
- exact unsigned bigint reference ids
- stable operation timestamps

Run locally with:

```bash
composer test
```

Static analysis:

```bash
./vendor/bin/psalm --no-progress --no-cache --threads=1
```

## Intended Place in the Stack

Typical layering:

1. `invflux-core` defines contracts, schema types, results, and the projection SPI.
2. `invflux-storage-mysql` persists those contracts safely in MySQL/MariaDB.
3. Platform adapters such as WooCommerce resolve business policy, actors, references, and projection participants on top.

This package is the persistence engine for that middle layer.
