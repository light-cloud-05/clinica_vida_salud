<?php
declare(strict_types=1);
namespace Clinica\Controllers;

use Clinica\Middleware\PaymentAccess;
use Clinica\Services\PaymentDemoService;
use Clinica\Services\PaymentPolicy;
use Clinica\Support\Input;
use Clinica\Support\PaymentFactory;
use Clinica\Services\SessionService;

final class PaymentController
{
    public function index(array $user): void
    {
        try {
            $payments = $this->service()->listing($user);
            $title = 'Ensayos de pago';
            $this->render('index', compact('user', 'title', 'payments'));
        } catch (\DomainException $e) {
            $this->error($user, 403, $e->getMessage());
        }
    }

    public function form(array $user): void
    {
        try {
            if (!PaymentAccess::view($user) || !(PaymentAccess::pay($user) || PaymentAccess::cash($user))) {
                throw new \DomainException('Consulta de pagos no autorizada.');
            }
            $title = 'Nuevo ensayo de pago';
            $appointments = PaymentFactory::repository()->candidates($user);
            $methods = $user['role'] === 'Paciente'
                ? PaymentPolicy::METHODS
                : array_intersect_key(PaymentPolicy::METHODS, ['efectivo' => true]);
            $intent = bin2hex(random_bytes(32));
            $this->render('form', compact('user', 'title', 'methods', 'intent', 'appointments'));
        } catch (\DomainException $e) {
            $this->error($user, 403, $e->getMessage());
        }
    }

    public function create(array $user): void
    {
        try {
            if (!SessionService::validCsrf($_POST['csrf'] ?? null)) {
                throw new \DomainException('El formulario venció. Vuelva a intentarlo.');
            }
            $method = $_POST['metodo'] ?? '';
            if (!is_string($method) || !isset(PaymentPolicy::METHODS[$method])) {
                throw new \InvalidArgumentException('Método de pago inválido.');
            }
            $appointment = Input::positiveId($_POST['cita'] ?? null);
            if ($appointment === null) { throw new \InvalidArgumentException('Seleccione una cita válida.'); }
            $rehearsal = PaymentPolicy::rehearsal($_POST['reloj'] ?? null);
            $context = PaymentFactory::repository()->context($user, $appointment, $rehearsal ?? PaymentPolicy::clock());
            $intent = $_POST['intent'] ?? '';
            if (!is_string($intent)) { throw new \InvalidArgumentException('Intención inválida.'); }
            $id = $this->service()->create($user, $context, $context['amount'], $method, $rehearsal, $intent);
            $payment = $this->service()->find($user, $id);
            if ($payment === null) {
                throw new \RuntimeException('El ensayo se creó, pero no pudo recuperarse para mostrar la confirmación.');
            }
            $title = 'Ensayo creado';
            $this->render('confirmation', compact('user', 'title', 'payment'));
        } catch (\DomainException $e) {
            $this->error($user, 403, $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            $this->error($user, 422, $e->getMessage());
        } catch (\Clinica\Services\BookingConflict $e) {
            $this->error($user, 409, $e->getMessage());
        } catch (\RuntimeException $e) {
            $this->error($user, 503, $e->getMessage());
        }
    }

    public function action(array $user): void
    {
        try {
            if (!SessionService::validCsrf($_POST['csrf'] ?? null)) {
                throw new \DomainException('El formulario venció. Vuelva a intentarlo.');
            }
            $id = $_POST['id'] ?? '';
            if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
                throw new \InvalidArgumentException('Ensayo inválido.');
            }
            $action = $_POST['accion'] ?? '';
            $clock = $action === 'reloj' ? PaymentPolicy::rehearsal($_POST['reloj'] ?? null) : null;
            $payment=$this->service()->find($user,$id);
            if(!$payment){throw new \DomainException('Ensayo fuera de su ámbito.');}
            if(empty($payment['appointment']) && in_array($action,['aprobar','sandbox_iniciar','sandbox_capturar'],true)){throw new \Clinica\Services\BookingConflict('Ensayo anterior sin vínculo P5-B. Cree uno nuevo seleccionando una cita.');}
            if(!\Clinica\Support\IntegrationGuard::active() && !empty($payment['appointment']) && in_array($action,['aprobar','sandbox_iniciar','sandbox_capturar'],true)){
                $now=isset($payment['demo_clock']) ? new \DateTimeImmutable($payment['demo_clock']) : PaymentPolicy::clock();
                $context=PaymentFactory::repository()->context($user,(int)$payment['appointment'],$now);
                if($context['amount']!==$payment['importe'] || $context['fecha']!==$payment['fecha'] || $context['hora']!==$payment['hora_inicio'] || $context['end']!==$payment['hora_fin'] || $context['medico']!==(int)$payment['medico'] || $context['especialidad']!==(int)$payment['especialidad'] || $context['paciente']!==(int)$payment['patient']){throw new \Clinica\Services\BookingConflict('La cita o el importe cambió. Inicie otro ensayo.');}
            }
            if(in_array($action,['sandbox_iniciar','sandbox_capturar'],true)){
                $url=$this->service()->sandbox($user,$id,$action==='sandbox_capturar',\Clinica\Services\PayPalSandboxService::configured());
                header('Location: '.($url ?? $this->base($user)),true,303);return;
            }
            $this->service()->action($user, $id, is_string($action) ? $action : '', $clock);
            header('Location: ' . $this->base($user) . '?id=' . rawurlencode($id), true, 303);
        } catch (\DomainException $e) {
            $this->error($user, 403, $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            $this->error($user, 422, $e->getMessage());
        } catch (\Clinica\Services\BookingConflict $e) {
            $this->error($user, 409, $e->getMessage());
        }
    }

    public function sandboxReturn(array $user):void
    {
        $id=$_GET['pago'] ?? null;$token=$_GET['token'] ?? null;
        if(!is_string($id) || !is_string($token)){$this->error($user,422,'Retorno Sandbox inválido.');return;}
        $payment=$this->service()->find($user,$id);
        if(!$payment || !isset($payment['sandbox']['order']) || !hash_equals($payment['sandbox']['order'],$token)){$this->error($user,403,'Orden Sandbox fuera de su ámbito.');return;}
        // GET no confirma cobros. El usuario solicita verificación por POST con CSRF.
        $this->index($user);
    }

    public function receipt(array $user):void
    {
        try{
            if(!PaymentAccess::receipt($user)){throw new \DomainException('Comprobante no autorizado.');}
            $id=$_GET['id'] ?? '';
            if(!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id)){throw new \InvalidArgumentException('Ensayo inválido.');}
            $payment=$this->service()->find($user,$id);
            if(!$payment){throw new \DomainException('Comprobante fuera de su ámbito.');}
            $pdf=\Clinica\Services\PaymentReceipt::pdf($payment);
            header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="comprobante-demo-'.$id.'.pdf"');echo $pdf;
        }catch(\DomainException $e){$this->error($user,403,$e->getMessage());}
        catch(\InvalidArgumentException $e){$this->error($user,422,$e->getMessage());}
        catch(\Clinica\Services\BookingConflict $e){$this->error($user,409,$e->getMessage());}
    }

    private function service(): PaymentDemoService|\Clinica\Services\IntegrationPaymentService
    {
        return PaymentFactory::service();
    }

    private function base(array $user): string
    {
        return $user['role'] === 'Paciente' ? '/mis-pagos' : '/pagos';
    }

    private function error(array $user, int $status, string $message): void
    {
        http_response_code($status);
        $title = 'Ensayo de pago';
        $this->render('error', compact('user', 'title', 'message'));
    }

    private function render(string $template, array $variables): void
    {
        extract($variables, EXTR_SKIP);
        $escape = static fn(mixed $value): string => htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $base = $this->base($user);
        $section = ltrim($base, '/');
        $sectionLabel = 'Pagos · demostración sin persistencia';
        $integration=\Clinica\Support\IntegrationGuard::active();
        if($integration){$sectionLabel='PRUEBA PERSISTENTE · clinica_vida_salud_test · 127.0.0.1:55432';}
        $success = null;
        $views = dirname(__DIR__, 3) . '/frontend/views/';
        require $views . 'layouts/management-header.php';
        require $views . 'pagos/' . $template . '.php';
        require $views . 'layouts/management-footer.php';
    }
}
