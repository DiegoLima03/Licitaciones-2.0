<?php

declare(strict_types=1);

session_start();

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

/** @var array<string, mixed> $user */
$user = $_SESSION['user'];
$email = (string)($user['email'] ?? '');
$fullName = (string)($user['full_name'] ?? '');
$role = (string)($user['role'] ?? '');
$organizationId = (string)($user['organization_id'] ?? '');

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>A&ntilde;adir l&iacute;neas</title>
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
        .sidebar-logo span {
            color: #38bdf8;
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
        .sidebar-footer {
            margin-top: 24px;
            font-size: 0.75rem;
            color: #6b7280;
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
            font-size: 0.85rem;
            text-align: right;
        }
        .pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 9999px;
            background-color: #1e293b;
            color: #a5b4fc;
            font-size: 0.75rem;
            margin-top: 2px;
        }
        main {
            max-width: 900px;
            margin: 32px auto;
            padding: 0 16px 32px;
        }
        .card {
            background-color: #020617;
            border-radius: 12px;
            padding: 18px 18px 20px;
            box-shadow: 0 18px 35px rgba(15, 23, 42, 0.65);
            border: 1px solid #1f2937;
        }
        .card h2 {
            margin: 0 0 8px;
            font-size: 1.1rem;
            font-weight: 600;
        }
        .card p {
            margin: 0 0 12px;
            font-size: 0.9rem;
            color: #9ca3af;
        }
        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 12px;
        }
        .field {
            flex: 1 1 220px;
        }
        .field label {
            display: block;
            font-size: 0.8rem;
            margin-bottom: 4px;
            color: #9ca3af;
        }
        .field input {
            width: 100%;
            border-radius: 8px;
            border: 1px solid #1f2937;
            padding: 7px 10px;
            font-size: 0.9rem;
            background-color: #020617;
            color: #e5e7eb;
        }
        .actions {
            margin-top: 16px;
            display: flex;
            justify-content: flex-end;
        }
        .actions button {
            border-radius: 9999px;
            border: none;
            padding: 8px 16px;
            font-size: 0.9rem;
            font-weight: 600;
            background: linear-gradient(135deg, #10b981, #0ea5e9);
            color: #020617;
            cursor: pointer;
        }
        .actions button:hover {
            filter: brightness(1.05);
        }
        .hint {
            margin-top: 8px;
            font-size: 0.8rem;
            color: #6b7280;
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
        <aside class="sidebar">
            <div class="sidebar-logo">
                Licitaciones
            </div>
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-link">Dashboard</a>
                <a href="licitaciones.php" class="nav-link">Licitaciones</a>
                <a href="buscador.php" class="nav-link">Buscador hist&oacute;rico</a>
                <a href="lineas-referencia.php" class="nav-link active">A&ntilde;adir l&iacute;neas</a>
                <a href="analytics.php" class="nav-link">Anal&iacute;tica</a>
                <a href="usuarios.php" class="nav-link">Usuarios</a>
            </nav>
            <div class="sidebar-footer">
                <?php echo htmlspecialchars($organizationId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
            </div>
        </aside>
        <div class="main">
            <header>
                <h1>A&ntilde;adir l&iacute;neas de referencia</h1>
                <div class="user-info">
                    <div><?php echo htmlspecialchars($fullName !== '' ? $fullName : $email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                    <?php if ($role !== ''): ?>
                        <div class="pill"><?php echo htmlspecialchars($role, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    <div>
                        <a href="logout.php">Cerrar sesi&oacute;n</a>
                    </div>
                </div>
            </header>

            <main>
                <div class="card">
                    <h2>Nueva l&iacute;nea de referencia</h2>
                    <p>
                        Aqu&iacute; podr&aacute;s guardar precios de producto sin vincularlos a ninguna licitaci&oacute;n.
                        Estas l&iacute;neas aparecer&aacute;n despu&eacute;s en el Buscador Hist&oacute;rico.
                    </p>

                    <form method="post">
                        <div class="form-row">
                            <div class="field">
                                <label for="producto">Producto</label>
                                <input id="producto" name="producto" type="text" placeholder="Nombre del producto..." />
                            </div>
                            <div class="field">
                                <label for="precio">Precio (EUR)</label>
                                <input id="precio" name="precio" type="number" step="0.01" placeholder="0,00" />
                            </div>
                            <div class="field">
                                <label for="proveedor">Proveedor (opcional)</label>
                                <input id="proveedor" name="proveedor" type="text" placeholder="Nombre del proveedor..." />
                            </div>
                        </div>
                        <div class="actions">
                            <button type="button">Guardar (mock)</button>
                        </div>
                        <p class="hint">
                            De momento el formulario es solo visual. M&aacute;s adelante lo conectaremos
                            al backend PHP para insertar las l&iacute;neas en la tabla correspondiente.
                        </p>
                    </form>
                </div>
            </main>
        </div>
    </div>
</body>
</html>



