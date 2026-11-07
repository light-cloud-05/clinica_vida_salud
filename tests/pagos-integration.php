<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/bootstrap.php';
use Clinica\Repositories\PaymentRepository;
use Clinica\Repositories\PaymentDemoStore;
use Clinica\Services\PaymentDemoService;
use Clinica\Services\BookingConflict;
final class PaymentContextPdo extends PDO {
    public array $queries=[];
    public bool $paid=false;
    public array $row=['id_cita'=>101,'codigo_cita'=>'CVS-P5B','id_paciente'=>1,'owner'=>22,'id_medico'=>3,'id_especialidad'=>4,'fecha'=>'2026-11-09','hora_inicio'=>'16:00:00','hora_fin'=>'16:45:00','estado'=>'programada','fecha_registro'=>'2026-11-09T08:00:00-04:00','precio_consulta'=>'37.50','reserva_estado'=>null,'fecha_vencimiento'=>null];
    public function __construct(){}
    public function prepare(string $query,array $options=[]):PDOStatement|false {if(!str_starts_with($query,'SELECT')){throw new RuntimeException('Solo SELECT.');}return new PaymentContextStatement($this,$query);}
}
final class PaymentContextStatement extends PDOStatement {
    private array $params=[];
    public function __construct(private PaymentContextPdo $db,private string $sql){}
    public function execute(?array $params=null):bool {$this->params=$params ?? [];$this->db->queries[]=[$this->sql,$params];return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed {return isset($this->params['user']) && $this->params['user']!==$this->db->row['owner'] ? false : $this->db->row;}
    public function fetchColumn(int $column=0):mixed {return $this->db->paid ? 1 : 0;}
}
$n=0;$check=static function(bool $ok,string $message)use(&$n){if(!$ok){throw new RuntimeException($message);}++$n;};
$reject=static function(callable $call,string $class)use($check){try{$call();$check(false,'Debe rechazar');}catch(Throwable $e){$check($e instanceof $class,'Error esperado: '.$class);}};
$actor=['id'=>22,'role'=>'Paciente','permissions'=>['consultar_pagos_propios','pagar_cita_propia']];
$clock=new DateTimeImmutable('2026-11-09T08:00:00-04:00');$db=new PaymentContextPdo();$repo=new PaymentRepository($db);
$c=$repo->context($actor,101,$clock);
$check($c['owner']===22 && $c['paciente']===1,'Usuario distinto de paciente');
$check($c['amount']==='37.50' && $c['end']==='16:45:00','Precio y duración del servidor');
$path=tempnam(sys_get_temp_dir(),'p9-integration-');unlink($path);
try {
    $service=new PaymentDemoService(new PaymentDemoStore($path));
    $id=$service->create($actor,$c,$c['amount'],'efectivo',$clock,str_repeat('1',64));
    $r=$service->find($actor,$id);
    $check($r['appointment']===101 && $r['patient']===1 && $r['owner']===22 && $r['importe']==='37.50','P5-B integrado sin crear cita');
    $check($r['fecha_vencimiento']==='2026-11-09T13:00:00-04:00','Efectivo tres horas antes de la cita');
    $reject(fn()=>$service->create($actor,$c,$c['amount'],'qr_simulado',$clock,str_repeat('2',64)),BookingConflict::class);
    $foreign=$actor;$foreign['id']=99;
    $reject(fn()=>$repo->context($foreign,101,$clock),DomainException::class);
    $db->paid=true;$reject(fn()=>$repo->context($actor,101,$clock),BookingConflict::class);$db->paid=false;
    $db->row['estado']='cancelada';$reject(fn()=>$repo->context($actor,101,$clock),BookingConflict::class);$db->row['estado']='programada';
    $db->row['reserva_estado']='pendiente';$db->row['fecha_vencimiento']='2026-11-09T07:59:00-04:00';
    $reject(fn()=>$repo->context($actor,101,$clock),BookingConflict::class);
    $check(str_contains($db->queries[0][0],'pa.id_usuario=:user') && $db->queries[0][1]['user']===22,'Ámbito parametrizado por usuario');
} finally {@unlink($path);@unlink($path.'.lock');}
echo "$n comprobaciones integración P9/P5-B con PDO simulado; sin PostgreSQL.\n";
