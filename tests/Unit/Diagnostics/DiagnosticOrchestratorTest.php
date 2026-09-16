<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tests\Unit\Diagnostics;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\InvFlux\Diagnostics\DiagnosticCheck;
use Nandan108\InvFlux\Diagnostics\DiagnosticResult;
use Nandan108\InvFlux\Diagnostics\DiagnosticStatus;
use Nandan108\InvFlux\Exceptions\ConfigurationException;
use Nandan108\InvFlux\Storage\Mysql\Diagnostics\DiagnosticOrchestrator;
use Nandan108\InvFlux\Storage\Mysql\Session\MysqlSession;
use PHPUnit\Framework\TestCase;

final class DiagnosticOrchestratorTest extends TestCase
{
    public function testRegisterIssuesNoDatabaseWrites(): void
    {
        $session = new StubMysqlSession();
        $orchestrator = new DiagnosticOrchestrator($session, new Connection($session, new MysqlDialect()), 'wp_');

        $orchestrator->register($this->makeCheck('woo_stock_sync'));

        self::assertSame([], $session->allCalls(), 'register() must be pure in-memory wiring');
    }

    public function testInstallSeedsScheduleRowsWithoutCreatingTables(): void
    {
        $session = new StubMysqlSession();
        $orchestrator = new DiagnosticOrchestrator($session, new Connection($session, new MysqlDialect()), 'wp_');
        $orchestrator->register($this->makeCheck('woo_stock_sync'));
        $orchestrator->register($this->makeCheck('layer_totals'));

        $orchestrator->install();

        $sql = array_map(static fn (array $call): string => $call['sql'], $session->allCalls());

        // The tables are converged like every other managed table; install() only fills them.
        self::assertSame(
            [],
            array_filter($sql, static fn (string $s): bool => str_contains($s, 'CREATE TABLE')),
            'install() must not emit DDL',
        );

        // One insert-or-ignore for both rows, not a statement per check.
        $seeds = array_values(array_filter(
            $sql,
            static fn (string $s): bool => str_contains($s, 'INSERT') && str_contains($s, 'invflux_diagnostic_schedule'),
        ));
        self::assertCount(1, $seeds, 'both rows seed in one statement');
        $boundValues = array_merge(...array_map(
            static fn (array $call): array => array_values($call['params'] ?? []),
            $session->allCalls(),
        ));
        self::assertContains('woo_stock_sync', $boundValues, 'both checks are bound into the one statement');
        self::assertContains('layer_totals', $boundValues);
    }

    public function testInstallWithNoChecksOnlyCreatesTables(): void
    {
        $session = new StubMysqlSession();
        $orchestrator = new DiagnosticOrchestrator($session, new Connection($session, new MysqlDialect()), 'wp_');

        $orchestrator->install();

        // Filter-then-assert rather than assert-inside-a-loop: with no registered checks the
        // orchestrator may issue no session calls at all, in which case a per-call assertion runs
        // zero times. phpunit reports that as risky (failOnRisky="true" here, so it fails the
        // build) and, worse, the test silently stops checking anything.
        $seeds = array_values(array_filter(
            array_column($session->allCalls(), 'sql'),
            static fn (string $sql): bool => str_contains($sql, 'INSERT IGNORE'),
        ));

        self::assertSame([], $seeds, 'No schedule seed without registered checks');
    }

    public function testLatestResultsByCheckReturnsEmptyWithNoResults(): void
    {
        $session = new StubMysqlSession();
        $orchestrator = new DiagnosticOrchestrator($session, new Connection($session, new MysqlDialect()), 'wp_');
        $check = $this->makeCheck('woo_stock_sync');
        $orchestrator->register($check);

        $results = $orchestrator->latestResultsByCheck();

        self::assertSame([], $results);
    }

    public function testLatestResultsByCheckReturnsDecodedRow(): void
    {
        $session = new StubMysqlSession();
        $session->fetchAllRows = [[
            'check_key'     => 'woo_stock_sync',
            'status'        => 'ok',
            'ran_at'        => '2025-06-01 12:00:00.000000',
            'findings_json' => '[]',
            'duration_ms'   => 42,
        ]];
        $orchestrator = new DiagnosticOrchestrator($session, new Connection($session, new MysqlDialect()), 'wp_');
        $check = $this->makeCheck('woo_stock_sync');
        $orchestrator->register($check);

        $results = $orchestrator->latestResultsByCheck();

        self::assertCount(1, $results);
        self::assertSame('woo_stock_sync', $results[0]['check_key']);
        self::assertSame('ok', $results[0]['status']);
        self::assertSame([], $results[0]['findings']);
        self::assertSame(42, $results[0]['duration_ms']);
    }

    public function testRunCheckByKeyThrowsForUnknownKey(): void
    {
        $session = new StubMysqlSession();
        $orchestrator = new DiagnosticOrchestrator($session, new Connection($session, new MysqlDialect()), 'wp_');

        $this->expectException(ConfigurationException::class);
        $orchestrator->runCheckByKey('nonexistent');
    }

    public function testRunCheckByKeyRunsAndReturnsResult(): void
    {
        $session = new StubMysqlSession();
        // Hydrated into a DiagnosticSchedule, so the stub row needs every column the Record declares.
        $session->fetchOneRow = [
            'check_key'         => 'sample_check',
            'enabled'           => 1,
            'frequency_seconds' => 3600,
            'last_run_at'       => null,
            'next_run_at'       => '2026-07-29 00:00:00.000000',
            'updated_at'        => '2026-07-29 00:00:00.000000',
        ];
        $orchestrator = new DiagnosticOrchestrator($session, new Connection($session, new MysqlDialect()), 'wp_');
        $check = $this->makeCheck('sample_check', DiagnosticStatus::Ok);
        $orchestrator->register($check);

        $result = $orchestrator->runCheckByKey('sample_check');

        self::assertSame(DiagnosticStatus::Ok, $result->status);
    }

    public function testRunCheckByKeyPersistsResult(): void
    {
        $session = new StubMysqlSession();
        $session->fetchOneRow = ['frequency_seconds' => 3600];
        $orchestrator = new DiagnosticOrchestrator($session, new Connection($session, new MysqlDialect()), 'wp_');
        $check = $this->makeCheck('sample_check', DiagnosticStatus::Ok);
        $orchestrator->register($check);

        $orchestrator->runCheckByKey('sample_check');

        $insertCall = null;
        foreach ($session->allCalls() as $call) {
            if (str_contains($call['sql'], 'INSERT INTO') && str_contains($call['sql'], 'invflux_diagnostic_results')) {
                $insertCall = $call;
                break;
            }
        }
        self::assertNotNull($insertCall, 'Expected an INSERT into diagnostic_results');
        // attrecord binds positionally, so assert on the bound values rather than named keys.
        self::assertContains('sample_check', $insertCall['params']);
        self::assertContains(DiagnosticStatus::Ok->value, $insertCall['params']);
    }

    private function makeCheck(string $key, DiagnosticStatus $status = DiagnosticStatus::Ok): DiagnosticCheck
    {
        return new class($key, $status) implements DiagnosticCheck {
            public function __construct(
                private readonly string $checkKey,
                private readonly DiagnosticStatus $checkStatus,
            ) {
            }

            #[\Override]
            public function key(): string
            {
                return $this->checkKey;
            }

            #[\Override]
            public function defaultFrequencySeconds(): int
            {
                return 3600;
            }

            #[\Override]
            public function run(): DiagnosticResult
            {
                return new DiagnosticResult($this->checkStatus, [], 1);
            }

            #[\Override]
            public function repair(DiagnosticResult $result): ?DiagnosticResult
            {
                return null;
            }
        };
    }
}

final class StubMysqlSession implements MysqlSession
{
    /** @var list<array{sql: string, params: array<array-key, scalar|null>}> */
    private array $calls = [];

    /** @var array<string, scalar|null>|null */
    public ?array $fetchOneRow = null;

    /** @var list<array<string, scalar|null>> */
    public array $fetchAllRows = [];

    /**
     * @return list<array{sql: string, params: array<array-key, scalar|null>}>
     */
    public function allCalls(): array
    {
        return $this->calls;
    }

    #[\Override]
    public function exec(string $sql, array $params = []): int
    {
        $this->calls[] = ['sql' => $sql, 'params' => $params];

        return 1;
    }

    #[\Override]
    public function fetchAll(string $sql, array $params = []): array
    {
        $this->calls[] = ['sql' => $sql, 'params' => $params];

        return $this->fetchAllRows;
    }

    #[\Override]
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $this->calls[] = ['sql' => $sql, 'params' => $params];

        return $this->fetchOneRow;
    }

    #[\Override]
    public function fetchScalar(string $sql, array $params = []): string | int | float | null
    {
        $this->calls[] = ['sql' => $sql, 'params' => $params];

        return null;
    }

    #[\Override]
    public function lastInsertId(): string | int
    {
        return 0;
    }

    #[\Override]
    public function transactional(\Closure $operation): mixed
    {
        return $operation();
    }

    #[\Override]
    public function withAdvisoryLock(string $lockName, int $timeoutSeconds, \Closure $callback): mixed
    {
        return $callback();
    }

    #[\Override]
    public function inTransaction(): bool
    {
        return false;
    }

    #[\Override]
    public function isDuplicateKeyError(\Throwable $throwable): bool
    {
        return false;
    }

    #[\Override]
    public function isRetryableTransactionError(\Throwable $throwable): bool
    {
        return false;
    }

    #[\Override]
    public function defaultCollation(): string
    {
        return 'utf8mb4_unicode_ci';
    }
}
