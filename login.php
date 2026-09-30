<?php
/**
 * Sign in / sign up entry point.
 */
declare(strict_types=1);

require_once __DIR__ . '/src/Bootstrap.php';

if (Auth::check()) {
    redirect('index.php');
}

$error    = null;
$email    = '';
// The form posts this, but it may also arrive on the URL from a deep link.
$redirect = safe_page($_POST['next'] ?? $_GET['next'] ?? null);

// ---- POST: password sign-in ------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $email = trim((string) ($_POST['email'] ?? ''));
    try {
        // attempt() reports failure in its return value, not by throwing, so
        // the result has to be checked or a bad password looks like success.
        $result = Auth::attempt(
            $email,
            (string) ($_POST['password'] ?? ''),
            !empty($_POST['remember'])
        );
        if (empty($result['ok'])) {
            $error = (string) ($result['error'] ?? 'Sign-in failed. Please try again.');
        } else {
            flash('success', 'Welcome back!');
            redirect($redirect);
        }
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

$flashes = take_flashes();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in &middot; <?= e(config('app.name', 'FlatMate')) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(asset('css/style.css')) ?>" rel="stylesheet">
</head>
<body data-api-base="<?= e(base_url('api/index.php')) ?>" data-csrf="<?= e(csrf_token()) ?>">

<div class="fm-auth-wrap">
  <div class="fm-auth-card">

    <div class="text-center mb-4">
      <span class="fm-brand-mark d-inline-grid" style="width:52px;height:52px;font-size:1.5rem">
        <i class="bi bi-house-heart-fill"></i>
      </span>
      <h1 class="h4 mt-2 mb-0"><?= e(config('app.name', 'FlatMate')) ?></h1>
      <p class="text-muted-2 mb-0" style="font-size:.85rem">Shared flat, without the group-chat chaos.</p>
    </div>

    <?php foreach ($flashes as $f): ?>
      <div class="alert alert-<?= e($f['type']) ?> py-2" style="font-size:.85rem"><?= e($f['message']) ?></div>
    <?php endforeach; ?>

    <?php if ($error): ?>
      <div class="alert alert-danger py-2 d-flex gap-2 align-items-center" role="alert">
        <i class="bi bi-exclamation-octagon-fill"></i><span><?= e($error) ?></span>
      </div>
    <?php endif; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($redirect) ?>">

      <div class="mb-3">
        <label class="form-label" for="email">Email</label>
        <input type="email" class="form-control" id="email" name="email"
               value="<?= e($email) ?>" required autocomplete="email"
               placeholder="you@example.com">
      </div>

      <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <input type="password" class="form-control" id="password" name="password"
               required autocomplete="current-password" placeholder="••••••••">
      </div>

      <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1" checked>
        <label class="form-check-label" for="remember" style="font-size:.85rem">Keep me signed in</label>
      </div>

      <button class="btn btn-primary w-100" type="submit">
        <i class="bi bi-box-arrow-in-right"></i> Sign in
      </button>
    </form>

    <div class="text-center my-3">
      <span class="text-faint" style="font-size:.78rem">or</span>
    </div>

    <button class="btn btn-outline-secondary w-100" data-magic>
      <i class="bi bi-magic"></i> Email me a sign-in link
    </button>

    <hr class="fm-divider">

    <!-- Demo credentials, so the project is usable straight after import -->
    <div class="fm-demo">
      <div class="fw-semibold mb-1"><i class="bi bi-info-circle"></i> Demo accounts</div>
      <div class="fm-demo-row">
        <span>Admin</span>
        <span><code>aisha@flatmate.test</code> / <code>admin123</code></span>
      </div>
      <div class="fm-demo-row">
        <span>Residents</span>
        <span><code>rakib@flatmate.test</code> … / <code>password123</code></span>
      </div>
      <button class="btn btn-sm btn-link p-0 mt-1" data-fill-demo>
        Fill the form for me
      </button>
    </div>

    <p class="text-center text-faint mt-3 mb-0" style="font-size:.75rem">
      Got an invite? <a href="<?= e(base_url('join.php')) ?>">Set up your account</a>
    </p>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(asset('js/api.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<script>
  document.querySelector('[data-fill-demo]').addEventListener('click', function () {
    document.getElementById('email').value    = 'aisha@flatmate.test';
    document.getElementById('password').value = 'admin123';
  });

  document.querySelector('[data-magic]').addEventListener('click', function () {
    var email = document.getElementById('email').value.trim();
    if (!email) { App.toast('Enter your email first.', 'warn'); return; }

    App.guard(function () {
      return API.post('auth.magic_request', { email: email }).then(function () {
        App.toast('If that address is registered, a sign-in link is on its way.', 'ok', 5000);
      });
    });
  });
</script>
</body>
</html>