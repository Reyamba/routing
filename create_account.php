<?php
session_start();
require_once __DIR__ . '/config.php';

$errorMessage = '';
$successMessage = '';
if (isset($_SESSION['username'])) {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($username === '' || $email === '' || $password === '' || $confirmPassword === '') {
        $errorMessage = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'Please enter a valid email address.';
    } elseif ($password !== $confirmPassword) {
        $errorMessage = 'Passwords do not match.';
    } else {
        $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->bind_param('ss', $username, $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $errorMessage = 'Username or email already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $insert = $conn->prepare('INSERT INTO users (username, password, email) VALUES (?, ?, ?)');
            $insert->bind_param('sss', $username, $hash, $email);

            if ($insert->execute()) {
                $successMessage = 'Account created successfully. You can now log in.';
            } else {
                $errorMessage = 'Unable to create account. Please try again.';
            }

            $insert->close();
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
    <title>Create Account - PSA Routing</title>
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
            height: 650px;
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

        .form-back {
            transform: rotateY(180deg);
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
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            color: #374151;
        }

        input[type="text"],
        input[type="email"],
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

        .flip-trigger {
            display: inline-block;
            margin-top: 20px;
            color: var(--primary-color);
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            font-size: 0.95rem;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
        }

        .flip-trigger:hover {
            border-bottom-color: var(--primary-color);
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

        .success-box {
            padding: 12px 16px;
            background-color: #d1fae5;
            color: #065f46;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            border-left: 4px solid #10b981;
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
            .info-panel { display: none; }
        }
    </style>
</head>
<body>
    <section class="page-section">
        <div class="login-container">
            <div class="flip-card" id="loginCard">
                <div class="form-face form-front">
                    <h1>Create Account</h1>
                    <p class="subtitle">Register for PSA Agusan Del Sur Routing</p>

                    <form action="create_account.php" method="POST">
                        <div class="input-group">
                            <label>Username</label>
                            <input type="text" name="username" placeholder="Username" required>
                        </div>
                        <div class="input-group">
                            <label>Email</label>
                            <input type="email" name="email" placeholder="Email" required>
                        </div>
                        <div class="input-group">
                            <label>Password</label>
                            <input type="password" name="password" placeholder="••••••••" required>
                        </div>
                        <div class="input-group">
                            <label>Confirm Password</label>
                            <input type="password" name="confirm_password" placeholder="••••••••" required>
                        </div>
                        <?php if ($errorMessage): ?>
                            <div class="error-box"><?php echo htmlspecialchars($errorMessage); ?></div>
                        <?php elseif ($successMessage): ?>
                            <div class="success-box"><?php echo htmlspecialchars($successMessage); ?></div>
                        <?php endif; ?>
                        <button type="submit" class="btn-sign-in">Create Account</button>
                        <p style="margin-top: 18px; text-align: center; font-size: 0.95rem; color: #4b5563;">
                            Already have an account? <a href="index.php" class="flip-trigger">Log in</a>
                        </p>
                    </form>
                </div>
            </div>
        </div>
        <div class="info-panel">
            <video autoplay muted loop playsinline>
                <source src="Animated_PSA_Logo_Video.mp4" type="video/mp4">
            </video>
        </div>
    </section>
</body>
</html>
