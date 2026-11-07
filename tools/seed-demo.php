<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/demo-safety.php';
require dirname(__DIR__) . '/backend/bootstrap.php';

use Clinica\Repositories\Connection;
use Clinica\Support\Environment;

if (Environment::get('DEMO_SEED_CONFIRM') !== 'EMPTY_DATABASE_ONLY') {
    throw new RuntimeException('Falta DEMO_SEED_CONFIRM=EMPTY_DATABASE_ONLY.');
}

$config = require dirname(__DIR__) . '/backend/config/database.php';
$isProduction = Environment::get('DEMO_SEED_TARGET') === 'render'
    && Environment::get('APP_ENV') === 'production'
    && Environment::get('DATABASE_URL') !== ''
    && Environment::get('DEMO_RENDER_RESOURCE') === 'clinica-vida-salud-db'
    && Environment::get('DEMO_RENDER_DATABASE_NAME') === $config['database']
    && Environment::get('DEMO_RENDER_DATABASE_HOST') === $config['host'];
$isDisposableLocal = Environment::get('DEMO_SEED_ALLOW_LOCAL') === 'true'
    && Environment::get('DEMO_SEED_TARGET') === 'disposable-local'
    && Environment::get('APP_ENV') === 'local'
    && $config['host'] === '127.0.0.1'
    && $config['port'] === '55432'
    && !in_array($config['database'], ['clinica_vida_salud', 'clinica_vida_salud_test'], true);

if (!$isProduction && !$isDisposableLocal) {
    throw new RuntimeException('Destino no autorizado: use Render production o una base local desechable en 55432.');
}

$passwords = [];
foreach (['ADMIN', 'RECEPTION', 'DOCTOR', 'PATIENT'] as $role) {
    $value = Environment::get('DEMO_' . $role . '_PASSWORD');
    if ($value === '' || strlen($value) < 16 || strlen($value) > 72 || str_contains($value, "\0")) {
        throw new RuntimeException('Cada contraseña DEMO_* debe tener entre 16 y 72 caracteres.');
    }
    $passwords[$role] = password_hash($value, PASSWORD_DEFAULT);
    if (!is_string($passwords[$role])) {
        throw new RuntimeException('No se pudo generar un hash de demostración.');
    }
}

$pdo = Connection::accountMaintenance($config);
if (Environment::get('DEMO_SEED_TARGET') === 'render') { demoRenderPreflight($pdo, $config); }
$identity = $pdo->query("SELECT current_database() AS database, inet_server_port() AS port")->fetch();
if (!$identity || $identity['database'] !== $config['database'] || (int)$identity['port'] !== (int)$config['port']) {
    throw new RuntimeException('La identidad de la base no coincide con la configuración.');
}

$emptyTables = [
    'roles', 'permisos', 'rol_permiso', 'usuarios', 'especialidades',
    'medicos', 'medico_especialidad', 'horarios_atencion', 'pacientes',
];
$pdo->beginTransaction();
try {
$pdo->exec('LOCK TABLE public.roles, public.permisos, public.rol_permiso, public.usuarios, public.especialidades, public.medicos, public.medico_especialidad, public.horarios_atencion, public.pacientes IN EXCLUSIVE MODE');
foreach ($emptyTables as $table) {
    $count = (int)$pdo->query('SELECT count(*) FROM public."' . $table . '"')->fetchColumn();
    if ($count !== 0) {
        throw new RuntimeException('La base no está vacía: public.' . $table);
    }
}

$insert = static function (PDO $pdo, string $sql, array $params): int {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (int)$statement->fetchColumn();
};

$permissions = [
    'gestionar_usuarios' => 'Administrar cuentas y roles',
    'consultar_auditoria' => 'Consultar auditoría',
    'consultar_agenda' => 'Consultar agenda y horarios',
    'gestionar_pacientes' => 'Gestionar pacientes',
    'gestionar_medicos' => 'Gestionar médicos y especialidades',
    'gestionar_horarios' => 'Gestionar horarios',
    'gestionar_bloqueos' => 'Gestionar bloqueos',
    'consultar_historial_citas' => 'Consultar historial de citas',
    'gestionar_citas' => 'Gestionar citas administrativas',
    'generar_reportes' => 'Generar reportes',
    'exportar_reportes' => 'Exportar reportes',
    'controlar_asistencia' => 'Controlar asistencia',
    'gestionar_notas_medicas' => 'Gestionar notas médicas',
    'marcar_cita_atendida' => 'Marcar citas atendidas',
    'realizar_consultas_voz' => 'Realizar consultas de voz',
    'gestionar_perfil_propio' => 'Gestionar perfil propio',
    'gestionar_citas_propias' => 'Gestionar citas propias',
    'consultar_informacion_medica_propia' => 'Consultar información médica propia',
    'confirmar_cita_propia' => 'Confirmar cita propia',
    'pagar_cita_propia' => 'Pagar cita propia',
    'descargar_comprobante_propio' => 'Descargar comprobante propio',
    'consultar_pagos_propios' => 'Consultar pagos propios',
    'consultar_pagos_autorizados' => 'Consultar pagos autorizados',
];

$rolePermissions = [
    'Administrador' => array_keys($permissions),
    'Recepcionista' => [
        'consultar_agenda', 'gestionar_pacientes', 'consultar_historial_citas',
        'gestionar_citas', 'generar_reportes', 'exportar_reportes',
        'controlar_asistencia', 'consultar_pagos_autorizados',
    ],
    'Medico' => [
        'realizar_consultas_voz', 'consultar_agenda', 'consultar_historial_citas',
        'generar_reportes', 'exportar_reportes', 'gestionar_bloqueos',
        'marcar_cita_atendida', 'gestionar_notas_medicas',
    ],
    'Paciente' => [
        'realizar_consultas_voz', 'gestionar_perfil_propio', 'generar_reportes',
        'exportar_reportes', 'gestionar_citas_propias',
        'consultar_informacion_medica_propia', 'confirmar_cita_propia',
        'pagar_cita_propia', 'descargar_comprobante_propio', 'consultar_pagos_propios',
    ],
];

    $roleIds = [];
    foreach ([
        'Administrador' => 'Cuenta de demostración administrativa',
        'Recepcionista' => 'Cuenta de demostración de recepción',
        'Medico' => 'Cuenta de demostración médica',
        'Paciente' => 'Cuenta de demostración de paciente',
    ] as $name => $description) {
        $roleIds[$name] = $insert($pdo, 'INSERT INTO public.roles (nombre, descripcion) VALUES (:name, :description) RETURNING id_rol', [
            'name' => $name,
            'description' => $description,
        ]);
    }

    $permissionIds = [];
    foreach ($permissions as $name => $description) {
        $permissionIds[$name] = $insert($pdo, 'INSERT INTO public.permisos (nombre, descripcion) VALUES (:name, :description) RETURNING id_permiso', [
            'name' => $name,
            'description' => $description,
        ]);
    }
    foreach ($rolePermissions as $role => $names) {
        foreach ($names as $permission) {
            $statement = $pdo->prepare('INSERT INTO public.rol_permiso (id_rol, id_permiso) VALUES (:role, :permission)');
            $statement->execute(['role' => $roleIds[$role], 'permission' => $permissionIds[$permission]]);
        }
    }

    $users = [];
    foreach ([
        'ADMIN' => ['demo_admin', 'demo.admin@cvs.invalid', 'Administrador'],
        'RECEPTION' => ['demo_recepcion', 'demo.recepcion@cvs.invalid', 'Recepcionista'],
        'DOCTOR' => ['demo_medico', 'demo.medico@cvs.invalid', 'Medico'],
        'PATIENT' => ['demo_paciente', 'demo.paciente@cvs.invalid', 'Paciente'],
    ] as $key => [$username, $email, $role]) {
        $users[$key] = $insert($pdo, 'INSERT INTO public.usuarios (id_rol, nombre_usuario, correo_acceso, contrasena_hash, estado) VALUES (:role, :username, :email, :hash, \'activo\') RETURNING id_usuario', [
            'role' => $roleIds[$role],
            'username' => $username,
            'email' => $email,
            'hash' => $passwords[$key],
        ]);
    }

    $specialty = $insert($pdo, 'INSERT INTO public.especialidades (nombre, descripcion, precio_consulta) VALUES (:name, :description, :price) RETURNING id_especialidad', [
        'name' => 'Medicina familiar',
        'description' => 'Atención médica general de demostración.',
        'price' => '120.00',
    ]);
    $specialtyTwo = $insert($pdo, 'INSERT INTO public.especialidades (nombre, descripcion, precio_consulta) VALUES (:name, :description, :price) RETURNING id_especialidad', [
        'name' => 'Pediatría',
        'description' => 'Atención pediátrica ficticia de demostración.',
        'price' => '150.00',
    ]);

    $doctor = $insert($pdo, 'INSERT INTO public.medicos (id_usuario, codigo_medico, registro_profesional, nombres, apellidos, telefono, correo_contacto) VALUES (:user, :code, :license, :names, :surname, :phone, :email) RETURNING id_medico', [
        'user' => $users['DOCTOR'], 'code' => 'DEMO-MED-001', 'license' => 'DEMO-REG-001',
        'names' => 'Alex', 'surname' => 'Montes', 'phone' => '70000001', 'email' => 'demo.medico@cvs.invalid',
    ]);
    $doctorTwo = $insert($pdo, 'INSERT INTO public.medicos (codigo_medico, registro_profesional, nombres, apellidos, telefono, correo_contacto) VALUES (:code, :license, :names, :surname, :phone, :email) RETURNING id_medico', [
        'code' => 'DEMO-MED-002', 'license' => 'DEMO-REG-002', 'names' => 'Sam', 'surname' => 'Rojas',
        'phone' => '70000002', 'email' => 'demo.medico2@cvs.invalid',
    ]);
    $pdo->prepare('INSERT INTO public.medico_especialidad (id_medico, id_especialidad) VALUES (:doctor, :specialty), (:doctorTwo, :specialtyTwo)')
        ->execute(['doctor' => $doctor, 'specialty' => $specialty, 'doctorTwo' => $doctorTwo, 'specialtyTwo' => $specialtyTwo]);

    foreach ([$doctor, $doctorTwo] as $doctorId) {
        $pdo->prepare('INSERT INTO public.horarios_atencion (id_medico, dia_semana, hora_inicio, hora_fin, duracion_cita_minutos) VALUES (:doctor, 1, \'08:00\', \'12:00\', 30), (:doctor, 3, \'14:00\', \'18:00\', 30)')
            ->execute(['doctor' => $doctorId]);
    }

    $pdo->prepare('INSERT INTO public.pacientes (id_usuario, codigo_paciente, documento_identidad, nombres, apellidos, fecha_nacimiento, sexo, telefono, correo_contacto) VALUES (:user, \'DEMO-PAC-001\', \'DEMO-DOC-001\', \'Taylor\', \'Vega\', \'1990-04-12\', \'no especificado\', \'70000003\', \'demo.paciente@cvs.invalid\'), (NULL, \'DEMO-PAC-002\', \'DEMO-DOC-002\', \'Jordan\', \'Paz\', \'1988-09-21\', \'no especificado\', \'70000004\', \'demo.paciente2@cvs.invalid\')')
        ->execute(['user' => $users['PATIENT']]);

    $pdo->commit();
    fwrite(STDOUT, "Datos ficticios cargados en {$identity['database']}.\n");
    fwrite(STDOUT, "Las contraseñas no se imprimieron ni se almacenaron en el repositorio.\n");
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}
