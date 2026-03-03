<?php

declare(strict_types=1);

/**
 * Layout base de la aplicación PHP.
 *
 * Usa Tailwind CSS vía CDN para replicar el estilo del frontend original.
 *
 * Variables esperadas:
 * - string $title    Título de la página (opcional).
 * - string $content  HTML del contenido principal a inyectar en el layout.
 */

$pageTitle = isset($title) && $title !== ''
    ? $title
    : 'Veraleza Licitaciones';

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></title>
    <!-- Tailwind CDN para estilos (equivalente a globals.css + clases Tailwind del frontend original) -->
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 antialiased">
    <div class="flex min-h-screen">
        <!-- Sidebar / Navbar lateral (basado en AuthLayout de React) -->
        <aside class="fixed inset-y-0 left-0 z-20 w-64 bg-slate-900 text-slate-50 shadow-xl">
            <div
                class="flex h-16 w-full items-center gap-2 border-b border-slate-800 px-6 text-left"
            >
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-500 text-lg font-semibold text-slate-900">
                    V
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold">Veraleza</p>
                    <p class="text-xs text-slate-400">Licitaciones</p>
                </div>
            </div>

            <nav class="mt-4 space-y-1 px-3 text-sm">
                <a
                    href="dashboard.php"
                    class="flex items-center gap-2 rounded-lg px-3 py-2 text-slate-100 hover:bg-slate-800 hover:text-white"
                >
                    <span class="inline-block h-2 w-2 rounded-full bg-emerald-400"></span>
                    <span>Dashboard</span>
                </a>
                <a
                    href="licitaciones.php"
                    class="flex items-center gap-2 rounded-lg px-3 py-2 text-slate-100 hover:bg-slate-800 hover:text-white"
                >
                    <span class="inline-block h-2 w-2 rounded-full bg-sky-400"></span>
                    <span>Mis Licitaciones</span>
                </a>
                <a
                    href="buscador.php"
                    class="flex items-center gap-2 rounded-lg px-3 py-2 text-slate-100 hover:bg-slate-800 hover:text-white"
                >
                    <span class="inline-block h-2 w-2 rounded-full bg-indigo-400"></span>
                    <span>Buscador Histórico</span>
                </a>
                <a
                    href="lineas-referencia.php"
                    class="flex items-center gap-2 rounded-lg px-3 py-2 text-slate-100 hover:bg-slate-800 hover:text-white"
                >
                    <span class="inline-block h-2 w-2 rounded-full bg-amber-400"></span>
                    <span>Añadir líneas</span>
                </a>
                <a
                    href="analytics.php"
                    class="flex items-center gap-2 rounded-lg px-3 py-2 text-slate-100 hover:bg-slate-800 hover:text-white"
                >
                    <span class="inline-block h-2 w-2 rounded-full bg-fuchsia-400"></span>
                    <span>Analítica</span>
                </a>
                <a
                    href="usuarios.php"
                    class="flex items-center gap-2 rounded-lg px-3 py-2 text-slate-100 hover:bg-slate-800 hover:text-white"
                >
                    <span class="inline-block h-2 w-2 rounded-full bg-rose-400"></span>
                    <span>Usuarios</span>
                </a>
            </nav>
        </aside>

        <!-- Contenido principal (equivalente a {children} dentro de AuthLayout) -->
        <main class="ml-64 flex min-h-screen flex-1 flex-col bg-slate-50">
            <div class="mx-auto flex w-full max-w-6xl flex-1 flex-col px-6 py-6 lg:px-10 lg:py-8">
                <?php echo $content ?? ''; ?>
            </div>
        </main>
    </div>
</body>
</html>

