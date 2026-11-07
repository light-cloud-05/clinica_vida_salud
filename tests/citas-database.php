<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/backend/bootstrap.php';
use Clinica\Repositories\Connection;
use Clinica\Repositories\CitaRepository;
use Clinica\Repositories\UserRepository;
use Clinica\Services\CitaService;
use Clinica\Services\CitaFilters;
use Clinica\Services\DisponibilidadService;
try{
    $pdo=Connection::readOnly(require dirname(__DIR__).'/backend/config/database.php');$pdo->beginTransaction();$repo=new CitaRepository($pdo);$service=new CitaService($repo);$users=new UserRepository($pdo);$actors=[];
    foreach(['usuario1','usuario2','profesional01','paciente01'] as $username){$row=$users->findByLogin($username);$actors[$row['rol']]=['id'=>(int)$row['id_usuario'],'role'=>$row['rol'],'permissions'=>$users->permissions((int)$row['id_rol'])];}
    $ids=[];$sample=null;foreach(range(1,6) as $p){$page=$service->page($actors['Administrador'],['pagina'=>(string)$p]);if($page['total']!==60 || count($page['items'])!==10){throw new RuntimeException();}foreach($page['items'] as $c){$ids[]=$c['id_cita'];$sample ??=$c;if(isset($c['motivo_consulta']) || isset($c['documento_identidad'])){throw new RuntimeException();}}}
    if(count(array_unique($ids))!==60){throw new RuntimeException();}echo "Listado real: 60 citas distintas, 6 páginas de 10.\n";
    foreach(['fecha'=>'fecha','medico'=>'id_medico','especialidad'=>'id_especialidad','paciente'=>'id_paciente','estado'=>'estado'] as $filter=>$column){$result=$service->page($actors['Administrador'],[$filter=>(string)$sample[$column]]);if($result['total']<1){throw new RuntimeException();}foreach($result['items'] as $c){if((string)$c[$column]!== (string)$sample[$column]){throw new RuntimeException();}}}
    if($service->page($actors['Administrador'],['q'=>$sample['codigo_cita']])['total']!==1 || $service->page($actors['Administrador'],['q'=>"' OR 1=1 --"])['total']!==0){throw new RuntimeException();}
    foreach(['Medico'=>'m','Paciente'=>'p'] as $role=>$alias){$actor=$actors[$role];$stmt=$pdo->prepare('SELECT count(*) FROM public.citas c JOIN public.medicos m ON m.id_medico=c.id_medico JOIN public.pacientes p ON p.id_paciente=c.id_paciente WHERE '.$alias.'.id_usuario=:id');$stmt->execute(['id'=>$actor['id']]);$expected=(int)$stmt->fetchColumn();$own=$service->page($actor,[]);if($own['total']!==$expected || $expected===0){throw new RuntimeException();}
        $stmt=$pdo->prepare('SELECT c.id_cita,c.id_paciente,c.id_medico FROM public.citas c JOIN public.medicos m ON m.id_medico=c.id_medico JOIN public.pacientes p ON p.id_paciente=c.id_paciente WHERE '.$alias.'.id_usuario<>:id OR '.$alias.'.id_usuario IS NULL ORDER BY c.id_cita LIMIT 1');$stmt->execute(['id'=>$actor['id']]);$foreign=$stmt->fetch();if(!$foreign || $repo->find($actor,(int)$foreign['id_cita'])!==null){throw new RuntimeException();}
        if($role==='Paciente' && $repo->count($actor,CitaFilters::parse(['paciente'=>(string)$foreign['id_paciente']]))!==0){throw new RuntimeException();}
        echo "$role: $expected citas propias; detalle ajeno rechazado dentro del SQL.\n";
    }
    $choices=$repo->availabilityChoices($actors['Paciente']);$doctor=$choices['doctors'][0];$specialty=(int)$doctor['especialidades'][0];$window=DisponibilidadService::window(new DateTimeImmutable('now',new DateTimeZone('America/La_Paz')));$availability=$service->availability($actors['Paciente'],(int)$doctor['id_medico'],$specialty,$window[1]);
    if(!$availability['slots'] || isset($availability['appointments']) || isset($availability['blocks'])){throw new RuntimeException();}
    $outside=$service->availability($actors['Paciente'],(int)$doctor['id_medico'],$specialty,'2030-11-04');if($outside['slots']!==[]){throw new RuntimeException();}
    $constraint=$pdo->query("SELECT pg_get_constraintdef(k.oid) FROM pg_constraint k WHERE k.conrelid='public.citas'::regclass AND k.contype='x'")->fetchColumn();if(!is_string($constraint) || !str_contains($constraint,'cancelada') || !str_contains($constraint,'[)')){throw new RuntimeException();}
    $pdo->rollBack();echo "Filtros, búsqueda, disponibilidad P4 y exclusión PostgreSQL verificados. Sin modificaciones.\n";
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction()){$pdo->rollBack();}fwrite(STDERR,"Falló la integración de citas. Detalles omitidos para proteger datos.\n");exit(1);}
