<?php
declare(strict_types=1);
namespace Clinica\Support;

use Clinica\Repositories\PaymentDemoStore;
use Clinica\Services\PaymentDemoService;

final class PaymentFactory
{
    public static function repository(): \Clinica\Repositories\PaymentRepository
    {
        return new \Clinica\Repositories\PaymentRepository(\Clinica\Repositories\Connection::readOnly(require dirname(__DIR__,2).'/config/database.php'));
    }
    public static function service(): PaymentDemoService|\Clinica\Services\IntegrationPaymentService
    {
        if(IntegrationGuard::active()){return new \Clinica\Services\IntegrationPaymentService(\Clinica\Repositories\Connection::integration(require dirname(__DIR__,2).'/config/database.php'));}
        $name=Environment::get('PAYMENT_DEMO_STORE_NAME','payment-demo');
        if(!preg_match('/^[a-zA-Z0-9-]{1,50}$/D',$name)){throw new \RuntimeException('Nombre de almacén inválido.');}
        return new PaymentDemoService(new PaymentDemoStore(dirname(__DIR__, 3) . '/var/p9/'.$name.'.json'));
    }
}
