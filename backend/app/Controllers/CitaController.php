<?php
declare(strict_types=1);
namespace Clinica\Controllers;
use Clinica\Middleware\CitaAccess;
use Clinica\Support\CitaFactory;
use Clinica\Support\Input;
use Clinica\Services\CitaFilters;
use Clinica\Services\ScheduleValidator;
final class CitaController
{
    private function run(callable $action): void
    {
        try{$action();}catch(\DomainException $e){http_response_code(403);echo 'Consulta fuera de su ámbito autorizado.';}
        catch(\InvalidArgumentException $e){http_response_code(400);echo htmlspecialchars($e->getMessage(),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    }
    public function index(array $user): void{$this->run(fn()=>$this->listing($user,false));}
    public function agenda(array $user): void{$this->run(fn()=>$this->listing($user,true));}
    private function listing(array $user,bool $agenda): void
    {
        $service=CitaFactory::service();$query=$_GET;
        if($agenda){$choices=$service->choices($user,true);$query['fecha']=$query['fecha'] ?? date('Y-m-d');$query['medico']=$query['medico'] ?? (isset($choices['doctors'][0]) ? (string)$choices['doctors'][0]['id_medico'] : '');}
        $result=$service->page($user,$query,$agenda);$title=$agenda ? 'Agenda diaria de citas' : (CitaAccess::personal($user) ? 'Mis citas médicas' : 'Consulta de citas');
        $this->render('index',compact('user','title','result','agenda'));
    }
    public function show(array $user): void
    {
        $this->run(function()use($user): void{
            $id=Input::positiveId($_GET['id'] ?? null);if($id===null){throw new \InvalidArgumentException('Identificador de cita inválido.');}
            $appointment=CitaFactory::service()->find($user,$id);if(!$appointment){http_response_code(404);echo 'Cita no encontrada dentro de su ámbito.';return;}
            $title='Detalle de la cita';$this->render('show',compact('user','title','appointment'));
        });
    }
    public function availability(array $user): void
    {
        $this->run(function()use($user): void{
            $service=CitaFactory::service();$choices=$service->availabilityChoices($user);$title='Consultar disponibilidad';$result=null;
            $doctor=$_GET['medico'] ?? '';$specialty=$_GET['especialidad'] ?? '';$date=$_GET['fecha'] ?? date('Y-m-d');$time=$_GET['hora'] ?? '';
            if(!ScheduleValidator::date($date) || !is_string($time) || ($time!=='' && ScheduleValidator::time($time)===null)){throw new \InvalidArgumentException('Fecha u hora inválida.');}
            if($doctor!=='' || $specialty!==''){
                $doctor=Input::positiveId($doctor);$specialty=Input::positiveId($specialty);
                if($doctor===null || $specialty===null){throw new \InvalidArgumentException('Seleccione médico y especialidad válidos.');}
                $result=$service->availability($user,$doctor,$specialty,$date,$time==='' ? null : $time);
            }
            $this->render('availability',compact('user','title','choices','doctor','specialty','date','time','result'));
        });
    }
    private function render(string $template,array $variables): void
    {
        extract($variables,EXTR_SKIP);$escape=static fn(mixed $value): string=>htmlspecialchars((string)($value ?? ''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $base=CitaAccess::base($user);$section=ltrim($base,'/');$sectionLabel='Citas médicas · Consulta';$success=null;$views=dirname(__DIR__,3).'/frontend/views/';
        require $views.'layouts/management-header.php';require $views.'citas/navigation.php';require $views.'citas/'.$template.'.php';require $views.'layouts/management-footer.php';
    }
}
