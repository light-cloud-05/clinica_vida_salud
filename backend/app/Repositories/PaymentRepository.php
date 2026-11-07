<?php
declare(strict_types=1);
namespace Clinica\Repositories;
use Clinica\Middleware\PaymentAccess;
final class PaymentRepository
{
    public function __construct(private \PDO $pdo){}
    private function scope(array $actor):array
    {
        if(!PaymentAccess::view($actor)){throw new \DomainException('Consulta de pagos no autorizada.');}
        return $actor['role']==='Paciente' ? [' AND pa.id_usuario=:user',['user'=>$actor['id']]] : ['',[]];
    }
    public function listing(array $actor):array
    {
        [$scope,$params]=$this->scope($actor);
        $stmt=$this->pdo->prepare("SELECT p.id_pago,p.id_cita,p.importe,p.moneda,p.metodo,p.estado,p.fecha_confirmacion,p.referencia,c.codigo_cita,c.fecha,c.hora_inicio,c.estado AS cita_estado,r.estado AS reserva_estado,r.fecha_vencimiento,co.numero_comprobante FROM public.pagos p JOIN public.citas c ON c.id_cita=p.id_cita JOIN public.pacientes pa ON pa.id_paciente=c.id_paciente LEFT JOIN public.reservas_provisionales r ON r.id_cita=c.id_cita LEFT JOIN public.comprobantes_pago co ON co.id_pago=p.id_pago WHERE TRUE".$scope.' ORDER BY p.id_pago DESC LIMIT 100');$stmt->execute($params);return $stmt->fetchAll();
    }
    public function appointment(array $actor,int $id,bool $lock=false):?array
    {
        [$scope,$params]=$this->scope($actor);$params['id']=$id;
        $stmt=$this->pdo->prepare('SELECT c.id_cita,c.id_paciente,c.id_medico,c.id_especialidad,c.codigo_cita,c.fecha,c.hora_inicio,c.hora_fin,c.estado,c.fecha_registro,e.precio_consulta FROM public.citas c JOIN public.pacientes pa ON pa.id_paciente=c.id_paciente JOIN public.especialidades e ON e.id_especialidad=c.id_especialidad WHERE c.id_cita=:id'.$scope.($lock ? ' FOR UPDATE OF c' : ''));$stmt->execute($params);return $stmt->fetch() ?: null;
    }
    public function price(int $specialty):string
    {
        $stmt=$this->pdo->prepare('SELECT precio_consulta FROM public.especialidades WHERE id_especialidad=:id');$stmt->execute(['id'=>$specialty]);$price=$stmt->fetchColumn();if($price===false){throw new \InvalidArgumentException('Especialidad no disponible.');}return \Clinica\Services\PaymentPolicy::amount($price);
    }
    /** P5-B registra citas programadas; P9 solo las consulta, sin crear otra cita. */
    public function candidates(array $actor):array
    {
        [$scope,$params]=$this->scope($actor);
        if(\Clinica\Support\IntegrationGuard::active()){$scope.=' AND c.id_cita>:original_max';$params['original_max']=(int)\Clinica\Support\Environment::get('INTEGRATION_ORIGINAL_MAX_CITA');}
        $s=$this->pdo->prepare("SELECT c.id_cita,c.codigo_cita,c.fecha,c.hora_inicio,c.fecha_registro,e.precio_consulta FROM public.citas c JOIN public.pacientes pa ON pa.id_paciente=c.id_paciente JOIN public.especialidades e ON e.id_especialidad=c.id_especialidad WHERE c.estado='programada'".$scope.' ORDER BY c.fecha,c.hora_inicio LIMIT 100');
        $s->execute($params);return $s->fetchAll();
    }
    public function context(array $actor,int $id,\DateTimeImmutable $now):array
    {
        [$scope,$params]=$this->scope($actor);$params['id']=$id;
        $s=$this->pdo->prepare("SELECT c.*,pa.id_usuario AS owner,e.precio_consulta,r.estado AS reserva_estado,r.fecha_vencimiento FROM public.citas c JOIN public.pacientes pa ON pa.id_paciente=c.id_paciente JOIN public.especialidades e ON e.id_especialidad=c.id_especialidad LEFT JOIN public.reservas_provisionales r ON r.id_cita=c.id_cita WHERE c.id_cita=:id".$scope);
        $s->execute($params);$c=$s->fetch();
        if(!$c){throw new \DomainException('Cita fuera de su ámbito.');}
        $date=new \DateTimeImmutable($c['fecha'].' '.$c['hora_inicio'],new \DateTimeZone('America/La_Paz'));
        if($c['estado']!=='programada' || $date<=$now || ($c['reserva_estado']!==null && ($c['reserva_estado']!=='pendiente' || new \DateTimeImmutable($c['fecha_vencimiento'])<=$now))){throw new \Clinica\Services\BookingConflict('Cita o reserva no vigente.');}
        $s=$this->pdo->prepare("SELECT count(*) FROM public.pagos WHERE id_cita=:id AND estado NOT IN ('rechazado','cancelado')");$s->execute(['id'=>$id]);
        if((int)$s->fetchColumn()>0){throw new \Clinica\Services\BookingConflict('La cita ya tiene un pago activo.');}
        return ['appointment'=>$id,'code'=>$c['codigo_cita'],'medico'=>(int)$c['id_medico'],'especialidad'=>(int)$c['id_especialidad'],'fecha'=>$c['fecha'],'hora'=>$c['hora_inicio'],'end'=>$c['hora_fin'],'owner'=>(int)$c['owner'],'paciente'=>(int)$c['id_paciente'],'amount'=>\Clinica\Services\PaymentPolicy::amount($c['precio_consulta']),'created'=>$c['fecha_registro'],'reserve_deadline'=>$c['fecha_vencimiento']];
    }
    public function receipt(array $actor,int $id):?array
    {
        if(!PaymentAccess::receipt($actor)){throw new \DomainException('Comprobante no autorizado.');}[$scope,$params]=$this->scope($actor);$params['id']=$id;
        $stmt=$this->pdo->prepare("SELECT p.id_pago,p.importe,p.moneda,p.metodo,p.estado,p.fecha_confirmacion,p.referencia,c.codigo_cita,c.fecha,c.hora_inicio,co.numero_comprobante FROM public.pagos p JOIN public.citas c ON c.id_cita=p.id_cita JOIN public.pacientes pa ON pa.id_paciente=c.id_paciente JOIN public.comprobantes_pago co ON co.id_pago=p.id_pago WHERE p.id_pago=:id AND p.estado='confirmado'".$scope);$stmt->execute($params);return $stmt->fetch() ?: null;
    }
}
