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
    public function receipt(array $actor,int $id):?array
    {
        if(!PaymentAccess::receipt($actor)){throw new \DomainException('Comprobante no autorizado.');}[$scope,$params]=$this->scope($actor);$params['id']=$id;
        $stmt=$this->pdo->prepare("SELECT p.id_pago,p.importe,p.moneda,p.metodo,p.estado,p.fecha_confirmacion,p.referencia,c.codigo_cita,c.fecha,c.hora_inicio,co.numero_comprobante FROM public.pagos p JOIN public.citas c ON c.id_cita=p.id_cita JOIN public.pacientes pa ON pa.id_paciente=c.id_paciente JOIN public.comprobantes_pago co ON co.id_pago=p.id_pago WHERE p.id_pago=:id AND p.estado='confirmado'".$scope);$stmt->execute($params);return $stmt->fetch() ?: null;
    }
}
