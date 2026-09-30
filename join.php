<?php
/**
 * Invite acceptance: a new resident sets their name and password from the
 * token in their invite link, then signs in.
 */
declare(strict_types=1);

require_once __DIR__ . '/src/Bootstrap.php';

$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$error = null;

// Look the invite up so we can show who is joining.
$invite = $token !== '' ? ResidentService::inviteRowByToken($token) : [];

if ($token === '' || $invite === []) {
    http_response_code(400);
    $pageTitle = 'Invite not found';
} else {
    $pageTitle = 'Join ' . (string) ($invite['apartment_name'] ?? 'the flat');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $invite !== []) {
    require_csrf();

    try {
        $user = ResidentService::acceptInvite(
            $token,
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['full_name'] ?? '')
        );
        // Sign them straight in.
        Auth::login($user, true);
        flash('success', 'Welcome to the flat, ' . explode(' ', (string) $user['full_name'])[0] . '!');
        redirect('index.php');
    } catch (ValidationException $e) {
        $error = implode(' ', array_values($e->errors));
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> &middot; <?= e(config('app.name', 'FlatMate')) ?></title>
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
      <h1 class="h5 mt-2 mb-0"><?= e($pageTitle) ?></h1>
      <?php if ($invite !== []): ?>
        <p class="text-muted-2 mb-0" style="font-size:.85rem">
          <?= e($invite['apartment_name'] ?? '') ?>
          <?php if (!empty($invite['room_code'])): ?>
            &middot; Room <?= e($invite['room_code']) ?>
          <?php endif; ?>
          <?php if (!empty($invite['group_name'])): ?>
            &middot; <?= e($invite['group_name']) ?>
          <?php endif; ?>
        </p>
      <?php endif; ?>
    </div>

    <?php if ($invite === []): ?>
      <div class="alert alert-warning d-flex gap-2 align-items-start">
        <i class="bi bi-exclamation-triangle-fill mt-1"></i>
        <div>
          <strong>This invite link is not valid.</strong>
          <div style="font-size:.85rem">
            It may have expired or already been used. Ask the apartment admin to send a fresh one.
          </div>
        </div>
      </div>
      <a class="btn btn-primary w-100" href="<?= e(base_url('login.php')) ?>">Go to sign in</a>

    <?php else: ?>
      <?php if (!empty($invite['is_expired'])): ?>
        <div class="alert alert-warning py-2" style="font-size:.85rem">
          This invite has expired. Ask the admin for a new link.
        </div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="alert alert-danger py-2 d-flex gap-2 align-items-center">
          <i class="bi bi-exclamation-octagon-fill"></i><span><?= e($error) ?></span>
        </div>
      <?php endif; ?>

      <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">

        <div class="mb-3">
          <label class="form-label" for="full_name">Your name</label>
          <input type="text" class="form-control" id="full_name" name="full_name"
                 value="<?= e($_POST['full_name'] ?? '') ?>" required autocomplete="name"
                 placeholder="e.g. Nusrat Jahan">
        </div>

        <div class="mb-3">
          <label class="form-label" for="password">Choose a password</label>
          <input type="password" class="form-control" id="password" name="password"
                 required autocomplete="new-password" minlength="8" placeholder="At least 8 characters">
          <div class="form-hint">Use 8+ characters. Avoid names or room numbers.</div>
        </div>

        <button class="btn btn-primary w-100" type="submit">
          <i class="bi bi-person-check"></i> Join the flat
        </button>
      </form>

      <p class="text-center text-faint mt-3 mb-0" style="font-size:.75rem">
        Already set up? <a href="<?= e(base_url('login.php')) ?>">Sign in instead</a>
      </p>
    <?php endif; ?>

  </div>
</div>

<script src="<?= e(asset('js/api.js')) ?>"></script>
</body>
</html>