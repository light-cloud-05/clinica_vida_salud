<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/bootstrap.php';
use Clinica\Repositories\ReservaRepository;
use Clinica\Services\BookingConflict;
final class BookingPdoFake extends PDO
{
    public bool $active=false;public array $events=[];public array $insert=[];public ?string $failure=null;public bool $occupied=false;public int $duration=30;
    public function __construct(){}
    public function beginTransaction():bool{$this->events[]='begin';return $this->active=true;}
    public function inTransaction():bool{return $this->active;}
    public function commit():bool{$this->events[]='commit';$this->active=false;return true;}
    public function rollBack():bool{$this->events[]='rollback';$this->active=false;return true;}
    public function exec(string $statement):int|false{$this->events[]=$statement;return 0;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new BookingStatementFake($this,$query);}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false{return new BookingStatementFake($this,$query);}
}
final class BookingStatementFake extends PDOStatement
{
    public function __construct(private BookingPdoFake $db,private string $sql){}
    public function execute(?array $params=null):bool{
        $this->db->events[]=$this->sql;
        if(str_starts_with($this->sql,'INSERT')){
            $this->db->insert=$params;
            if($this->db->failure!==null){throw new class($this->db->failure) extends PDOException{public function __construct(string $state){parent::__construct('Simulado');$this->code=$state;}};}
        }return true;
    }
    public function fetchColumn(int $column=0):mixed{return str_contains($this->sql,'clock_timestamp') ? '2026-11-09 08:00:00' : 1;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return ['id_cita'=>101,'codigo_cita'=>$this->db->insert['code']];}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{
        if(str_starts_with($this->sql,'SELECT m.id_medico')){return [['id_medico'=>1,'nombres'=>'Médico','apellidos'=>'Simulado','estado'=>'activo','especialidades'=>'[1]']];}
        if(str_starts_with($this->sql,'SELECT id_especialidad')){return [['id_especialidad'=>1,'nombre'=>'Simulada']];}
        if(str_starts_with($this->sql,'SELECT id_horario')){return [['id_horario'=>1,'id_medico'=>1,'dia_semana'=>1,'hora_inicio'=>'08:00:00','hora_fin'=>'16:00:00','duracion_cita_minutos'=>$this->db->duration,'estado'=>'activo']];}
        if(str_starts_with($this->sql,'SELECT id_medico,fecha') && $this->db->occupied){return [['id_medico'=>1,'fecha'=>'2026-11-09','hora_inicio'=>'10:00:00','hora_fin'=>'10:30:00','estado'=>'programada']];}
        return [];
    }
}
$n=0;$check=static function(bool $ok)use(&$n):void{if(!$ok){throw new RuntimeException('Fallo de reserva simulada.');}++$n;};
$actor=['id'=>22,'role'=>'Paciente','permissions'=>['gestionar_citas_propias']];$input=['medico'=>1,'especialidad'=>1,'fecha'=>'2026-11-09','hora'=>'10:00:00','paciente'=>null];
$db=new BookingPdoFake();$result=(new ReservaRepository($db))->create($actor,$input);
$check($result['id']===101 && preg_match('/^CVS-[A-F0-9]{24}$/',$result['code'])===1);
$check($db->insert['patient']===1 && $db->insert['end']==='10:30:00');$check(end($db->events)==='commit');
$check(in_array('SELECT id_paciente FROM public.pacientes WHERE id_usuario=:user FOR KEY SHARE',$db->events,true));
$check(in_array('SELECT id_medico FROM public.medicos WHERE id_medico=:id FOR UPDATE',$db->events,true));
$check(count(array_filter($db->events,static fn(string $s):bool=>str_starts_with($s,'INSERT INTO public.citas')))===1);
foreach(['23P01','23505','23503','23514','P0001','55P03','40P01','40001','57014'] as $state){$db=new BookingPdoFake();$db->failure=$state;try{(new ReservaRepository($db))->create($actor,$input);$check(false);}catch(BookingConflict $e){$check(!$db->active && end($db->events)==='rollback');}}
$db=new BookingPdoFake();$db->occupied=true;try{(new ReservaRepository($db))->create($actor,$input);$check(false);}catch(BookingConflict $e){$check($db->insert===[] && end($db->events)==='rollback');}
$db=new BookingPdoFake();$db->duration=45;$input['hora']='10:15:00';(new ReservaRepository($db))->create($actor,$input);$check($db->insert['end']==='11:00:00');
$actor=['id'=>2,'role'=>'Recepcionista','permissions'=>['gestionar_citas','consultar_agenda','consultar_historial_citas']];$input['paciente']=1;$input['hora']='10:00:00';$db=new BookingPdoFake();(new ReservaRepository($db))->create($actor,$input);
$check(in_array('SELECT id_paciente FROM public.pacientes WHERE id_paciente=:patient FOR KEY SHARE',$db->events,true));
$check(!array_filter($db->events,static fn(string $s):bool=>preg_match('/^(UPDATE|DELETE|INSERT INTO public\.(?!citas))/', $s)===1));
echo "$n comprobaciones transaccionales P5-B pasaron con PDO simulado; sin PostgreSQL ni secuencias.\n";

