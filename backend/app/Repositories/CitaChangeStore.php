<?php
declare(strict_types=1);
namespace Clinica\Repositories;
interface CitaChangeStore
{
    public function change(array $actor,string $action,array $input):int;
}
