<?php
session_start();
require_once __DIR__ . '/config.php';

$message = '';
$success = false;
$token = $_GET['token'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'], $_POST['confirm_password'])) {
    $password = $_POST['password'];
    $confirm = $_POST['confirm_password'];

    if (empty($password) || strlen($password) < 6) {
        $message = 'Password must be at least 6 characters long.';
    } elseif ($password !== $confirm) {
        $message = 'Passwords do not match.';
    } else {
        $stmt = $conn->prepare('SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW() LIMIT 1');
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows === 1) {
            $user = $result->fetch_assoc();
            $hashed = password_hash($password, PASSWORD_DEFAULT);

            $update = $conn->prepare('UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?');
            $update->bind_param('si', $hashed, $user['id']);
            $update->execute();
            $update->close();

            $message = 'Password has been reset successfully. You can now log in.';
            $success = true;
        } else {
            $message = 'Invalid or expired reset token.';
        }
        $stmt->close();
    }
} elseif (!empty($token)) {
    $stmt = $conn->prepare('SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW() LIMIT 1');
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $valid = $result && $result->num_rows === 1;
    $stmt->close();

    if (!$valid) {
        $message = 'Invalid or expired reset token.';
    }
} else {
    $message = 'No reset token provided.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - DMS PSA</title>
    <link rel="shortcut icon" type="image/x-icon" href="logo.png" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .container {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
        }

        h1 {
            text-align: center;
            margin-bottom: 8px;
            color: #111;
        }

        p.subtitle {
            text-align: center;
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

        .btn-submit {
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

        .btn-submit:hover {
            background-color: var(--primary-dark);
        }

        .message {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }

        .success {
            background-color: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }

        .error {
            background-color: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #dc2626;
        }

        .back-link {
            text-align: center;
            margin-top: 20px;
        }

        .back-link a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
        }

        .back-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Reset Password</h1>
        <p class="subtitle">Enter your new password</p>

        <?php if ($message): ?>
            <div class="message <?php echo $success ? 'success' : 'error'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if (!$success && !empty($token) && empty($message)): ?>
            <form action="reset_password.php?token=<?php echo htmlspecialchars($token); ?>" method="POST">
                <div class="input-group">
                    <label>New Password</label>
                    <input type="password" name="password" placeholder="••••••••" required minlength="6">
                </div>
                <div class="input-group">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" placeholder="••••••••" required minlength="6">
                </div>
                <button type="submit" class="btn-submit">Reset Password</button>
            </form>
        <?php endif; ?>

        <div class="back-link">
            <a href="index.php">Back to Login</a>
        </div>
    </div>
</body>
</html>