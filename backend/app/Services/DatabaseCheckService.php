<?php
declare(strict_types=1);

namespace Clinica\Services;

use Clinica\Repositories\Connection;
use Clinica\Repositories\SchemaRepository;

final class DatabaseCheckService
{
    public function check(array $config): array
    {
        $tables = (new SchemaRepository(Connection::readOnly($config)))->tables($config['schema']);
        return ['connection' => true, 'expected_count' => 21, 'table_count' => count($tables), 'matches_expected_count' => count($tables) === 21, 'tables' => $tables];
    }
}
