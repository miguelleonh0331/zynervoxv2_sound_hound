<?php
require_once __DIR__ . '/includes/Auth.php';
use Includes\Auth;

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = $_POST['username'] ?? '';
    $pass = $_POST['password'] ?? '';

    if (Auth::login($user, $pass)) {
        $level = $_SESSION['user_level'];
        header("Location: " . Auth::getRedirectPath($level));
        exit;
    } else {
        $error = "Usuario o contraseña incorrectos.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zynervox - Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #F5821F;
            --primary-hover: #E0731A;
            --dark: #2D2F3B;
            --cream: #F2EAE0;
            --card-bg: #FFFFFF;
            --input-bg: #EAF0FB;
            --input-border: #D7E0F0;
            --text: #2D2F3B;
            --text-muted: #6B7280;
            --border: #E5E0D8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, var(--dark) 0%, var(--dark) 42%, var(--cream) 58%, var(--cream) 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text);
            overflow: hidden;
        }

        .login-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            padding: 2.5rem;
            border-radius: 24px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 25px 50px -12px rgba(45, 47, 59, 0.35);
            animation: fadeIn 0.8s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .logo {
            text-align: center;
            margin-bottom: 2rem;
        }

        .logo .eyebrow {
            display: block;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .logo h1 {
            font-size: 2rem;
            font-weight: 600;
            letter-spacing: -1px;
            color: var(--dark);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            color: var(--text-muted);
        }

        input {
            width: 100%;
            padding: 0.75rem 1rem;
            background: var(--input-bg);
            border: 1px solid var(--input-border);
            border-radius: 12px;
            color: var(--text);
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(245, 130, 31, 0.15);
        }

        .btn-login {
            width: 100%;
            padding: 0.75rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-hover));
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.3s;
            margin-top: 1rem;
        }

        .btn-login:hover {
            box-shadow: 0 10px 20px -6px rgba(245, 130, 31, 0.5);
            transform: translateY(-2px);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .error-msg {
            background: #FDECEC;
            border: 1px solid #F5C2C2;
            color: #B91C1C;
            padding: 0.75rem;
            border-radius: 12px;
            font-size: 0.875rem;
            margin-bottom: 1.5rem;
            text-align: center;
        }

        .footer {
            margin-top: 2rem;
            text-align: center;
            font-size: 0.75rem;
            color: var(--text-muted);
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="logo">
            <span class="eyebrow">Zynervox Workspace</span>
            <p style="color: var(--text-muted); font-size: 0.875rem;">Operativa Premium</p>
        </div>

        <?php if ($error): ?>
            <div class="error-msg"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="username">Usuario</label>
                <input type="text" id="username" name="username" required placeholder="Ingresa tu usuario">
            </div>
            <div class="form-group">
                <label for="password">Contraseña</label>
                <input type="password" id="password" name="password" required placeholder="••••••••">
            </div>
            <button type="submit" class="btn-login">Iniciar Sesión</button>
        </form>

        <div class="footer">
            &copy; 2024 Todos los derechos reservados
        </div>
    </div>
</body>
</html>
