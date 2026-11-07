<?php
declare(strict_types=1);

namespace Clinica\Repositories;

use PDO;

final class Connection
{
    public static function readOnly(array $config): PDO
    {
        return self::connect($config, true);
    }

    /** Exclusivamente para la herramienta CLI explícita de configuración de cuentas. */
    public static function accountMaintenance(array $config): PDO
    {
        if (PHP_SAPI !== 'cli') {
            throw new \RuntimeException('Operación disponible solo por consola.');
        }
        return self::connect($config, false);
    }

    private static function connect(array $config, bool $readOnly): PDO
    {
        if (!extension_loaded('pdo_pgsql')) {
            throw new \RuntimeException('Controlador PostgreSQL pendiente.');
        }
        foreach (['host', 'port', 'database', 'sslmode'] as $key) {
            if (!preg_match('/^[a-zA-Z0-9_.:\-]+$/D', $config[$key])) {
                throw new \RuntimeException('Configuración de conexión inválida.');
            }
        }
        if (!ctype_digit($config['port']) || !in_array($config['sslmode'], ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)) {
            throw new \RuntimeException('Configuración de conexión inválida.');
        }
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s;connect_timeout=5', $config['host'], $config['port'], $config['database'], $config['sslmode']);
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
        ]);
        if ($readOnly) {
            $pdo->exec("SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY");
        }
        $pdo->exec("SET TIME ZONE 'America/La_Paz'");
        $pdo->exec("SET statement_timeout = '5s'");
        return $pdo;
    }

    public static function patientManagement(array $config): PDO
    {
        if (\Clinica\Support\Environment::get('PATIENT_WRITES_ENABLED') !== 'true') {
            throw new \RuntimeException('El guardado de pacientes no está habilitado.');
        }
        $pdo = self::connect($config, false);
        if ($pdo->query('SELECT current_database()')->fetchColumn() !== 'clinica_vida_salud') {
            throw new \RuntimeException('Base de datos no autorizada.');
        }
        return $pdo;
    }

    public static function medicalManagement(array $config): PDO
    {
        if(\Clinica\Support\Environment::get('MEDICAL_WRITES_ENABLED')!=='true'){throw new \RuntimeException('El guardado P3 no está habilitado.');}
        $pdo=self::connect($config,false);
        if($pdo->query('SELECT current_database()')->fetchColumn()!=='clinica_vida_salud'){throw new \RuntimeException('Base no autorizada.');}
        return $pdo;
    }
    public static function scheduleManagement(array $config): PDO
    {
        if(\Clinica\Support\Environment::get('SCHEDULE_WRITES_ENABLED')!=='true'){throw new \RuntimeException('El guardado P4 no está habilitado.');}
        $pdo=self::connect($config,false);if($pdo->query('SELECT current_database()')->fetchColumn()!=='clinica_vida_salud'){throw new \RuntimeException('Base no autorizada.');}return $pdo;
    }
    public static function appointmentChanges(array $config): PDO
    {
        if(\Clinica\Support\Environment::get('APPOINTMENT_CHANGES_ENABLED')!=='true'){throw new \RuntimeException('Los cambios de citas están deshabilitados.');}
        $pdo=self::connect($config,false);if($pdo->query('SELECT current_database()')->fetchColumn()!=='clinica_vida_salud'){throw new \RuntimeException('Base no autorizada.');}return $pdo;
    }
    public static function appointmentCreation(array $config): PDO
    {
        if(\Clinica\Support\Environment::get('APPOINTMENT_WRITES_ENABLED')!=='true'){throw new \RuntimeException('Las reservas persistentes están deshabilitadas.');}
        $pdo=self::connect($config,false);if($pdo->query('SELECT current_database()')->fetchColumn()!=='clinica_vida_salud'){throw new \RuntimeException('Base no autorizada.');}return $pdo;
    }
}
