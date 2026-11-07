<?php
declare(strict_types=1);
namespace Clinica\Controllers;

use Clinica\Middleware\PatientAccess;
use Clinica\Services\PatientValidator;
use Clinica\Services\PatientValidation;
use Clinica\Services\PatientConflict;
use Clinica\Services\SessionService;
use Clinica\Support\PatientFactory;

final class PacienteController
{
    private function authorized(array $user): bool
    {
        if(PatientAccess::allowed($user)) {return true;}
        http_response_code(403); echo 'No tiene autorización para gestionar pacientes.'; return false;
    }

    private function id(): ?int
    {
        $id=PatientValidator::id($_GET['id'] ?? null);
        if($id===null) {http_response_code(400); echo 'Identificador de paciente inválido.';}
        return $id;
    }

    public function index(array $user): void
    {
        if(!$this->authorized($user)) {return;}
        $search=$_GET['q'] ?? '';
        $page=PatientValidator::id($_GET['pagina'] ?? '1');
        if(!is_string($search) || !mb_check_encoding($search,'UTF-8') || str_contains($search,"\0") || mb_strlen($search,'UTF-8')>200 || $page===null) {
            http_response_code(400); echo 'Los parámetros de búsqueda no son válidos.'; return;
        }
        $search=trim($search);
        $result=PatientFactory::service()->page($search,$page);
        $title='Pacientes';
        $this->render('index',compact('user','title','result','search'));
    }

    public function show(array $user): void
    {
        if(!$this->authorized($user) || ($id=$this->id())===null) {return;}
        $patient=PatientFactory::service()->find($id);
        if(!$patient) {http_response_code(404); echo 'Paciente no encontrado.'; return;}
        $title='Detalle del paciente';
        $this->render('show',compact('user','title','patient'));
    }

    public function create(array $user): void { $this->form($user,false,false); }
    public function store(array $user): void { $this->form($user,false,true); }
    public function edit(array $user): void { $this->form($user,true,false); }
    public function update(array $user): void { $this->form($user,true,true); }

    private function form(array $user, bool $editing, bool $posting): void
    {
        if(!$this->authorized($user)) {return;}
        if($posting && !SessionService::validCsrf($_POST['csrf'] ?? null)) {
            http_response_code(419); echo 'El formulario venció. Recargue la página e inténtelo de nuevo.'; return;
        }
        $id=$editing ? $this->id() : null;
        if($editing && $id===null) {return;}
        $patient=$editing ? PatientFactory::service()->find($id) : null;
        if($editing && !$patient) {http_response_code(404); echo 'Paciente no encontrado.'; return;}
        $values=$patient ?? array_fill_keys(array_keys(PatientValidator::FIELDS),'');
        if(!$editing) {$values['estado']='activo';}
        $version=$patient['updated_at'] ?? '';
        $errors=[];
        $writesEnabled=PatientFactory::writesEnabled();
        if($posting) {
            $validated=PatientValidator::validate($_POST);
            $values=$validated['data']; $errors=$validated['errors'];
            $version=is_string($_POST['version'] ?? null) ? $_POST['version'] : '';
            if($errors) {http_response_code(422);}
            elseif(!$writesEnabled) {
                http_response_code(423); $errors['_form']='El guardado real está bloqueado hasta autorizar las pruebas de escritura.';
            } else {
                try {
                    $saved=PatientFactory::service(true)->save($_POST,$id,$user);
                    $_SESSION['patient_success']=$editing ? 'Los datos del paciente se actualizaron correctamente.' : 'El paciente se registró correctamente.';
                    header('Location: /pacientes/detalle?id='.$saved,true,303); return;
                } catch(PatientValidation $e) {http_response_code(422); $errors=$e->errors;}
                catch(PatientConflict $e) {http_response_code(409); $errors['_form']=$e->getMessage();}
                catch(\Throwable $e) {http_response_code(503); $errors['_form']='No se pudo guardar el paciente. No se confirmaron cambios. Inténtelo de nuevo.';}
            }
        }
        $title=$editing ? 'Editar paciente' : 'Registrar paciente';
        $this->render('form',compact('user','title','values','errors','version','id','editing','writesEnabled'));
    }

    private function render(string $template, array $variables): void
    {
        extract($variables,EXTR_SKIP);
        $escape=static fn(mixed $value): string=>htmlspecialchars((string)($value ?? ''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $success=$_SESSION['patient_success'] ?? null;
        unset($_SESSION['patient_success']);
        $base=dirname(__DIR__,3).'/frontend/views/pacientes/';
        require $base.'header.php'; require $base.$template.'.php'; require $base.'footer.php';
    }
}
