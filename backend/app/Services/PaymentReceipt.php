<?php
declare(strict_types=1);
namespace Clinica\Services;

final class PaymentReceipt
{
    public static function pdf(array $payment):string
    {
        if($payment['estado']!=='confirmado'){throw new BookingConflict('Solo un pago confirmado tiene comprobante.');}
        $escape=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $options=new \Dompdf\Options();$options->set('isRemoteEnabled',false);$options->set('isPhpEnabled',false);
        $pdf=new \Dompdf\Dompdf($options);
        $html='<html><meta charset="utf-8"><style>body{font-family:DejaVu Sans;font-size:12px}h1{color:#176b66}.demo{border:2px solid #555;padding:12px}</style><h1>Clínica Vida Salud</h1><p class="demo">COMPROBANTE ACADÉMICO DE DEMOSTRACIÓN · SIN VALOR FISCAL · SIN DINERO REAL</p>';
        foreach(['numero_comprobante'=>'Número','source_code'=>'Cita de referencia','referencia'=>'Referencia local','importe'=>'Importe BOB','fecha_confirmacion'=>'Fecha America/La_Paz'] as $key=>$label){$html.='<p><b>'.$label.':</b> '.$escape($payment[$key] ?? '').'</p>';}
        $html.='<p>'.$escape(isset($payment['sandbox']) ? 'Resultado verificado en PayPal Sandbox; no confirma la cita en PostgreSQL.' : 'Simulación local. No acredita una transacción auténtica de PayPal ni bancaria.').'</p></html>';
        $pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4');$pdf->render();return $pdf->output();
    }
}
