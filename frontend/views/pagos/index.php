<?php use Clinica\Services\SessionService; use Clinica\Services\PaymentPolicy; use Clinica\Middleware\PaymentAccess; ?>
<div class="patient-page-heading"><div><h1>Pagos de demostración</h1><p><?= !empty($integration) ? 'Operaciones SQL reales exclusivamente en la copia aislada. Dinero y resultados de proveedor simulados.' : 'No se escribe en PostgreSQL ni se modifican citas reales.' ?></p></div><?php if(PaymentAccess::pay($user) || PaymentAccess::cash($user)): ?><a class="btn btn-clinic" href="<?= $escape($base) ?>/nuevo">Nuevo ensayo</a><?php endif; ?></div>
<?php foreach($payments as $payment): ?>
<section class="patient-card mb-3">
<h2 class="h5"><?= $escape($payment['referencia']) ?></h2>
<p><?= $escape(PaymentPolicy::METHODS[$payment['metodo']]) ?> · <?= $escape($payment['importe']) ?> BOB<br>
Cita origen: <?= $escape($payment['source_code'] ?? 'Ensayo anterior sin vínculo P5-B') ?> · <?= $escape($payment['fecha'].' '.$payment['hora_inicio']) ?><br>
Estado: <strong><?= $escape($payment['estado']) ?></strong> / <?= $escape($payment['reserva_estado']) ?><br>
Vence: <?= $escape($payment['fecha_vencimiento']) ?> · Reloj: <?= $escape($payment['demo_clock'] ?? 'hora real') ?></p>
<?php if($payment['metodo']==='qr_simulado' && $payment['estado']==='pendiente'): ?>
<figure><?= \Clinica\Services\PaymentQr::svg($payment) ?><figcaption>QR de demostración sin valor bancario. Escanearlo no confirma el pago.</figcaption></figure>
<?php endif; ?>
<?php if($payment['estado']==='pendiente' && !empty($payment['appointment']) && ($payment['metodo']==='efectivo' ? PaymentAccess::cash($user) : PaymentAccess::pay($user))): ?>
<form method="post" action="<?= $escape($base) ?>/accion" class="d-flex gap-2 flex-wrap">
<input type="hidden" name="csrf" value="<?= $escape(SessionService::csrf()) ?>"><input type="hidden" name="id" value="<?= $escape($payment['id']) ?>">
<?php if(!isset($payment['sandbox'])): ?>
<button class="btn btn-success" name="accion" value="aprobar">Confirmar <?= $payment['metodo']==='efectivo' ? 'cobro ficticio en caja' : 'simulación local' ?></button>
<button class="btn btn-outline-secondary" name="accion" value="rechazar">Rechazar simulación</button>
<?php endif; ?>
<?php if($payment['metodo']==='paypal_sandbox' && !isset($payment['demo_clock']) && \Clinica\Support\Environment::get('PAYMENT_SANDBOX_ENABLED','false')==='true'): ?>
<button class="btn btn-primary" name="accion" value="sandbox_iniciar">Ir a PayPal Sandbox externo</button>
<?php if(isset($payment['sandbox'])): ?><button class="btn btn-primary" name="accion" value="sandbox_capturar">Verificar y capturar en Sandbox</button><?php endif; ?>
<?php endif; ?></form>
<?php elseif($payment['metodo']==='efectivo' && $payment['estado']==='pendiente'): ?><p>Acuda a recepción: solo la Recepcionista autorizada confirma el cobro.</p><?php endif; ?>
<?php if(PaymentAccess::cash($user) && $payment['estado']==='pendiente' && !isset($payment['sandbox']) && empty($payment['persistent'])): ?>
<form method="post" action="<?= $escape($base) ?>/accion" class="mt-3">
<input type="hidden" name="csrf" value="<?= $escape(SessionService::csrf()) ?>"><input type="hidden" name="id" value="<?= $escape($payment['id']) ?>">
<label>Avanzar reloj de ensayo <input type="datetime-local" name="reloj" required></label><button name="accion" value="reloj" class="btn btn-outline-secondary">Avanzar</button>
</form><?php endif; ?>
<?php if(isset($payment['sandbox'])): ?><p>Orden Sandbox externa. Su aprobación en PayPal requiere verificación de captura en servidor. Solo se guarda el resultado local.</p><?php else: ?><p>SIMULACIÓN LOCAL: no representa una transacción auténtica de PayPal ni bancaria.</p><?php endif; ?>
<?php if($payment['estado']==='confirmado' && PaymentAccess::receipt($user)): ?><a class="btn btn-outline-secondary" href="<?= $escape($base) ?>/comprobante?id=<?= $escape($payment['id']) ?>">Descargar comprobante académico PDF</a><?php endif; ?>
</section>
<?php endforeach; ?>
<?php if(!$payments): ?><p>No hay ensayos locales.</p><?php endif; ?>
