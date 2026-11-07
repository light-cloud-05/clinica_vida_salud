<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/bootstrap.php';
use Clinica\Repositories\MedicoRepository;
use Clinica\Services\MedicalValidator as V;
use Clinica\Services\MedicalConflict;
final class MedicalPdoSimulation extends PDO
{
    public array $queries=[];public array $events=[];public bool $transaction=false;
    public array $old=[1,2];public array $referenced=[];public bool $missing=false;public ?string $failure=null;public string $version='v1';
    public function __construct(){}
    public function beginTransaction(): bool{$this->events[]='begin';return $this->transaction=true;}
    public function inTransaction(): bool{return $this->transaction;}
    public function commit(): bool{$this->events[]='commit';$this->transaction=false;return true;}
    public function rollBack(): bool{$this->events[]='rollback';$this->transaction=false;return true;}
    public function prepare(string $query,array $options=[]): PDOStatement|false{return new MedicalStatementSimulation($this,$query);}
}
final class MedicalStatementSimulation extends PDOStatement
{
    private array $params=[];
    public function __construct(private MedicalPdoSimulation $simulation,private string $sql){}
    public function execute(?array $params=null): bool{
        $this->params=$params ?? [];$this->simulation->queries[]=['sql'=>$this->sql,'params'=>$params];
        $write=preg_match('/^(INSERT|UPDATE|DELETE)/',$this->sql)===1;
        if($write){$this->simulation->events[]='write';}
        if($this->simulation->failure && str_starts_with($this->sql,'INSERT INTO public.medico_especialidad')){
            throw new class($this->simulation->failure) extends PDOException{public function __construct(string $state){parent::__construct('Fallo simulado.');$this->code=$state;}};
        }
        return true;
    }
    public function fetchColumn(int $column=0): mixed{
        if(str_starts_with($this->sql,'SELECT updated_at')){return $this->simulation->version;}
        if(str_starts_with($this->sql,'SELECT 1 FROM public.citas')){return in_array($this->params['specialty'],$this->simulation->referenced,true) ? 1 : false;}
        return 101;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array{
        if(str_contains($this->sql,'FROM public.medico_especialidad')){return $this->simulation->old;}
        $ids=array_values($this->params);return $this->simulation->missing ? array_slice($ids,1) : $ids;
    }
}
$checks=0;$check=static function(bool $ok) use (&$checks): void{if(!$ok){throw new RuntimeException('Fallo del repositorio P3 simulado.');}++$checks;};
$data=V::validate('medico',['codigo_medico'=>'SIM','registro_profesional'=>'SIM','nombres'=>'Prueba','apellidos'=>'Memoria','estado'=>'activo'])['data'];
$pdo=new MedicalPdoSimulation();$repo=new MedicoRepository($pdo);
$check($repo->save('medico',$data+['id_usuario'=>500],[1,3],101,'v1',V::associationVersion([1,2]),1)===101);
$writes=array_values(array_filter($pdo->queries,static fn(array $q): bool=>preg_match('/^(INSERT|UPDATE|DELETE)/',$q['sql'])===1));
$check(count($writes)===3 && end($pdo->events)==='commit');
$check(str_starts_with($writes[0]['sql'],'UPDATE public.medicos') && !str_contains($writes[0]['sql'],'id_usuario'));
$check($writes[1]['params']===['doctor'=>101,'specialty'=>2] && str_starts_with($writes[1]['sql'],'DELETE FROM public.medico_especialidad WHERE'));
$check($writes[2]['params']===['doctor'=>101,'specialty'=>3] && str_starts_with($writes[2]['sql'],'INSERT INTO public.medico_especialidad'));
$check($pdo->queries[0]['params']===['actor'=>'1'] && str_contains($pdo->queries[0]['sql'],"set_config('app.usuario_id'"));
$check(count(array_filter($pdo->queries,static fn(array $q): bool=>str_contains($q['sql'],'FOR UPDATE')))===2);
$pdo=new MedicalPdoSimulation();(new MedicoRepository($pdo))->save('medico',$data,[1,2],101,'v1',V::associationVersion([1,2]),1);
$check(count(array_filter($pdo->queries,static fn(array $q): bool=>preg_match('/^(INSERT|DELETE) INTO?/', $q['sql'])===1))===0);
// En particular no recrear asociaciones que ya existen, aunque tengan citas.
$check(count(array_filter($pdo->queries,static fn(array $q): bool=>str_starts_with($q['sql'],'DELETE FROM public.medico_especialidad') || str_starts_with($q['sql'],'INSERT INTO public.medico_especialidad')))===0);
foreach(['referenced','missing','version','associations','fk','unique'] as $scenario){
    $pdo=new MedicalPdoSimulation();$associationVersion=V::associationVersion([1,2]);
    if($scenario==='referenced'){$pdo->referenced=[2];}
    if($scenario==='missing'){$pdo->missing=true;}
    if($scenario==='version'){$pdo->version='v2';}
    if($scenario==='associations'){$associationVersion=V::associationVersion([1]);}
    if($scenario==='fk'){$pdo->failure='23503';}
    if($scenario==='unique'){$pdo->failure='23505';}
    try{(new MedicoRepository($pdo))->save('medico',$data,[1,3],101,'v1',$associationVersion,1);$check(false);}catch(MedicalConflict $e){$check(!$pdo->transaction && end($pdo->events)==='rollback');$check(!in_array('commit',$pdo->events,true));}
    if(in_array($scenario,['referenced','missing','version','associations'],true)){$check(!in_array('write',$pdo->events,true));}
}
$specialty=V::validate('especialidad',['nombre'=>'Simulada','precio_consulta'=>'99.99','estado'=>'activo'])['data'];
$pdo=new MedicalPdoSimulation();(new MedicoRepository($pdo))->save('especialidad',$specialty,[],null,null,null,1);
$check(str_starts_with($pdo->queries[1]['sql'],'INSERT INTO public.especialidades') && $pdo->queries[1]['params']['precio_consulta']==='99.99');
$pdo=new MedicalPdoSimulation();(new MedicoRepository($pdo))->save('especialidad',$specialty,[],9,'v1',null,1);
$check(str_starts_with($pdo->queries[2]['sql'],'UPDATE public.especialidades') && end($pdo->events)==='commit');
echo "$checks comprobaciones del repositorio P3 pasaron con PDO simulado; sin PostgreSQL.\n";
