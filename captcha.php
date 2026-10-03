<?php
declare(strict_types=1);
require_once __DIR__ . '/src/Bootstrap.php';
header('Content-Type: image/png');
header('Cache-Control: no-cache, no-store, must-revalidate');
echo Captcha::getImageData();
exit;
