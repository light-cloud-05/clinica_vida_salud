<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/bootstrap.php';
use Clinica\Services\{MailTransport,LocalMailTransport,SmtpMailTransport,SmtpPasswordRecoveryTransport};
$n=0;$check=static function(bool $ok)use(&$n):void{if(!$ok){throw new RuntimeException('Fallo de correo seguro');}++$n;};
try{(new LocalMailTransport())->send('demo@example.invalid','Prueba','No entregar');$check(false);}catch(RuntimeException){$check(true);}
$mail=new class implements MailTransport {public array $messages=[];public function send(string $recipient,string $subject,string $body):void{$this->messages[]=compact('recipient','subject','body');}};
$transport=new SmtpPasswordRecoveryTransport($mail,'https://clinica.example.invalid');$token=str_repeat('a',64);$transport->send('demo@example.invalid',$token);
$check(count($mail->messages)===1 && str_contains($mail->messages[0]['body'],'https://clinica.example.invalid/restablecer-contrasena?token='.$token));
foreach(['http://invalid.example','https://user@invalid.example','https://invalid.example?query=1'] as $url){try{new SmtpPasswordRecoveryTransport($mail,$url);$check(false);}catch(RuntimeException){$check(true);}}
foreach([['host'=>"smtp.example.invalid\r\nBAD",'from'=>'demo@example.invalid','encryption'=>'tls'],['host'=>'smtp.example.invalid','from'=>"demo@example.invalid\r\nBAD",'encryption'=>'tls'],['host'=>'smtp.example.invalid','from'=>'demo@example.invalid','encryption'=>'none']] as $c){try{new SmtpMailTransport($c['host'],587,'','',$c['from'],$c['encryption']);$check(false);}catch(RuntimeException){$check(true);}}
$smtp=new SmtpMailTransport('smtp.example.invalid',587,'','','demo@example.invalid');
try{$smtp->send("demo@example.invalid\r\nBAD",'subject','body');$check(false);}catch(RuntimeException){$check(true);}
try{$smtp->send('demo@example.invalid',"subject\r\nBAD",'body');$check(false);}catch(RuntimeException){$check(true);}
$original=getenv('APP_ENV');$transportOriginal=getenv('PASSWORD_RESET_TRANSPORT');
try{putenv('APP_ENV=production');putenv('PASSWORD_RESET_TRANSPORT=local');try{Clinica\Support\PasswordRecoveryFactory::service();$check(false);}catch(RuntimeException){$check(true);}}finally{$original===false?putenv('APP_ENV'):putenv('APP_ENV='.$original);$transportOriginal===false?putenv('PASSWORD_RESET_TRANSPORT'):putenv('PASSWORD_RESET_TRANSPORT='.$transportOriginal);}
echo "$n comprobaciones de correo pasaron; sin red, correo real ni BD.\n";
