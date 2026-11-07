<?php
declare(strict_types=1);
namespace Clinica\Middleware;
final class PaymentAccess
{
    public static function view(array $a):bool{return ($a['id'] ?? 0)>0 && (($a['role']==='Paciente' && in_array('consultar_pagos_propios',$a['permissions'],true)) || (in_array($a['role'],['Administrador','Recepcionista'],true) && in_array('consultar_pagos_autorizados',$a['permissions'],true)));}
    public static function pay(array $a):bool{return $a['role']==='Paciente' && in_array('pagar_cita_propia',$a['permissions'],true);}
    public static function cash(array $a):bool{return $a['role']==='Recepcionista' && in_array('registrar_pago_efectivo',$a['permissions'],true);}
    public static function receipt(array $a):bool{return self::view($a) && ($a['role']!=='Paciente' || in_array('descargar_comprobante_propio',$a['permissions'],true));}
}
