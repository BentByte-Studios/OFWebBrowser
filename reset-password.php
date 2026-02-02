<?php
/**
 * Password Reset Script
 *
 * Run from command line: php reset-password.php
 * Or access via browser with a valid reset token
 */

require_once 'config.php';
require_once 'includes/auth.php';
require_once 'includes/error_handler.php';

ErrorHandler::init();

// Check if running from CLI
$isCli = php_sapi_name() === 'cli';

if ($isCli) {
    // CLI Mode: Generate a reset token
    echo "\n";
    echo "================================\n";
    echo "  Password Reset Tool\n";
    echo "================================\n\n";

    $token = generateResetToken();

    if ($token) {
        echo "A password reset link has been generated.\n\n";
        echo "Open this URL in your browser:\n";
        echo "  http://localhost:8080/reset-password.php?token=$token\n\n";
        echo "This link will expire in 1 hour.\n\n";
    } else {
        echo "ERROR: Could not generate reset token.\n";
        echo "Make sure the database exists (run a scan first).\n\n";
        exit(1);
    }
    exit(0);
}

// Browser Mode: Handle reset with token
initSecureSession();

$token = $_GET['token'] ?? '';
$error = '';
$success = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } elseif (!validateResetToken($token)) {
        $error = 'Invalid or expired reset link. Please generate a new one.';
    } else {
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($newPassword)) {
            $error = 'Password is required.';
        } elseif (strlen($newPassword) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } elseif (updatePassword($newPassword)) {
            clearResetToken();
            $success = true;
        } else {
            $error = 'Failed to update password. Please try again.';
        }
    }
}

// Validate token for GET requests
$validToken = !empty($token) && validateResetToken($token);
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - <?= SITE_TITLE ?></title>
    <style>
        :root {
            --bg-body: #0b0e11;
            --bg-card: #191f27;
            --text-primary: #e4e7eb;
            --text-secondary: #8a96a3;
            --accent: #00aff0;
            --accent-hover: #0091c9;
            --border-color: #242c37;
            --error-color: #ef4444;
            --success-color: #22c55e;
        }
        * { box-sizing: border-box; }
        body {
            background-color: var(--bg-body);
            color: var(--text-primary);
            font-family: 'Segoe UI', Helvetica, Arial, sans-serif;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            width: 100%;
            max-width: 400px;
            padding: 20px;
        }
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 40px 30px;
        }
        .title {
            text-align: center;
            margin: 0 0 10px;
            font-size: 1.5rem;
            font-weight: 700;
        }
        .subtitle {
            text-align: center;
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin: 0 0 30px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-size: 0.9rem;
            color: var(--text-secondary);
        }
        .form-input {
            width: 100%;
            padding: 12px 15px;
            background: var(--bg-body);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-primary);
            font-size: 1rem;
            transition: border-color 0.2s;
        }
        .form-input:focus {
            outline: none;
            border-color: var(--accent);
        }
        .error-message {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid var(--error-color);
            color: var(--error-color);
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }
        .success-message {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid var(--success-color);
            color: var(--success-color);
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            text-align: center;
        }
        .btn {
            width: 100%;
            padding: 14px;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: 50px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
            text-decoration: none;
            display: block;
            text-align: center;
        }
        .btn:hover {
            background: var(--accent-hover);
        }
        .password-requirements {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-top: 5px;
        }
        .info-box {
            background: var(--bg-body);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 20px;
            text-align: center;
        }
        .info-box p {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin: 0 0 15px;
            line-height: 1.5;
        }
        .info-box code {
            display: block;
            background: var(--bg-card);
            padding: 10px;
            border-radius: 4px;
            color: var(--accent);
            font-family: 'Consolas', monospace;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <?php if ($success): ?>
                <h1 class="title">Password Reset</h1>
                <div class="success-message">
                    Your password has been reset successfully!
                </div>
                <a href="login.php" class="btn">Go to Login</a>

            <?php elseif ($validToken): ?>
                <h1 class="title">Reset Password</h1>
                <p class="subtitle">Enter your new password below</p>

                <?php if ($error): ?>
                    <div class="error-message"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                    <div class="form-group">
                        <label class="form-label" for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" class="form-input"
                               required autocomplete="new-password" autofocus minlength="6">
                        <div class="password-requirements">Minimum 6 characters</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-input"
                               required autocomplete="new-password" minlength="6">
                    </div>

                    <button type="submit" class="btn">Reset Password</button>
                </form>

            <?php else: ?>
                <h1 class="title">Reset Password</h1>

                <?php if ($error): ?>
                    <div class="error-message"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="info-box">
                    <p>To reset your password, run this command in the browser directory:</p>
                    <code>php reset-password.php</code>
                    <p style="margin-top: 15px;">This will generate a reset link you can use.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
