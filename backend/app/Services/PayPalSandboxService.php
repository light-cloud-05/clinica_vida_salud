<?php
declare(strict_types=1);
namespace Clinica\Services;
use Clinica\Support\Environment;
final class PayPalSandboxService
{
    private const BASE='https://api-m.sandbox.paypal.com';
    public function __construct(private string $client,private string $secret,private string $rate,private string $returnBase,private ?\Closure $transport=null){}
    public static function configured():self{return new self(Environment::get('PAYPAL_SANDBOX_CLIENT_ID',''),Environment::get('PAYPAL_SANDBOX_CLIENT_SECRET',''),Environment::get('PAYPAL_SANDBOX_BOB_PER_USD',''),Environment::get('PAYPAL_SANDBOX_RETURN_BASE',''));}
    public function quote(string $bob):string
    {
        if(!$this->client || !$this->secret || !preg_match('/^\d{1,6}(\.\d{1,6})?$/D',$this->rate) || bccomp($this->rate,'0',6)<=0 || !preg_match('~^https?://[a-zA-Z0-9.:-]+$~D',$this->returnBase)){throw new BookingConflict('Sandbox requiere credenciales, URL de retorno y conversión BOB/USD aprobada. La simulación local está disponible.');}
        $usd=bcdiv(PaymentPolicy::amount($bob),$this->rate,2);if(bccomp($usd,'0.01',2)<0){throw new BookingConflict('La cotización Sandbox no es válida.');}return $usd;
    }
    private function request(string $method,string $path,?array $body=null,?string $requestId=null,bool $oauth=false):array
    {
        if($this->transport){return ($this->transport)($method,$path,$body,$requestId,$oauth);}
        $headers=['Accept: application/json'];$payload=null;
        if($oauth){$payload='grant_type=client_credentials';$headers[]='Content-Type: application/x-www-form-urlencoded';}
        else{$token=$this->request('POST','/v1/oauth2/token',null,null,true)['access_token'] ?? null;if(!is_string($token)){throw new BookingConflict('Sandbox no pudo autorizar la solicitud.');}$headers[]='Authorization: Bearer '.$token;$headers[]='Content-Type: application/json';if($body!==null){$payload=json_encode($body,JSON_THROW_ON_ERROR);}}
        if($requestId!==null){$headers[]='PayPal-Request-Id: '.$requestId;}
        $curl=curl_init(self::BASE.$path);$options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS];
        if($payload!==null){$options[CURLOPT_POSTFIELDS]=$payload;}if($oauth){$options[CURLOPT_USERPWD]=$this->client.':'.$this->secret;}$options[CURLOPT_HTTPAUTH]=CURLAUTH_BASIC;curl_setopt_array($curl,$options);
        $raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
        if(!is_string($raw) || $status<200 || $status>=300){throw new BookingConflict('Sandbox no confirmó la operación. Revise el estado antes de reintentar.');}return json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    }
    public function create(string $reference,string $bob,int $payment):array
    {
        $usd=$this->quote($bob);$order=$this->request('POST','/v2/checkout/orders',['intent'=>'CAPTURE','purchase_units'=>[['custom_id'=>$reference,'amount'=>['currency_code'=>'USD','value'=>$usd]]],'application_context'=>['return_url'=>$this->returnBase.'/pagos/sandbox/retorno?pago='.$payment,'cancel_url'=>$this->returnBase.'/pagos','shipping_preference'=>'NO_SHIPPING','user_action'=>'PAY_NOW']],substr(hash('sha256','create:'.$reference),0,32));
        $id=$order['id'] ?? '';if(!is_string($id) || !preg_match('/^[A-Z0-9]{5,40}$/D',$id)){throw new BookingConflict('Orden Sandbox inválida.');}
        $approve=null;foreach($order['links'] ?? [] as $link){$url=$link['href'] ?? '';if(in_array($link['rel'] ?? '',['approve','payer-action'],true) && parse_url($url,PHP_URL_SCHEME)==='https' && parse_url($url,PHP_URL_HOST)==='www.sandbox.paypal.com'){$approve=$url;}}
        if(!$approve){throw new BookingConflict('No se recibió aprobación Sandbox válida.');}return ['order'=>$id,'usd'=>$usd,'rate'=>$this->rate,'approve'=>$approve];
    }
    public function capture(string $order,string $reference,string $usd):string
    {
        if(!preg_match('/^[A-Z0-9]{5,40}$/D',$order)){throw new \InvalidArgumentException('Orden inválida.');}
        $data=$this->request('GET','/v2/checkout/orders/'.$order);
        if(($data['status'] ?? '')!=='COMPLETED'){$this->request('POST','/v2/checkout/orders/'.$order.'/capture',new \ArrayObject() instanceof \ArrayObject ? [] : [],substr(hash('sha256','capture:'.$reference),0,32));$data=$this->request('GET','/v2/checkout/orders/'.$order);}
        return self::verify($data,$order,$reference,$usd);
    }
    public static function verify(array $data,string $order,string $reference,string $usd):string
    {
        $units=$data['purchase_units'] ?? [];if(($data['id'] ?? '')!==$order || ($data['status'] ?? '')!=='COMPLETED' || count($units)!==1 || ($units[0]['custom_id'] ?? '')!==$reference){throw new BookingConflict('La orden Sandbox no coincide con la reserva.');}
        $captures=$units[0]['payments']['captures'] ?? [];if(count($captures)!==1){throw new BookingConflict('Captura Sandbox no válida.');}$c=$captures[0];
        if(($c['status'] ?? '')!=='COMPLETED' || ($c['amount']['currency_code'] ?? '')!=='USD' || !is_string($c['amount']['value'] ?? null) || PaymentPolicy::amount($c['amount']['value'])!==PaymentPolicy::amount($usd) || empty($c['id']) || !is_string($c['id']) || strlen($c['id'])>100){throw new BookingConflict('Sandbox no verificó estado, moneda e importe esperados.');}return $c['id'];
    }
}
