<?php
declare(strict_types=1);
namespace Clinica\Services;
use Clinica\Repositories\CitaChangeStore;
final class CitaChangeService
{
    public function __construct(private CitaChangeStore $store,private bool $enabled=false){}
    public function change(array $actor,string $action,array $input):int
    {
        $data=CitaChangePolicy::input($actor,$input,$action);
        if(!$this->enabled){throw new \RuntimeException('Los cambios persistentes están deshabilitados.');}
        return $this->store->change($actor,$action,$data);
    }
}
