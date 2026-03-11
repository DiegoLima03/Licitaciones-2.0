<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/database.php';

$error = null;

// Si ya hay sesión iniciada, ir al dashboard.
if (isset($_SESSION['user'])) {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = isset($_POST['email']) ? trim((string)$_POST['email']) : '';
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Email y contraseña son obligatorios.';
    } else {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare(
                'SELECT id, email, password_hash, role, full_name
                 FROM profiles
                 WHERE email = :email
                 LIMIT 1'
            );
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($user && isset($user['password_hash']) && password_verify($password, (string)$user['password_hash'])) {
                unset($user['password_hash']);
                $_SESSION['user'] = $user;
                header('Location: dashboard.php');
                exit;
            }

            $error = 'Credenciales inválidas. Revisa tu email y contraseña.';
        } catch (\Throwable $e) {
            $error = 'Error al conectar con la base de datos: ' . $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Iniciar sesión</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: #0f172a;
            display: flex;
            min-height: 100vh;
            align-items: center;
            justify-content: center;
        }
        .card {
            background: #f9fafb;
            border-radius: 12px;
            box-shadow: 0 20px 40px rgba(15, 23, 42, 0.35);
            max-width: 420px;
            width: 100%;
            padding: 24px 28px 28px;
        }
        .card h1 {
            margin: 0 0 4px;
            font-size: 1.5rem;
            font-weight: 600;
            color: #0f172a;
        }
        .card p {
            margin: 0 0 20px;
            font-size: 0.9rem;
            color: #6b7280;
        }
        label {
            display: block;
            margin-bottom: 6px;
            font-size: 0.85rem;
            font-weight: 500;
            color: #374151;
        }
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 9px 11px;
            font-size: 0.95rem;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            background-color: #ffffff;
            transition: border-color 0.15s, box-shadow 0.15s, background-color 0.15s;
        }
        input[type="email"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #10b981;
            box-shadow: 0 0 0 1px #10b98133;
            background-color: #ffffff;
        }
        .field {
            margin-bottom: 14px;
        }
        .btn {
            width: 100%;
            padding: 10px 14px;
            border-radius: 9999px;
            border: none;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 600;
            background: linear-gradient(135deg, #10b981, #0ea5e9);
            color: #f9fafb;
            transition: transform 0.1s ease, box-shadow 0.1s ease, filter 0.1s ease;
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.35);
        }
        .btn:hover {
            filter: brightness(1.05);
            box-shadow: 0 14px 30px rgba(16, 185, 129, 0.4);
        }
        .btn:active {
            transform: translateY(1px);
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.35);
        }
        .error {
            margin-bottom: 14px;
            padding: 8px 10px;
            border-radius: 8px;
            font-size: 0.85rem;
            color: #b91c1c;
            background-color: #fee2e2;
            border: 1px solid #fecaca;
        }
        .footer-text {
            margin-top: 14px;
            text-align: center;
            font-size: 0.8rem;
            color: #9ca3af;
        }
    </style>
    <link rel="stylesheet" href="assets/css/master-detail-theme.css">
</head>
<body>
    <div class="card">
        <h1>Iniciar sesión</h1>
        <p>Introduce tu email y contraseña para acceder al panel de licitaciones.</p>

        <?php if ($error !== null): ?>
            <div class="error" role="alert">
                <?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="login.php" autocomplete="on">
            <div class="field">
                <label for="email">Email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    required
                    value="<?php echo isset($email) ? htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : ''; ?>"
                >
            </div>

            <div class="field">
                <label for="password">Contraseña</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    required
                >
            </div>

            <button type="submit" class="btn">Entrar</button>
        </form>

        <div class="footer-text">
            Proyecto licitaciones · PHP 8 &amp; MySQL
        </div>
    </div>
</body>
</html>


