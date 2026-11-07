<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/bootstrap.php';
use Clinica\Services\PatientValidator;
use Clinica\Services\PacienteService;
use Clinica\Services\PatientValidation;
use Clinica\Services\PatientConflict;
use Clinica\Middleware\PatientAccess;
use Clinica\Repositories\PatientStore;

$checks=0;
$check=static function(bool $ok,string $label) use (&$checks): void {if(!$ok){throw new RuntimeException('FALLO: '.$label);} ++$checks;};
$input=['codigo_paciente'=>'PRUEBA-MEMORIA','documento_identidad'=>'DOC-MEMORIA','nombres'=>'María','apellidos'=>'Prueba','estado'=>'activo'];
$result=PatientValidator::validate($input);
$check(!$result['errors'] && $result['data']['telefono']===null,'Mínimos válidos y opcionales nulos');
foreach(['codigo_paciente','documento_identidad','nombres','apellidos','estado'] as $field) {
    $invalid=$input; $invalid[$field]='   ';
    $check(isset(PatientValidator::validate($invalid)['errors'][$field]),'Obligatorio '.$field);
}
foreach(PatientValidator::FIELDS as $field=>[$label,$max,$required]) {
    if($max===null || $field==='estado') {continue;}
    $invalid=$input; $invalid[$field]=str_repeat('ñ',$max+1);
    $check(isset(PatientValidator::validate($invalid)['errors'][$field]),'Longitud Unicode '.$field);
}
foreach(['2025-02-29','2026-13-01','2026-02-30','hoy','0000-01-01'] as $date) {
    $check(isset(PatientValidator::validate($input+['fecha_nacimiento'=>$date])['errors']['fecha_nacimiento']),'Fecha inválida');
}
$check(!PatientValidator::validate($input+['fecha_nacimiento'=>'2000-02-29','sexo'=>'Sin catálogo definido'])['errors'],'Fecha bisiesta y sexo sin enum inventado');
$check(isset(PatientValidator::validate($input+['correo_contacto'=>'sin-arroba'])['errors']['correo_contacto']),'Formato de correo');
foreach(['id_usuario'=>1,'alergias'=>'No sobrescribir','id_paciente'=>2,'rol'=>'Administrador'] as $key=>$value) {
    $check(isset(PatientValidator::validate($input+[$key=>$value])['errors']['_form']),'Campos no editables rechazados');
}
foreach([['x'],"abc\0def", "\xFF"] as $invalid) {
    $bad=$input; $bad['nombres']=$invalid;
    $check(isset(PatientValidator::validate($bad)['errors']['nombres']),'Tipo/encoding inválido');
}
foreach([null,[],0,'0','-1','1 OR 1=1','99999999999999999999999','1.5'] as $id) {$check(PatientValidator::id($id)===null,'ID inválido');}
$check(PatientValidator::id('1')===1,'ID válido');
foreach(['Administrador','Recepcionista','Medico','Paciente'] as $role) {
    $check(PatientAccess::allowed(['role'=>$role,'permissions'=>['gestionar_pacientes']])===in_array($role,['Administrador','Recepcionista'],true),'Roles exactos');
    $check(!PatientAccess::allowed(['role'=>$role,'permissions'=>[]]),'Sin permiso denegado');
}
$store=new class implements PatientStore {
    public array $rows=[]; public int $writes=0; public bool $stale=false;
    public function count(string $search): int {return count($this->rows);}
    public function listing(string $search,int $limit,int $offset): array {return array_slice(array_values($this->rows),$offset,$limit);}
    public function find(int $id): ?array {return $this->rows[$id] ?? null;}
    public function duplicates(array $data,?int $exclude): array {
        foreach($this->rows as $id=>$row) {if($id!==$exclude && $row['documento_identidad']===$data['documento_identidad']) {return ['documento_identidad'=>'Documento duplicado.'];}}
        return [];
    }
    public function save(array $data,?int $id,?string $version,int $actor): int {
        if($this->stale) {throw new PatientConflict('Registro cambiado.');}
        ++$this->writes; $id ??= count($this->rows)+1;
        $this->rows[$id]=$data+['id_paciente'=>$id,'updated_at'=>'version-memoria']; return $id;
    }
};
$actor=['id'=>1,'role'=>'Administrador','permissions'=>['gestionar_pacientes']];
$readonly=new PacienteService($store);
try{$readonly->save($input,null,$actor);$check(false,'Bloqueo de escritura');}catch(RuntimeException $e){$check($store->writes===0,'Sin habilitación no escribe');}
$service=new PacienteService($store,true);
$id=$service->save($input,null,$actor);
$check($id===1 && $store->writes===1,'Alta simulada');
try{$service->save($input,null,$actor);$check(false,'Duplicado');}catch(PatientValidation $e){$check(isset($e->errors['documento_identidad']) && $store->writes===1,'Duplicado sin escritura');}
$edited=$input+['version'=>'version-memoria']; $edited['nombres']='Nombre editado';
$service->save($edited,1,$actor);
$check($store->rows[1]['nombres']==='Nombre editado' && $store->writes===2,'Edición simulada');
$store->stale=true;
try{$service->save($edited,1,$actor);$check(false,'Concurrencia');}catch(PatientConflict $e){$check($store->writes===2,'Conflicto no sobrescribe');}
foreach(['Medico','Paciente'] as $role) {
    try{$service->save($input,null,['id'=>2,'role'=>$role,'permissions'=>['gestionar_pacientes']]);$check(false,'Escritura no autorizada');}catch(RuntimeException $e){$check($store->writes===2,'Sin acceso no escribe');}
}
try{$service->save(['nombres'=>[]],null,$actor);$check(false,'Validación de servicio');}catch(PatientValidation $e){$check($store->writes===2,'Servicio valida sin navegador');}
$check($service->find(999)===null,'Registro inexistente');
for($i=2;$i<=30;$i++) {$store->rows[$i]=$store->rows[1]+[];}
$page=$service->page('',999);
$check($page['page']===3 && $page['total']===30 && count($page['items'])===10,'Paginación acotada');
$routes=require dirname(__DIR__).'/backend/routes/web.php';
foreach(['/pacientes','/pacientes/detalle','/pacientes/nuevo','/pacientes/editar'] as $path) {
    $check($routes[$path]['permission']==='gestionar_pacientes' && $routes[$path]['roles']===['Administrador','Recepcionista'],'Guardas de ruta');
}
ob_start();(new Clinica\Controllers\PacienteController())->index(['role'=>'Paciente','permissions'=>[]]);$output=ob_get_clean();
$check(http_response_code()===403 && str_contains($output,'autorización'),'Controlador rechaza antes de consultar BD');
http_response_code(200);
$_SESSION=['csrf'=>bin2hex(random_bytes(32))];
$controller=new Clinica\Controllers\PacienteController();
$render=new ReflectionMethod($controller,'render');
$render->setAccessible(true);
$patient=$result['data']+['id_paciente'=>1,'cuenta_usuario'=>null,'updated_at'=>'version'];
$patient['nombres']='<script>alert(1)</script>';
ob_start();$render->invoke($controller,'show',['user'=>$actor+['username'=>'memoria'],'title'=>'Detalle','patient'=>$patient]);$html=ob_get_clean();
$check(str_contains($html,'&lt;script&gt;') && !str_contains($html,'<script>alert(1)'),'Detalle escapa HTML');
$check(!str_contains($html,'alergias') && !str_contains($html,'contrasena_hash'),'Detalle sin datos clínicos ni hashes');
ob_start();$render->invoke($controller,'form',['user'=>$actor+['username'=>'memoria'],'title'=>'Alta','values'=>$input,'errors'=>[],'version'=>'','id'=>null,'editing'=>false,'writesEnabled'=>false]);$html=ob_get_clean();
$check(str_contains($html,'name="csrf"') && str_contains($html,'disabled'),'Formulario protegido y guardado bloqueado');
echo "$checks comprobaciones de pacientes pasaron: escrituras solo simuladas, sin BD.\n";
