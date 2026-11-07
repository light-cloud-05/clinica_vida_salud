<?php
declare(strict_types=1);
namespace Clinica\Repositories;
interface BookingStore
{
    public function create(array $actor,array $input): array;
}
