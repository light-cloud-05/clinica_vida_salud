<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/bootstrap.php';
use Clinica\Support\IntegrationGuard;
final class IsolationPdoFake extends PDO {
    public array $row=['db'=>'clinica_vida_salud_test','port'=>55432,'system_id'=>'TEST-SYSTEM','marker'=>'TEST-MARKER','originals'=>0];
    public function __construct(){}
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false{return new IsolationStatementFake($this);}
}
final class IsolationStatementFake extends PDOStatement {
    public function __construct(private IsolationPdoFake $pdo){}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return $this->pdo->row;}
}
$vars=['APP_ENV'=>'integration','DB_HOST'=>'127.0.0.1','DB_PORT'=>'55432','DB_DATABASE'=>'clinica_vida_salud_test','INTEGRATION_SYSTEM_ID'=>'TEST-SYSTEM','INTEGRATION_MARKER'=>'TEST-MARKER','INTEGRATION_ORIGINAL_MAX_CITA'=>'60'];$old=[];
foreach($vars as $key=>$value){$old[$key]=getenv($key);putenv($key.'='.$value);}
$n=0;
try{
    $db=new IsolationPdoFake();IntegrationGuard::check($db,61);++$n;
    foreach(['db'=>'clinica_vida_salud','port'=>5432,'system_id'=>'ORIGINAL','marker'=>'OTHER','originals'=>1] as $field=>$bad){$before=$db->row[$field];$db->row[$field]=$bad;try{IntegrationGuard::check($db);throw new LogicException('Destino inseguro aceptado.');}catch(RuntimeException $e){++$n;}$db->row[$field]=$before;}
    try{IntegrationGuard::check($db,60);throw new LogicException('Cita original aceptada.');}catch(DomainException $e){++$n;}
    putenv('DB_DATABASE=clinica_vida_salud');try{IntegrationGuard::check($db);throw new LogicException('Configuración original aceptada.');}catch(RuntimeException $e){++$n;}
}finally{foreach($old as $key=>$value){putenv($value===false ? $key : $key.'='.$value);}}
echo "$n comprobaciones del aislamiento (dobles PDO): original, puerto, clúster, marcador y citas heredadas bloqueados.\n";
