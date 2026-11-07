<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/bootstrap.php';
use Clinica\Repositories\PacienteRepository;
use Clinica\Services\PatientValidator;
use Clinica\Services\PatientConflict;

final class PatientPdoSimulation extends PDO
{
    public array $queries=[]; public array $events=[]; public bool $transaction=false;
    public bool $stale=false; public ?string $failure=null;
    public function __construct() {}
    public function beginTransaction(): bool {$this->events[]='begin';return $this->transaction=true;}
    public function inTransaction(): bool {return $this->transaction;}
    public function commit(): bool {$this->events[]='commit';$this->transaction=false;return true;}
    public function rollBack(): bool {$this->events[]='rollback';$this->transaction=false;return true;}
    public function prepare(string $query,array $options=[]): PDOStatement|false {return new PatientStatementSimulation($this,$query);}
}
final class PatientStatementSimulation extends PDOStatement
{
    public function __construct(private PatientPdoSimulation $simulation,private string $sql) {}
    public function execute(?array $params=null): bool {
        $this->simulation->queries[]=['sql'=>$this->sql,'params'=>$params];
        $write=str_starts_with($this->sql,'INSERT') || str_starts_with($this->sql,'UPDATE');
        $this->simulation->events[]=$write ? 'write' : 'audit';
        if($write && $this->simulation->failure!==null) {
            throw new class($this->simulation->failure) extends PDOException {
                public function __construct(string $sqlState) {$this->code=$sqlState;parent::__construct('Fallo simulado.');$this->code=$sqlState;}
            };
        }
        return true;
    }
    public function fetchColumn(int $column=0): mixed {return $this->simulation->stale ? false : 101;}
}
$checks=0;
$check=static function(bool $ok) use (&$checks): void {if(!$ok){throw new RuntimeException('Fallo del repositorio simulado.');}++$checks;};
$data=PatientValidator::validate(['codigo_paciente'=>'SIMULADO','documento_identidad'=>'SIMULADO','nombres'=>'Prueba','apellidos'=>'En memoria','estado'=>'activo'])['data'];
$pdo=new PatientPdoSimulation();$repository=new PacienteRepository($pdo);
$id=$repository->save($data+['id_usuario'=>500,'alergias'=>'No tocar'],null,null,1);
$check($id===101 && $pdo->events===['begin','audit','write','commit']);
$check($pdo->queries[0]['params']===['actor'=>'1'] && str_contains($pdo->queries[0]['sql'],"set_config('app.usuario_id'"));
$write=$pdo->queries[1];
$check(!str_contains($write['sql'],'id_usuario') && !str_contains($write['sql'],'alergias') && !isset($write['params']['id_usuario']));
$check(str_starts_with($write['sql'],'INSERT INTO public.pacientes') && !str_contains($write['sql'],'usuarios'));
$pdo=new PatientPdoSimulation();$repository=new PacienteRepository($pdo);
$repository->save($data,101,'version-anterior',2);
$check($pdo->queries[1]['params']['version']==='version-anterior' && $pdo->queries[1]['params']['id']===101);
$check(str_contains($pdo->queries[1]['sql'],'updated_at::text=:version') && str_contains($pdo->queries[1]['sql'],'WHERE id_paciente=:id'));
$check($pdo->events===['begin','audit','write','commit']);
$pdo=new PatientPdoSimulation();$pdo->stale=true;$repository=new PacienteRepository($pdo);
try{$repository->save($data,101,'version-antigua',2);$check(false);}catch(PatientConflict $e){$check($pdo->events===['begin','audit','write','rollback']);}
foreach(['23505','23503'] as $state) {
    $pdo=new PatientPdoSimulation();$pdo->failure=$state;$repository=new PacienteRepository($pdo);
    try{$repository->save($data,null,null,1);$check(false);}catch(Throwable $e){
        $check(!$pdo->transaction && end($pdo->events)==='rollback');
        $check($state==='23505' ? $e instanceof PatientConflict : $e instanceof PDOException);
    }
}
echo "$checks comprobaciones del repositorio de escritura pasaron sobre PDO simulado; sin PostgreSQL.\n";
