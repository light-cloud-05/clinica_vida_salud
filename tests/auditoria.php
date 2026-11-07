<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/bootstrap.php';

use Clinica\Repositories\AuditStore;
use Clinica\Services\AuditFilters;
use Clinica\Services\AuditService;

$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok){throw new RuntimeException('FALLO P8: '.$label);}++$checks;};
$filters=AuditFilters::parse(['usuario'=>'2','fecha_inicio'=>'2026-11-01','fecha_fin'=>'2026-11-07','operacion'=>'UPDATE','entidad'=>'usuarios','pagina'=>'2']);
$check($filters['usuario']===2 && $filters['pagina']===2 && $filters['operacion']==='UPDATE','Filtros válidos');
foreach ([['usuario'=>'0'],['pagina'=>'x'],['fecha_inicio'=>'2026-02-30'],['fecha_inicio'=>'2026-11-08','fecha_fin'=>'2026-11-01'],['operacion'=>'DROP'],['entidad'=>str_repeat('a',81)]] as $invalid) {
    try { AuditFilters::parse($invalid); $check(false,'Rechazo de filtros inválidos'); } catch (InvalidArgumentException) { $check(true,'Rechazo de filtros inválidos'); }
}
$store=new class implements AuditStore {
    public function choices():array{return ['users'=>[['id_usuario'=>2,'nombre_usuario'=>'admin','rol'=>'Administrador']]];}
    public function count(array $filters):int{return 26;}
    public function listing(array $filters,int $limit,int $offset):array{return [['id_registro'=>1,'id_usuario'=>null,'fecha_hora'=>'2026-11-07 10:00','operacion'=>'UPDATE','entidad_afectada'=>'usuarios','id_entidad'=>1,'detalles'=>'trigger','nombre_usuario'=>null,'rol'=>null]];}
    public function find(int $id):?array{return $id===1?['id_registro'=>1,'id_usuario'=>null,'fecha_hora'=>'2026-11-07','operacion'=>'UPDATE','entidad_afectada'=>'usuarios','id_entidad'=>1,'detalles'=>'trigger','nombre_usuario'=>null,'rol'=>null]:null;}
    public function appointmentHistory(array $filters,int $limit):array{return [];}
};
$service=new AuditService($store);
$data=$service->page(['id'=>1,'role'=>'Administrador','permissions'=>['consultar_auditoria']],['usuario'=>'2']);
$check($data['total']===26 && $data['pages']===2 && $data['rows'][0]['nombre_usuario']===null,'Listado y paginación');
$check($service->detail(['id'=>1,'permissions'=>['consultar_auditoria']],1)['id_registro']===1,'Detalle autorizado');
try{$service->page(['id'=>1,'role'=>'Recepcionista','permissions'=>['consultar_historial_citas']],[]);$check(false,'Permiso exclusivo');}catch(DomainException){$check(true,'Permiso exclusivo');}
$routes=require dirname(__DIR__).'/backend/routes/web.php';
$check($routes['/auditoria']['permission']==='consultar_auditoria' && $routes['/auditoria/detalle']['permission']==='consultar_auditoria','Rutas protegidas');
echo "$checks comprobaciones P8 pasaron: filtros, autorización, paginación y detalle simulado.\n";
