<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Session;

use Nandan108\Attrecord\DbSession;
use Nandan108\InvFlux\Contracts\Inventory\TransactionalStore;

/**
 * Execute MySQL/MariaDB statements behind one connection/session abstraction.
 *
 * Also fulfils the core {@see TransactionalStore} contract — `transactional()` and
 * `withAdvisoryLock()` are already declared (matching signatures) on {@see DbSession}, so the
 * session doubles as the atomic-operation boundary handed to core application services (e.g.
 * AnnotationService) without a separate adapter.
 */
interface MysqlSession extends DbSession, TransactionalStore
{
    /**
     * Return the default collation for this connection's database (e.g. `utf8mb4_unicode_ci`).
     *
     * Used by installers to create InvFlux tables with the same collation as the host
     * database, preventing illegal-mix-of-collations errors on cross-table comparisons.
     */
    public function defaultCollation(): string;
}
