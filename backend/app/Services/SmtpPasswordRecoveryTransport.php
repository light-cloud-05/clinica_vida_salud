<?php
declare(strict_types=1);
namespace Clinica\Services;

final class SmtpPasswordRecoveryTransport implements PasswordRecoveryTransport
{
    public function __construct(private MailTransport $mail,private string $baseUrl)
    {
        $parts=parse_url($baseUrl);
        if(!$parts || ($parts['scheme']??'')!=='https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \RuntimeException('APP_URL HTTPS requerida para recuperacion.');
        }
    }
    public function send(string $recipient,string $token): void
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token)) {throw new \RuntimeException('Token de recuperacion invalido.');}
        $url=rtrim($this->baseUrl,'/').'/restablecer-contrasena?token='.$token;
        $this->mail->send($recipient,'Recuperar acceso a Clinica Vida Salud',"Se solicito recuperar el acceso a tu cuenta. El enlace vence en 30 minutos y se puede utilizar una sola vez.\n\n".$url."\n\nSi no lo solicitaste, ignora este correo.");
    }
}
