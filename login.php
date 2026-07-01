<?php
require_once __DIR__ . '/auth.php';

$error    = '';
$redirect = $_GET['redirect'] ?? '/';
if (isset($_POST['redirect'])) $redirect = $_POST['redirect'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $matched  = false;

    foreach (GROUPS as $group_key => $group) {
        if (password_verify($password, $group['password_hash'])) {
            $token   = auth_make_token($group_key);
            $expires = time() + (AUTH_DAYS * 86400);
            setcookie(AUTH_COOKIE, $token, $expires, '/', '', true, true);
            header('Location: ' . $redirect);
            exit;
        }
    }

    $error = 'Incorrect password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LocTrack Login</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #1a1a2e; color: #e0e0e0;
            height: 100vh; display: flex; align-items: center; justify-content: center;
        }
        .login-box {
            background: #16213e; border: 1px solid #0f3460;
            border-radius: 12px; padding: 40px; width: 100%; max-width: 360px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.4);
        }
        h1 { color: #4fc3f7; font-size: 22px; margin-bottom: 6px; text-align: center; }
        .subtitle { color: #888; font-size: 13px; text-align: center; margin-bottom: 28px; }
        label { display: block; font-size: 12px; color: #aaa; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
        input[type=password] {
            width: 100%; background: #0f3460; border: 1px solid #4fc3f7;
            border-radius: 6px; color: #e0e0e0; font-size: 15px;
            padding: 10px 14px; margin-bottom: 20px; outline: none;
        }
        input[type=password]:focus { border-color: #81d4fa; box-shadow: 0 0 0 2px rgba(79,195,247,0.2); }
        button {
            width: 100%; background: #0f3460; border: 1px solid #4fc3f7;
            border-radius: 6px; color: #4fc3f7; font-size: 15px; font-weight: 600;
            padding: 10px; cursor: pointer; transition: background 0.2s;
        }
        button:hover { background: #1a4a80; }
        .error {
            background: rgba(244,67,54,0.15); border: 1px solid #f44336;
            border-radius: 6px; color: #ef9a9a; font-size: 13px;
            padding: 8px 12px; margin-bottom: 16px; text-align: center;
        }
    </style>
</head>
<body>
<div class="login-box">
    <h1>&#9679; LocTrack</h1>
    <div class="subtitle">Family Location</div>
    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST">
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" autofocus autocomplete="current-password">
        <button type="submit">Sign In</button>
    </form>
</div>
</body>
</html>
