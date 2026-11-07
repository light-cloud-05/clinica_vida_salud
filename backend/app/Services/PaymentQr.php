<?php
declare(strict_types=1);
namespace Clinica\Services;

final class PaymentQr
{
    public static function svg(array $payment):string
    {
        $payload=json_encode(['tipo'=>'DEMOSTRACION_SIN_VALOR_BANCARIO','referencia'=>$payment['referencia'],'importe'=>$payment['importe'],'moneda'=>'BOB'],JSON_THROW_ON_ERROR);
        return (new \Endroid\QrCode\Writer\SvgWriter())->write(new \Endroid\QrCode\QrCode($payload))->getString();
    }
}
