<?php
declare(strict_types=1);

namespace Clinica\Repositories;

final class SchemaRepository
{
    public function __construct(private \PDO $pdo) {}

    public function tables(string $schema): array
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema = :schema AND table_type = :type ORDER BY table_name");
            $statement->execute(['schema' => $schema, 'type' => 'BASE TABLE']);
            return $statement->fetchAll(\PDO::FETCH_COLUMN);
        } finally {
            $this->pdo->rollBack();
        }
    }
}
