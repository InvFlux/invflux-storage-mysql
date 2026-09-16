<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Projection;

use Nandan108\InvFlux\Storage\Mysql\Session\MysqlSession;

final class MysqlProjectionRuntime
{
    public function __construct(
        public readonly MysqlSession $session,
    ) {
    }
}
