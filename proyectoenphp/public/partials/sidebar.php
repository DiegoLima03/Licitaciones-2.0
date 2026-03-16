<?php
/**
 * Sidebar partial con permisos.
 *
 * Espera que antes de incluir este archivo ya existan:
 *   - $role          string  (rol del usuario en sesion)
 *   - $activePage    string  (clave de la pagina activa: 'dashboard', 'licitaciones', etc.)
 *
 * Opcionalmente se puede pasar $sidebarPermissions si ya se cargaron;
 * si no, se cargan aqui.
 */

if (!isset($sidebarPermissions)) {
    require_once __DIR__ . '/../../src/Repositories/PermissionsRepository.php';
    $sidebarPermRepo = new PermissionsRepository();
    $sidebarPermissions = $sidebarPermRepo->getPermissionsForRole($role ?? '');
}

$sidebarItems = [
    ['key' => 'dashboard',    'href' => 'dashboard.php',          'label' => 'Dashboard'],
    ['key' => 'licitaciones', 'href' => 'licitaciones.php',       'label' => 'Licitaciones'],
    ['key' => 'buscador',     'href' => 'buscador.php',           'label' => 'Buscador historico'],
    ['key' => 'analytics',    'href' => 'analytics.php',          'label' => 'Analitica'],
    ['key' => 'disponible',   'href' => 'disponible.php',         'label' => 'Disponible'],
    ['key' => 'vista_cliente','href' => 'disponible-cliente.php',  'label' => 'Vista Cliente'],
    ['key' => 'pedidos',      'href' => 'pedidos-disponible.php',  'label' => 'Pedidos'],
    ['key' => 'usuarios',     'href' => 'usuarios.php',           'label' => 'Usuarios'],
    ['key' => 'usuarios',     'href' => 'roles.php',              'label' => 'Roles'],
];

$activePage = $activePage ?? '';
?>
<aside class="sidebar">
    <div class="sidebar-logo">Licitaciones</div>
    <nav class="sidebar-nav">
<?php foreach ($sidebarItems as $item):
    $allowed = $sidebarPermissions[$item['key']] ?? false;
    if (!$allowed) continue;
    $isActive = ($activePage === $item['key'] && basename($_SERVER['SCRIPT_NAME'] ?? '') === $item['href'])
             || ($activePage !== '' && basename($_SERVER['SCRIPT_NAME'] ?? '') === $item['href']);
    $activeClass = (basename($_SERVER['SCRIPT_NAME'] ?? '') === $item['href']) ? ' active' : '';
?>
        <a href="<?php echo $item['href']; ?>" class="nav-link<?php echo $activeClass; ?>"><?php echo $item['label']; ?></a>
<?php endforeach; ?>
    </nav>
    <div class="sidebar-footer"></div>
</aside>
