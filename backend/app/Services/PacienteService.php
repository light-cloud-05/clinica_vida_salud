<?php
declare(strict_types=1);
namespace Clinica\Services;

use Clinica\Repositories\PatientStore;
use Clinica\Middleware\PatientAccess;

final class PacienteService
{
    public function __construct(private PatientStore $patients, private bool $writesEnabled=false) {}

    public function page(string $search, int $page): array
    {
        $total=$this->patients->count($search);
        $pages=max(1,(int)ceil($total/10));
        $page=max(1,min($page,$pages));
        return ['items'=>$this->patients->listing($search,10,($page-1)*10),'total'=>$total,'pages'=>$pages,'page'=>$page];
    }

    public function find(int $id): ?array
    {
        if($id<1) {throw new \InvalidArgumentException('Identificador inválido.');}
        return $this->patients->find($id);
    }

    public function save(array $input, ?int $id, array $actor): int
    {
        if(!PatientAccess::allowed($actor) || ($actor['id'] ?? 0)<1) {throw new \RuntimeException('Acceso no autorizado.');}
        if(!$this->writesEnabled) {throw new \RuntimeException('El guardado está deshabilitado.');}
        if($id!==null && $id<1) {throw new \InvalidArgumentException('Identificador inválido.');}
        $result=PatientValidator::validate($input);
        if($result['errors']) {throw new PatientValidation($result['errors']);}
        $version=$input['version'] ?? null;
        if($id!==null && (!is_string($version) || $version==='' || strlen($version)>100)) {throw new PatientValidation(['_form'=>'El formulario venció. Vuelva a abrirlo.']);}
        $errors=$this->patients->duplicates($result['data'],$id);
        if($errors) {throw new PatientValidation($errors);}
        return $this->patients->save($result['data'],$id,$id===null ? null : $version,(int)$actor['id']);
    }
}
