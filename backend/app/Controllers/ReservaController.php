<?php
declare(strict_types=1);
namespace Clinica\Controllers;
use Clinica\Middleware\BookingAccess;
use Clinica\Middleware\CitaAccess;
use Clinica\Services\BookingInput;
use Clinica\Services\BookingIntent;
use Clinica\Services\BookingConflict;
use Clinica\Services\ScheduleValidator;
use Clinica\Services\SessionService;
use Clinica\Support\BookingFactory;
use Clinica\Support\CitaFactory;
use Clinica\Support\Input;
final class ReservaController
{
    private function guard(array $user): void{if(!BookingAccess::allowed($user)){throw new \DomainException('No tiene permiso para reservar citas.');}}
    public function form(array $user): void
    {
        try{
            $this->guard($user);$doctor=Input::positiveId($_GET['medico'] ?? null);$specialty=Input::positiveId($_GET['especialidad'] ?? null);$date=$_GET['fecha'] ?? null;$hour=ScheduleValidator::time($_GET['hora'] ?? null);
            if($doctor===null || $specialty===null || !ScheduleValidator::date($date) || $hour===null){header('Location: '.CitaAccess::base($user).'/disponibilidad',true,303);return;}
            $context=['medico'=>$doctor,'especialidad'=>$specialty,'fecha'=>$date,'hora'=>ScheduleValidator::formatTime($hour)];
            $service=CitaFactory::service();$availability=$service->availability($user,$doctor,$specialty,$date,$context['hora']);
            if(!$availability['selected']){throw new BookingConflict('La franja ya no está disponible. Consulte nuevamente antes de reservar.');}
            $choices=$service->availabilityChoices($user);$patients=$service->choices($user)['patients'];$doctorName='';$specialtyName='';
            foreach($choices['doctors'] as $m){if((int)$m['id_medico']===$doctor){$doctorName=$m['nombre'];}}
            foreach($choices['specialties'] as $e){if((int)$e['id_especialidad']===$specialty){$specialtyName=$e['nombre'];}}
            $intent=BookingIntent::issue($context);$enabled=BookingFactory::enabled();$title='Revisar y reservar cita';
            $this->render('booking',compact('user','title','context','availability','patients','doctorName','specialtyName','intent','enabled'));
        }catch(\DomainException $e){$this->error($user,403,$e->getMessage());}catch(\InvalidArgumentException $e){$this->error($user,422,$e->getMessage());}catch(BookingConflict $e){$this->error($user,409,$e->getMessage());}
    }
    public function store(array $user): void
    {
        try{
            $this->guard($user);
            if(!SessionService::validCsrf($_POST['csrf'] ?? null)){$this->error($user,419,'El formulario venció. Vuelva a consultar la franja.');return;}
            $input=BookingInput::parse($user,$_POST);$token=$_POST['booking_token'] ?? null;
            $existing=BookingIntent::check($token,$input);
            if($existing!==null){header('Location: '.CitaAccess::base($user).'/confirmacion?id='.$existing,true,303);return;}
            if(!BookingFactory::enabled()){$this->error($user,423,'El registro persistente de citas está deshabilitado hasta autorizar la prueba controlada.');return;}
            $saved=BookingFactory::service()->reserve($user,$_POST);
            BookingIntent::complete($token,$input,$saved['id']);
            header('Location: '.CitaAccess::base($user).'/confirmacion?id='.$saved['id'],true,303);
        }catch(\DomainException $e){$this->error($user,403,$e->getMessage());}catch(\InvalidArgumentException $e){$this->error($user,422,$e->getMessage());}catch(BookingConflict $e){$this->error($user,409,$e->getMessage());}catch(\Throwable $e){$this->error($user,503,'No se pudo completar la solicitud. Revise sus citas antes de volver a intentarlo.');}
    }
    public function confirmation(array $user): void
    {
        try{
            $this->guard($user);$id=Input::positiveId($_GET['id'] ?? null);if($id===null){throw new \InvalidArgumentException('Identificador inválido.');}
            $appointment=CitaFactory::service()->find($user,$id);if(!$appointment){$this->error($user,404,'La cita no está disponible dentro de su ámbito.');return;}
            $title='Cita registrada';$this->render('confirmation',compact('user','title','appointment'));
        }catch(\DomainException $e){$this->error($user,403,$e->getMessage());}catch(\InvalidArgumentException $e){$this->error($user,422,$e->getMessage());}
    }
    private function error(array $user,int $status,string $message): void
    {
        http_response_code($status);$title='Revisar solicitud de cita';$this->render('booking_error',compact('user','title','message'));
    }
    private function render(string $template,array $variables): void
    {
        extract($variables,EXTR_SKIP);$escape=static fn(mixed $v): string=>htmlspecialchars((string)($v ?? ''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$base=CitaAccess::base($user);$section=ltrim($base,'/');$sectionLabel='Reserva de citas';$success=null;$views=dirname(__DIR__,3).'/frontend/views/';
        require $views.'layouts/management-header.php';require $views.'citas/navigation.php';require $views.'citas/'.$template.'.php';require $views.'layouts/management-footer.php';
    }
}
