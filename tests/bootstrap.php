<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

// Route this run at its own database when INVFLUX_TEST_LANE is set, so concurrent sessions stop
// interleaving their schema drops. No-op without it. See the class docblock.
Nandan108\InvFlux\Storage\Mysql\Tests\Support\TestDatabase::configure();

/** @psalm-suppress RiskyTruthyFalsyComparison */
if (PHP_SAPI === 'cli' && ($_SERVER['COLLISION_TESTING'] ?? false)) {
    /** @psalm-suppress InternalMethod */
    (new NunoMaduro\Collision\Provider())->register();
}
