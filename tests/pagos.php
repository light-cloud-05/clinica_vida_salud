<?php
declare(strict_types=1);
require dirname(__DIR__) . '/backend/bootstrap.php';

use Clinica\Middleware\PaymentAccess;
use Clinica\Repositories\PaymentDemoStore;
use Clinica\Services\BookingConflict;
use Clinica\Services\PaymentDemoService;
use Clinica\Services\PaymentPolicy;

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException('FALLO P9: ' . $label);
    }
    ++$checks;
};
$reject = static function (callable $work, string $type) use ($check): void {
    try {
        $work();
        $check(false, 'se esperaba ' . $type);
    } catch (Throwable $e) {
        $check($e instanceof $type, 'tipo de error ' . $type);
    }
};

$patient = ['id' => 22, 'role' => 'Paciente', 'permissions' => ['consultar_pagos_propios', 'pagar_cita_propia']];
$reception = ['id' => 2, 'role' => 'Recepcionista', 'permissions' => ['consultar_pagos_autorizados', 'registrar_pago_efectivo']];
$doctor = ['id' => 7, 'role' => 'Medico', 'permissions' => ['consultar_agenda']];
$check(PaymentAccess::view($patient) && PaymentAccess::pay($patient), 'permisos del paciente');
$check(PaymentAccess::view($reception) && PaymentAccess::cash($reception), 'permisos de recepción');
$check(!PaymentAccess::view($doctor), 'médico sin acceso a pagos');
$check(PaymentPolicy::cashOpen(new DateTimeImmutable('2026-11-09 08:00:00', new DateTimeZone('America/La_Paz'))), 'caja abre lunes 08:00');
$check(!PaymentPolicy::cashOpen(new DateTimeImmutable('2026-11-09 18:00:00', new DateTimeZone('America/La_Paz'))), 'caja cierra lunes 18:00');
$check(!PaymentPolicy::cashOpen(new DateTimeImmutable('2026-11-08 10:00:00', new DateTimeZone('America/La_Paz'))), 'caja cerrada domingo');
$check(PaymentPolicy::rehearsal('2026-11-09T08:15')?->format(DATE_ATOM) === '2026-11-09T08:15:00-04:00', 'reloj de ensayo identificado');
$reject(fn() => PaymentPolicy::rehearsal('2026-11-09 08:15'), InvalidArgumentException::class);

$path = tempnam(sys_get_temp_dir(), 'p9-');
$existing=dirname(__DIR__).'/var/p9/payment-demo.json';
$before=is_file($existing) ? hash_file('sha256',$existing) : null;
unlink($path);
$service = new PaymentDemoService(new PaymentDemoStore($path));
$context = ['medico' => 1, 'especialidad' => 1, 'fecha' => '2026-11-09', 'hora' => '10:00:00', 'end' => '10:30:00', 'owner' => 22, 'paciente' => 22];
$clock = new DateTimeImmutable('2026-11-09 08:00:00', new DateTimeZone('America/La_Paz'));
$paypal = $service->create($patient, $context, '100.00', 'paypal_sandbox', $clock, str_repeat('a', 64));
$check(strlen($paypal) === 32, 'crear PayPal simulado');
$check($service->create($patient, $context, '100.00', 'paypal_sandbox', $clock, str_repeat('a', 64)) === $paypal, 'idempotencia del formulario');
$check($service->find($patient, $paypal)['fecha_vencimiento'] === '2026-11-09T08:30:00-04:00', 'límite online de 30 minutos');
$service->action($patient, $paypal, 'aprobar');
$check($service->find($patient, $paypal)['estado'] === 'confirmado', 'aprobar PayPal simulado');

$qrContext = $context;
$qrContext['hora'] = '11:00:00';
$qrContext['end'] = '11:30:00';
$qr = $service->create($patient, $qrContext, '50', 'qr_simulado', $clock, str_repeat('b', 64));
$service->action($patient, $qr, 'aprobar');
$check($service->find($patient, $qr)['estado'] === 'confirmado', 'aprobar QR simulado');

$cashContext = $context;
$cashContext['hora'] = '16:00:00';
$cashContext['end'] = '16:30:00';
$closed = new DateTimeImmutable('2026-11-09 07:59:00', new DateTimeZone('America/La_Paz'));
$cash = $service->create($patient, $cashContext, '75', 'efectivo', $closed, str_repeat('c', 64));
$check($service->find($patient,$cash)['estado']==='pendiente','paciente elige efectivo fuera de caja y queda pendiente');
$reject(fn()=>$service->action($patient,$cash,'aprobar'),DomainException::class);
$reject(fn()=>$service->action($reception,$cash,'aprobar'),BookingConflict::class);
$service->action($reception,$cash,'reloj',$clock);
$service->action($reception, $cash, 'aprobar', $clock);
$check($service->find($reception, $cash)['estado'] === 'confirmado', 'aprobar efectivo en caja');

$expiredContext = $context;
$expiredContext['hora'] = '14:00:00';
$expiredContext['end'] = '14:30:00';
$expired = $service->create($patient, $expiredContext, '100', 'qr_simulado', $clock, str_repeat('d', 64));
$service->action($reception, $expired, 'reloj', new DateTimeImmutable('2026-11-09 08:31:00', new DateTimeZone('America/La_Paz')));
$check($service->find($patient, $expired)['reserva_estado'] === 'expirada', 'expiración online con reloj simulado');
$reject(fn() => $service->action($patient, $expired, 'aprobar'), BookingConflict::class);

$routes = require dirname(__DIR__) . '/backend/routes/web.php';
$check(isset($routes['/mis-pagos']['GET'], $routes['/mis-pagos/crear']['POST'], $routes['/pagos']['GET'], $routes['/pagos/accion']['POST']), 'rutas P9');
$check((is_file($existing) ? hash_file('sha256',$existing) : null)===$before, 'almacén previo intacto');
$check($service->find($patient,$expired)['estado']==='vencido','pago vencido coherente');
$reject(fn()=>$service->action($patient,$paypal,'rechazar'),BookingConflict::class);
$other=$patient;$other['id']=99;
$check($service->find($other,$paypal)===null,'pago ajeno oculto');
$reject(fn()=>$service->create($other,$context,'100','qr_simulado',$clock,str_repeat('e',64)),DomainException::class);
$reject(fn()=>$service->create($patient,$context,'999','paypal_sandbox',$clock,str_repeat('a',64)),BookingConflict::class);
$rejectedContext=$context;$rejectedContext['hora']='15:00:00';$rejectedContext['end']='15:30:00';
$rejected=$service->create($patient,$rejectedContext,'50','qr_simulado',$clock,str_repeat('f',64));
$service->action($patient,$rejected,'rechazar');
$reject(fn()=>$service->action($patient,$rejected,'aprobar'),BookingConflict::class);
$svg=\Clinica\Services\PaymentQr::svg($service->find($patient,$qr));
$check(str_contains($svg,'<svg') && str_contains($svg,'<path'),'QR SVG generado por dependencia instalada');
$friday=new DateTimeImmutable('2026-11-06 17:00',new DateTimeZone('America/La_Paz'));
$monday=new DateTimeImmutable('2026-11-09 16:00',new DateTimeZone('America/La_Paz'));
$check(PaymentPolicy::cashDeadline($friday,$monday)?->format('Y-m-d H:i')==='2026-11-06 18:00','efectivo ajusta fin de semana sin superar 48h');
$reject(fn()=>$service->sandbox($patient,$paypal,false,new \Clinica\Services\PayPalSandboxService('','','','')),BookingConflict::class);
@unlink($path);
@unlink($path . '.lock');
echo "$checks comprobaciones P9 pasaron con almacenamiento temporal; sin conexión ni escrituras en PostgreSQL.\n";
