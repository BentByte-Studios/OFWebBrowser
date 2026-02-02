<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'includes/error_handler.php';

ErrorHandler::init();
initSecureSession();

$showPasswordChange = false;
$redirect = $_GET['redirect'] ?? 'index.php';

// Validate redirect URL is local
if (strpos($redirect, '//') !== false || strpos($redirect, ':') !== false) {
    $redirect = 'index.php';
}

// If already logged in
if (isAuthenticated()) {
    // Check if password change is needed
    if (needsPasswordChange()) {
        $showPasswordChange = true;
    } else {
        header('Location: ' . $redirect);
        exit;
    }
}

$error = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$showPasswordChange) {
    // Validate CSRF token
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Please enter both username and password.';
        } elseif (attemptLogin($username, $password)) {
            // Login successful - check if password change needed
            $redirect = $_POST['redirect'] ?? 'index.php';
            if (strpos($redirect, '//') !== false || strpos($redirect, ':') !== false) {
                $redirect = 'index.php';
            }

            if (needsPasswordChange()) {
                $showPasswordChange = true;
            } else {
                header('Location: ' . $redirect);
                exit;
            }
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $showPasswordChange ? 'Set Password' : 'Login' ?> - <?= SITE_TITLE ?></title>
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
        .login-container {
            width: 100%;
            max-width: 400px;
            padding: 20px;
        }
        .login-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 40px 30px;
        }
        .login-title {
            text-align: center;
            margin: 0 0 10px;
            font-size: 1.5rem;
            font-weight: 700;
        }
        .login-subtitle {
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
        }
        .btn-login {
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
        }
        .btn-login:hover {
            background: var(--accent-hover);
        }
        .btn-login:disabled {
            background: var(--border-color);
            cursor: not-allowed;
        }
        .site-subtitle {
            text-align: center;
            color: var(--text-secondary);
            font-size: 0.85rem;
            margin-top: 20px;
        }
        .forgot-link {
            display: block;
            text-align: center;
            margin-top: 15px;
            color: var(--text-secondary);
            font-size: 0.85rem;
            text-decoration: none;
            cursor: pointer;
        }
        .forgot-link:hover {
            color: var(--accent);
        }
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.8);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 30px;
            max-width: 450px;
            width: 90%;
        }
        .modal h2 {
            margin: 0 0 15px;
            font-size: 1.25rem;
        }
        .modal p {
            color: var(--text-secondary);
            font-size: 0.9rem;
            line-height: 1.5;
            margin: 0 0 20px;
        }
        .modal code {
            background: var(--bg-body);
            padding: 10px 15px;
            border-radius: 6px;
            display: block;
            font-family: 'Consolas', monospace;
            font-size: 0.85rem;
            color: var(--accent);
            word-break: break-all;
            margin: 10px 0;
        }
        .modal-close {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 10px 25px;
            border-radius: 50px;
            cursor: pointer;
            font-size: 0.9rem;
        }
        .modal-close:hover {
            border-color: var(--text-secondary);
        }
        .password-requirements {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <?php if ($showPasswordChange): ?>
        <!-- Password Change Form (First Login) -->
        <div class="login-card">
            <h1 class="login-title">Set Your Password</h1>
            <p class="login-subtitle">Please choose a secure password for your account</p>

            <div id="pwChangeError" class="error-message" style="display: none;"></div>
            <div id="pwChangeSuccess" class="success-message" style="display: none;"></div>

            <form id="passwordChangeForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

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

                <button type="submit" class="btn-login" id="pwChangeBtn">Set Password</button>
            </form>
        </div>
        <p class="site-subtitle">This is required on first login</p>

        <script>
            const form = document.getElementById('passwordChangeForm');
            const errorDiv = document.getElementById('pwChangeError');
            const successDiv = document.getElementById('pwChangeSuccess');
            const submitBtn = document.getElementById('pwChangeBtn');
            const redirect = <?= json_encode($redirect) ?>;

            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                errorDiv.style.display = 'none';
                successDiv.style.display = 'none';
                submitBtn.disabled = true;
                submitBtn.textContent = 'Saving...';

                const formData = new FormData(form);

                // Client-side validation
                const newPw = formData.get('new_password');
                const confirmPw = formData.get('confirm_password');

                if (newPw.length < 6) {
                    errorDiv.textContent = 'Password must be at least 6 characters';
                    errorDiv.style.display = 'block';
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Set Password';
                    return;
                }

                if (newPw !== confirmPw) {
                    errorDiv.textContent = 'Passwords do not match';
                    errorDiv.style.display = 'block';
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Set Password';
                    return;
                }

                try {
                    const res = await fetch('change-password.php', {
                        method: 'POST',
                        body: formData
                    });
                    const data = await res.json();

                    if (data.status === 'ok') {
                        successDiv.textContent = 'Password set successfully! Redirecting...';
                        successDiv.style.display = 'block';
                        setTimeout(() => {
                            window.location.href = redirect;
                        }, 1500);
                    } else {
                        errorDiv.textContent = data.message || 'Failed to set password';
                        errorDiv.style.display = 'block';
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Set Password';
                    }
                } catch (err) {
                    errorDiv.textContent = 'An error occurred. Please try again.';
                    errorDiv.style.display = 'block';
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Set Password';
                }
            });
        </script>

        <?php else: ?>
        <!-- Login Form -->
        <div class="login-card">
            <h1 class="login-title"><?= SITE_TITLE ?></h1>

            <?php if ($error): ?>
                <div class="error-message"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <?php csrfField(); ?>
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">

                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <input type="text" id="username" name="username" class="form-input"
                           required autocomplete="username" autofocus>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-input"
                           required autocomplete="current-password">
                </div>

                <button type="submit" class="btn-login">Sign In</button>
            </form>

            <a class="forgot-link" onclick="document.getElementById('forgotModal').classList.add('active')">Forgot password?</a>
        </div>
        <p class="site-subtitle">Sign in to access your library</p>

        <!-- Forgot Password Modal -->
        <div class="modal-overlay" id="forgotModal">
            <div class="modal">
                <h2>Reset Your Password</h2>
                <p>To reset your password, run this command in the browser directory:</p>
                <code>php reset-password.php</code>
                <p>This will generate a password reset link that you can use to set a new password.</p>
                <button class="modal-close" onclick="document.getElementById('forgotModal').classList.remove('active')">Close</button>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
