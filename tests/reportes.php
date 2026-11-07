<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/bootstrap.php';

use Clinica\Repositories\ReportStore;
use Clinica\Services\ReportExport;
use Clinica\Services\ReportFilters;
use Clinica\Services\ReportService;

$checks=0;
$check=static function(bool $ok,string $label)use(&$checks): void{if(!$ok){throw new RuntimeException('FALLO P6: '.$label);}++$checks;};
$filters=ReportFilters::parse(['fecha_inicio'=>'2026-11-01','fecha_fin'=>'2026-11-07','medico'=>'2','especialidad'=>'3','estado'=>'atendida']);
$check($filters['medico']===2 && $filters['estado']==='atendida','Filtros válidos');
foreach ([['fecha_inicio'=>'2026-02-30'],['fecha_inicio'=>'2026-11-08','fecha_fin'=>'2026-11-07'],['estado'=>'pendiente'],['medico'=>'0'],['medico'=>'1 OR 1=1']] as $invalid) {
    try { ReportFilters::parse($invalid); $check(false,'Rechazo de filtros'); } catch (InvalidArgumentException) { $check(true,'Rechazo de filtros inválidos'); }
}
$store=new class implements ReportStore {
    public int $calls=0;
    public function choices(): array{return ['doctors'=>[['id_medico'=>2,'nombre'=>'Médico']], 'specialties'=>[['id_especialidad'=>3,'nombre'=>'Cardiología']]];}
    public function dashboard(array $filters): array{$this->calls++;return ['appointments'=>[['codigo_cita'=>'C-1','fecha'=>'2026-11-07','hora_inicio'=>'09:00','hora_fin'=>'09:30','paciente_nombre'=>'Paciente','medico_nombre'=>'Médico','especialidad_nombre'=>'Cardiología','estado'=>'atendida']], 'doctors'=>[['medico_nombre'=>'Médico','especialidades'=>'Cardiología','total'=>1,'programadas'=>0,'confirmadas'=>0,'atendidas'=>1,'canceladas'=>0,'inasistencias'=>0]], 'specialties'=>[['nombre'=>'Cardiología','medicos'=>1,'citas'=>1]], 'attendance'=>['total'=>1,'atendidas'=>1,'inasistencias'=>0,'canceladas'=>0], 'summary'=>['pacientes'=>10,'medicos'=>1,'especialidades'=>1,'citas'=>1], 'workload'=>[['medico_nombre'=>'Médico','horas_semanales_programadas'=>10,'horas_programadas_periodo'=>2,'horas_ocupadas'=>.5,'horas_atendidas'=>.5,'horas_disponibles'=>1.5,'citas_programadas'=>1,'citas_atendidas'=>1]], 'daily'=>[], 'weekly'=>[], 'specialty_workload'=>[], 'blocks'=>[], 'conflicts'=>[], 'planning_range'=>['from'=>'2026-11-07','to'=>'2026-11-07'], 'absence_note'=>'Bloqueos, no ausencias.' ];}
};
$service=new ReportService($store);
$data=$service->dashboard(['id'=>1,'role'=>'Administrador','permissions'=>['generar_reportes']],['medico'=>'2','especialidad'=>'3']);
$check($store->calls===1 && $data['report']['attendance']['atendidas']===1,'Consulta de reporte autorizada');
try{$service->dashboard(['id'=>1,'role'=>'Recepcionista','permissions'=>['consultar_historial_citas']],[]);$check(false,'Permiso de generación obligatorio');}catch(DomainException){$check(true,'Permiso de generación obligatorio');}
$exported=$service->dashboard(['id'=>1,'role'=>'Recepcionista','permissions'=>['exportar_reportes']],[],'exportar_reportes');
$check($exported['report']['summary']['citas']===1,'Permiso de exportación independiente');
$csv=ReportExport::csv($data['report']);
$check(str_contains($csv,'C-1') && str_contains($csv,'Cardiología') && str_contains($csv,'CARGA HORARIA'),'Exportación CSV');
$pdf=ReportExport::pdf($data['report'],$data['filters']);
$check(str_starts_with($pdf,'%PDF-'),'Exportación PDF');
$routes=require dirname(__DIR__).'/backend/routes/web.php';
$check($routes['/reportes']['permission']==='generar_reportes' && $routes['/reportes/exportar']['permission']==='exportar_reportes','Permisos reales en rutas');
$_SESSION=['csrf'=>'test'];
$render=new ReflectionMethod(Clinica\Controllers\ReportController::class,'render');
$render->setAccessible(true);
ob_start();
$render->invoke(new Clinica\Controllers\ReportController(),'index',['user'=>['id'=>1,'role'=>'Administrador','permissions'=>['generar_reportes'],'username'=>'admin'],'title'=>'Reportes','data'=>['filters'=>['fecha_inicio'=>null,'fecha_fin'=>null,'medico'=>null,'especialidad'=>null,'estado'=>null],'choices'=>['doctors'=>[],'specialties'=>[]],'report'=>['appointments'=>[],'doctors'=>[],'specialties'=>[],'attendance'=>['total'=>0,'atendidas'=>0,'inasistencias'=>0,'canceladas'=>0],'summary'=>['pacientes'=>0,'medicos'=>0,'especialidades'=>0,'citas'=>0]]]]);
$html=ob_get_clean();
$check(str_contains($html,'Reportes administrativos') && str_contains($html,'Exportar CSV'),'Render HTML');
echo "$checks comprobaciones P6 pasaron: filtros, autorización, consultas simuladas y exportaciones.\n";
