<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/bootstrap.php';
use Clinica\Repositories\CitaChangeRepository;
use Clinica\Services\{CitaChangePolicy,BookingConflict};
final class ChangePdoFake extends PDO
{
    public bool $active=false;public array $events=[];public array $queries=[];public ?string $failure=null;public bool $occupied=false;public bool $blocked=false;public int $duration=30;public bool $foreign=false;
    public array $c=['id_cita'=>1,'codigo_cita'=>'SIMULADA','id_paciente'=>1,'id_medico'=>1,'id_especialidad'=>1,'fecha'=>'2026-11-09','hora_inicio'=>'10:00:00','hora_fin'=>'10:30:00','estado'=>'programada','cantidad_reprogramaciones'=>0,'updated_at'=>'v1'];
    public function __construct(){}
    public function beginTransaction():bool{$this->events[]='begin';return $this->active=true;}
    public function inTransaction():bool{return $this->active;}
    public function commit():bool{$this->events[]='commit';$this->active=false;return true;}
    public function rollBack():bool{$this->events[]='rollback';$this->active=false;return true;}
    public function exec(string $statement):int|false{$this->events[]=$statement;return 0;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new ChangeStatementFake($this,$query);}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false{return new ChangeStatementFake($this,$query);}
}
final class ChangeStatementFake extends PDOStatement
{
    public function __construct(private ChangePdoFake $db,private string $sql){}
    public function execute(?array $params=null):bool{
        $this->db->queries[]=['sql'=>$this->sql,'params'=>$params];
        if(preg_match('/^(UPDATE|CALL)/',$this->sql)){
            $this->db->events[]='write';
            if($this->db->failure!==null){throw new class($this->db->failure) extends PDOException{public function __construct(string $code){parent::__construct('Simulado');$this->code=$code;}};}
        }return true;
    }
    public function fetchColumn(int $column=0):mixed{return str_contains($this->sql,'clock_timestamp') ? '2026-11-09 08:00:00' : 1;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return $this->db->foreign ? false : $this->db->c;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{
        if(str_starts_with($this->sql,'SELECT m.id_medico')){return [['id_medico'=>1,'nombres'=>'Médico','apellidos'=>'Simulado','estado'=>'activo','especialidades'=>'[1]']];}
        if(str_starts_with($this->sql,'SELECT id_especialidad')){return [['id_especialidad'=>1,'nombre'=>'Simulada']];}
        if(str_starts_with($this->sql,'SELECT id_horario')){return [['id_horario'=>1,'id_medico'=>1,'dia_semana'=>1,'hora_inicio'=>'08:00:00','hora_fin'=>'16:00:00','duracion_cita_minutos'=>$this->db->duration,'estado'=>'activo']];}
        if(str_starts_with($this->sql,'SELECT id_bloqueo') && $this->db->blocked){return [['id_medico'=>1,'fecha'=>'2026-11-09','hora_inicio'=>'11:00:00','hora_fin'=>'12:00:00']];}
        if(str_starts_with($this->sql,'SELECT id_medico,fecha') && $this->db->occupied){return [['id_medico'=>1,'fecha'=>'2026-11-09','hora_inicio'=>'11:00:00','hora_fin'=>'12:00:00','estado'=>'confirmada']];}
        return [];
    }
}
$n=0;$check=static function(bool $ok)use(&$n):void{if(!$ok){throw new RuntimeException('Fallo repositorio P5-C.');}++$n;};
$actor=['id'=>22,'role'=>'Paciente','permissions'=>['gestionar_citas_propias']];
$db=new ChangePdoFake();$input=['id'=>1,'version'=>CitaChangePolicy::version($db->c),'fecha'=>'2026-11-09','hora'=>'11:00:00','motivo'=>null];
$check((new CitaChangeRepository($db))->change($actor,'reprogramar',$input)===1 && end($db->events)==='commit');
$writes=array_values(array_filter($db->queries,static fn($q)=>str_starts_with($q['sql'],'UPDATE')));$check(count($writes)===1 && $writes[0]['params']['end']==='11:30:00' && !str_contains($writes[0]['sql'],'cantidad_reprogramaciones'));
$check((bool)array_filter($db->queries,static fn($q)=>str_contains($q['sql'],'FOR UPDATE OF c') && $q['params']['user']===22));
$check((bool)array_filter($db->queries,static fn($q)=>str_contains($q['sql'],'id_cita<>:id') && $q['params']['id']===1));
$check((bool)array_filter($db->queries,static fn($q)=>str_contains($q['sql'],"set_config('app.usuario_id'") && $q['params']['actor']==='22'));
$db=new ChangePdoFake();(new CitaChangeRepository($db))->change($actor,'cancelar',$input);$calls=array_values(array_filter($db->queries,static fn($q)=>str_starts_with($q['sql'],'CALL')));$check(count($calls)===1 && $calls[0]['params']===['id'=>1,'reason'=>null] && end($db->events)==='commit');
foreach(['occupied','blocked','foreign','stale','limit','past','atendida','outside','same'] as $scenario){
    $db=new ChangePdoFake();$data=$input;
    if(in_array($scenario,['occupied','blocked','foreign'],true)){$db->$scenario=true;}
    if($scenario==='stale'){$data['version']=str_repeat('0',64);}
    if($scenario==='limit'){$db->c['cantidad_reprogramaciones']=1;$data['version']=CitaChangePolicy::version($db->c);}
    if($scenario==='past'){$db->c['fecha']='2026-11-06';$data['version']=CitaChangePolicy::version($db->c);}
    if($scenario==='atendida'){$db->c['estado']='atendida';$data['version']=CitaChangePolicy::version($db->c);}
    if($scenario==='outside'){$data['fecha']='2030-11-11';}
    if($scenario==='same'){$data['hora']='10:00:00';}
    try{(new CitaChangeRepository($db))->change($actor,'reprogramar',$data);$check(false);}catch(BookingConflict|InvalidArgumentException $e){$check(end($db->events)==='rollback' && !in_array('write',$db->events,true));}
}
foreach(['23P01','P0001','40P01','40001','55P03','57014'] as $state){$db=new ChangePdoFake();$db->failure=$state;try{(new CitaChangeRepository($db))->change($actor,'reprogramar',$input);$check(false);}catch(BookingConflict $e){$check(end($db->events)==='rollback' && !$db->active);}}
$db=new ChangePdoFake();$db->duration=45;$input['hora']='11:00:00';(new CitaChangeRepository($db))->change($actor,'reprogramar',$input);$writes=array_values(array_filter($db->queries,static fn($q)=>str_starts_with($q['sql'],'UPDATE')));$check($writes[0]['params']['end']==='11:45:00');
$check(!array_filter($db->queries,static fn($q)=>preg_match('/^(DELETE|INSERT|UPDATE public\.(pagos|reservas_provisionales|pacientes|medicos))/', $q['sql'])));
echo "$n comprobaciones transaccionales P5-C pasaron sobre PDO simulado; sin escrituras PostgreSQL.\n";
