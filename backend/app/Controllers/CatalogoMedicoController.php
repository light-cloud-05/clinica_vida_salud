<?php
declare(strict_types=1);
namespace Clinica\Controllers;
use Clinica\Middleware\MedicalAccess;
use Clinica\Support\Input;
use Clinica\Support\MedicalFactory;
use Clinica\Services\MedicalValidator;
use Clinica\Services\MedicalValidation;
use Clinica\Services\MedicalConflict;
use Clinica\Services\SessionService;

final class CatalogoMedicoController
{
    private function authorized(array $user): bool
    {
        if(MedicalAccess::allowed($user)){return true;}http_response_code(403);echo 'No tiene autorización para gestionar médicos y especialidades.';return false;
    }
    private function id(): ?int
    {
        $id=Input::positiveId($_GET['id'] ?? null);if($id===null){http_response_code(400);echo 'Identificador inválido.';}return $id;
    }
    public function index(array $user): void
    {
        if(!$this->authorized($user)){return;}
        $search=$_GET['q'] ?? '';$page=Input::positiveId($_GET['pagina'] ?? '1');$raw=$_GET['especialidad'] ?? '';
        $specialty=$raw==='' ? null : Input::positiveId($raw);
        if(!is_string($search) || !mb_check_encoding($search,'UTF-8') || str_contains($search,"\0") || mb_strlen($search,'UTF-8')>200 || $page===null || ($raw!=='' && $specialty===null)){http_response_code(400);echo 'Parámetros de búsqueda inválidos.';return;}
        $search=trim($search);$service=MedicalFactory::service();$specialties=$service->specialties();
        if($specialty!==null && !in_array($specialty,array_map(static fn(array $e): int=>(int)$e['id_especialidad'],$specialties),true)){http_response_code(404);echo 'Especialidad no encontrada.';return;}
        $result=$service->page($search,$page,$specialty);$title='Médicos';$section='medicos';
        $this->render('index',compact('user','title','section','result','search','specialty','specialties'));
    }
    public function specialties(array $user): void
    {
        if(!$this->authorized($user)){return;}$specialties=MedicalFactory::service()->specialties();$title='Especialidades';$section='especialidades';$this->render('specialties',compact('user','title','section','specialties'));
    }
    public function show(array $user): void
    {
        if(!$this->authorized($user) || ($id=$this->id())===null){return;}
        $doctor=MedicalFactory::service()->find('medico',$id);if(!$doctor){http_response_code(404);echo 'Médico no encontrado.';return;}
        $title='Detalle del médico';$section='medicos';$this->render('show',compact('user','title','section','doctor'));
    }
    public function create(array $user): void{$this->form($user,'medico',false,false);}
    public function store(array $user): void{$this->form($user,'medico',false,true);}
    public function edit(array $user): void{$this->form($user,'medico',true,false);}
    public function update(array $user): void{$this->form($user,'medico',true,true);}
    public function createSpecialty(array $user): void{$this->form($user,'especialidad',false,false);}
    public function storeSpecialty(array $user): void{$this->form($user,'especialidad',false,true);}
    public function editSpecialty(array $user): void{$this->form($user,'especialidad',true,false);}
    public function updateSpecialty(array $user): void{$this->form($user,'especialidad',true,true);}

    private function form(array $user,string $kind,bool $editing,bool $posting): void
    {
        if(!$this->authorized($user)){return;}
        if($posting && !SessionService::validCsrf($_POST['csrf'] ?? null)){http_response_code(419);echo 'El formulario venció. Recargue la página.';return;}
        $id=$editing ? $this->id() : null;if($editing && $id===null){return;}
        $service=MedicalFactory::service();$record=$editing ? $service->find($kind,$id) : null;
        if($editing && !$record){http_response_code(404);echo 'Registro no encontrado.';return;}
        $values=$record ?? array_fill_keys(array_keys(MedicalValidator::fields($kind)),'');if(!$editing){$values['estado']='activo';}
        $specialties=$kind==='medico' ? $service->specialties() : [];
        $selected=array_map(static fn(array $e): int=>(int)$e['id_especialidad'],$record['especialidades'] ?? []);
        $protected=[];foreach($record['especialidades'] ?? [] as $specialty){if((int)$specialty['tiene_citas']===1){$protected[]=(int)$specialty['id_especialidad'];}}
        $version=$record['updated_at'] ?? '';$associationVersion=MedicalValidator::associationVersion($selected);$errors=[];$writesEnabled=MedicalFactory::writesEnabled();
        if($posting){
            $result=$service->validate($kind,$_POST,$id);$values=$result['data'];$selected=$result['ids'];$errors=$result['errors'];
            $version=is_string($_POST['version'] ?? null) ? $_POST['version'] : '';
            $associationVersion=is_string($_POST['asociaciones_version'] ?? null) ? $_POST['asociaciones_version'] : '';
            if($errors){http_response_code(422);}
            elseif(!$writesEnabled){http_response_code(423);$errors['_form']='El guardado real está bloqueado hasta autorizar las pruebas de escritura.';}
            else{
                try{
                    $saved=MedicalFactory::service(true)->save($kind,$_POST,$id,$user);
                    $_SESSION['medical_success']=$editing ? 'Los cambios se guardaron correctamente.' : 'El registro se creó correctamente.';
                    header('Location: '.($kind==='medico' ? '/medicos/detalle?id='.$saved : '/especialidades'),true,303);return;
                }catch(MedicalValidation $e){http_response_code(422);$errors=$e->errors;}
                catch(MedicalConflict $e){http_response_code(409);$errors['_form']=$e->getMessage();}
                catch(\Throwable $e){http_response_code(503);$errors['_form']='No se pudo completar el guardado. Inténtelo de nuevo.';}
            }
        }
        $section=$kind==='medico' ? 'medicos' : 'especialidades';$title=($editing ? 'Editar ' : 'Registrar ').($kind==='medico' ? 'médico' : 'especialidad');
        $this->render('form',compact('user','title','section','kind','editing','id','values','selected','protected','specialties','version','associationVersion','errors','writesEnabled'));
    }
    private function render(string $template,array $variables): void
    {
        extract($variables,EXTR_SKIP);$escape=static fn(mixed $value): string=>htmlspecialchars((string)($value ?? ''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $success=$_SESSION['medical_success'] ?? null;unset($_SESSION['medical_success']);$sectionLabel='Médicos y especialidades';
        $views=dirname(__DIR__,3).'/frontend/views/';
        require $views.'layouts/management-header.php';require $views.'medicos/'.$template.'.php';require $views.'layouts/management-footer.php';
    }
}
