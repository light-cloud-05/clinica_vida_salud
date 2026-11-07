<?php
declare(strict_types=1);
namespace Clinica\Services;

use Clinica\Middleware\AuthorizationMiddleware;
use Clinica\Repositories\UserAdminStore;

final class UserAdminService
{
    public function __construct(private UserAdminStore $users, private bool $writesEnabled = false) {}

    public function page(string $search, ?int $roleId, ?string $status, int $page): array
    {
        $total = $this->users->count($search, $roleId, $status);
        $pages = max(1, (int)ceil($total / 10));
        $page = max(1, min($page, $pages));
        return ['items' => $this->users->listing($search, $roleId, $status, 10, ($page - 1) * 10), 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    public function find(int $id): ?array { return $id > 0 ? $this->users->find($id) : null; }
    public function roles(): array { return $this->users->roles(); }

    public function permissionsByRole(): array
    {
        $result = [];
        foreach ($this->users->rolePermissions() as $row) {
            $id = (int)$row['id_rol'];
            $result[$id] ??= ['name' => $row['rol'], 'description' => $row['rol_descripcion'], 'permissions' => []];
            if ($row['permiso'] !== null) { $result[$id]['permissions'][] = ['name' => $row['permiso'], 'description' => $row['descripcion']]; }
        }
        return $result;
    }

    public function save(array $input, ?int $id, array $actor): int
    {
        if (!AuthorizationMiddleware::allows($actor, 'Administrador', 'gestionar_usuarios') || !$this->writesEnabled) { throw new \RuntimeException('La operación no está autorizada o está bloqueada.'); }
        if ($id !== null && $id === (int)$actor['id']) { throw new UserAdminValidation(['_form' => 'No puede modificar su propia cuenta desde este módulo.']); }
        $roles = $this->users->roles();
        $result = UserAdminValidator::validate($input, $roles, $id !== null);
        if ($result['errors']) { throw new UserAdminValidation($result['errors']); }
        $duplicates = $this->users->duplicates($result['data']['username'], $result['data']['email'], $id);
        if ($duplicates) { throw new UserAdminValidation($duplicates); }
        $current = $id === null ? null : $this->users->find($id);
        if ($id !== null && !$current) { throw new UserAdminValidation(['_form' => 'La cuenta no existe.']); }
        $roleNames = array_column($roles, 'nombre', 'id_rol');
        $newRole = $roleNames[$result['data']['role']] ?? '';
        if ($current && (($current['id_paciente'] !== null && $newRole !== 'Paciente') || ($current['id_medico'] !== null && $newRole !== 'Medico'))) {
            throw new UserAdminValidation(['id_rol' => 'No se puede cambiar el rol porque la cuenta está vinculada a un perfil relacionado.']);
        }
        if ($current && $current['rol'] === 'Administrador' && $current['estado'] === 'activo'
            && ($result['data']['status'] === 'inactivo' || $newRole !== 'Administrador')
            && $this->users->activeAdministrators() <= 1) {
            throw new UserAdminValidation(['estado' => 'No se puede desactivar la última cuenta administrativa activa.']);
        }
        return $this->users->save($result['data'], $id, (int)$actor['id']);
    }
}
