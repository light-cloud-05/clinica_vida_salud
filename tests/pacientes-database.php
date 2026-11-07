<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') {http_response_code(404);exit;}
require dirname(__DIR__).'/backend/bootstrap.php';
use Clinica\Repositories\Connection;
use Clinica\Repositories\PacienteRepository;
use Clinica\Services\PacienteService;
try {
    $pdo=Connection::readOnly(require dirname(__DIR__).'/backend/config/database.php');
    $pdo->beginTransaction();
    if($pdo->query('SELECT current_database()')->fetchColumn()!=='clinica_vida_salud' || $pdo->query('SHOW transaction_read_only')->fetchColumn()!=='on') {throw new RuntimeException();}
    $snapshot=static fn(): string=>hash('sha256',(string)$pdo->query('SELECT json_agg(p ORDER BY id_paciente)::text FROM public.pacientes p')->fetchColumn());
    $before=$snapshot(); $repository=new PacienteRepository($pdo);$service=new PacienteService($repository);
    $ids=[]; $first=null;
    for($p=1;$p<=3;$p++) {
        $page=$service->page('',$p);
        if($page['total']!==30 || count($page['items'])!==10) {throw new RuntimeException();}
        foreach($page['items'] as $row) {
            $ids[]=$row['id_paciente']; $first ??=$row;
            if(isset($row['alergias']) || isset($row['telefono']) || isset($row['fecha_nacimiento'])) {throw new RuntimeException();}
        }
    }
    if(count(array_unique($ids))!==30) {throw new RuntimeException();}
    echo "Listado PDO: 30 pacientes distintos, 3 páginas de 10; columnas mínimas.\n";
    $detail=$repository->find((int)$first['id_paciente']);
    if(!$detail || isset($detail['alergias']) || isset($detail['antecedentes_relevantes']) || isset($detail['contrasena_hash'])) {throw new RuntimeException();}
    foreach(['nombres','apellidos','documento_identidad'] as $field) {
        if($repository->count($first[$field])<1) {throw new RuntimeException();}
    }
    echo "Detalle administrativo y búsqueda por nombre/apellido/documento: correctos.\n";
    foreach(['%', '_', '!', "' OR 1=1 --"] as $term) {
        $stmt=$pdo->prepare('SELECT count(*) FROM public.pacientes WHERE strpos(lower(nombres),lower(:n))>0 OR strpos(lower(apellidos),lower(:a))>0 OR strpos(lower(documento_identidad),lower(:d))>0');
        $stmt->execute(['n'=>$term,'a'=>$term,'d'=>$term]);
        if($repository->count($term)!==(int)$stmt->fetchColumn()) {throw new RuntimeException();}
    }
    if($repository->count('__PACIENTE_NO_EXISTENTE_'.bin2hex(random_bytes(8)))!==0) {throw new RuntimeException();}
    $duplicates=$repository->duplicates($detail,null);
    if(!isset($duplicates['codigo_paciente'],$duplicates['documento_identidad']) || $repository->duplicates($detail,(int)$first['id_paciente'])!==[]) {throw new RuntimeException();}
    echo "Búsqueda literal parametrizada, cero resultados y duplicados/exclusión propia: correctos.\n";
    $permissions=$pdo->query("SELECT r.nombre FROM public.roles r JOIN public.rol_permiso rp ON rp.id_rol=r.id_rol JOIN public.permisos p ON p.id_permiso=rp.id_permiso WHERE p.nombre='gestionar_pacientes' ORDER BY r.nombre")->fetchAll(PDO::FETCH_COLUMN);
    if($permissions!==['Administrador','Recepcionista']) {throw new RuntimeException();}
    if($before!==$snapshot()) {throw new RuntimeException();}
    $pdo->rollBack();
    echo "Permisos reales: Administrador y Recepcionista. Pacientes sin cambios; solo SELECT y ROLLBACK.\n";
} catch(Throwable $e) {
    if(isset($pdo)&&$pdo->inTransaction()) {$pdo->rollBack();}
    fwrite(STDERR,"Falló la integración de pacientes. Detalles omitidos para proteger datos.\n");exit(1);
}
