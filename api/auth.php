<?php
// ============================================================
// api/auth.php — Login / Logout / Register / Session check
// ============================================================

// Start session only if not already active
if (session_status() === PHP_SESSION_NONE) session_start();

switch ($action) {

    case 'login':
        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $email = strtolower(trim($body['email'] ?? ''));
        $pass  = trim($body['password'] ?? '');

        if (!$email || !$pass) jsonError('Email and password are required');

        $db   = getDB();
        $stmt = $db->prepare('SELECT * FROM st_users WHERE email = ? AND isActive = 1 LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($pass, $user['password'])) {
            jsonError('Invalid email or password');
        }

        $_SESSION['userId']    = $user['id'];
        $_SESSION['userEmail'] = $user['email'];
        $_SESSION['userRole']  = $user['role'];
        $_SESSION['userName']  = $user['name'];

        $db->prepare('UPDATE st_users SET lastLogin = NOW() WHERE id = ?')
           ->execute([$user['id']]);

        jsonOk([
            'id'    => $user['id'],
            'name'  => $user['name'],
            'email' => $user['email'],
            'role'  => $user['role'],
        ], 'Login successful');
        break;

    case 'logout':
        $_SESSION = [];
        session_destroy();
        jsonOk([], 'Logged out');
        break;

    case 'register':
        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $name  = trim($body['name']  ?? '');
        $email = strtolower(trim($body['email'] ?? ''));
        $pass  = trim($body['password'] ?? '');
        $phone = trim($body['phone'] ?? '');

        if (!$name || !$email || !$pass) jsonError('Name, email, and password are required');
        if (strlen($pass) < 6)           jsonError('Password must be at least 6 characters');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Invalid email address');

        $db  = getDB();
        $chk = $db->prepare('SELECT id FROM st_users WHERE email = ? LIMIT 1');
        $chk->execute([$email]);
        if ($chk->fetch()) jsonError('This email is already registered');

        $userId = genId();
        $hashed = password_hash($pass, PASSWORD_BCRYPT);
        $role   = ($email === SUPERADMIN_EMAIL) ? 'superadmin' : 'user';

        $db->prepare('INSERT INTO st_users (id, name, email, password, role, phone) VALUES (?, ?, ?, ?, ?, ?)')
           ->execute([$userId, $name, $email, $hashed, $role, $phone]);

        $db->prepare('INSERT INTO st_settings (id, ownerId) VALUES (?, ?)')
           ->execute([genId(), $userId]);

        // Auto-login after register
        $_SESSION['userId']    = $userId;
        $_SESSION['userEmail'] = $email;
        $_SESSION['userRole']  = $role;
        $_SESSION['userName']  = $name;

        jsonOk(['id' => $userId, 'role' => $role], 'Registration successful');
        break;

    case 'checkSession':
        if (!empty($_SESSION['userId'])) {
            jsonOk([
                'id'    => $_SESSION['userId'],
                'name'  => $_SESSION['userName'],
                'email' => $_SESSION['userEmail'],
                'role'  => $_SESSION['userRole'],
            ]);
        } else {
            jsonError('Not authenticated', 401);
        }
        break;
}
