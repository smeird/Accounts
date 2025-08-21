<?php
class Totp {
    private static $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret($length = 32) {
        $alphabet = self::$alphabet;
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $secret;
    }


    public static function getOtpAuthUri($username, $secret) {
        return sprintf('otpauth://totp/%s?secret=%s',
            rawurlencode($username),
            $secret
        );
    }

    public static function verifyCode($secret, $code, $window = 2) {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $key = self::base32Decode($secret);
        $time = floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::calcTotp($key, $time + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    public static function getCode($secret) {
        $key = self::base32Decode($secret);
        return self::calcTotp($key, floor(time() / 30));
    }

    private static function calcTotp($key, $timeSlice) {
        $binaryTime = pack('N*', 0, $timeSlice);
        $hash = hash_hmac('sha1', $binaryTime, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
        return str_pad($value % 1000000, 6, '0', STR_PAD_LEFT);
    }

    private static function base32Decode($b32) {
        if ($b32 === '') {
            return '';
        }
        $alphabet = self::$alphabet;
        $b32 = strtoupper($b32);
        $b32 = preg_replace('/[^A-Z2-7=]/', '', $b32);
        $padding = substr_count($b32, '=');
        $b32 = str_replace('=', '', $b32);
        $binaryString = '';
        foreach (str_split($b32) as $char) {
            $index = strpos($alphabet, $char);
            if ($index !== false) {
                $binaryString .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
            }
        }
        if ($padding) {
            $binaryString = substr($binaryString, 0, -$padding * 5);
        }
        $bytes = str_split($binaryString, 8);
        $result = '';
        foreach ($bytes as $byte) {
            if (strlen($byte) === 8) {
                $result .= chr(bindec($byte));
            }
        }
        return $result;
    }
}
?>
