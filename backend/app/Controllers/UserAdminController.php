<?php
declare(strict_types=1);
namespace Clinica\Controllers;

use Clinica\Services\SessionService;
use Clinica\Services\UserAdminValidation;
use Clinica\Services\UserAdminValidator;
use Clinica\Support\Input;
use Clinica\Support\UserAdminFactory;

final class UserAdminController
{
    private function id(): ?int
    {
        $id = Input::positiveId($_GET['id'] ?? null);
        if ($id === null) { http_response_code(400); echo 'Identificador de usuario inválido.'; }
        return $id;
    }

    public function index(array $user): void
    {
        $search = $_GET['q'] ?? '';
        $page = Input::positiveId($_GET['pagina'] ?? '1');
        $roleRaw = $_GET['rol'] ?? '';
        $role = $roleRaw === '' ? null : Input::positiveId($roleRaw);
        $status = $_GET['estado'] ?? '';
        if (!is_string($search) || !mb_check_encoding($search, 'UTF-8') || str_contains($search, "\0") || mb_strlen($search, 'UTF-8') > 200 || $page === null || ($roleRaw !== '' && $role === null) || ($status !== '' && !in_array($status, ['activo','inactivo'], true))) {
            http_response_code(400); echo 'Los parámetros de búsqueda no son válidos.'; return;
        }
        $service = UserAdminFactory::service();
        $roles = $service->roles();
        if ($role !== null && !in_array($role, array_map(static fn(array $item): int => (int)$item['id_rol'], $roles), true)) { http_response_code(404); echo 'Rol no encontrado.'; return; }
        $search = trim($search); $status = $status === '' ? null : $status;
        $result = $service->page($search, $role, $status, $page);
        $title = 'Administración de usuarios'; $section = 'usuarios'; $sectionLabel = 'Administración de usuarios';
        $this->render('index', compact('user','title','section','sectionLabel','result','search','role','status','roles'));
    }

    public function show(array $user): void
    {
        $id = $this->id(); if ($id === null) { return; }
        $service = UserAdminFactory::service(); $account = $service->find($id);
        if (!$account) { http_response_code(404); echo 'Usuario no encontrado.'; return; }
        $permissions = $service->permissionsByRole()[(int)$account['id_rol']]['permissions'] ?? [];
        $title = 'Detalle de cuenta'; $section = 'usuarios'; $sectionLabel = 'Administración de usuarios';
        $this->render('show', compact('user','title','section','sectionLabel','account','permissions'));
    }

    public function roles(array $user): void
    {
        $title = 'Roles y permisos'; $section = 'usuarios'; $sectionLabel = 'Administración de usuarios';
        $roles = UserAdminFactory::service()->permissionsByRole();
        $this->render('roles', compact('user','title','section','sectionLabel','roles'));
    }

    public function create(array $user): void { $this->form($user, false, false); }
    public function store(array $user): void { $this->form($user, false, true); }
    public function edit(array $user): void { $this->form($user, true, false); }
    public function update(array $user): void { $this->form($user, true, true); }

    private function form(array $user, bool $editing, bool $posting): void
    {
        $id = $editing ? $this->id() : null; if ($editing && $id === null) { return; }
        $service = UserAdminFactory::service(); $account = $editing ? $service->find($id) : null;
        if ($editing && !$account) { http_response_code(404); echo 'Usuario no encontrado.'; return; }
        $roles = $service->roles(); $values = $account ?? ['nombre_usuario'=>'','correo_acceso'=>'','id_rol'=>'','estado'=>'inactivo'];
        $errors = []; $writesEnabled = UserAdminFactory::writesEnabled();
        if ($posting) {
            if (!SessionService::validCsrf($_POST['csrf'] ?? null)) { http_response_code(419); echo 'El formulario venció. Recargue la página.'; return; }
            $validated = UserAdminValidator::validate($_POST, $roles, $editing);
            $errors = $validated['errors'];
            $values = array_merge($values, ['nombre_usuario'=>$_POST['nombre_usuario'] ?? '', 'correo_acceso'=>$_POST['correo_acceso'] ?? '', 'id_rol'=>$_POST['id_rol'] ?? '', 'estado'=>$_POST['estado'] ?? '']);
            if ($errors) { http_response_code(422); }
            elseif (!$writesEnabled) { http_response_code(423); $errors['_form'] = 'El guardado real está deshabilitado. La simulación no modifica PostgreSQL.'; }
            else {
                try {
                    $saved = UserAdminFactory::service(true)->save($_POST, $id, $user);
                    $_SESSION['user_admin_success'] = $editing ? 'La cuenta se actualizó correctamente.' : 'La cuenta se creó correctamente.';
                    header('Location: /usuarios/detalle?id=' . $saved, true, 303); return;
                } catch (UserAdminValidation $e) { http_response_code(422); $errors = $e->errors; }
                catch (\Throwable $e) { http_response_code(503); $errors['_form'] = 'No se pudo guardar la cuenta. No se confirmaron cambios.'; }
            }
        }
        $title = $editing ? 'Editar cuenta' : 'Crear usuario'; $section = 'usuarios'; $sectionLabel = 'Administración de usuarios';
        $this->render('form', compact('user','title','section','sectionLabel','editing','id','values','roles','errors','writesEnabled'));
    }

    private function render(string $template, array $variables): void
    {
        extract($variables, EXTR_SKIP);
        $escape = static fn(mixed $value): string => htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $success = $_SESSION['user_admin_success'] ?? null; unset($_SESSION['user_admin_success']);
        require dirname(__DIR__, 3) . '/frontend/views/layouts/management-header.php';
        require dirname(__DIR__, 3) . '/frontend/views/usuarios/' . $template . '.php';
        require dirname(__DIR__, 3) . '/frontend/views/layouts/management-footer.php';
    }
}
