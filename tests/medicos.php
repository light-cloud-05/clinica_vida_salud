<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/bootstrap.php';
use Clinica\Services\MedicalValidator as V;
use Clinica\Services\MedicalValidation;
use Clinica\Services\MedicoService;
use Clinica\Repositories\MedicalStore;
use Clinica\Middleware\MedicalAccess;
use Clinica\Support\Input;
$checks=0;$check=static function(bool $ok,string $label) use (&$checks): void{if(!$ok){throw new RuntimeException('FALLO: '.$label);}++$checks;};
$doctor=['codigo_medico'=>'SIM-MED','registro_profesional'=>'SIM-REG','nombres'=>'María','apellidos'=>'Prueba','estado'=>'activo','especialidades'=>['1']];
$specialty=['nombre'=>'Especialidad simulada','descripcion'=>'','precio_consulta'=>'75.50','estado'=>'activo'];
foreach(['medico'=>$doctor,'especialidad'=>$specialty] as $kind=>$input){
    $check(!V::validate($kind,$input)['errors'],'Datos válidos');
    foreach(V::fields($kind) as $field=>[$label,$max,$required]){
        if($required){$bad=$input;$bad[$field]='  ';$check(isset(V::validate($kind,$bad)['errors'][$field]),'Obligatorio '.$field);}
        if($max!==null){$bad=$input;$bad[$field]=str_repeat('ñ',$max+1);$check(isset(V::validate($kind,$bad)['errors'][$field]),'Longitud '.$field);}
    }
    foreach(['id_usuario'=>99,'id_medico'=>2,'id_especialidad'=>4,'rol'=>'Administrador'] as $key=>$value){$check(isset(V::validate($kind,$input+[$key=>$value])['errors']['_form']),'Campos no editables');}
}
foreach(['0','0.00','-1','1e3','NaN','100.001','99999999999.00','1,50',''] as $price){$check(V::price($price)===null,'Precio inválido');}
foreach(['0.01'=>'0.01','9999999999.99'=>'9999999999.99','0001.5'=>'1.50','15'=>'15.00'] as $price=>$expected){$check(V::price((string)$price)===$expected,'Decimal exacto');}
foreach([['1','1'],['0'],['-1'],[['1']],['9999999999999999999999'],'1',['clave'=>'1']] as $ids){$bad=$doctor;$bad['especialidades']=$ids;$check(isset(V::validate('medico',$bad)['errors']['especialidades']),'IDs de asociación inválidos');}
$none=$doctor;$none['especialidades']=[];$check(!V::validate('medico',$none)['errors'],'Sin mínimo inventado de asociaciones');
foreach([['x'],"abc\0def","\xFF"] as $name){$bad=$doctor;$bad['nombres']=$name;$check(isset(V::validate('medico',$bad)['errors']['nombres']),'Datos malformados');}
$bad=$doctor;$bad['correo_contacto']='invalido';$check(isset(V::validate('medico',$bad)['errors']['correo_contacto']),'Correo inválido');
$check(V::associationVersion([2,1])===V::associationVersion([1,2]),'Versión de asociaciones estable');
foreach([null,'0','-1','1 OR 1=1',[],'9999999999999999999999999'] as $id){$check(Input::positiveId($id)===null,'ID inválido');}
foreach(['Administrador','Recepcionista','Medico','Paciente'] as $role){$check(MedicalAccess::allowed(['role'=>$role,'permissions'=>['gestionar_medicos']])===($role==='Administrador'),'Acceso por rol');$check(!MedicalAccess::allowed(['role'=>$role,'permissions'=>[]]),'Permiso requerido');}
$store=new class implements MedicalStore {
    public int $writes=0;public array $last=[];
    public function countDoctors(string $search,?int $specialty): int{return 18;}
    public function doctors(string $search,?int $specialty,int $limit,int $offset): array{return array_slice(range(1,18),$offset,$limit);}
    public function specialties(): array{return [['id_especialidad'=>1],['id_especialidad'=>2]];}
    public function find(string $kind,int $id): ?array{return $id===1 ? ['especialidades'=>[['id_especialidad'=>1,'tiene_citas'=>1]]] : null;}
    public function duplicates(string $kind,array $data,?int $exclude): array{return [];}
    public function save(string $kind,array $data,array $specialties,?int $id,?string $version,?string $associationVersion,int $actor): int{++$this->writes;$this->last=compact('kind','data','specialties','actor');return $id ?? 101;}
};
$actor=['id'=>1,'role'=>'Administrador','permissions'=>['gestionar_medicos'],'username'=>'simulado'];
$service=new MedicoService($store,true);
$check($service->save('medico',$doctor,null,$actor)===101 && $store->writes===1,'Alta simulada');
$edit=$doctor+['version'=>'v1','asociaciones_version'=>V::associationVersion([1])];$edit['especialidades']=['1','2'];
$check($service->save('medico',$edit,1,$actor)===1 && $store->last['specialties']===[1,2],'Edición y asociación simuladas');
$removed=$edit;$removed['especialidades']=['2'];
try{$service->save('medico',$removed,1,$actor);$check(false,'Retirar cita');}catch(MedicalValidation $e){$check(isset($e->errors['especialidades']) && $store->writes===2,'Asociación referenciada conservada');}
$bad=$doctor;$bad['especialidades']=['99'];$check(isset($service->validate('medico',$bad,null)['errors']['especialidades']),'FK desconocida');
$check($service->save('especialidad',$specialty,null,$actor)===101 && $store->last['data']['precio_consulta']==='75.50','Especialidad simulada y decimal sin float');
foreach(['Medico','Recepcionista','Paciente'] as $role){try{$service->save('medico',$doctor,null,['id'=>2,'role'=>$role,'permissions'=>['gestionar_medicos']]);$check(false,'Permiso');}catch(RuntimeException $e){$check($store->writes===3,'Otro rol no escribe');}}
try{(new MedicoService($store))->save('medico',$doctor,null,$actor);$check(false,'Bloqueo');}catch(RuntimeException $e){$check($store->writes===3,'Guardado desactivado');}
$invalidVersion=$edit;$invalidVersion['asociaciones_version']=[];
try{$service->save('medico',$invalidVersion,1,$actor);$check(false,'Versión inválida');}catch(MedicalValidation $e){$check($store->writes===3,'Valida versión antes de guardar');}
$page=$service->page('',999,null);$check($page['page']===2 && $page['total']===18 && count($page['items'])===8,'Paginación de 18');
$routes=require dirname(__DIR__).'/backend/routes/web.php';
foreach(['/medicos','/medicos/detalle','/medicos/nuevo','/medicos/editar','/especialidades','/especialidades/nueva','/especialidades/editar'] as $route){$check($routes[$route]['role']==='Administrador' && $routes[$route]['permission']==='gestionar_medicos','Guarda de ruta');}
$_SESSION=['csrf'=>bin2hex(random_bytes(32))];
ob_start();(new Clinica\Controllers\CatalogoMedicoController())->index(['role'=>'Medico','permissions'=>['gestionar_medicos']]);ob_end_clean();$check(http_response_code()===403,'Controlador bloquea antes de consultas');http_response_code(200);
$controller=new Clinica\Controllers\CatalogoMedicoController();$render=new ReflectionMethod($controller,'render');$render->setAccessible(true);
$record=V::validate('medico',$doctor)['data']+['id_medico'=>1,'cuenta_usuario'=>null,'especialidades'=>[]];$record['nombres']='<script>alert(1)</script>';
ob_start();$render->invoke($controller,'show',['user'=>$actor,'title'=>'Detalle','section'=>'medicos','doctor'=>$record]);$html=ob_get_clean();$check(str_contains($html,'&lt;script&gt;') && !str_contains($html,'<script>alert(1)'),'Escape de salida');
ob_start();$render->invoke($controller,'form',['user'=>$actor,'title'=>'Alta','section'=>'medicos','kind'=>'medico','editing'=>false,'id'=>null,'values'=>$doctor,'selected'=>[],'protected'=>[],'specialties'=>[],'version'=>'','associationVersion'=>'','errors'=>[],'writesEnabled'=>false]);$html=ob_get_clean();$check(str_contains($html,'name="csrf"') && str_contains($html,'disabled'),'Formulario protegido y bloqueado');
echo "$checks comprobaciones de P3 pasaron con datos en memoria; sin PostgreSQL.\n";
