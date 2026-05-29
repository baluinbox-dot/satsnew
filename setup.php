<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>STAS — First Time Setup</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<style>
  body { background: #f0f2f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
  .setup-card { background: #fff; border-radius: 12px; padding: 36px; max-width: 480px; width: 100%; border: 1px solid #e0e0e0; }
  .setup-title { font-size: 20px; font-weight: 700; text-align: center; margin-bottom: 6px; }
  .setup-sub   { font-size: 13px; color: #6c757d; text-align: center; margin-bottom: 24px; }
  .step-badge  { background: #185FA5; color: #fff; font-size: 12px; padding: 3px 10px; border-radius: 12px; }
</style>
</head>
<body>
<div class="setup-card">
  <div class="text-center mb-4">
    <div style="font-size:40px;">📈</div>
    <div class="setup-title">STAS Setup</div>
    <div class="setup-sub">Smart Trailing Accumulation System<br>by FinOps Digital Solutions</div>
  </div>

  <?php
  require_once 'api/config.php';

  // Check if already set up
  try {
    $db = getDB();
    $chk = $db->prepare('SELECT COUNT(*) FROM st_users WHERE role = "superadmin"');
    $chk->execute();
    $count = (int)$chk->fetchColumn();
    if ($count > 0) {
      echo '<div class="alert alert-success">✅ Setup already complete. <a href="login.php">Go to Login →</a></div>';
      echo '</div></body></html>';
      exit;
    }
  } catch(Exception $e) {
    // DB might not exist yet — show schema instructions
    echo '<div class="alert alert-warning">⚠️ Database not set up yet. Please run <code>setup.sql</code> in phpMyAdmin first.</div>';
  }

  $done = false;
  $error = '';

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['name']  ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pass  = trim($_POST['password'] ?? '');

    if (!$name || !$email || !$pass) {
      $error = 'All fields are required.';
    } elseif ($email !== SUPERADMIN_EMAIL) {
      $error = 'Superadmin email must be: ' . SUPERADMIN_EMAIL;
    } elseif (strlen($pass) < 6) {
      $error = 'Password must be at least 6 characters.';
    } else {
      try {
        $db     = getDB();
        $userId = genId();
        $hashed = password_hash($pass, PASSWORD_BCRYPT);

        $db->prepare('
          INSERT INTO st_users (id, name, email, password, role)
          VALUES (?, ?, ?, ?, "superadmin")
        ')->execute([$userId, $name, $email, $hashed]);

        $db->prepare('
          INSERT INTO st_settings (id, ownerId) VALUES (?, ?)
        ')->execute([genId(), $userId]);

        $done = true;
      } catch(Exception $e) {
        $error = 'Error: ' . $e->getMessage();
      }
    }
  }

  if ($done): ?>
    <div class="alert alert-success">
      <strong>✅ Setup complete!</strong><br>
      Superadmin account created for <strong><?= htmlspecialchars($_POST['email']) ?></strong>.
      <br><br>
      <a href="login.php" class="btn btn-primary btn-sm">Go to Login →</a>
    </div>
    <div class="alert alert-warning mt-3" style="font-size:12px;">
      ⚠️ <strong>Important:</strong> Delete or rename <code>setup.php</code> after first login for security.
    </div>
  <?php elseif ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if (!$done): ?>
  <div class="mb-3">
    <span class="step-badge">Step 1</span>
    <span class="ms-2" style="font-size:13px;">Create Superadmin Account</span>
  </div>
  <form method="POST">
    <div class="mb-3">
      <label class="form-label" style="font-size:13px;">Full Name</label>
      <input type="text" name="name" class="form-control" value="Balusamy Paramasivam" required>
    </div>
    <div class="mb-3">
      <label class="form-label" style="font-size:13px;">Email (must be <?= SUPERADMIN_EMAIL ?>)</label>
      <input type="email" name="email" class="form-control" value="<?= SUPERADMIN_EMAIL ?>" required>
    </div>
    <div class="mb-3">
      <label class="form-label" style="font-size:13px;">Password</label>
      <input type="password" name="password" class="form-control" required minlength="6">
    </div>
    <button type="submit" class="btn btn-primary w-100">Create Superadmin & Complete Setup</button>
  </form>
  <?php endif; ?>

  <p style="font-size:11px;color:#aaa;text-align:center;margin-top:20px;">
    FinOps Digital Solutions · Chennai · v1.0
  </p>
</div>
</body>
</html>
