<?php
declare(strict_types=1);
namespace Clinica\Controllers;
use Clinica\Middleware\ScheduleAccess as Access;
use Clinica\Services\ScheduleValidator as V;
use Clinica\Services\ScheduleValidation;
use Clinica\Services\ScheduleConflict;
use Clinica\Services\SessionService;
use Clinica\Support\ScheduleFactory as Factory;
use Clinica\Support\Input;
final class HorarioController
{
    private function guard(array $user,string $operation='view'): bool
    {
        $allowed=match($operation){'horario'=>Access::schedules($user),'bloqueo'=>Access::blocks($user),default=>Access::view($user)};
        if(!$allowed){http_response_code(403);echo 'No tiene autorización para esta operación de horarios.';}return $allowed;
    }
    private function filters(array $user,bool $requiredDoctor=false): ?array
    {
        $service=Factory::service();$doctors=$service->doctors($user);$specialties=$service->specialties();$doctorFilter=null;$specialtyFilter=null;$dayFilter=null;
        foreach(['medico'=>&$doctorFilter,'especialidad'=>&$specialtyFilter,'dia'=>&$dayFilter] as $key=>&$target){
            $value=$_GET[$key] ?? '';if($value===''){continue;}$target=Input::positiveId($value);if($target===null){http_response_code(400);echo 'Filtros inválidos.';return null;}
        }unset($target);
        if($requiredDoctor && $doctorFilter===null){http_response_code(400);echo 'Seleccione un médico.';return null;}
        if($dayFilter!==null && !isset(V::DAYS[$dayFilter])){http_response_code(400);echo 'Día inválido.';return null;}
        if($doctorFilter!==null && !in_array($doctorFilter,array_map(static fn(array $m): int=>(int)$m['id_medico'],$doctors),true)){http_response_code(Access::ownOnly($user) ? 403 : 404);echo 'Médico fuera de su ámbito o inexistente.';return null;}
        if($specialtyFilter!==null && !in_array($specialtyFilter,array_map(static fn(array $e): int=>(int)$e['id_especialidad'],$specialties),true)){http_response_code(404);echo 'Especialidad no encontrada.';return null;}
        return compact('doctors','specialties','doctorFilter','specialtyFilter','dayFilter');
    }
    public function index(array $user): void{$this->catalog($user,'index');}
    public function show(array $user): void{$this->catalog($user,'detail');}
    public function blocks(array $user): void{$this->catalog($user,'blocks');}
    private function catalog(array $user,string $template): void
    {
        if(!$this->guard($user) || ($filters=$this->filters($user,$template==='detail'))===null){return;}
        $catalog=Factory::service()->catalog($user,$filters['doctorFilter'],$filters['specialtyFilter'],$filters['dayFilter']);
        $title=match($template){'detail'=>'Horarios del médico','blocks'=>'Bloqueos de disponibilidad',default=>'Horarios médicos'};
        $this->render($template,$filters+compact('user','title','catalog'));
    }
    public function agenda(array $user): void
    {
        if(!$this->guard($user) || ($filters=$this->filters($user))===null){return;}
        $weekDate=$_GET['semana'] ?? (new \DateTimeImmutable('now',new \DateTimeZone('America/La_Paz')))->format('Y-m-d');
        $date=V::date($weekDate);if(!$date){http_response_code(400);echo 'Fecha de semana inválida.';return;}
        $monday=$date->modify('-'.((int)$date->format('N')-1).' days');
        if(!V::date($monday->format('Y-m-d')) || !V::date($monday->modify('+6 days')->format('Y-m-d'))){http_response_code(400);echo 'Semana fuera del rango admitido.';return;}
        $catalog=Factory::service()->week($user,$filters['doctorFilter'],$filters['specialtyFilter'],$filters['dayFilter'],$weekDate);$title='Agenda semanal';
        $this->render('agenda',$filters+compact('user','title','catalog','weekDate'));
    }
    public function create(array $user): void{$this->form($user,'horario',false,false);}
    public function store(array $user): void{$this->form($user,'horario',false,true);}
    public function edit(array $user): void{$this->form($user,'horario',true,false);}
    public function update(array $user): void{$this->form($user,'horario',true,true);}
    public function createBlock(array $user): void{$this->form($user,'bloqueo',false,false);}
    public function storeBlock(array $user): void{$this->form($user,'bloqueo',false,true);}
    public function editBlock(array $user): void{$this->form($user,'bloqueo',true,false);}
    public function updateBlock(array $user): void{$this->form($user,'bloqueo',true,true);}
    private function form(array $user,string $kind,bool $editing,bool $posting): void
    {
        if(!$this->guard($user,$kind)){return;}
        if($posting && !SessionService::validCsrf($_POST['csrf'] ?? null)){http_response_code(419);echo 'El formulario venció. Recargue la página.';return;}
        $id=$editing ? Input::positiveId($_GET['id'] ?? null) : null;if($editing && $id===null){http_response_code(400);echo 'Identificador inválido.';return;}
        $service=Factory::service();$doctors=$service->doctors($user);$record=$editing ? $service->find($kind,$id,$user) : null;
        if($editing && !$record){http_response_code(404);echo 'Registro no encontrado dentro de su ámbito.';return;}
        $values=$record ?? array_fill_keys(V::fields($kind),'');
        if(!$editing){$values['id_medico']=$doctors[0]['id_medico'] ?? '';if($kind==='horario'){$values['dia_semana']=1;$values['estado']='activo';$values['duracion_cita_minutos']=30;}}
        $version=$record ? V::version($kind,$record) : '';$errors=[];$writesEnabled=Factory::writesEnabled();
        if($posting){
            $result=$service->validate($kind,$_POST,$id,$user);$values=$result['data'];$errors=$result['errors'];$version=is_string($_POST['version'] ?? null) ? $_POST['version'] : '';
            if($errors){http_response_code(422);}elseif(!$writesEnabled){http_response_code(423);$errors['_form']='El guardado real está bloqueado hasta autorizar pruebas controladas.';}
            else{try{$saved=Factory::service(true)->save($kind,$_POST,$id,$user);$_SESSION['schedule_success']='Los datos se guardaron correctamente.';header('Location: '.($kind==='horario' ? '/horarios' : '/bloqueos'),true,303);return;}
                catch(ScheduleValidation $e){http_response_code(422);$errors=$e->errors;}catch(ScheduleConflict $e){http_response_code(409);$errors['_form']=$e->getMessage();}catch(\Throwable $e){http_response_code(503);$errors['_form']='No se pudo completar el guardado. Inténtelo de nuevo.';}}
        }
        $title=($editing ? 'Editar ' : 'Crear ').($kind==='horario' ? 'horario de atención' : 'bloqueo');$this->render('form',compact('user','title','doctors','kind','editing','id','values','version','errors','writesEnabled'));
    }
    private function render(string $template,array $variables): void
    {
        extract($variables,EXTR_SKIP);$escape=static fn(mixed $value): string=>htmlspecialchars((string)($value ?? ''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $section='horarios';$sectionLabel='Horarios y disponibilidad';$success=$_SESSION['schedule_success'] ?? null;unset($_SESSION['schedule_success']);$views=dirname(__DIR__,3).'/frontend/views/';
        require $views.'layouts/management-header.php';require $views.'horarios/navigation.php';require $views.'horarios/'.$template.'.php';require $views.'layouts/management-footer.php';
    }
}
