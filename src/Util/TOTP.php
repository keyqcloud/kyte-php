<?php

namespace Kyte\Util;

class TOTP {
    const EPOCH = 0;
    const TIME_STEP = 30; // 30 seconds time step

    public static function generateCode($secret, $timeSlice = null) {
        if ($timeSlice === null) {
            $timeSlice = floor((time() - self::EPOCH) / self::TIME_STEP);
        }

        $secretKey = self::base32Decode($secret);
        $time = chr(0) . chr(0) . chr(0) . chr(0) . pack('N*', $timeSlice); // Time packed as a 64-bit integer (binary)
        $hash = hash_hmac('sha1', $time, $secretKey, true);

        $offset = ord($hash[19]) & 0xF;
        $binary = (
            (ord($hash[$offset + 0]) & 0x7F) << 24 |
            (ord($hash[$offset + 1]) & 0xFF) << 16 |
            (ord($hash[$offset + 2]) & 0xFF) << 8 |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        $otp = $binary % pow(10, 6); // 6-digit code
        return str_pad($otp, 6, '0', STR_PAD_LEFT);
    }

    private static function base32Decode($b32) {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $d = '';
        $b32 = strtoupper($b32);

        for ($i = 0; $i < strlen($b32); $i += 8) {
            $chunk = substr($b32, $i, 8);
            $x = '';
            for ($j = 0; $j < strlen($chunk); $j++) {
                $x .= str_pad(base_convert(strrpos($alphabet, $chunk[$j]), 10, 2), 5, '0', STR_PAD_LEFT);
            }
            $eightBits = str_split($x, 8);
            for ($z = 0; $z < count($eightBits); $z++) {
                $d .= (($y = chr(base_convert($eightBits[$z], 2, 10))) || ord($y) == 48) ? $y : "";
            }
        }
        return $d;
    }

    public static function verifyCode($secret, $userCode) {
        $timeSlice = floor((time() - self::EPOCH) / self::TIME_STEP);
        // Check the code against the current, previous, and future slices to account for possible time drift
        for ($i = -1; $i <= 1; $i++) {
            if (self::generateCode($secret, $timeSlice + $i) === $userCode) {
                return true;
            }
        }
        return false;
    }

    public static function generateSecret() {
        return base64_encode(random_bytes(10));
    }

    public static function generateQRCode($issuer, $userEmail, $secret) {
        $googleUrl = 'otpauth://totp/'.urlencode($issuer.':'.$userEmail).'?secret='.$secret.'&issuer='.urlencode($issuer);
        $qrCodeUrl = 'https://chart.googleapis.com/chart?chs=200x200&chld=M|0&cht=qr&chl='.urlencode($googleUrl);
        return $qrCodeUrl;
    }
}
