<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../src/Repositories/PermissionsRepository.php';

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

/** @var array<string, mixed> $user */
$user = $_SESSION['user'];
$email = trim((string)($user['email'] ?? ''));
$fullName = trim((string)($user['full_name'] ?? ''));
$roleRaw = trim((string)($user['role'] ?? 'member_licitaciones'));

$normalizeRole = static function (string $role): string {
    $role = mb_strtolower(trim($role));
    if ($role === 'member') {
        return 'member_licitaciones';
    }
    if ($role === '') {
        return 'member_licitaciones';
    }
    return $role;
};

$actorRole = $normalizeRole($roleRaw);
$isAdmin = $actorRole === 'admin';

/**
 * @var array<string, string>
 */
$features = [
    'dashboard' => 'Dashboard',
    'licitaciones' => 'Mis licitaciones',
    'buscador' => 'Buscador historico',
    'disponible' => 'Disponible',
    'vista_cliente' => 'Vista Cliente',
    'pedidos' => 'Pedidos',
    'analytics' => 'Analitica',
    'usuarios' => 'Gestion de usuarios',
];

/**
 * @var array<string, array{label:string,description:string}>
 */
$roles = [
    'admin' => [
        'label' => 'Administrador',
        'description' => 'Acceso completo a toda la aplicacion y gestion de usuarios.',
    ],
    'admin_licitaciones' => [
        'label' => 'Administrador licitaciones',
        'description' => 'Responsable de licitaciones: dashboard, gestion y analitica.',
    ],
    'member_licitaciones' => [
        'label' => 'Miembro licitaciones',
        'description' => 'Usuario operativo de licitaciones sin configuracion ni usuarios.',
    ],
    'admin_planta' => [
        'label' => 'Administrador planta',
        'description' => 'Responsable de planta: CRM presupuestos sin analitica global.',
    ],
    'member_planta' => [
        'label' => 'Miembro planta',
        'description' => 'Usuario de planta con acceso operativo limitado.',
    ],
];

/**
 * @return array<string, array<string, bool>>
 */
$buildDefaultMatrix = static function () use ($features): array {
    $baseFalse = array_fill_keys(array_keys($features), false);
    return [
        'admin' => array_merge($baseFalse, [
            'dashboard' => true,
            'licitaciones' => true,
            'buscador' => true,
            'disponible' => true,
            'vista_cliente' => true,
            'pedidos' => true,
            'analytics' => true,
            'usuarios' => true,
        ]),
        'admin_licitaciones' => array_merge($baseFalse, [
            'dashboard' => true,
            'licitaciones' => true,
            'buscador' => true,
            'disponible' => true,
            'vista_cliente' => true,
            'pedidos' => true,
            'analytics' => true,
            'usuarios' => false,
        ]),
        'member_licitaciones' => array_merge($baseFalse, [
            'dashboard' => true,
            'licitaciones' => true,
            'buscador' => true,
            'disponible' => true,
            'vista_cliente' => true,
            'pedidos' => true,
            'analytics' => false,
            'usuarios' => false,
        ]),
        'admin_planta' => array_merge($baseFalse, [
            'dashboard' => false,
            'licitaciones' => false,
            'buscador' => true,
            'disponible' => true,
            'vista_cliente' => true,
            'pedidos' => true,
            'analytics' => false,
            'usuarios' => false,
        ]),
        'member_planta' => array_merge($baseFalse, [
            'dashboard' => false,
            'licitaciones' => false,
            'buscador' => true,
            'disponible' => true,
            'vista_cliente' => true,
            'pedidos' => false,
            'analytics' => false,
            'usuarios' => false,
        ]),
    ];
};

$defaultMatrix = $buildDefaultMatrix();
$repo = new PermissionsRepository();

$flash = $_SESSION['roles_flash'] ?? null;
unset($_SESSION['roles_flash']);

$error = null;
$matrix = $defaultMatrix;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'save_matrix') {
        if (!$isAdmin) {
            $_SESSION['roles_flash'] = [
                'type' => 'error',
                'message' => 'Solo el rol admin puede modificar permisos.',
            ];
            header('Location: roles.php');
            exit;
        }

        $postedMatrix = [];
        foreach ($roles as $roleKey => $_meta) {
            $postedMatrix[$roleKey] = [];
            foreach ($features as $featureKey => $_label) {
                $postedMatrix[$roleKey][$featureKey] = isset($_POST['matrix'][$roleKey][$featureKey]);
            }
        }

        try {
            $repo->updateRoleMatrix($postedMatrix);
            $_SESSION['roles_flash'] = [
                'type' => 'success',
                'message' => 'Permisos guardados correctamente.',
            ];
        } catch (\Throwable $e) {
            $_SESSION['roles_flash'] = [
                'type' => 'error',
                'message' => 'Error guardando permisos: ' . $e->getMessage(),
            ];
        }

        header('Location: roles.php');
        exit;
    }
}

try {
    $matrix = $repo->getRoleMatrix();
} catch (\Throwable $e) {
    $matrix = $defaultMatrix;
    $error = 'No se pudieron cargar permisos desde base de datos. Mostrando valores por defecto.';
}

// Completar matrix para evitar undefined keys.
foreach ($roles as $roleKey => $_meta) {
    if (!isset($matrix[$roleKey]) || !is_array($matrix[$roleKey])) {
        $matrix[$roleKey] = $defaultMatrix[$roleKey] ?? array_fill_keys(array_keys($features), false);
    }
    foreach ($features as $featureKey => $_label) {
        if (!array_key_exists($featureKey, $matrix[$roleKey])) {
            $matrix[$roleKey][$featureKey] = (bool)($defaultMatrix[$roleKey][$featureKey] ?? false);
        } else {
            $matrix[$roleKey][$featureKey] = (bool)$matrix[$roleKey][$featureKey];
        }
    }
}

$h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Roles y permisos</title>
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
            width: 100%;
            max-width: 1250px;
            margin: 24px auto;
            padding: 0 16px 28px;
        }
        .card {
            border-radius: 12px;
            border: 1px solid #1f2937;
            background: #0f172a;
            box-shadow: 0 18px 35px rgba(15, 23, 42, 0.35);
            padding: 18px;
        }
        .title {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 700;
        }
        .subtitle {
            margin: 5px 0 0;
            font-size: 0.9rem;
            color: var(--vz-marron2);
        }
        .hint {
            margin: 8px 0 14px;
            font-size: 0.82rem;
            color: var(--vz-marron2);
        }
        .alert {
            margin: 0 0 12px;
            border-radius: 8px;
            border: 1px solid rgba(133, 114, 94, 0.35);
            padding: 8px 10px;
            font-size: 0.84rem;
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
        .table-wrap {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 980px;
            font-size: 0.83rem;
        }
        th, td {
            border-bottom: 1px solid rgba(133, 114, 94, 0.35);
            padding: 9px 10px;
            vertical-align: top;
            text-align: left;
        }
        th {
            font-size: 0.74rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: var(--vz-marron2);
        }
        th.is-center,
        td.is-center {
            text-align: center;
            vertical-align: middle;
        }
        .role-code {
            display: inline-flex;
            align-items: center;
            border-radius: 9999px;
            border: 1px solid rgba(133, 114, 94, 0.45);
            background: var(--vz-crema);
            color: var(--vz-marron1);
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 0.69rem;
            font-weight: 700;
            padding: 2px 7px;
            margin-right: 6px;
        }
        .perm-read {
            display: inline-flex;
            align-items: center;
            border-radius: 9999px;
            padding: 2px 8px;
            font-size: 0.72rem;
            font-weight: 700;
            border: 1px solid transparent;
        }
        .perm-yes {
            background: rgba(22, 163, 74, 0.16);
            border-color: rgba(22, 163, 74, 0.35);
            color: #15803d;
        }
        .perm-no {
            background: rgba(148, 163, 184, 0.16);
            border-color: rgba(148, 163, 184, 0.35);
            color: #64748b;
        }
        .perm-edit {
            width: 18px;
            height: 18px;
            accent-color: #16a34a;
            cursor: pointer;
        }
        .actions {
            margin-top: 12px;
            display: flex;
            justify-content: flex-end;
        }
        .btn-save {
            border-radius: 9999px;
            border: 1px solid var(--vz-verde);
            background: var(--vz-verde);
            color: var(--vz-crema);
            font-size: 0.84rem;
            font-weight: 700;
            line-height: 1.2;
            padding: 8px 14px;
            cursor: pointer;
        }
        .btn-save:hover {
            filter: brightness(1.06);
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
        }
    </style>
    <link rel="stylesheet" href="assets/css/master-detail-theme.css">
</head>
<body>
    <div class="layout">
        <?php $activePage = 'usuarios'; $role = $roleRaw; include __DIR__ . '/partials/sidebar.php'; ?>

        <div class="main">
            <header>
                <h1>Roles y permisos</h1>
                <div class="user-info">
                    <?php
                        $displayName = $fullName !== '' ? $fullName : $email;
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
                        <?php if ($actorRole !== ''): ?>
                            <span class="pill"><?php echo $h($actorRole); ?></span>
                        <?php endif; ?>
                        <a href="logout.php" class="logout-link">Cerrar sesi&oacute;n</a>
                    </div>
                </div>
            </header>

            <main>
                <section class="card">
                    <h2 class="title">Matriz de permisos</h2>
                    <p class="subtitle">
                        Replica del proyecto anterior: rol por modulo con guardado centralizado en <code>role_permissions</code>.
                    </p>
                    <p class="hint">
                        <?php if ($isAdmin): ?>
                            Haz clic en las casillas para modificar permisos y luego pulsa "Guardar cambios".
                        <?php else: ?>
                            Modo solo lectura. Solo el rol admin puede guardar cambios.
                        <?php endif; ?>
                    </p>

                    <?php if ($error !== null): ?>
                        <div class="alert error"><?php echo $h($error); ?></div>
                    <?php endif; ?>

                    <?php if (is_array($flash) && isset($flash['message'])): ?>
                        <div class="alert <?php echo ($flash['type'] ?? '') === 'success' ? 'success' : 'error'; ?>">
                            <?php echo $h((string)$flash['message']); ?>
                        </div>
                    <?php endif; ?>

                    <form method="post">
                        <input type="hidden" name="action" value="save_matrix">

                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Rol</th>
                                        <th>Descripcion</th>
                                        <?php foreach ($features as $featureLabel): ?>
                                            <th class="is-center"><?php echo $h($featureLabel); ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($roles as $roleKey => $meta): ?>
                                        <tr>
                                            <td>
                                                <span class="role-code"><?php echo $h($roleKey); ?></span>
                                                <?php echo $h($meta['label']); ?>
                                            </td>
                                            <td><?php echo $h($meta['description']); ?></td>
                                            <?php foreach ($features as $featureKey => $_featureLabel): ?>
                                                <?php $allowed = (bool)($matrix[$roleKey][$featureKey] ?? false); ?>
                                                <td class="is-center">
                                                    <?php if ($isAdmin): ?>
                                                        <input
                                                            type="checkbox"
                                                            class="perm-edit"
                                                            name="matrix[<?php echo $h($roleKey); ?>][<?php echo $h($featureKey); ?>]"
                                                            value="1"
                                                            <?php echo $allowed ? 'checked' : ''; ?>
                                                        >
                                                    <?php else: ?>
                                                        <span class="perm-read <?php echo $allowed ? 'perm-yes' : 'perm-no'; ?>">
                                                            <?php echo $allowed ? 'Si' : 'No'; ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($isAdmin): ?>
                            <div class="actions">
                                <button type="submit" class="btn-save">Guardar cambios</button>
                            </div>
                        <?php endif; ?>
                    </form>
                </section>
            </main>
        </div>
    </div>
</body>
</html>

