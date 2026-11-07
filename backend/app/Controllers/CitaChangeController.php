<?php
declare(strict_types=1);
namespace Clinica\Controllers;
use Clinica\Middleware\{BookingAccess,CitaAccess};
use Clinica\Services\{BookingConflict,CitaChangePolicy,SessionService,ScheduleValidator};
use Clinica\Support\{CitaChangeFactory,Input};
final class CitaChangeController
{
    public function rescheduleForm(array $user):void{$this->form($user,'reprogramar');}
    public function cancelForm(array $user):void{$this->form($user,'cancelar');}
    public function reschedule(array $user):void{$this->store($user,'reprogramar');}
    public function cancel(array $user):void{$this->store($user,'cancelar');}
    private function guard(array $user):void{if(!BookingAccess::allowed($user)){throw new \DomainException('No tiene permiso para modificar citas.');}}
    private function form(array $user,string $action):void
    {
        try{
            $this->guard($user);$id=Input::positiveId($_GET['id'] ?? null);if($id===null){throw new \InvalidArgumentException('Identificador inválido.');}
            $repo=CitaChangeFactory::reader();$appointment=$repo->find($user,$id);
            if(!$appointment){$this->error($user,404,'La cita no está disponible dentro de su ámbito.');return;}
            CitaChangePolicy::check($appointment,$action,new \DateTimeImmutable('now',new \DateTimeZone('America/La_Paz')));
            $date=$_GET['fecha'] ?? (new \DateTimeImmutable('now',new \DateTimeZone('America/La_Paz')))->format('Y-m-d');
            if(!ScheduleValidator::date($date)){throw new \InvalidArgumentException('Fecha inválida.');}
            $availability=$action==='reprogramar' ? $repo->availability($user,$appointment,$date) : null;
            $version=CitaChangePolicy::version($appointment);$enabled=CitaChangeFactory::enabled();$title=$action==='reprogramar' ? 'Reprogramar cita' : 'Cancelar cita';
            $this->render($user,'change',compact('appointment','action','date','availability','version','enabled','title'));
        }catch(\DomainException $e){$this->error($user,403,$e->getMessage());}catch(\InvalidArgumentException $e){$this->error($user,422,$e->getMessage());}catch(BookingConflict $e){$this->error($user,409,$e->getMessage());}
    }
    private function store(array $user,string $action):void
    {
        try{
            $this->guard($user);
            if(!SessionService::validCsrf($_POST['csrf'] ?? null)){$this->error($user,419,'El formulario venció. Vuelva a abrir la cita.');return;}
            $input=CitaChangePolicy::input($user,$_POST,$action);
            $c=CitaChangeFactory::reader()->find($user,$input['id']);
            if(!$c){$this->error($user,404,'La cita no está disponible dentro de su ámbito.');return;}
            if(!hash_equals(CitaChangePolicy::version($c),$input['version'])){throw new BookingConflict('La cita cambió. Vuelva a abrir el formulario.');}
            CitaChangePolicy::check($c,$action,new \DateTimeImmutable('now',new \DateTimeZone('America/La_Paz')));
            if(!CitaChangeFactory::enabled()){$this->error($user,423,'El guardado está deshabilitado. No se reprogramó ni canceló ninguna cita.');return;}
            $id=CitaChangeFactory::writer()->change($user,$action,$_POST);
            $_SESSION['cita_change_notice']=['id'=>$id,'message'=>$action==='reprogramar' ? 'La cita se reprogramó correctamente.' : 'La cita se canceló correctamente; su historial se conserva.'];
            header('Location: '.CitaAccess::base($user).'/detalle?id='.$id,true,303);
        }catch(\DomainException $e){$this->error($user,403,$e->getMessage());}catch(\InvalidArgumentException $e){$this->error($user,422,$e->getMessage());}catch(BookingConflict $e){$this->error($user,409,$e->getMessage());}catch(\Throwable $e){$this->error($user,503,'No se pudo completar la operación. Revise el estado de la cita antes de reintentar.');}
    }
    private function error(array $user,int $status,string $message):void{http_response_code($status);$this->render($user,'booking_error',['title'=>'Revisar cambio de cita','message'=>$message]);}
    private function render(array $user,string $template,array $vars):void
    {
        extract($vars,EXTR_SKIP);$escape=static fn(mixed $v):string=>htmlspecialchars((string)($v ?? ''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$base=CitaAccess::base($user);$section=ltrim($base,'/');$sectionLabel='Gestión de citas';$success=null;$views=dirname(__DIR__,3).'/frontend/views/';
        require $views.'layouts/management-header.php';require $views.'citas/navigation.php';require $views.'citas/'.$template.'.php';require $views.'layouts/management-footer.php';
    }
}
