<?php
declare(strict_types=1);
namespace Clinica\Repositories;

interface ReportStore
{
    public function choices(): array;
    public function dashboard(array $filters): array;
}
