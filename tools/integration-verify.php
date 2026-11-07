<?php
declare(strict_types=1);
require __DIR__.'/integration-bootstrap.php';require __DIR__.'/integration-setup.php';
use Clinica\Repositories\Connection;
use Clinica\Support\IntegrationGuard;
$pdo=Connection::integration(require dirname(__DIR__).'/backend/config/database.php');IntegrationGuard::check($pdo);
$dir=dirname(__DIR__).'/var/integration';$base=json_decode(file_get_contents($dir.'/original-snapshot.json'),true);
foreach($base['tables'] as $table=>$hash){
    $s=$pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name=:t AND is_identity='YES'");$s->execute(['t'=>$table]);$col=$s->fetchColumn();$where='';
    if($col){$seq=$pdo->query("SELECT pg_get_serial_sequence('public.$table','$col')")->fetchColumn();$key=substr($seq,strrpos($seq,'.')+1);$where=' WHERE "'.$col.'"<='.(int)$base['sequences'][$key]['last_value'];}
    $value=hash('sha256',(string)$pdo->query('SELECT COALESCE(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text COLLATE "C")::text,\'[]\') FROM (SELECT * FROM public."'.$table.'"'.$where.') t')->fetchColumn());
    if($value!==$hash){throw new RuntimeException('Filas heredadas distintas en '.$table);}
}
$current=integrationSnapshot($pdo);
$normalize=static function(array $schema):array{foreach($schema['constraints'] as &$c){$c['definition']=preg_replace('/[()\s]+/','',str_replace(['::character varying','::text[]','::text'],'',$c['definition']));}unset($c);return $schema;};
if($normalize($base['schema'])!==$normalize($current['schema'])){throw new RuntimeException('Esquema de prueba modificado.');}
$counts=$pdo->query('SELECT (SELECT count(*) FROM public.citas) citas,(SELECT count(*) FROM public.pacientes) pacientes,(SELECT count(*) FROM public.medicos) medicos,(SELECT count(*) FROM public.horarios_atencion) horarios,(SELECT count(*) FROM public.especialidades) especialidades')->fetch();
echo 'COPIA: filas heredadas de 21 tablas intactas; restricciones, triggers, funciones, columnas e índices conservados. Totales con registros nuevos: '.json_encode($counts).PHP_EOL;
file_put_contents($dir.'/final-counts.json',json_encode($counts));
