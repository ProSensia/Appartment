<?php
/**
 * Sign out.
 *
 * A POST-only endpoint: a GET link would let a third-party page log a resident
 * out with an <img> tag, so the nav uses a form rather than an anchor.
 */
declare(strict_types=1);

require_once __DIR__ . '/src/Bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('index.php');
}

// The nav signs out through a form, so the token is present; a bare POST is
// rejected rather than silently allowed.
require_csrf();

Auth::logout();
flash('ok', 'Signed out. See you soon.');
redirect('login.php');