<?php
session_start();
require_once __DIR__ . '/config.php';

$errorMessage = '';

if (isset($_SESSION['username'])) {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'], $_POST['password'])) {

    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if ($username === '' || $password === '') {

        $errorMessage = 'Please enter both username and password.';

    } else {

        // Get user by username only
        $stmt = $conn->prepare('
            SELECT id, username, email, password 
            FROM users 
            WHERE username = ? 
            LIMIT 1
        ');

        $stmt->bind_param('s', $username);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($result && $result->num_rows === 1) {

            $user = $result->fetch_assoc();

            $email = strtolower(trim($user['email']));

            $allowedDomain = '@psa.gov.ph';

            $allowedEmails = [
                'example.psa@gmail.com'
            ];

            $isAllowed = false;

            // Allow PSA emails
            if (substr($email, -strlen($allowedDomain)) === $allowedDomain) {
                $isAllowed = true;
            }

            // Allow specific Gmail
            if (in_array($email, $allowedEmails)) {
                $isAllowed = true;
            }

            if (!$isAllowed) {

                $errorMessage = 'Only PSA authorized accounts can access this system.';

            } else {

                $storedPassword = $user['password'];

                $loginValid = false;

                // Verify password
                if (password_get_info($storedPassword)['algo'] !== 0) {

                    $loginValid = password_verify($password, $storedPassword);

                } else {

                    $loginValid = trim($password) === trim($storedPassword);
                }

                if ($loginValid) {

                    // Upgrade old passwords to hashed passwords
                    if (password_get_info($storedPassword)['algo'] === 0) {

                        $newHash = password_hash($password, PASSWORD_DEFAULT);

                        $update = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');

                        $update->bind_param('si', $newHash, $user['id']);

                        $update->execute();

                        $update->close();
                    }

                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];

                    header('Location: dashboard.php');
                    exit;
                }

                $errorMessage = 'Invalid username or password.';
            }

        } else {

            $errorMessage = 'Invalid username or password.';
        }

        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DMS PSA, Agusan Del Sur</title>

    <link rel="shortcut icon" type="image/x-icon" href="logo.png" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --primary-color: #3946bd;
            --primary-dark: #000d80;
            --bg-light: #f9fafb;
            --text-main: #111827;
            --text-muted: #6b7280;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', sans-serif;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-main);
            overflow: hidden;
            height: 100vh;
        }

        .page-section {
            display: flex;
            height: 100vh;
            width: 100%;
        }

        .login-container {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 40px;
            background: white;
            perspective: 1000px;
        }

        .flip-card {
            position: relative;
            width: 100%;
            max-width: 400px;
            height: 550px;
            transform-style: preserve-3d;
            transition: transform 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .form-face {
            position: absolute;
            width: 100%;
            height: 100%;
            backface-visibility: hidden;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: white;
        }

        h1 {
            font-size: 1.85rem;
            color: #111;
            margin-bottom: 8px;
        }

        p.subtitle {
            color: var(--text-muted);
            margin-bottom: 30px;
            font-size: 0.95rem;
        }

        .input-group {
            margin-bottom: 20px;
            position: relative;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            color: #374151;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 1rem;
            outline: none;
            transition: all 0.2s;
        }

        input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(57, 70, 189, 0.1);
        }

        .btn-sign-in {
            width: 100%;
            padding: 14px;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }

        .btn-sign-in:hover {
            background-color: var(--primary-dark);
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #6b7280;
            font-size: 1.2rem;
        }

        .error-box {
            padding: 12px 16px;
            background-color: #fee2e2;
            color: #991b1b;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            border-left: 4px solid #dc2626;
        }

        .info-panel {
            flex: 1;
            background-color: var(--primary-color);
            position: relative;
            overflow: hidden;
        }

        .info-panel video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        @media (max-width: 768px) {
            .info-panel {
                display: none;
            }
        }
    </style>
</head>

<body>

<section class="page-section">

    <div class="login-container">

        <div class="flip-card" id="loginCard">

            <div class="form-face form-front">

                <h1>Login</h1>

                <p class="subtitle">
                    PSA Agusan Del Sur Routing System
                </p>

                <form action="index.php" method="POST">

                    <div class="input-group">
                        <label>Username</label>
                        <input type="text" name="username" placeholder="Enter Username" required>
                    </div>

                    <div class="input-group">
                        <label>Password</label>

                        <input type="password"
                               name="password"
                               id="password"
                               placeholder="••••••••"
                               required>

                        <i class="fas fa-eye password-toggle" id="togglePassword"></i>
                    </div>

                    <?php if ($errorMessage): ?>
                        <div class="error-box">
                            <?php echo htmlspecialchars($errorMessage); ?>
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="btn-sign-in">
                        Sign In
                    </button>

                    <p style="margin-top:18px; text-align:center; font-size:0.95rem; color:#4b5563;">

                        Don't have an account?

                        <a href="create_account.php" class="flip-trigger">
                            Create one
                        </a>

                        |

                        <a href="forgot_password.php" class="flip-trigger">
                            Forgot Password?
                        </a>

                    </p>

                </form>

            </div>

        </div>

    </div>

    <!-- RIGHT PANEL VIDEO -->
    <div class="info-panel">

        <video autoplay muted loop playsinline>
            <source src="Animated_PSA_Logo_Video.mp4" type="video/mp4">
        </video>

    </div>

</section>

<script>

document.getElementById('togglePassword').addEventListener('click', function () {

    const password = document.getElementById('password');

    const type = password.getAttribute('type') === 'password'
        ? 'text'
        : 'password';

    password.setAttribute('type', type);

    this.classList.toggle('fa-eye');
    this.classList.toggle('fa-eye-slash');
});

</script>

</body>
</html>

