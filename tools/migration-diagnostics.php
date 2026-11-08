<?php
declare(strict_types=1);

/** Closed diagnostic protocol: never include native exception messages or connection values. */
final class MigrationDiagnosticFailure extends RuntimeException
{
    public function __construct(public readonly string $reason) { parent::__construct($reason); }
}
final class MigrationDiagnostics
{
    public const REASONS = [
        'MBSTRING_REQUIRED','PDO_PGSQL_REQUIRED','PGSQL_SNI_REQUIRED','ARGUMENT_INVALID','BASELINE_APPLY_FORBIDDEN',
        'SOURCE_CONFIG_INVALID','SOURCE_IDENTITY_INVALID','SOURCE_COUNTS_CHANGED','SOURCE_BLOCKS_OR_RESERVATIONS',
        'TARGET_URL_INVALID','TARGET_CONFIRMATION_REQUIRED','TARGET_IDENTITY_INVALID','TARGET_TABLE_COUNT_INVALID',
        'SCHEMA_COLUMNS_DIFFER','SCHEMA_CONSTRAINTS_DIFFER','SCHEMA_TRIGGERS_DIFFER','TARGET_ACCOUNTS_INVALID',
        'SEQUENCE_UNKNOWN','SEQUENCE_STALE','PLAN_CHANGED','MAPPING_INCOMPLETE','EXISTING_ROW_CHANGED','COUNTS_INVALID',
        'IDEMPOTENCE_FAILED','ORPHANS_FOUND','DB_CONNECTION_FAILED','DB_AUTH_FAILED','DB_PERMISSION_DENIED',
        'DB_COLUMN_MISSING','DB_TABLE_MISSING','DB_TIMEOUT','DB_QUERY_FAILED','SOURCE_REFERENCE_MISSING',
        'SPECIALTY_STATE_CONFLICT','EXISTING_IMPORT_DIVERGED','DOCTOR_CODE_CONFLICT','PATIENT_CODE_CONFLICT',
        'SCHEDULE_OVERLAP','APPOINTMENT_INTERVAL_INVALID','APPOINTMENT_ASSOCIATION_MISSING','APPOINTMENT_STATE_INVALID',
        'APPOINTMENT_OVERLAP','APPOINTMENT_DATE_INVALID','DOCTOR_INACTIVE','APPOINTMENT_SCHEDULE_INVALID',
        'APPOINTMENT_BLOCKED','APPOINTMENT_RESERVATION_CONFLICT','UNEXPECTED_ERROR',
    ];
    public static function fail(string $reason): never { throw new MigrationDiagnosticFailure($reason); }
    public static function event(Throwable $error,string $stage): array
    {
        $reason='UNEXPECTED_ERROR'; $sqlstate=null;
        if ($error instanceof MigrationDiagnosticFailure && in_array($error->reason,self::REASONS,true)) {
            $reason=$error->reason;
        } elseif ($error instanceof PDOException) {
            $candidate=$error->errorInfo[0]??(string)$error->getCode();
            if (is_string($candidate) && preg_match('/^[A-Z0-9]{5}$/D',$candidate)) { $sqlstate=$candidate; }
            $reason=match (true) {
                $sqlstate==='28P01' || $sqlstate==='28000'=>'DB_AUTH_FAILED',
                $sqlstate==='42501'=>'DB_PERMISSION_DENIED',
                $sqlstate==='42703'=>'DB_COLUMN_MISSING',
                $sqlstate==='42P01'=>'DB_TABLE_MISSING',
                $sqlstate==='57014' || $sqlstate==='55P03'=>'DB_TIMEOUT',
                str_starts_with($sqlstate??'','08') || in_array($stage,['SOURCE_CONNECT','TARGET_CONNECT'],true)=>'DB_CONNECTION_FAILED',
                default=>'DB_QUERY_FAILED',
            };
        } elseif ($error instanceof RuntimeException) {
            // Exact planner messages only. Data-bearing strings are never forwarded.
            $reason=match ($error->getMessage()) {
                'Especialidad existente con estado distinto.'=>'SPECIALTY_STATE_CONFLICT',
                'Registro profesional ocupado.'=>'DOCTOR_CODE_CONFLICT',
                'Documento ficticio ocupado.'=>'PATIENT_CODE_CONFLICT',
                'Solapamiento de horario.'=>'SCHEDULE_OVERLAP',
                'Intervalo de cita inválido.'=>'APPOINTMENT_INTERVAL_INVALID',
                'Cita sin asociación médico-especialidad.'=>'APPOINTMENT_ASSOCIATION_MISSING',
                'Estado o reprogramación inválidos.'=>'APPOINTMENT_STATE_INVALID',
                'Citas superpuestas.'=>'APPOINTMENT_OVERLAP',
                'Cita fuera de ventana futura; no se cambiarán fechas automáticamente.'=>'APPOINTMENT_DATE_INVALID',
                'Médico de cita no activo.'=>'DOCTOR_INACTIVE',
                'Cita fuera del horario o duración configurada.'=>'APPOINTMENT_SCHEDULE_INVALID',
                'Cita en bloqueo existente.'=>'APPOINTMENT_BLOCKED',
                'Reserva temporal en conflicto.'=>'APPOINTMENT_RESERVATION_CONFLICT',
                default=>'UNEXPECTED_ERROR',
            };
            if (preg_match('/^Referencia de origen sin padre: (?:especialidades|medicos|pacientes|medico_especialidad|horarios_atencion|citas)$/uD',$error->getMessage())) { $reason='SOURCE_REFERENCE_MISSING'; }
            if (preg_match('/^Registro UNI26 existente divergente: (?:especialidades|medicos|pacientes|medico_especialidad|horarios_atencion|citas)$/uD',$error->getMessage())) { $reason='EXISTING_IMPORT_DIVERGED'; }
        }
        return ['stage'=>preg_match('/^[A-Z_]+$/D',$stage)?$stage:'BOOTSTRAP','reason'=>$reason,'sqlstate'=>$sqlstate];
    }
    public static function runtime(): array
    {
        $versions=[];
        foreach (['pdo_pgsql','pgsql'] as $extension) {
            $versions[$extension]=null;
            if (extension_loaded($extension)) {
                ob_start(); (new ReflectionExtension($extension))->info(); $info=(string)ob_get_clean();
                if (preg_match('/PostgreSQL\s*\(libpq\)\s*Version\s*=>\s*(\d+(?:\.\d+)*)/',$info,$match)) { $versions[$extension]=$match[1]; }
            }
        }
        return ['php'=>PHP_VERSION,'binary'=>PHP_BINARY,'ini'=>php_ini_loaded_file()?:null,'mbstring'=>extension_loaded('mbstring'),'libpq'=>$versions];
    }
}
