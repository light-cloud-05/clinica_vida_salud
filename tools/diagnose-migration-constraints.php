<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');

require dirname(__DIR__).'/backend/bootstrap.php';

function constraints(PDO $pdo): array {
    return $pdo->query("
        SELECT c.relname AS tabla,
               k.conname AS nombre,
               k.contype AS tipo,
               pg_get_constraintdef(k.oid) AS definicion
        FROM pg_constraint k
        JOIN pg_class c ON c.oid=k.conrelid
        JOIN pg_namespace n ON n.oid=c.relnamespace
        WHERE n.nspname='public'
          AND c.relname IN (
            'especialidades','medicos','pacientes',
            'medico_especialidad','horarios_atencion',
            'citas','bloqueos_horario','reservas_provisionales'
          )
          AND k.contype <> 'n'
        ORDER BY c.relname,k.conname
    ")->fetchAll(PDO::FETCH_ASSOC);
}

function grouped(array $rows): array {
    $groups=[];
    foreach ($rows as $r) {
        $semantic=[
            'tabla'=>$r['tabla'],
            'tipo'=>$r['tipo'],
            'definicion'=>$r['definicion']
        ];
        $key=hash('sha256',json_encode($semantic,JSON_THROW_ON_ERROR));
        $groups[$key]['semantic']=$semantic;
        $groups[$key]['names'][]=$r['nombre'];
    }
    foreach ($groups as &$g) {
        sort($g['names'],SORT_STRING);
    }
    unset($g);
    return $groups;
}

$source=null;
$target=null;
$stage='BOOTSTRAP';

try {
    $stage='LOCAL';

    $config=[
        'host'=>\Clinica\Support\Environment::get('DB_HOST','127.0.0.1'),
        'port'=>\Clinica\Support\Environment::get('DB_PORT','5432'),
        'database'=>\Clinica\Support\Environment::get('DB_DATABASE','clinica_vida_salud'),
        'username'=>\Clinica\Support\Environment::get('DB_USERNAME'),
        'password'=>\Clinica\Support\Environment::get('DB_PASSWORD'),
        'sslmode'=>\Clinica\Support\Environment::get('DB_SSLMODE','prefer')
    ];

    if (!in_array($config['host'],['127.0.0.1','localhost','::1'],true)
        || $config['database']!=='clinica_vida_salud') {
        throw new RuntimeException();
    }

    $source=\Clinica\Repositories\Connection::readOnly($config);
    $source->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
    $localRows=constraints($source);
    $source->rollBack();

    $stage='RENDER';

    $url=getenv('MIGRATION_RENDER_URL');
    $host=getenv('MIGRATION_EXPECTED_HOST');
    $u=is_string($url)?parse_url($url):false;

    if (!$u || !in_array($u['scheme']??'', ['postgres','postgresql'],true)
        || ($u['host']??'')!==$host
        || rawurldecode(ltrim($u['path']??'','/'))!=='clinica_vida_salud_db'
        || empty($u['user']) || empty($u['pass'])
        || !in_array($u['query']??'',[
            '', 'sslmode=require',
            'sslmode=verify-full','sslmode=verify-ca'
        ],true)) {
        throw new RuntimeException();
    }

    $ssl='require';
    if (!empty($u['query'])) {
        parse_str($u['query'],$q);
        $ssl=$q['sslmode'];
    }

    $targetConfig=[
        'host'=>$host,
        'port'=>'5432',
        'database'=>'clinica_vida_salud_db',
        'username'=>rawurldecode($u['user']),
        'password'=>rawurldecode($u['pass']),
        'sslmode'=>$ssl
    ];

    $target=\Clinica\Repositories\Connection::readOnly($targetConfig);
    $target->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');

    $identity=$target->query("
        SELECT current_database() AS db,
        current_setting('server_version_num')::int AS version,
        (SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()) AS tls
    ")->fetch();

    if (!$identity || $identity['db']!=='clinica_vida_salud_db'
        || (int)$identity['version']<180000
        || (int)$identity['version']>=190000
        || !in_array($identity['tls'],[true,'t',1,'1'],true)) {
        throw new RuntimeException();
    }

    $renderRows=constraints($target);
    $target->rollBack();

    $a=grouped($localRows);
    $b=grouped($renderRows);

    echo "LOCAL_CONSTRAINTS=".count($localRows).PHP_EOL;
    echo "RENDER_CONSTRAINTS=".count($renderRows).PHP_EOL;

    $structural=[];
    $names=[];
    foreach (array_unique(array_merge(array_keys($a),array_keys($b))) as $key) {
        if (!isset($a[$key])) {
            $structural[]=['RENDER_ONLY'=>$b[$key]];
        } elseif (!isset($b[$key])) {
            $structural[]=['LOCAL_ONLY'=>$a[$key]];
        } elseif (count($a[$key]['names'])!==count($b[$key]['names'])) {
            $structural[]=[
                'COUNT_DIFFERENCE'=>[
                    'LOCAL'=>$a[$key],
                    'RENDER'=>$b[$key]
                ]
            ];
        } elseif ($a[$key]['names']!==$b[$key]['names']) {
            $names[]=[
                'LOCAL'=>$a[$key],
                'RENDER'=>$b[$key]
            ];
        }
    }

    echo "STRUCTURAL_DIFFERENCES=".json_encode($structural,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
    echo "NAME_ONLY_DIFFERENCES=".json_encode($names,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
    echo "READ_ONLY_DIAGNOSTIC_COMPLETED".PHP_EOL;

} catch (Throwable $e) {
    foreach ([$target,$source] as $pdo) {
        try {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable) {}
    }
    echo "DIAGNOSTIC_FAILED_STAGE=".$stage.PHP_EOL;
    exit(1);
}
