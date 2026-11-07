<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/bootstrap.php';
use Clinica\Repositories\VoiceQueryRepository;

final class VoicePdoFake extends PDO
{
    public array $queries=[];
    public function __construct(){}
    public function prepare(string $query,array $options=[]):PDOStatement|false
    {
        if(!str_starts_with($query,'SELECT ')){throw new RuntimeException('P10 solo SELECT.');}
        return new VoiceStatementFake($this,$query);
    }
}
final class VoiceStatementFake extends PDOStatement
{
    public function __construct(private VoicePdoFake $pdo,private string $sql){}
    public function execute(?array $params=null):bool{$this->pdo->queries[]=[$this->sql,$params];return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return [];}
}
$n=0;$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok){throw new RuntimeException('FALLO repositorio P10: '.$label);}++$n;};
$db=new VoicePdoFake();$repo=new VoiceQueryRepository($db);$now=new DateTimeImmutable('2026-11-09T08:00:00-04:00');
foreach(['Paciente','Medico','Recepcionista','Administrador'] as $role){
    $actor=['id'=>22,'role'=>$role,'permissions'=>['realizar_consultas_voz',$role==='Paciente' ? 'gestionar_citas_propias' : 'consultar_agenda']];
    foreach([false,true] as $today){
        $repo->appointments($actor,$today,$now);[$sql,$params]=end($db->queries);
        $check(str_contains($sql,'LIMIT 10'),'Límite de resultados');
        $check(!str_contains($sql,'motivo_consulta') && !str_contains($sql,'documento_identidad'),'Sin datos clínicos');
        if($role==='Paciente'){$check(str_contains($sql,'p.id_usuario=:actor') && $params['actor']===22,'Usuario se resuelve por pacientes.id_usuario');}
        elseif($role==='Medico'){$check(str_contains($sql,'m.id_usuario=:actor') && $params['actor']===22,'Médico solo su agenda');}
        else{$check(!isset($params['actor']),'Ámbito administrativo por permiso');}
        $check($today ? $params['day']==='2026-11-09' : $params['now']==='2026-11-09 08:00:00','Fecha parametrizada');
    }
}
$before=count($db->queries);
foreach(['appointments','payments','choices','availability'] as $method){
    try{
        $actor=['id'=>22,'role'=>'Paciente','permissions'=>['realizar_consultas_voz']];
        match($method){'appointments'=>$repo->appointments($actor,false,$now),'payments'=>$repo->payments($actor),'choices'=>$repo->choices($actor),'availability'=>$repo->availability($actor,null,null,$now)};
        $check(false,'Debe denegar');
    }catch(DomainException $e){$check(true,'Deniega sin permiso funcional');}
}
$check(count($db->queries)===$before,'No ejecuta SQL al denegar');
echo "$n comprobaciones repositorio P10 con PDO simulado; solo SELECT, ámbitos y parámetros.\n";
