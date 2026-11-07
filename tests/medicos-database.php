<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/backend/bootstrap.php';
use Clinica\Repositories\Connection;
use Clinica\Repositories\MedicoRepository;
use Clinica\Services\MedicoService;
try{
    $pdo=Connection::readOnly(require dirname(__DIR__).'/backend/config/database.php');$pdo->beginTransaction();
    if($pdo->query('SELECT current_database()')->fetchColumn()!=='clinica_vida_salud' || $pdo->query('SHOW transaction_read_only')->fetchColumn()!=='on'){throw new RuntimeException();}
    $snapshot=static function() use($pdo): array{$out=[];foreach(['medicos','especialidades','medico_especialidad'] as $table){$out[$table]=hash('sha256',(string)$pdo->query("SELECT COALESCE(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text)::text,'[]') FROM public.$table t")->fetchColumn());}return $out;};
    $before=$snapshot();$repo=new MedicoRepository($pdo);$service=new MedicoService($repo);$ids=[];$first=null;
    foreach([1=>10,2=>8] as $p=>$expected){$page=$service->page('',$p,null);if($page['total']!==18 || count($page['items'])!==$expected){throw new RuntimeException();}foreach($page['items'] as $row){$ids[]=$row['id_medico'];$first ??=$row;if(isset($row['telefono'],$row['correo_contacto'])){throw new RuntimeException();}}}
    if(count(array_unique($ids))!==18){throw new RuntimeException();}
    echo "Listado real: 18 médicos distintos en páginas de 10 y 8.\n";
    $specialties=$repo->specialties();if(count($specialties)!==9){throw new RuntimeException();}
    foreach($specialties as $e){$filtered=$service->page('',1,(int)$e['id_especialidad']);if($filtered['total']!==(int)$e['medicos']){throw new RuntimeException();}}
    echo "Catálogo real: 9 especialidades; 9 filtros verificados sin duplicar médicos.\n";
    $detail=$repo->find('medico',(int)$first['id_medico']);if(!$detail || !$detail['especialidades'] || isset($detail['contrasena_hash'])){throw new RuntimeException();}
    foreach(['nombres','apellidos','codigo_medico','registro_profesional'] as $field){if($repo->countDoctors($first[$field],null)<1){throw new RuntimeException();}}
    foreach(['%','_','!',"' OR 1=1 --"] as $term){$stmt=$pdo->prepare('SELECT count(*) FROM public.medicos WHERE strpos(lower(nombres),lower(:n))>0 OR strpos(lower(apellidos),lower(:a))>0 OR strpos(lower(codigo_medico),lower(:c))>0 OR strpos(lower(registro_profesional),lower(:r))>0');$stmt->execute(['n'=>$term,'a'=>$term,'c'=>$term,'r'=>$term]);if($repo->countDoctors($term,null)!==(int)$stmt->fetchColumn()){throw new RuntimeException();}}
    if($repo->countDoctors('__NO_EXISTE_'.bin2hex(random_bytes(8)),null)!==0){throw new RuntimeException();}
    $duplicates=$repo->duplicates('medico',$detail,null);if(!isset($duplicates['codigo_medico'],$duplicates['registro_profesional']) || $repo->duplicates('medico',$detail,(int)$first['id_medico'])!==[]){throw new RuntimeException();}
    $specialty=$specialties[0];$normalized=['nombre'=>' '.mb_strtoupper($specialty['nombre'],'UTF-8').' '];
    if(!isset($repo->duplicates('especialidad',$normalized,null)['nombre']) || $repo->duplicates('especialidad',$normalized,(int)$specialty['id_especialidad'])!==[]){throw new RuntimeException();}
    echo "Detalle, búsqueda parametrizada y duplicados de médico/nombre normalizado: correctos.\n";
    $roles=$pdo->query("SELECT r.nombre FROM public.roles r JOIN public.rol_permiso rp ON rp.id_rol=r.id_rol JOIN public.permisos p ON p.id_permiso=rp.id_permiso WHERE p.nombre='gestionar_medicos' ORDER BY r.nombre")->fetchAll(PDO::FETCH_COLUMN);
    if($roles!==['Administrador'] || $before!==$snapshot()){throw new RuntimeException();}
    $associations=(int)$pdo->query('SELECT count(*) FROM public.medico_especialidad')->fetchColumn();
    $pdo->rollBack();echo "Permiso real: solo Administrador. $associations asociaciones existentes. P3 sin cambios; solo lectura.\n";
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction()){$pdo->rollBack();}fwrite(STDERR,"Falló la integración de P3. Detalles omitidos para proteger datos.\n");exit(1);}
