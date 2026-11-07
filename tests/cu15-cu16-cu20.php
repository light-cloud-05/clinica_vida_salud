<?php
declare(strict_types=1);
require dirname(__DIR__) . '/backend/bootstrap.php';

use Clinica\Repositories\{PatientAccountStore,PasswordRecoveryStore,PatientProfileStore};
use Clinica\Services\{PatientAccountService,PatientValidation,PasswordRecoveryService,PasswordRecoveryTransport,PatientProfileService};

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void { if (!$ok) { throw new RuntimeException('FALLO: '.$label); } ++$checks; };

$accountStore = new class implements PatientAccountStore {
    public array $users = []; public array $patients = []; public function duplicates(string $u,string $e,string $c,string $d): array {
        return ['nombre_usuario'=>isset($this->users[$u])?'duplicado':null,'correo_acceso'=>null,'codigo_paciente'=>isset($this->patients[$c])?'duplicado':null,'documento_identidad'=>null];
    }
    public function matchingPatient(array $i): ?array { return $this->patients[$i['codigo_paciente']] ?? null; }
    public function register(array $a,array $p,?int $existingPatientId=null): int {
        $id=count($this->users)+1; $this->users[$a['username']]=['id'=>$id,'role'=>'Paciente']; if($existingPatientId){$this->patients[$p['codigo_paciente']]['id_usuario']=$id;} else {$this->patients[$p['codigo_paciente']]=['id_paciente'=>count($this->patients)+1,'id_usuario'=>$id]+$p;} return $id;
    }
};
$service = new PatientAccountService($accountStore);
$input=['nombre_usuario'=>'paciente_nuevo','correo_acceso'=>'nuevo@example.test','codigo_paciente'=>'CVS-TEST-1','documento_identidad'=>'DOC-1','nombres'=>'Ana','apellidos'=>'Prueba','contrasena'=>'correcta-123','contrasena_confirmacion'=>'correcta-123'];
$id=$service->register($input); $check($id===1 && $accountStore->users['paciente_nuevo']['role']==='Paciente','Registro asigna exclusivamente rol Paciente');
try{$service->register($input);$check(false,'Duplicado rechazado');}catch(PatientValidation $e){$check(isset($e->errors['nombre_usuario']),'Cuenta duplicada rechazada');}
$accountStore->patients['CVS-TEST-2']=['id_paciente'=>9,'id_usuario'=>null,'codigo_paciente'=>'CVS-TEST-2','documento_identidad'=>'DOC-2','nombres'=>'Luis','apellidos'=>'Prueba'];
$linked=$service->register(array_merge($input,['nombre_usuario'=>'luis','correo_acceso'=>'luis@example.test','codigo_paciente'=>'CVS-TEST-2','documento_identidad'=>'DOC-2','nombres'=>'Luis']));
$check($linked===2 && $accountStore->patients['CVS-TEST-2']['id_usuario']===2,'Vinculación exige identidad completa y paciente sin cuenta');
try{$service->register(array_merge($input,['nombre_usuario'=>'otro','correo_acceso'=>'otro@example.test','codigo_paciente'=>'CVS-TEST-2','documento_identidad'=>'DOC-2','nombres'=>'Otro']));$check(false,'Identidad parcial rechazada');}catch(PatientValidation $e){$check(isset($e->errors['documento_identidad']),'No vincula identidad parcial');}
$transport = new class implements PasswordRecoveryTransport { public array $tokens=[]; public function send(string $recipient,string $token):void{$this->tokens[$recipient]=$token;} };
$recoveryStore = new class implements PasswordRecoveryStore {
    public ?array $user=['id_usuario'=>4,'correo_acceso'=>'cuenta@example.test']; public int $active=0; public ?string $hash=null; public bool $used=false;
    public function userByEmail(string $email):?array{return strcasecmp($email,$this->user['correo_acceso'])===0?$this->user:null;}
    public function activeRequests(int $id):int{return $this->active;}
    public function create(int $id,string $hash,DateTimeImmutable $expires):void{$this->active++;$this->hash=$hash;}
    public function reset(string $hash,string $password):bool{if($this->used||$hash!==$this->hash){return false;}$this->used=true;return true;}
};
$recovery=new PasswordRecoveryService($recoveryStore,$transport);$recovery->request('no-existe@example.test');$check($transport->tokens===[],'Respuesta inexistente no envía correo');
$recovery->request('cuenta@example.test');$token=$transport->tokens['cuenta@example.test'];$check(strlen($token)===64 && $recoveryStore->hash!==$token,'Token aleatorio y solo hash en persistencia');
$check($recovery->reset($token,'nueva-123','nueva-123'),'Token válido permite cambio');$check(!$recovery->reset($token,'nueva-123','nueva-123'),'Token usado no se reutiliza');$check(!$recovery->reset(str_repeat('0',64),'x','y'),'Token inválido y confirmación rechazada');
$profileStore = new class implements PatientProfileStore { public array $row=['id_paciente'=>1,'nombres'=>'Ana','apellidos'=>'Prueba']; public function findByUser(int $id):?array{return $id===7?$this->row:null;} public function update(int $id,array $data):void{$this->row=$this->row+$data;} };
$profile=new PatientProfileService($profileStore,true);$check($profile->find(7)!==null && $profile->find(8)===null,'Perfil limitado al usuario autenticado');$profile->update(7,['correo_acceso'=>'ana@example.test','telefono'=>'70000000']);$check($profile->find(7)['telefono']==='70000000','Actualización de perfil validada');
$routes=require dirname(__DIR__).'/backend/routes/web.php';$check(isset($routes['/registro'],$routes['/recuperar-contrasena'],$routes['/restablecer-contrasena'],$routes['/mi-perfil']),'Rutas CU15 CU16 CU20 integradas');
echo "$checks comprobaciones CU15/CU16/CU20 pasaron (simulaciones, sin PostgreSQL).\n";
