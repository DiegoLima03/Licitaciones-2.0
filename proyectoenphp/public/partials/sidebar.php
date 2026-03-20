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
    <div class="sidebar-footer">
        <?php
            $sbDisplayName = $fullName !== '' ? $fullName : ($email ?? '');
            $sbInitials = '';
            $sbParts = explode(' ', trim($sbDisplayName));
            foreach (array_slice($sbParts, 0, 2) as $p) {
                if ($p !== '') $sbInitials .= mb_strtoupper(mb_substr($p, 0, 1));
            }
            $sbRole = $role ?? '';
        ?>
        <div class="sidebar-user" id="sidebar-user-toggle">
            <div class="sidebar-user-avatar"><?php echo $sbInitials; ?></div>
            <div class="sidebar-user-details">
                <span class="sidebar-user-name"><?php echo htmlspecialchars($sbDisplayName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                <?php if ($sbRole !== ''): ?>
                    <span class="sidebar-user-role"><?php echo htmlspecialchars($sbRole, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                <?php endif; ?>
            </div>
            <svg class="sidebar-user-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
        <div class="sidebar-user-menu" id="sidebar-user-menu">
            <a href="logout.php" class="sidebar-user-menu-item sidebar-user-menu-logout">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M6 14H3.333A1.333 1.333 0 012 12.667V3.333A1.333 1.333 0 013.333 2H6M10.667 11.333L14 8l-3.333-3.333M14 8H6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Cerrar sesión
            </a>
        </div>
    </div>
</aside>
<script>
(function(){
    const toggle = document.getElementById('sidebar-user-toggle');
    const menu = document.getElementById('sidebar-user-menu');
    if (!toggle || !menu) return;
    toggle.addEventListener('click', function(e) {
        e.stopPropagation();
        menu.classList.toggle('is-open');
        toggle.classList.toggle('is-open');
    });
    document.addEventListener('click', function() {
        menu.classList.remove('is-open');
        toggle.classList.remove('is-open');
    });
    menu.addEventListener('click', function(e) { e.stopPropagation(); });
})();
</script>
