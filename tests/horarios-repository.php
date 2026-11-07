<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/bootstrap.php';
use Clinica\Repositories\HorarioRepository;
use Clinica\Services\ScheduleValidator as V;
use Clinica\Services\ScheduleValidation;
use Clinica\Services\ScheduleConflict;
final class SchedulePdoSimulation extends PDO
{
    public bool $transaction=false;public array $events=[];public array $queries=[];public ?string $failure=null;public array $hour;public ?array $block=null;
    public array $appointments=[['id_medico'=>1,'fecha'=>'2026-11-09','hora_inicio'=>'10:00:00','hora_fin'=>'10:30:00','estado'=>'confirmada']];
    public function __construct(){$this->hour=['id_horario'=>1,'id_medico'=>1,'dia_semana'=>1,'hora_inicio'=>'08:00:00','hora_fin'=>'12:00:00','duracion_cita_minutos'=>30,'estado'=>'activo','updated_at'=>'v1'];}
    public function beginTransaction(): bool{$this->events[]='begin';return $this->transaction=true;}
    public function inTransaction(): bool{return $this->transaction;}
    public function commit(): bool{$this->events[]='commit';$this->transaction=false;return true;}
    public function rollBack(): bool{$this->events[]='rollback';$this->transaction=false;return true;}
    public function exec(string $statement): int|false{$this->events[]=$statement;return 0;}
    public function prepare(string $query,array $options=[]): PDOStatement|false{return new ScheduleStatementSimulation($this,$query);}
}
final class ScheduleStatementSimulation extends PDOStatement
{
    private array $params=[];
    public function __construct(private SchedulePdoSimulation $simulation,private string $sql){}
    public function execute(?array $params=null): bool{
        $this->params=$params ?? [];$this->simulation->queries[]=['sql'=>$this->sql,'params'=>$params];
        if(preg_match('/^(INSERT|UPDATE)/',$this->sql)){$this->simulation->events[]='write';if($this->simulation->failure!==null){throw new class($this->simulation->failure) extends PDOException{public function __construct(string $state){parent::__construct('Fallo simulado.');$this->code=$state;}};}}return true;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array{
        if(str_starts_with($this->sql,'SELECT m.id_medico')){return [['id_medico'=>1,'especialidades'=>'[]'],['id_medico'=>2,'especialidades'=>'[]']];}
        if(str_starts_with($this->sql,'SELECT id_horario')){return [$this->simulation->hour];}
        if(str_starts_with($this->sql,'SELECT id_bloqueo')){return $this->simulation->block ? [$this->simulation->block] : [];}
        if(str_starts_with($this->sql,'SELECT id_medico,fecha')){return $this->simulation->appointments;}
        return [];
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed{return ($this->params['id'] ?? null)===1 ? (str_starts_with($this->sql,'SELECT id_bloqueo') ? ($this->simulation->block ?? false) : $this->simulation->hour) : false;}
    public function fetchColumn(int $column=0): mixed{return 99;}
}
$checks=0;$check=static function(bool $ok)use(&$checks): void{if(!$ok){throw new RuntimeException('Fallo del repositorio P4 simulado.');}++$checks;};
$actor=['id'=>1,'role'=>'Administrador','permissions'=>['consultar_agenda','gestionar_horarios','gestionar_bloqueos']];
$pdo=new SchedulePdoSimulation();$repo=new HorarioRepository($pdo);$data=V::validate('horario',['id_medico'=>'1','dia_semana'=>'1','hora_inicio'=>'13:00','hora_fin'=>'16:00','estado'=>'activo'])['data'];
$check($repo->save('horario',$data,null,null,$actor)===99);
$check($pdo->events[1]==='LOCK TABLE public.citas IN SHARE MODE' && $pdo->events[2]==='LOCK TABLE public.horarios_atencion, public.bloqueos_horario IN SHARE ROW EXCLUSIVE MODE');
$writes=array_values(array_filter($pdo->queries,static fn(array $q): bool=>preg_match('/^(INSERT|UPDATE)/',$q['sql'])===1));$check(count($writes)===1 && str_starts_with($writes[0]['sql'],'INSERT INTO public.horarios_atencion'));
$audit=array_values(array_filter($pdo->queries,static fn(array $q): bool=>str_contains($q['sql'],"set_config('app.usuario_id'")));$check($audit[0]['params']===['actor'=>'1'] && end($pdo->events)==='commit');
$pdo=new SchedulePdoSimulation();$repo=new HorarioRepository($pdo);$current=array_intersect_key($pdo->hour,array_flip(V::fields('horario')));$repo->save('horario',$current,1,V::version('horario',$pdo->hour),$actor);
$check(end($pdo->events)==='commit' && count(array_filter($pdo->queries,static fn(array $q): bool=>str_starts_with($q['sql'],'UPDATE public.horarios_atencion')))===1);
foreach(['overlap','deactivate','stale','foreign','appointment','fk'] as $scenario){
    $pdo=new SchedulePdoSimulation();$repo=new HorarioRepository($pdo);$kind='horario';$id=null;$version=null;$candidate=$data;
    if($scenario==='overlap'){$candidate['hora_inicio']='09:00:00';$candidate['hora_fin']='11:00:00';}
    if($scenario==='deactivate'){$candidate=array_intersect_key($pdo->hour,array_flip(V::fields('horario')));$candidate['estado']='inactivo';$id=1;$version=V::version('horario',$pdo->hour);}
    if($scenario==='stale'){$id=1;$version=str_repeat('0',64);}
    if($scenario==='foreign'){$candidate['id_medico']=99;}
    if($scenario==='appointment'){$kind='bloqueo';$candidate=['id_medico'=>1,'fecha'=>'2026-11-09','hora_inicio'=>'10:00:00','hora_fin'=>'11:00:00','motivo'=>null];}
    if($scenario==='fk'){$pdo->failure='23503';}
    try{$repo->save($kind,$candidate,$id,$version,$actor);$check(false);}catch(ScheduleValidation|ScheduleConflict $e){$check(!$pdo->transaction && end($pdo->events)==='rollback');$check(!in_array('commit',$pdo->events,true));if($scenario!=='fk'){$check(!in_array('write',$pdo->events,true));}}
}
$pdo=new SchedulePdoSimulation();$repo=new HorarioRepository($pdo);$block=['id_medico'=>1,'fecha'=>'2026-11-10','hora_inicio'=>'13:00:00','hora_fin'=>'14:00:00','motivo'=>null];$repo->save('bloqueo',$block,null,null,$actor);
$check(end($pdo->events)==='commit' && count(array_filter($pdo->queries,static fn(array $q): bool=>str_starts_with($q['sql'],'INSERT INTO public.bloqueos_horario')))===1);
$check(!array_filter($pdo->queries,static fn(array $q): bool=>preg_match('/^(DELETE|UPDATE public\.(citas|medicos|pacientes|pagos))/', $q['sql'])===1));
$pdo=new SchedulePdoSimulation();$pdo->block=$block+['id_bloqueo'=>1];$repo=new HorarioRepository($pdo);$changed=$block;$changed['motivo']='Edición solo en memoria';
$repo->save('bloqueo',$changed,1,V::version('bloqueo',$pdo->block),$actor);
$check(end($pdo->events)==='commit' && count(array_filter($pdo->queries,static fn(array $q): bool=>str_starts_with($q['sql'],'UPDATE public.bloqueos_horario')))===1);
foreach(['stale','appointment'] as $scenario){
    $pdo=new SchedulePdoSimulation();$pdo->block=$block+['id_bloqueo'=>1];$repo=new HorarioRepository($pdo);$candidate=$block;$version=V::version('bloqueo',$pdo->block);
    if($scenario==='stale'){$version=str_repeat('0',64);}else{$candidate['fecha']='2026-11-09';$candidate['hora_inicio']='10:00:00';$candidate['hora_fin']='11:00:00';}
    try{$repo->save('bloqueo',$candidate,1,$version,$actor);$check(false);}catch(ScheduleConflict|ScheduleValidation $e){$check(end($pdo->events)==='rollback');$check(!in_array('write',$pdo->events,true));}
}
echo "$checks comprobaciones del repositorio P4 pasaron sobre PDO simulado; sin PostgreSQL.\n";
