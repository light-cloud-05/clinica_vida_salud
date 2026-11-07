<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/bootstrap.php';

use Clinica\Repositories\AttendanceRepository;
use Clinica\Services\AttendancePolicy;

final class AttendancePdoFake extends PDO
{
    public bool $active=false;
    public array $events=[];
    public array $queries=[];
    public array $appointment=[
        'id_cita'=>1,'codigo_cita'=>'SIMULADA','id_paciente'=>1,'id_medico'=>7,
        'id_especialidad'=>1,'fecha'=>'2026-11-06','hora_inicio'=>'10:00:00',
        'hora_fin'=>'10:30:00','estado'=>'programada','cantidad_reprogramaciones'=>0,
        'updated_at'=>'v1','paciente_nombre'=>'Paciente','medico_nombre'=>'Médico',
        'especialidad_nombre'=>'Especialidad'
    ];
    public function __construct(){}
    public function beginTransaction(): bool{$this->active=true;$this->events[]='begin';return true;}
    public function inTransaction(): bool{return $this->active;}
    public function commit(): bool{$this->active=false;$this->events[]='commit';return true;}
    public function rollBack(): bool{$this->active=false;$this->events[]='rollback';return true;}
    public function exec(string $sql): int|false{$this->events[]=$sql;return 0;}
    public function prepare(string $sql,array $options=[]): PDOStatement|false{return new AttendanceStatementFake($this,$sql);}
    public function query(string $sql,?int $fetchMode=null,mixed ...$args): PDOStatement|false{return new AttendanceStatementFake($this,$sql);}
}

final class AttendanceStatementFake extends PDOStatement
{
    private int $affected=1;
    public function __construct(private AttendancePdoFake $db,private string $sql){}
    public function execute(?array $params=null): bool{$this->db->queries[]=['sql'=>$this->sql,'params'=>$params];return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed{return $this->db->appointment;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array{return [];}
    public function fetchColumn(int $column=0): mixed{return '2026-11-06 10:15:00';}
    public function rowCount(): int{return $this->affected;}
}

$checks=0;
$check=static function(bool $ok)use(&$checks):void{if(!$ok){throw new RuntimeException('Fallo repositorio de asistencia.');}$checks++;};
$actor=['id'=>7,'role'=>'Medico','permissions'=>['marcar_cita_atendida','consultar_historial_citas']];
$db=new AttendancePdoFake();
$appointment=$db->appointment;
(new AttendanceRepository($db))->change($actor,$appointment,'atendida');
$check(end($db->events)==='commit');
$check((bool)array_filter($db->queries,static fn(array $q):bool=>str_contains($q['sql'],'FOR UPDATE OF c')));
$check((bool)array_filter($db->queries,static fn(array $q):bool=>str_contains($q['sql'],"set_config('app.usuario_id'")));
$updates=array_values(array_filter($db->queries,static fn(array $q):bool=>str_starts_with($q['sql'],'UPDATE')));
$check(count($updates)===1 && $updates[0]['params']['state']==='atendida' && $updates[0]['params']['old_state']==='programada');
$check(AttendancePolicy::version($appointment)!==AttendancePolicy::version([...$appointment,'estado'=>'atendida']));
echo "$checks comprobaciones transaccionales de asistencia pasaron sobre PDO simulado; sin PostgreSQL.\n";
