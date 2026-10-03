<?php
declare(strict_types=1);

final class Captcha
{
    public static function generate(): array
    {
        $length = 5;
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $_SESSION['captcha_code'] = $code;
        return ['code' => $code];
    }

    public static function getImageData(): string
    {
        if (empty($_SESSION['captcha_code'])) {
            self::generate();
        }
        $code = $_SESSION['captcha_code'];
        $width = 120;
        $height = 40;
        $img = imagecreatetruecolor($width, $height);
        $bg = imagecolorallocate($img, 255, 255, 255);
        imagefill($img, 0, 0, $bg);
        $textColor = imagecolorallocate($img, 0, 0, 0);
        $lineColor = imagecolorallocate($img, 200, 200, 200);
        for ($i = 0; $i < 5; $i++) {
            imageline($img, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $lineColor);
        }
        imagestring($img, 5, 20, 12, $code, $textColor);
        ob_start();
        imagepng($img);
        $data = ob_get_clean();
        imagedestroy($img);
        return $data;
    }

    public static function verify(?string $input): bool
    {
        if (empty($_SESSION['captcha_code'])) {
            return false;
        }
        $ok = strtolower((string)$input) === strtolower((string)$_SESSION['captcha_code']);
        unset($_SESSION['captcha_code']);
        return $ok;
    }
}
