<?php
declare(strict_types=1);
namespace Clinica\Controllers;

use Clinica\Middleware\VoiceAccess;
use Clinica\Services\{SessionService,VoiceIntent};
use Clinica\Support\VoiceQueryFactory;

final class ConsultaVozController
{
    public function index(array $user):void
    {
        $this->render($user);
    }
    public function query(array $user):void
    {
        $text=$_POST['consulta'] ?? null;$date=$_POST['fecha'] ?? '';
        $input=is_string($text) && mb_strlen($text,'UTF-8')<=240 ? $text : '';
        $dateInput=is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) ? $date : '';
        $read=($_POST['leer'] ?? null)==='1';
        try{
            if(!VoiceAccess::allowed($user)){throw new \DomainException('Acceso no autorizado.');}
            if(!SessionService::validCsrf($_POST['csrf'] ?? null)){
                http_response_code(419);$this->render($user,$input,$dateInput,null,'El formulario venció. Recargue la página e intente nuevamente.',$read);return;
            }
            if(array_diff(array_keys($_POST),['consulta','fecha','leer','csrf'])){throw new \InvalidArgumentException('El formulario solo admite consulta, fecha opcional y controles de lectura.');}
            if(!is_string($date)){throw new \InvalidArgumentException('Fecha inválida.');}
            $command=VoiceIntent::parse($text);
            if(!VoiceAccess::query($user,$command['intent'])){throw new \DomainException('Su rol no tiene permiso para esa consulta.');}
            $answer=VoiceQueryFactory::service()->answer($user,$text,$date);
            $this->render($user,$input,$dateInput,$answer,null,$read);
        }catch(\DomainException $e){http_response_code(403);$this->render($user,$input,$dateInput,null,$e->getMessage(),$read);}
        catch(\InvalidArgumentException $e){http_response_code(422);$this->render($user,$input,$dateInput,null,$e->getMessage(),$read);}
        catch(\Throwable $e){http_response_code(503);$this->render($user,$input,$dateInput,null,'No se pudo consultar PostgreSQL. No se generaron resultados. Intente nuevamente más tarde.',$read);}
    }
    private function render(array $user,string $input='',string $dateInput='',?array $answer=null,?string $error=null,bool $read=false):void
    {
        $escape=static fn(mixed $v):string=>htmlspecialchars((string)($v ?? ''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $title='Consultas por voz';$section='consultas-voz';$sectionLabel='Voz y texto · consultas autorizadas';$success=null;
        $views=dirname(__DIR__,3).'/frontend/views/';
        require $views.'layouts/management-header.php';
        require $views.'consultas-voz/index.php';
        require $views.'layouts/management-footer.php';
    }
}
