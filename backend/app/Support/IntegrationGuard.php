<?php
declare(strict_types=1);
namespace Clinica\Support;

final class IntegrationGuard
{
    public static function active():bool{return Environment::get('APP_ENV')==='integration';}
    public static function check(\PDO $pdo,?int $appointment=null):void
    {
        if(!self::active() || Environment::get('DB_HOST')!=='127.0.0.1' || Environment::get('DB_PORT')!=='55432' || Environment::get('DB_DATABASE')!=='clinica_vida_salud_test' || Environment::get('INTEGRATION_MARKER')==='' || Environment::get('INTEGRATION_SYSTEM_ID')===''){throw new \RuntimeException('No se garantiza el aislamiento de integración.');}
        $r=$pdo->query("SELECT current_database() AS db,inet_server_port() AS port,(SELECT system_identifier::text FROM pg_control_system()) AS system_id,shobj_description((SELECT oid FROM pg_database WHERE datname=current_database()),'pg_database') AS marker,(SELECT count(*) FROM pg_database WHERE datname='clinica_vida_salud') AS originals")->fetch();
        if(!$r || $r['db']!=='clinica_vida_salud_test' || (int)$r['port']!==55432 || $r['system_id']!==Environment::get('INTEGRATION_SYSTEM_ID') || $r['marker']!==Environment::get('INTEGRATION_MARKER') || (int)$r['originals']!==0){throw new \RuntimeException('Destino PostgreSQL no autorizado para integración.');}
        if($appointment!==null && $appointment<=(int)Environment::get('INTEGRATION_ORIGINAL_MAX_CITA')){throw new \DomainException('Las citas heredadas están protegidas también en la copia.');}
    }
}
