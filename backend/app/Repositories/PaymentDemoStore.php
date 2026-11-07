<?php
declare(strict_types=1);
namespace Clinica\Repositories;
/** Almacén de ensayos compartido por cuentas locales, fuera de public y separado de PostgreSQL. */
final class PaymentDemoStore
{
    public function __construct(private string $path){}
    public function transaction(callable $work):mixed
    {
        if(!is_dir(dirname($this->path))){mkdir(dirname($this->path),0700,true);}
        $lock=fopen($this->path.'.lock','c');if(!$lock || !flock($lock,LOCK_EX)){throw new \RuntimeException('Simulador ocupado.');}
        try{
            $rows=is_file($this->path) ? json_decode((string)file_get_contents($this->path),true,512,JSON_THROW_ON_ERROR) : [];
            $before=$rows;$result=$work($rows);
            if($rows!==$before){
                $temp=tempnam(dirname($this->path),'p9-');
                try{if(file_put_contents($temp,json_encode($rows,JSON_THROW_ON_ERROR),LOCK_EX)===false || !rename($temp,$this->path)){throw new \RuntimeException('No se pudo guardar el ensayo.');}}finally{if(is_file($temp)){unlink($temp);}}
            }return $result;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
