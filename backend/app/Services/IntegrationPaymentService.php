<?php
declare(strict_types=1);
namespace Clinica\Services;
use Clinica\Middleware\PaymentAccess;
use Clinica\Repositories\PaymentWriteRepository;
use Clinica\Support\{IntegrationGuard,Environment};

/** Adaptador exclusivo del clúster aislado: conserva la interfaz de demostración P9. */
final class IntegrationPaymentService
{
    public function __construct(private \PDO $pdo){IntegrationGuard::check($pdo);}
    private function writer(int $appointment):PaymentWriteRepository
    {
        IntegrationGuard::check($this->pdo,$appointment);putenv('PAYMENT_ALLOWED_APPOINTMENTS='.$appointment);
        $clock=Environment::get('INTEGRATION_CLOCK');
        return new PaymentWriteRepository($this->pdo,new PayPalSandboxService('','','',''),$clock==='' ? null : static fn()=>new \DateTimeImmutable($clock));
    }
    public function create(array $actor,array $context,string $amount,string $method,?\DateTimeImmutable $rehearsal,string $intent):string
    {
        if($rehearsal!==null){throw new \InvalidArgumentException('El reloj persistente de prueba se configura únicamente al iniciar el servidor aislado.');}
        if(!preg_match('/^[a-f0-9]{64}$/D',$intent)){throw new \InvalidArgumentException('Intención inválida.');}
        $id=$this->writer($context['appointment'])->start($actor,$context['appointment'],$method,'demo');
        return str_pad(dechex($id),32,'0',STR_PAD_LEFT);
    }
    public function listing(array $actor):array
    {
        IntegrationGuard::check($this->pdo);
        if(!PaymentAccess::view($actor)){throw new \DomainException('Consulta no autorizada.');}
        $params=['min'=>(int)Environment::get('INTEGRATION_ORIGINAL_MAX_CITA')];$scope='';
        if($actor['role']==='Paciente'){$scope=' AND pa.id_usuario=:user';$params['user']=$actor['id'];}
        $s=$this->pdo->prepare("SELECT p.*,c.id_paciente,c.id_medico,c.id_especialidad,c.codigo_cita,c.fecha,c.hora_inicio,c.hora_fin,c.estado AS cita_estado,pa.id_usuario AS owner,r.estado AS reserva_estado,r.fecha_vencimiento,co.numero_comprobante FROM public.pagos p JOIN public.citas c ON c.id_cita=p.id_cita JOIN public.pacientes pa ON pa.id_paciente=c.id_paciente JOIN public.reservas_provisionales r ON r.id_cita=c.id_cita LEFT JOIN public.comprobantes_pago co ON co.id_pago=p.id_pago WHERE c.id_cita>:min".$scope.' ORDER BY p.id_pago DESC LIMIT 100');$s->execute($params);$rows=[];
        foreach($s->fetchAll() as $r){$r['id']=str_pad(dechex((int)$r['id_pago']),32,'0',STR_PAD_LEFT);$r['appointment']=(int)$r['id_cita'];$r['source_code']=$r['codigo_cita'];$r['patient']=$r['id_paciente'];$r['medico']=$r['id_medico'];$r['especialidad']=$r['id_especialidad'];$r['persistent']=true;if(Environment::get('INTEGRATION_CLOCK')!==''){$r['demo_clock']=Environment::get('INTEGRATION_CLOCK');}$rows[]=$r;}return $rows;
    }
    public function find(array $actor,string $id):?array{foreach($this->listing($actor) as $r){if(hash_equals($r['id'],$id)){return $r;}}return null;}
    public function action(array $actor,string $id,string $action,?\DateTimeImmutable $clock=null):void
    {
        $row=$this->find($actor,$id);if(!$row){throw new \DomainException('Pago fuera de su ámbito.');}
        $writer=$this->writer($row['appointment']);
        if($action==='aprobar'){$writer->confirm($actor,$row['appointment'],(int)$row['id_pago']);return;}
        if($action==='rechazar'){$writer->reject($actor,$row['appointment'],(int)$row['id_pago']);return;}
        throw new \InvalidArgumentException('Acción no disponible en la prueba persistente.');
    }
    public function sandbox(array $actor,string $id,bool $capture,PayPalSandboxService $paypal):?string{throw new BookingConflict('El clúster aislado solo permite simulación local, sin proveedor externo.');}
}
