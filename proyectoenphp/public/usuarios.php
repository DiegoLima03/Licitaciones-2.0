<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../src/Repositories/AuthRepository.php';
require_once __DIR__ . '/../src/Repositories/PermissionsRepository.php';
require_once __DIR__ . '/../src/Repositories/ReservasDisponibleRepository.php';
require_once __DIR__ . '/../src/Repositories/DisponibleRepository.php';

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

/** @var array<string, mixed> $sessionUser */
$sessionUser = $_SESSION['user'];

$actorId = trim((string)($sessionUser['id'] ?? ''));
$actorEmail = trim((string)($sessionUser['email'] ?? ''));
$actorFullName = trim((string)($sessionUser['full_name'] ?? ''));
$actorRoleRaw = trim((string)($sessionUser['role'] ?? 'member_licitaciones'));

/**
 * @var array<string, string>
 */
$roles = [
    'admin' => 'Administrador',
    'admin_planta' => 'Administrador planta',
    'admin_licitaciones' => 'Administrador licitaciones',
    'member_planta' => 'Miembro planta',
    'member_licitaciones' => 'Miembro licitaciones',
];

/**
 * Jerarquia de borrado: actor -> roles que puede eliminar.
 *
 * @var array<string, array<int, string>>
 */
$rolesActorCanDelete = [
    'admin' => ['admin_planta', 'admin_licitaciones', 'member_planta', 'member_licitaciones'],
    'admin_planta' => ['member_planta'],
    'admin_licitaciones' => ['member_licitaciones'],
    'member_planta' => [],
    'member_licitaciones' => [],
];

$normalizeRole = static function (?string $value) use ($roles): string {
    $role = mb_strtolower(trim((string)$value));
    if ($role === 'member') {
        return 'member_licitaciones';
    }
    if ($role === '' || !array_key_exists($role, $roles)) {
        return 'member_licitaciones';
    }
    return $role;
};

$actorRole = $normalizeRole($actorRoleRaw);

$authRepo = new AuthRepository();
$permissionsRepo = new PermissionsRepository();
$reservasRepo = new ReservasDisponibleRepository();
$disponibleRepo = new DisponibleRepository();

/**
 * Tipos de cliente disponibles.
 * @var array<string, string>
 */
$tiposCliente = [
    'mayorista'    => 'Mayorista',
    'minorista'    => 'Minorista',
    'garden'       => 'Garden Center',
    'florista'     => 'Florista',
    'distribuidor' => 'Distribuidor',
    'online'       => 'Online',
    'otro'         => 'Otro',
];

/**
 * Columnas de precio disponibles.
 * @var array<string, string>
 */
$tarifasDisponibles = [
    'precio_x_unid'           => 'Precio x Unidad',
    'precio_x_unid_diplad_m7' => 'Precio x Unid Diplad M7',
    'precio_x_unid_almeria'   => 'Precio x Unid Almeria',
    'precio_t5_directo'       => 'Tarifa T5 Directo',
    'precio_t5_almeria'       => 'Tarifa T5 Almeria',
    'precio_t10'              => 'Tarifa T10',
    'precio_t15'              => 'Tarifa T15',
    'precio_dipladen_t25'     => 'Tarifa Dipladen T25',
    'precio_t25'              => 'Tarifa T25',
];

// Obtener zonas disponibles desde la BD
$zonasDisponibles = [];
try {
    $zonasDisponibles = $disponibleRepo->getZonas();
} catch (\Throwable) {
    // Sin zonas disponibles, el campo será de texto libre
}

$canManageUsers = false;
if ($actorRole === 'admin') {
    $canManageUsers = true;
} else {
    try {
        $canManageUsers = $authRepo->canManageUsersByRoleFeature($actorRole);
    } catch (\Throwable) {
        try {
            $perms = $permissionsRepo->getPermissionsForRole($actorRole);
            $canManageUsers = (bool)($perms['usuarios'] ?? false);
        } catch (\Throwable) {
            $canManageUsers = false;
        }
    }
}

if (!$canManageUsers) {
    header('Location: dashboard.php');
    exit;
}

$setFlash = static function (string $type, string $message): void {
    $_SESSION['usuarios_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
};

$setModal = static function (string $type, string $error, array $data = []): void {
    $_SESSION['usuarios_modal'] = [
        'type' => $type,
        'error' => $error,
        'data' => $data,
    ];
};

$clearModal = static function (): void {
    unset($_SESSION['usuarios_modal']);
};

$generateUuidV4 = static function (): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'create_user') {
            $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $roleInput = mb_strtolower(trim((string)($_POST['role'] ?? '')));
            $password = (string)($_POST['password'] ?? '');
            $passwordConfirm = (string)($_POST['password_confirm'] ?? '');

            $formData = [
                'email' => $email,
                'full_name' => $fullName,
                'role' => $roleInput !== '' ? $roleInput : 'member_licitaciones',
            ];

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $setModal('create', 'Debes indicar un email valido.', $formData);
                header('Location: usuarios.php');
                exit;
            }
            if (!array_key_exists($roleInput, $roles)) {
                $setModal('create', 'El rol seleccionado no es valido.', $formData);
                header('Location: usuarios.php');
                exit;
            }
            if (mb_strlen($password) < 6) {
                $setModal('create', 'La contrasena debe tener al menos 6 caracteres.', $formData);
                header('Location: usuarios.php');
                exit;
            }
            if ($password !== $passwordConfirm) {
                $setModal('create', 'Las contrasenas no coinciden.', $formData);
                header('Location: usuarios.php');
                exit;
            }
            if ($authRepo->emailExists($email)) {
                $setModal('create', 'Ya existe un usuario con ese email.', $formData);
                header('Location: usuarios.php');
                exit;
            }

            $authRepo->createUser(
                $generateUuidV4(),
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                $roleInput,
                $fullName !== '' ? $fullName : null
            );
            $clearModal();
            $setFlash('success', 'Usuario creado correctamente.');
        } elseif ($action === 'update_role') {
            $userId = trim((string)($_POST['user_id'] ?? ''));
            $newRole = mb_strtolower(trim((string)($_POST['role'] ?? '')));
            if ($userId === '') {
                $setFlash('error', 'No se recibio el usuario a actualizar.');
                header('Location: usuarios.php');
                exit;
            }
            if (!array_key_exists($newRole, $roles)) {
                $setFlash('error', 'Rol no valido.');
                header('Location: usuarios.php');
                exit;
            }

            $target = $authRepo->findById($userId);
            if ($target === null) {
                $setFlash('error', 'El usuario ya no existe.');
                header('Location: usuarios.php');
                exit;
            }

            $currentRole = $normalizeRole((string)($target['role'] ?? 'member_licitaciones'));
            if ($currentRole === $newRole) {
                $setFlash('success', 'Rol actualizado.');
                header('Location: usuarios.php');
                exit;
            }

            $updated = $authRepo->updateRole($userId, $newRole);
            if (!$updated) {
                $setFlash('error', 'No se pudo actualizar el rol del usuario.');
                header('Location: usuarios.php');
                exit;
            }

            $clearModal();
            $setFlash('success', 'Rol actualizado correctamente.');
        } elseif ($action === 'change_password') {
            $userId = trim((string)($_POST['user_id'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $passwordConfirm = (string)($_POST['password_confirm'] ?? '');

            $modalData = ['user_id' => $userId];
            if ($userId === '') {
                $setFlash('error', 'No se recibio el usuario para cambiar la contrasena.');
                header('Location: usuarios.php');
                exit;
            }
            if (mb_strlen($password) < 6) {
                $setModal('password', 'La contrasena debe tener al menos 6 caracteres.', $modalData);
                header('Location: usuarios.php');
                exit;
            }
            if ($password !== $passwordConfirm) {
                $setModal('password', 'Las contrasenas no coinciden.', $modalData);
                header('Location: usuarios.php');
                exit;
            }

            $target = $authRepo->findById($userId);
            if ($target === null) {
                $setFlash('error', 'El usuario ya no existe.');
                header('Location: usuarios.php');
                exit;
            }

            $updated = $authRepo->updatePassword($userId, password_hash($password, PASSWORD_DEFAULT));
            if (!$updated) {
                $setModal('password', 'No se pudo actualizar la contrasena.', $modalData);
                header('Location: usuarios.php');
                exit;
            }

            $clearModal();
            $setFlash('success', 'Contrasena actualizada correctamente.');
        } elseif ($action === 'delete_user') {
            $userId = trim((string)($_POST['user_id'] ?? ''));
            $modalData = ['user_id' => $userId];

            if ($userId === '') {
                $setFlash('error', 'No se recibio el usuario a eliminar.');
                header('Location: usuarios.php');
                exit;
            }
            if ($actorId !== '' && $userId === $actorId) {
                $setModal('delete', 'No puedes eliminar tu propio usuario.', $modalData);
                header('Location: usuarios.php');
                exit;
            }

            $target = $authRepo->findById($userId);
            if ($target === null) {
                $setFlash('error', 'El usuario ya no existe.');
                header('Location: usuarios.php');
                exit;
            }

            $targetRole = $normalizeRole((string)($target['role'] ?? 'member_licitaciones'));
            $allowedRoles = $rolesActorCanDelete[$actorRole] ?? [];
            if (!in_array($targetRole, $allowedRoles, true)) {
                $setModal('delete', 'No puedes eliminar un usuario con tu mismo rol o superior.', $modalData);
                header('Location: usuarios.php');
                exit;
            }

            $deleted = $authRepo->deleteUser($userId);
            if (!$deleted) {
                $setModal('delete', 'No se pudo eliminar el usuario.', $modalData);
                header('Location: usuarios.php');
                exit;
            }

            $clearModal();
            $setFlash('success', 'Usuario eliminado correctamente.');
        } elseif ($action === 'create_client') {
            $clientUserId = trim((string)($_POST['client_user_id'] ?? ''));
            $tipoCliente = trim((string)($_POST['tipo_cliente'] ?? 'minorista'));
            $zonasRaw = $_POST['zonas'] ?? [];
            $columnaPrecio = trim((string)($_POST['columna_precio'] ?? 'precio_x_unid'));
            $puedeReservar = isset($_POST['puede_reservar']);
            $clientNotas = trim((string)($_POST['client_notas'] ?? ''));

            $clientFormData = [
                'client_user_id' => $clientUserId,
                'tipo_cliente' => $tipoCliente,
                'zonas' => $zonasRaw,
                'columna_precio' => $columnaPrecio,
                'puede_reservar' => $puedeReservar,
                'client_notas' => $clientNotas,
            ];

            if ($clientUserId === '') {
                $setModal('create_client', 'Debes seleccionar un usuario.', $clientFormData);
                header('Location: usuarios.php#clientes');
                exit;
            }

            // Verificar que el usuario existe
            $targetUser = $authRepo->findById($clientUserId);
            if ($targetUser === null) {
                $setModal('create_client', 'El usuario seleccionado no existe.', $clientFormData);
                header('Location: usuarios.php#clientes');
                exit;
            }

            // Construir zonas
            $zonasStr = 'TODAS';
            if (is_array($zonasRaw) && count($zonasRaw) > 0) {
                $zonasStr = implode(',', array_map('trim', $zonasRaw));
            }

            if (!array_key_exists($columnaPrecio, $tarifasDisponibles)) {
                $columnaPrecio = 'precio_x_unid';
            }
            if (!array_key_exists($tipoCliente, $tiposCliente)) {
                $tipoCliente = 'minorista';
            }

            $reservasRepo->upsertClienteConfig(
                $clientUserId,
                $zonasStr,
                $columnaPrecio,
                $puedeReservar,
                $clientNotas !== '' ? $clientNotas : null,
                $tipoCliente
            );
            $clearModal();
            $setFlash('success', 'Cliente configurado correctamente.');
            header('Location: usuarios.php#clientes');
            exit;
        } elseif ($action === 'edit_client') {
            $clientUserId = trim((string)($_POST['client_user_id'] ?? ''));
            $tipoCliente = trim((string)($_POST['tipo_cliente'] ?? 'minorista'));
            $zonasRaw = $_POST['zonas'] ?? [];
            $columnaPrecio = trim((string)($_POST['columna_precio'] ?? 'precio_x_unid'));
            $puedeReservar = isset($_POST['puede_reservar']);
            $clientNotas = trim((string)($_POST['client_notas'] ?? ''));

            if ($clientUserId === '') {
                $setFlash('error', 'No se recibio el cliente a actualizar.');
                header('Location: usuarios.php#clientes');
                exit;
            }

            $zonasStr = 'TODAS';
            if (is_array($zonasRaw) && count($zonasRaw) > 0) {
                $zonasStr = implode(',', array_map('trim', $zonasRaw));
            }

            if (!array_key_exists($columnaPrecio, $tarifasDisponibles)) {
                $columnaPrecio = 'precio_x_unid';
            }
            if (!array_key_exists($tipoCliente, $tiposCliente)) {
                $tipoCliente = 'minorista';
            }

            $reservasRepo->upsertClienteConfig(
                $clientUserId,
                $zonasStr,
                $columnaPrecio,
                $puedeReservar,
                $clientNotas !== '' ? $clientNotas : null,
                $tipoCliente
            );
            $clearModal();
            $setFlash('success', 'Cliente actualizado correctamente.');
            header('Location: usuarios.php#clientes');
            exit;
        } elseif ($action === 'delete_client') {
            $clientUserId = trim((string)($_POST['client_user_id'] ?? ''));
            if ($clientUserId === '') {
                $setFlash('error', 'No se recibio el cliente a eliminar.');
                header('Location: usuarios.php#clientes');
                exit;
            }

            $reservasRepo->deleteClienteConfig($clientUserId);
            $clearModal();
            $setFlash('success', 'Cliente eliminado correctamente.');
            header('Location: usuarios.php#clientes');
            exit;
        } else {
            $setFlash('error', 'Accion no valida.');
        }
    } catch (\Throwable $e) {
        $setFlash('error', 'Se produjo un error al gestionar usuarios: ' . $e->getMessage());
    }

    header('Location: usuarios.php');
    exit;
}

$flash = $_SESSION['usuarios_flash'] ?? null;
unset($_SESSION['usuarios_flash']);

$modalState = $_SESSION['usuarios_modal'] ?? null;
unset($_SESSION['usuarios_modal']);

$openModal = null;
$modalError = null;
$modalData = [];
if (is_array($modalState)) {
    $openModal = isset($modalState['type']) ? (string)$modalState['type'] : null;
    $modalError = isset($modalState['error']) ? (string)$modalState['error'] : null;
    $modalData = isset($modalState['data']) && is_array($modalState['data']) ? $modalState['data'] : [];
}

$users = [];
$usersById = [];
$usersError = null;
try {
    $rows = $authRepo->listUsers();
    foreach ($rows as $row) {
        $id = trim((string)($row['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $roleKey = $normalizeRole((string)($row['role'] ?? 'member_licitaciones'));
        $item = [
            'id' => $id,
            'email' => trim((string)($row['email'] ?? '')),
            'full_name' => trim((string)($row['full_name'] ?? '')),
            'role' => $roleKey,
        ];
        $users[] = $item;
        $usersById[$id] = $item;
    }
} catch (\Throwable $e) {
    $usersError = 'No se pudieron cargar los usuarios: ' . $e->getMessage();
}

// ── Datos de clientes ────────────────────────────────────────────────────────
$clients = [];
$clientsError = null;
try {
    $clients = $reservasRepo->listClienteConfigs();
} catch (\Throwable $e) {
    $clientsError = 'No se pudieron cargar los clientes: ' . $e->getMessage();
}

// IDs de usuarios que ya tienen config de cliente
$clientUserIds = [];
foreach ($clients as $c) {
    $clientUserIds[] = (string)($c['user_id'] ?? '');
}

// Usuarios disponibles para asignar como cliente (los que aún no tienen config)
$availableForClient = [];
foreach ($users as $u) {
    if (!in_array((string)$u['id'], $clientUserIds, true)) {
        $availableForClient[] = $u;
    }
}

$isCreateClientOpen = $openModal === 'create_client';
$isEditClientOpen = $openModal === 'edit_client';
$isDeleteClientOpen = $openModal === 'delete_client';

$clientFormValues = [
    'client_user_id' => '',
    'tipo_cliente' => 'minorista',
    'zonas' => [],
    'columna_precio' => 'precio_x_unid',
    'puede_reservar' => true,
    'client_notas' => '',
];
if ($isCreateClientOpen || $isEditClientOpen) {
    $clientFormValues['client_user_id'] = trim((string)($modalData['client_user_id'] ?? ''));
    $clientFormValues['tipo_cliente'] = trim((string)($modalData['tipo_cliente'] ?? 'minorista'));
    $clientFormValues['zonas'] = is_array($modalData['zonas'] ?? null) ? $modalData['zonas'] : [];
    $clientFormValues['columna_precio'] = trim((string)($modalData['columna_precio'] ?? 'precio_x_unid'));
    $clientFormValues['puede_reservar'] = (bool)($modalData['puede_reservar'] ?? true);
    $clientFormValues['client_notas'] = trim((string)($modalData['client_notas'] ?? ''));
}

$deleteClientUserId = $isDeleteClientOpen ? trim((string)($modalData['client_user_id'] ?? '')) : '';

$createValues = [
    'email' => '',
    'full_name' => '',
    'role' => 'member_licitaciones',
];
if ($openModal === 'create') {
    $createValues['email'] = trim((string)($modalData['email'] ?? ''));
    $createValues['full_name'] = trim((string)($modalData['full_name'] ?? ''));
    $roleInput = trim((string)($modalData['role'] ?? 'member_licitaciones'));
    $createValues['role'] = array_key_exists($roleInput, $roles) ? $roleInput : 'member_licitaciones';
}

$passwordModalUserId = $openModal === 'password' ? trim((string)($modalData['user_id'] ?? '')) : '';
$deleteModalUserId = $openModal === 'delete' ? trim((string)($modalData['user_id'] ?? '')) : '';

$isCreateOpen = $openModal === 'create';
$isPasswordOpen = $openModal === 'password' && isset($usersById[$passwordModalUserId]);
$isDeleteOpen = $openModal === 'delete' && isset($usersById[$deleteModalUserId]);

$passwordTarget = $isPasswordOpen ? $usersById[$passwordModalUserId] : null;
$deleteTarget = $isDeleteOpen ? $usersById[$deleteModalUserId] : null;

$usersForJs = [];
foreach ($usersById as $id => $u) {
    $usersForJs[$id] = [
        'email' => (string)$u['email'],
        'full_name' => (string)$u['full_name'],
    ];
}

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Usuarios</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background-color: #020617;
            color: #e5e7eb;
        }
        .layout {
            display: flex;
            min-height: 100vh;
        }
        .sidebar {
            width: 220px;
            background: radial-gradient(circle at top left, #1e293b, #020617);
            border-right: 1px solid #1f2937;
            padding: 16px 14px;
            display: flex;
            flex-direction: column;
        }
        .sidebar-logo {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 18px;
        }
        .sidebar-nav {
            display: flex;
            flex-direction: column;
            gap: 4px;
            margin-bottom: auto;
        }
        .nav-link {
            display: block;
            padding: 8px 10px;
            border-radius: 8px;
            font-size: 0.9rem;
            color: #e5e7eb;
            text-decoration: none;
        }
        .nav-link:hover {
            background-color: #111827;
        }
        .nav-link.active {
            background: linear-gradient(135deg, #22c55e, #14b8a6);
            color: #020617;
            font-weight: 600;
        }
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }
        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 20px;
            background: linear-gradient(135deg, #020617, #0f172a);
            border-bottom: 1px solid #1f2937;
        }
        header h1 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
        }
        .user-info {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            font-size: 0.85rem;
        }
        .user-top {
            display: flex; align-items: center; gap: 8px;
        }
        .user-avatar {
            width: 32px; height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2563eb, #3b82f6);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 0.78rem;
            flex-shrink: 0;
        }
        .user-name {
            font-weight: 600; color: #e5e7eb; font-size: 0.85rem;
        }
        .user-meta {
            display: flex; align-items: center; gap: 8px; justify-content: center;
        }
        .pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 9999px;
            background-color: #1e293b;
            color: #a5b4fc;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.02em;
        }
        .logout-link {
            color: #94a3b8;
            font-size: 0.75rem;
            text-decoration: none;
            transition: color 0.15s;
        }
        .logout-link:hover { color: #f87171; }
        main {
            max-width: 1120px;
            margin: 24px auto;
            padding: 0 16px 28px;
            width: 100%;
        }
        .card {
            border-radius: 12px;
            border: 1px solid #1f2937;
            background: #0f172a;
            box-shadow: 0 18px 35px rgba(15, 23, 42, 0.35);
            padding: 18px;
        }
        .toolbar {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        .toolbar h2 {
            margin: 0;
            font-size: 1.24rem;
        }
        .toolbar p {
            margin: 3px 0 0;
            font-size: 0.9rem;
            color: #9ca3af;
        }
        .toolbar p a {
            color: #93c5fd;
            text-decoration: none;
        }
        .toolbar p a:hover {
            text-decoration: underline;
        }
        .btn {
            border: 1px solid #334155;
            border-radius: 9999px;
            background: #1e293b;
            color: #e2e8f0;
            font-size: 0.82rem;
            font-weight: 700;
            line-height: 1.2;
            padding: 7px 13px;
            cursor: pointer;
            text-decoration: none;
        }
        .btn:hover {
            filter: brightness(1.05);
        }
        .btn-danger-outline {
            border-color: rgba(200, 60, 50, 0.45) !important;
            background: #fff5f5 !important;
            color: #8d2b23 !important;
        }
        .btn:disabled {
            cursor: not-allowed;
            opacity: 0.6;
        }
        .alert {
            border-radius: 10px;
            border: 1px solid #334155;
            padding: 10px 12px;
            margin-bottom: 10px;
            font-size: 0.88rem;
        }
        .alert.success {
            border-color: rgba(22, 163, 74, 0.45);
            background: rgba(22, 163, 74, 0.12);
            color: #14532d;
        }
        .alert.error {
            border-color: rgba(200, 60, 50, 0.45);
            background: rgba(200, 60, 50, 0.1);
            color: #7a2722;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
            font-size: 0.86rem;
        }
        th,
        td {
            padding: 9px 10px;
            border-bottom: 1px solid #1f2937;
            text-align: left;
            vertical-align: middle;
        }
        th {
            font-size: 0.75rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #94a3b8;
        }
        .actions-cell {
            text-align: right;
            white-space: nowrap;
        }
        .actions-wrap {
            display: inline-flex;
            gap: 6px;
        }
        .role-form {
            margin: 0;
        }
        .role-select {
            min-width: 220px;
            height: 34px;
            border-radius: 8px;
            border: 1px solid #334155;
            background: #020617;
            color: #e2e8f0;
            padding: 0 8px;
            font-size: 0.82rem;
        }
        .hint {
            margin-top: 10px;
            font-size: 0.8rem;
            color: #94a3b8;
        }
        .me-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 9999px;
            border: 1px solid #334155;
            background: #1e293b;
            color: #cbd5e1;
            font-size: 0.68rem;
            font-weight: 700;
            padding: 2px 7px;
            margin-left: 6px;
        }
        .modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 80;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: rgba(16, 24, 14, 0.46);
            backdrop-filter: blur(3px);
        }
        .modal-overlay.is-open {
            display: flex;
        }
        .modal {
            width: min(700px, 100%);
            border-radius: 14px;
            border: 1px solid var(--vz-marron2);
            background: var(--vz-blanco);
            box-shadow: 0 14px 30px rgba(16, 24, 14, 0.24);
            padding: 18px;
        }
        .modal-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(133, 114, 94, 0.35);
        }
        .modal-head h3 {
            margin: 0;
            font-size: 1.9rem;
            line-height: 1.1;
            color: var(--vz-negro);
        }
        .modal-close {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            border: 1px solid var(--vz-marron2);
            background: var(--vz-crema);
            color: var(--vz-marron1);
            font-weight: 700;
            cursor: pointer;
            font-size: 1.05rem;
            line-height: 1;
        }
        .modal-close:hover {
            background: #f4f0e8;
        }
        .modal-sub {
            margin: 0 0 12px;
            font-size: 1rem;
            color: var(--vz-marron1);
            opacity: 0.85;
        }
        .form-stack {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .field {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .field label {
            font-size: 1.03rem;
            color: var(--vz-marron1);
            font-weight: 600;
        }
        .field input,
        .field select {
            height: 46px;
            border-radius: 8px;
            border: 1px solid var(--vz-marron2);
            background: var(--vz-blanco);
            color: var(--vz-negro);
            padding: 0 12px;
            font-size: 1.04rem;
        }
        .field input:focus,
        .field select:focus {
            outline: none;
            border-color: var(--vz-verde);
            box-shadow: 0 0 0 1px rgba(142, 139, 48, 0.3);
        }
        .modal-actions {
            margin-top: 10px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        .modal-actions .btn {
            min-height: 40px;
            border-radius: 9999px;
            padding: 8px 18px;
            font-size: 0.98rem;
            font-weight: 700;
        }
        .modal-actions .btn:first-child {
            border-color: var(--vz-marron2);
            background: var(--vz-crema);
            color: var(--vz-marron1);
        }
        .modal-actions .btn[type="submit"] {
            border-color: var(--vz-verde);
            background: var(--vz-verde);
            color: var(--vz-crema);
        }
        .modal-actions .btn-danger-outline {
            border-color: rgba(200, 60, 50, 0.5);
            background: #fff5f5;
            color: #8d2b23;
        }
        .modal-actions .btn:hover {
            filter: brightness(1.03);
        }
        .inline-error {
            border-radius: 8px;
            border: 1px solid rgba(200, 60, 50, 0.45);
            background: #fff5f5;
            color: #7a2722;
            font-size: 0.9rem;
            padding: 10px 12px;
        }
        .section-divider {
            margin: 28px 0 0;
        }
        .client-tipo {
            display: inline-flex;
            align-items: center;
            border-radius: 9999px;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 3px 10px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .tipo-mayorista    { background: rgba(59,130,246,0.14); color: #1d4ed8; border: 1px solid rgba(59,130,246,0.35); }
        .tipo-minorista    { background: rgba(16,185,129,0.14); color: #047857; border: 1px solid rgba(16,185,129,0.35); }
        .tipo-garden       { background: rgba(142,139,48,0.14); color: #5c5a1e; border: 1px solid rgba(142,139,48,0.35); }
        .tipo-florista     { background: rgba(217,70,239,0.14); color: #a21caf; border: 1px solid rgba(217,70,239,0.35); }
        .tipo-distribuidor { background: rgba(245,158,11,0.14); color: #b45309; border: 1px solid rgba(245,158,11,0.35); }
        .tipo-online       { background: rgba(99,102,241,0.14); color: #4338ca; border: 1px solid rgba(99,102,241,0.35); }
        .tipo-otro         { background: rgba(107,114,128,0.14); color: #374151; border: 1px solid rgba(107,114,128,0.35); }
        .zona-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }
        .zona-tag {
            display: inline-block;
            border-radius: 6px;
            background: rgba(142,139,48,0.1);
            border: 1px solid rgba(142,139,48,0.3);
            color: #5c5a1e;
            font-size: 0.7rem;
            font-weight: 600;
            padding: 2px 7px;
        }
        .tarifa-label {
            font-size: 0.78rem;
            color: var(--vz-marron1);
            font-weight: 600;
        }
        .puede-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 9999px;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 2px 8px;
        }
        .puede-si { background: #f0fdf4; color: #166534; border: 1px solid #86efac; }
        .puede-no { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .field-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .zonas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 6px;
        }
        .zonas-grid label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.88rem !important;
            font-weight: 500 !important;
            cursor: pointer;
            padding: 4px 6px;
            border-radius: 6px;
            border: 1px solid rgba(133,114,94,0.25);
            background: #fafaf6;
        }
        .zonas-grid label:hover {
            background: rgba(142,139,48,0.08);
            border-color: rgba(142,139,48,0.4);
        }
        .zonas-grid input[type="checkbox"] {
            width: 16px !important;
            height: 16px !important;
            accent-color: var(--vz-verde);
        }
        .zonas-toggle-all {
            font-size: 0.78rem;
            color: var(--vz-verde);
            cursor: pointer;
            border: none;
            background: transparent;
            font-weight: 600;
            text-decoration: underline;
            padding: 0;
            margin-bottom: 4px;
        }
        @media (max-width: 940px) {
            .actions-wrap {
                flex-wrap: wrap;
                justify-content: flex-end;
            }
            .role-select {
                min-width: 170px;
            }
            .field-row {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 768px) {
            .layout {
                flex-direction: column;
            }
            .sidebar {
                width: 100%;
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
            .sidebar-nav {
                flex-direction: row;
                gap: 6px;
            }
            table {
                display: block;
                overflow-x: auto;
            }
        }
    </style>
    <link rel="stylesheet" href="assets/css/master-detail-theme.css">
</head>
<body>
    <div class="layout">
        <?php $activePage = 'usuarios'; include __DIR__ . '/partials/sidebar.php'; ?>

        <div class="main">
            <header>
                <h1>Usuarios</h1>
                <div class="user-info">
                    <?php
                        $displayName = $actorFullName !== '' ? $actorFullName : $actorEmail;
                        $initials = '';
                        $parts = explode(' ', trim($displayName));
                        foreach (array_slice($parts, 0, 2) as $p) {
                            if ($p !== '') $initials .= mb_strtoupper(mb_substr($p, 0, 1));
                        }
                    ?>
                    <div class="user-top">
                        <div class="user-avatar"><?php echo $initials; ?></div>
                        <span class="user-name"><?php echo $h($displayName); ?></span>
                    </div>
                    <div class="user-meta">
                        <span class="pill"><?php echo $h($roles[$actorRole] ?? $actorRole); ?></span>
                        <a href="logout.php" class="logout-link">Cerrar sesi&oacute;n</a>
                    </div>
                </div>
            </header>

            <main>
                <section class="card">
                    <div class="toolbar">
                        <div>
                            <h2>Gesti&oacute;n de usuarios</h2>
                            <p>
                                Replica funcional del m&oacute;dulo anterior: alta, rol, contrase&ntilde;a y baja.
                                Matriz de permisos en <a href="roles.php">Roles y permisos</a>.
                            </p>
                        </div>
                        <button type="button" class="btn" data-open-modal="create">Crear usuario</button>
                    </div>

                    <?php if (is_array($flash) && isset($flash['message'])): ?>
                        <div class="alert <?php echo ($flash['type'] ?? '') === 'success' ? 'success' : 'error'; ?>">
                            <?php echo $h((string)$flash['message']); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($usersError !== null): ?>
                        <div class="alert error"><?php echo $h($usersError); ?></div>
                    <?php elseif ($users === []): ?>
                        <p class="hint">No hay usuarios registrados.</p>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Email</th>
                                    <th>Nombre</th>
                                    <th>Rol</th>
                                    <th class="actions-cell">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <?php
                                        $id = (string)$user['id'];
                                        $email = (string)$user['email'];
                                        $fullName = (string)$user['full_name'];
                                        $roleKey = (string)$user['role'];
                                    ?>
                                    <tr>
                                        <td>
                                            <?php echo $h($email !== '' ? $email : '-'); ?>
                                            <?php if ($actorId !== '' && $actorId === $id): ?>
                                                <span class="me-badge">Tu usuario</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $h($fullName !== '' ? $fullName : '-'); ?></td>
                                        <td>
                                            <form method="post" class="role-form">
                                                <input type="hidden" name="action" value="update_role">
                                                <input type="hidden" name="user_id" value="<?php echo $h($id); ?>">
                                                <select name="role" class="role-select js-role-select">
                                                    <?php foreach ($roles as $roleValue => $label): ?>
                                                        <option value="<?php echo $h($roleValue); ?>" <?php echo $roleValue === $roleKey ? 'selected' : ''; ?>>
                                                            <?php echo $h($label); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </form>
                                        </td>
                                        <td class="actions-cell">
                                            <div class="actions-wrap">
                                                <button
                                                    type="button"
                                                    class="btn js-open-password"
                                                    data-user-id="<?php echo $h($id); ?>"
                                                >
                                                    Cambiar contrase&ntilde;a
                                                </button>
                                                <button
                                                    type="button"
                                                    class="btn btn-danger-outline js-open-delete"
                                                    data-user-id="<?php echo $h($id); ?>"
                                                    <?php echo ($actorId !== '' && $actorId === $id) ? 'disabled' : ''; ?>
                                                >
                                                    Eliminar
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </section>

                <section id="clientes" class="card section-divider">
                    <div class="toolbar">
                        <div>
                            <h2>Gesti&oacute;n de clientes</h2>
                            <p>Configura perfiles de cliente: tipo, zonas de compra y tarifas asignadas.</p>
                        </div>
                        <button type="button" class="btn" data-open-modal="create_client">Crear cliente</button>
                    </div>

                    <?php if ($clientsError !== null): ?>
                        <div class="alert error"><?php echo $h($clientsError); ?></div>
                    <?php elseif ($clients === []): ?>
                        <p class="hint">No hay clientes configurados a&uacute;n.</p>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Usuario</th>
                                    <th>Tipo</th>
                                    <th>Zonas</th>
                                    <th>Tarifa</th>
                                    <th>Reservar</th>
                                    <th class="actions-cell">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($clients as $client): ?>
                                    <?php
                                        $cUserId = (string)($client['user_id'] ?? '');
                                        $cName   = trim((string)($client['full_name'] ?? ''));
                                        $cEmail  = trim((string)($client['email'] ?? ''));
                                        $cLabel  = $cName !== '' ? $cName : ($cEmail !== '' ? $cEmail : $cUserId);
                                        $cTipo   = trim((string)($client['tipo_cliente'] ?? 'minorista'));
                                        $cZonas  = trim((string)($client['zonas'] ?? 'TODAS'));
                                        $cTarifa = trim((string)($client['columna_precio'] ?? 'precio_x_unid'));
                                        $cReserv = (bool)($client['puede_reservar'] ?? true);
                                        $cNotas  = trim((string)($client['notas'] ?? ''));
                                        $zonasArr = $cZonas === 'TODAS' ? ['TODAS'] : array_map('trim', explode(',', $cZonas));
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight:600"><?php echo $h($cLabel); ?></div>
                                            <?php if ($cName !== '' && $cEmail !== ''): ?>
                                                <div style="font-size:0.78rem;opacity:0.7"><?php echo $h($cEmail); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="client-tipo tipo-<?php echo $h($cTipo); ?>">
                                                <?php echo $h($tiposCliente[$cTipo] ?? $cTipo); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="zona-tags">
                                                <?php foreach ($zonasArr as $z): ?>
                                                    <span class="zona-tag"><?php echo $h($z); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="tarifa-label"><?php echo $h($tarifasDisponibles[$cTarifa] ?? $cTarifa); ?></span>
                                        </td>
                                        <td>
                                            <span class="puede-badge <?php echo $cReserv ? 'puede-si' : 'puede-no'; ?>">
                                                <?php echo $cReserv ? 'S&iacute;' : 'No'; ?>
                                            </span>
                                        </td>
                                        <td class="actions-cell">
                                            <div class="actions-wrap">
                                                <button
                                                    type="button"
                                                    class="btn js-open-edit-client"
                                                    data-user-id="<?php echo $h($cUserId); ?>"
                                                    data-tipo="<?php echo $h($cTipo); ?>"
                                                    data-zonas="<?php echo $h($cZonas); ?>"
                                                    data-tarifa="<?php echo $h($cTarifa); ?>"
                                                    data-reservar="<?php echo $cReserv ? '1' : '0'; ?>"
                                                    data-notas="<?php echo $h($cNotas); ?>"
                                                    data-label="<?php echo $h($cLabel); ?>"
                                                >
                                                    Editar
                                                </button>
                                                <button
                                                    type="button"
                                                    class="btn btn-danger-outline js-open-delete-client"
                                                    data-user-id="<?php echo $h($cUserId); ?>"
                                                    data-label="<?php echo $h($cLabel); ?>"
                                                >
                                                    Eliminar
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </section>
            </main>
        </div>
    </div>

    <div
        id="modal-create"
        class="modal-overlay <?php echo $isCreateOpen ? 'is-open' : ''; ?>"
        data-modal-type="create"
        <?php echo $isCreateOpen ? '' : 'hidden'; ?>
    >
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-create-title">
            <div class="modal-head">
                <h3 id="modal-create-title">Crear usuario</h3>
                <button type="button" class="modal-close" data-close-modal="create">&times;</button>
            </div>
            <p class="modal-sub">El nuevo usuario se crear&aacute; con el rol seleccionado.</p>

            <form method="post" class="form-stack">
                <input type="hidden" name="action" value="create_user">

                <?php if ($isCreateOpen && $modalError !== null): ?>
                    <div class="inline-error"><?php echo $h($modalError); ?></div>
                <?php endif; ?>

                <div class="field">
                    <label for="create-email">Email</label>
                    <input id="create-email" type="email" name="email" required value="<?php echo $h($createValues['email']); ?>">
                </div>

                <div class="field">
                    <label for="create-full-name">Nombre (opcional)</label>
                    <input id="create-full-name" type="text" name="full_name" value="<?php echo $h($createValues['full_name']); ?>">
                </div>

                <div class="field">
                    <label for="create-role">Rol</label>
                    <select id="create-role" name="role" required>
                        <?php foreach ($roles as $roleValue => $label): ?>
                            <option value="<?php echo $h($roleValue); ?>" <?php echo $createValues['role'] === $roleValue ? 'selected' : ''; ?>>
                                <?php echo $h($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="create-password">Contrasena</label>
                    <input id="create-password" type="password" name="password" minlength="6" required>
                </div>

                <div class="field">
                    <label for="create-password-confirm">Repetir contrasena</label>
                    <input id="create-password-confirm" type="password" name="password_confirm" minlength="6" required>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn" data-close-modal="create">Cancelar</button>
                    <button type="submit" class="btn">Crear usuario</button>
                </div>
            </form>
        </div>
    </div>

    <div
        id="modal-password"
        class="modal-overlay <?php echo $isPasswordOpen ? 'is-open' : ''; ?>"
        data-modal-type="password"
        <?php echo $isPasswordOpen ? '' : 'hidden'; ?>
    >
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-password-title">
            <div class="modal-head">
                <h3 id="modal-password-title">Cambiar contrase&ntilde;a</h3>
                <button type="button" class="modal-close" data-close-modal="password">&times;</button>
            </div>
            <p class="modal-sub">
                Nueva contrase&ntilde;a para
                <strong id="password-user-label">
                    <?php
                        if ($passwordTarget !== null) {
                            $label = (string)($passwordTarget['email'] ?: ($passwordTarget['full_name'] ?: 'usuario'));
                            echo $h($label);
                        } else {
                            echo 'usuario';
                        }
                    ?>
                </strong>.
            </p>

            <form method="post" class="form-stack">
                <input type="hidden" name="action" value="change_password">
                <input
                    type="hidden"
                    id="password-user-id"
                    name="user_id"
                    value="<?php echo $h($passwordTarget !== null ? (string)$passwordTarget['id'] : ''); ?>"
                >

                <?php if ($isPasswordOpen && $modalError !== null): ?>
                    <div class="inline-error"><?php echo $h($modalError); ?></div>
                <?php endif; ?>

                <div class="field">
                    <label for="password-new">Nueva contrasena</label>
                    <input id="password-new" type="password" name="password" minlength="6" required>
                </div>

                <div class="field">
                    <label for="password-confirm">Repetir contrasena</label>
                    <input id="password-confirm" type="password" name="password_confirm" minlength="6" required>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn" data-close-modal="password">Cancelar</button>
                    <button type="submit" class="btn">Guardar contrase&ntilde;a</button>
                </div>
            </form>
        </div>
    </div>

    <div
        id="modal-delete"
        class="modal-overlay <?php echo $isDeleteOpen ? 'is-open' : ''; ?>"
        data-modal-type="delete"
        <?php echo $isDeleteOpen ? '' : 'hidden'; ?>
    >
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-delete-title">
            <div class="modal-head">
                <h3 id="modal-delete-title">Eliminar usuario</h3>
                <button type="button" class="modal-close" data-close-modal="delete">&times;</button>
            </div>
            <p class="modal-sub">
                Esta acci&oacute;n no se puede deshacer.
                Usuario:
                <strong id="delete-user-label">
                    <?php
                        if ($deleteTarget !== null) {
                            $label = (string)($deleteTarget['email'] ?: ($deleteTarget['full_name'] ?: 'usuario'));
                            echo $h($label);
                        } else {
                            echo 'usuario';
                        }
                    ?>
                </strong>
            </p>

            <form method="post" class="form-stack">
                <input type="hidden" name="action" value="delete_user">
                <input
                    type="hidden"
                    id="delete-user-id"
                    name="user_id"
                    value="<?php echo $h($deleteTarget !== null ? (string)$deleteTarget['id'] : ''); ?>"
                >

                <?php if ($isDeleteOpen && $modalError !== null): ?>
                    <div class="inline-error"><?php echo $h($modalError); ?></div>
                <?php endif; ?>

                <div class="modal-actions">
                    <button type="button" class="btn" data-close-modal="delete">Cancelar</button>
                    <button type="submit" class="btn btn-danger-outline">Eliminar</button>
                </div>
            </form>
        </div>
    </div>

    <?php
        // ── Helper: genera el bloque de campos del formulario de cliente ──────
        // Se reutiliza para crear y editar.
        $renderClientFields = function (string $prefix, array $vals, bool $isEdit) use ($h, $tiposCliente, $tarifasDisponibles, $zonasDisponibles): void {
            $selectedZonas = $vals['zonas'];
            if (!is_array($selectedZonas)) {
                $raw = trim((string)$selectedZonas);
                $selectedZonas = ($raw === '' || $raw === 'TODAS') ? [] : array_map('trim', explode(',', $raw));
            }
    ?>
        <div class="field-row">
            <div class="field">
                <label for="<?php echo $prefix; ?>-tipo">Tipo de cliente</label>
                <select id="<?php echo $prefix; ?>-tipo" name="tipo_cliente" required>
                    <?php foreach ($tiposCliente as $tk => $tl): ?>
                        <option value="<?php echo $h($tk); ?>" <?php echo $vals['tipo_cliente'] === $tk ? 'selected' : ''; ?>>
                            <?php echo $h($tl); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="<?php echo $prefix; ?>-tarifa">Tarifa asignada</label>
                <select id="<?php echo $prefix; ?>-tarifa" name="columna_precio" required>
                    <?php foreach ($tarifasDisponibles as $tk => $tl): ?>
                        <option value="<?php echo $h($tk); ?>" <?php echo $vals['columna_precio'] === $tk ? 'selected' : ''; ?>>
                            <?php echo $h($tl); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field">
            <label>Zonas donde puede comprar</label>
            <button type="button" class="zonas-toggle-all" onclick="toggleAllZonas(this)">Seleccionar todas</button>
            <div class="zonas-grid">
                <?php if ($zonasDisponibles === []): ?>
                    <span style="font-size:0.82rem;color:var(--vz-marron2)">No hay zonas disponibles en el cat&aacute;logo.</span>
                <?php else: ?>
                    <?php foreach ($zonasDisponibles as $zona): ?>
                        <label>
                            <input
                                type="checkbox"
                                name="zonas[]"
                                value="<?php echo $h($zona); ?>"
                                <?php echo in_array($zona, $selectedZonas, true) ? 'checked' : ''; ?>
                            >
                            <?php echo $h($zona); ?>
                        </label>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <span style="font-size:0.74rem;color:var(--vz-marron2);margin-top:2px">
                Si no seleccionas ninguna, el cliente ver&aacute; todas las zonas.
            </span>
        </div>

        <div class="field-row">
            <div class="field">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input
                        type="checkbox"
                        name="puede_reservar"
                        value="1"
                        style="width:18px !important;height:18px !important;accent-color:var(--vz-verde)"
                        <?php echo $vals['puede_reservar'] ? 'checked' : ''; ?>
                    >
                    Puede hacer reservas
                </label>
            </div>
        </div>

        <div class="field">
            <label for="<?php echo $prefix; ?>-notas">Notas (opcional)</label>
            <input id="<?php echo $prefix; ?>-notas" type="text" name="client_notas" value="<?php echo $h($vals['client_notas']); ?>" placeholder="Observaciones internas sobre el cliente">
        </div>
    <?php
        };
    ?>

    <div
        id="modal-create-client"
        class="modal-overlay <?php echo $isCreateClientOpen ? 'is-open' : ''; ?>"
        data-modal-type="create_client"
        <?php echo $isCreateClientOpen ? '' : 'hidden'; ?>
    >
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-cc-title">
            <div class="modal-head">
                <h3 id="modal-cc-title">Crear perfil de cliente</h3>
                <button type="button" class="modal-close" data-close-modal="create_client">&times;</button>
            </div>
            <p class="modal-sub">Selecciona un usuario y configura su perfil de cliente.</p>

            <form method="post" class="form-stack">
                <input type="hidden" name="action" value="create_client">

                <?php if ($isCreateClientOpen && $modalError !== null): ?>
                    <div class="inline-error"><?php echo $h($modalError); ?></div>
                <?php endif; ?>

                <div class="field">
                    <label for="cc-user">Usuario</label>
                    <select id="cc-user" name="client_user_id" required>
                        <option value="">-- Seleccionar usuario --</option>
                        <?php foreach ($availableForClient as $u): ?>
                            <option
                                value="<?php echo $h((string)$u['id']); ?>"
                                <?php echo $clientFormValues['client_user_id'] === (string)$u['id'] ? 'selected' : ''; ?>
                            >
                                <?php
                                    $label = (string)$u['full_name'];
                                    if ($label === '') $label = (string)$u['email'];
                                    echo $h($label . ' (' . $u['email'] . ')');
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php $renderClientFields('cc', $clientFormValues, false); ?>

                <div class="modal-actions">
                    <button type="button" class="btn" data-close-modal="create_client">Cancelar</button>
                    <button type="submit" class="btn">Crear cliente</button>
                </div>
            </form>
        </div>
    </div>

    <div
        id="modal-edit-client"
        class="modal-overlay <?php echo $isEditClientOpen ? 'is-open' : ''; ?>"
        data-modal-type="edit_client"
        <?php echo $isEditClientOpen ? '' : 'hidden'; ?>
    >
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-ec-title">
            <div class="modal-head">
                <h3 id="modal-ec-title">Editar cliente</h3>
                <button type="button" class="modal-close" data-close-modal="edit_client">&times;</button>
            </div>
            <p class="modal-sub">
                Editando perfil de <strong id="edit-client-label">cliente</strong>.
            </p>

            <form method="post" class="form-stack">
                <input type="hidden" name="action" value="edit_client">
                <input type="hidden" id="edit-client-user-id" name="client_user_id" value="">

                <?php if ($isEditClientOpen && $modalError !== null): ?>
                    <div class="inline-error"><?php echo $h($modalError); ?></div>
                <?php endif; ?>

                <?php $renderClientFields('ec', $clientFormValues, true); ?>

                <div class="modal-actions">
                    <button type="button" class="btn" data-close-modal="edit_client">Cancelar</button>
                    <button type="submit" class="btn">Guardar cambios</button>
                </div>
            </form>
        </div>
    </div>

    <div
        id="modal-delete-client"
        class="modal-overlay <?php echo $isDeleteClientOpen ? 'is-open' : ''; ?>"
        data-modal-type="delete_client"
        <?php echo $isDeleteClientOpen ? '' : 'hidden'; ?>
    >
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-dc-title">
            <div class="modal-head">
                <h3 id="modal-dc-title">Eliminar cliente</h3>
                <button type="button" class="modal-close" data-close-modal="delete_client">&times;</button>
            </div>
            <p class="modal-sub">
                Se eliminar&aacute; el perfil de cliente y todas sus reservas.
                Cliente: <strong id="delete-client-label">cliente</strong>
            </p>

            <form method="post" class="form-stack">
                <input type="hidden" name="action" value="delete_client">
                <input type="hidden" id="delete-client-user-id" name="client_user_id" value="<?php echo $h($deleteClientUserId); ?>">

                <?php if ($isDeleteClientOpen && $modalError !== null): ?>
                    <div class="inline-error"><?php echo $h($modalError); ?></div>
                <?php endif; ?>

                <div class="modal-actions">
                    <button type="button" class="btn" data-close-modal="delete_client">Cancelar</button>
                    <button type="submit" class="btn btn-danger-outline">Eliminar cliente</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        (function () {
            const users = <?php echo json_encode($usersForJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?> || {};
            const modalCreate = document.getElementById('modal-create');
            const modalPassword = document.getElementById('modal-password');
            const modalDelete = document.getElementById('modal-delete');

            const passwordUserInput = document.getElementById('password-user-id');
            const passwordUserLabel = document.getElementById('password-user-label');
            const deleteUserInput = document.getElementById('delete-user-id');
            const deleteUserLabel = document.getElementById('delete-user-label');

            const modalCreateClient = document.getElementById('modal-create-client');
            const modalEditClient   = document.getElementById('modal-edit-client');
            const modalDeleteClient = document.getElementById('modal-delete-client');

            const modals = {
                create: modalCreate,
                password: modalPassword,
                delete: modalDelete,
                create_client: modalCreateClient,
                edit_client: modalEditClient,
                delete_client: modalDeleteClient
            };

            function userLabel(userId) {
                if (!userId || !users[userId]) return 'usuario';
                const item = users[userId];
                return item.email || item.full_name || 'usuario';
            }

            function openModal(type) {
                const node = modals[type];
                if (!node) return;
                node.hidden = false;
                node.classList.add('is-open');
            }

            function closeModal(type) {
                const node = modals[type];
                if (!node) return;
                node.classList.remove('is-open');
                node.hidden = true;
            }

            document.querySelectorAll('[data-open-modal="create"]').forEach((btn) => {
                btn.addEventListener('click', function () {
                    openModal('create');
                });
            });

            document.querySelectorAll('.js-open-password').forEach((btn) => {
                btn.addEventListener('click', function () {
                    const userId = String(btn.getAttribute('data-user-id') || '').trim();
                    if (!userId || !users[userId]) return;
                    if (passwordUserInput) passwordUserInput.value = userId;
                    if (passwordUserLabel) passwordUserLabel.textContent = userLabel(userId);
                    openModal('password');
                });
            });

            document.querySelectorAll('.js-open-delete').forEach((btn) => {
                btn.addEventListener('click', function () {
                    const userId = String(btn.getAttribute('data-user-id') || '').trim();
                    if (!userId || !users[userId]) return;
                    if (deleteUserInput) deleteUserInput.value = userId;
                    if (deleteUserLabel) deleteUserLabel.textContent = userLabel(userId);
                    openModal('delete');
                });
            });

            document.querySelectorAll('[data-close-modal]').forEach((btn) => {
                btn.addEventListener('click', function () {
                    const type = String(btn.getAttribute('data-close-modal') || '');
                    if (type !== '') closeModal(type);
                });
            });

            document.querySelectorAll('.modal-overlay').forEach((overlay) => {
                overlay.addEventListener('mousedown', function (event) {
                    if (event.target !== overlay) return;
                    const type = String(overlay.getAttribute('data-modal-type') || '');
                    if (type !== '') closeModal(type);
                });
            });

            document.addEventListener('keydown', function (event) {
                if (event.key !== 'Escape') return;
                Object.keys(modals).forEach(t => closeModal(t));
            });

            document.querySelectorAll('.js-role-select').forEach((select) => {
                select.addEventListener('change', function () {
                    const form = select.closest('form');
                    if (!form) return;
                    form.submit();
                });
            });

            // ── Client modals ────────────────────────────────────────────
            document.querySelectorAll('[data-open-modal="create_client"]').forEach(btn => {
                btn.addEventListener('click', () => openModal('create_client'));
            });

            // Edit client
            const editClientUserIdInput = document.getElementById('edit-client-user-id');
            const editClientLabel = document.getElementById('edit-client-label');
            const editModal = document.getElementById('modal-edit-client');

            document.querySelectorAll('.js-open-edit-client').forEach(btn => {
                btn.addEventListener('click', () => {
                    const userId = btn.getAttribute('data-user-id') || '';
                    const label  = btn.getAttribute('data-label') || 'cliente';
                    const tipo   = btn.getAttribute('data-tipo') || 'minorista';
                    const zonas  = btn.getAttribute('data-zonas') || '';
                    const tarifa = btn.getAttribute('data-tarifa') || 'precio_x_unid';
                    const reserv = btn.getAttribute('data-reservar') === '1';
                    const notas  = btn.getAttribute('data-notas') || '';

                    if (editClientUserIdInput) editClientUserIdInput.value = userId;
                    if (editClientLabel) editClientLabel.textContent = label;

                    // Set form values
                    if (editModal) {
                        const tipoSel = editModal.querySelector('[name="tipo_cliente"]');
                        if (tipoSel) tipoSel.value = tipo;

                        const tarifaSel = editModal.querySelector('[name="columna_precio"]');
                        if (tarifaSel) tarifaSel.value = tarifa;

                        const reservCb = editModal.querySelector('[name="puede_reservar"]');
                        if (reservCb) reservCb.checked = reserv;

                        const notasInput = editModal.querySelector('[name="client_notas"]');
                        if (notasInput) notasInput.value = notas;

                        // Set zone checkboxes
                        const zonasArr = (zonas === '' || zonas === 'TODAS') ? [] : zonas.split(',').map(z => z.trim());
                        editModal.querySelectorAll('[name="zonas[]"]').forEach(cb => {
                            cb.checked = zonasArr.includes(cb.value);
                        });
                    }

                    openModal('edit_client');
                });
            });

            // Delete client
            const deleteClientUserIdInput = document.getElementById('delete-client-user-id');
            const deleteClientLabel = document.getElementById('delete-client-label');

            document.querySelectorAll('.js-open-delete-client').forEach(btn => {
                btn.addEventListener('click', () => {
                    const userId = btn.getAttribute('data-user-id') || '';
                    const label  = btn.getAttribute('data-label') || 'cliente';

                    if (deleteClientUserIdInput) deleteClientUserIdInput.value = userId;
                    if (deleteClientLabel) deleteClientLabel.textContent = label;

                    openModal('delete_client');
                });
            });
        })();

        // Toggle all zone checkboxes (global helper)
        function toggleAllZonas(btn) {
            const grid = btn.nextElementSibling;
            if (!grid) return;
            const boxes = grid.querySelectorAll('input[type="checkbox"]');
            const allChecked = Array.from(boxes).every(cb => cb.checked);
            boxes.forEach(cb => cb.checked = !allChecked);
            btn.textContent = allChecked ? 'Seleccionar todas' : 'Deseleccionar todas';
        }
    </script>
</body>
</html>
