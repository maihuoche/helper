<?php


namespace Maihuoche;

use Exception;


class Token
{
    /**
     * AES 加密方法
     */
    private static string $method = 'AES-256-CBC';

    /**
     * 获取密钥
     */
    private static function getKey(): string
    {
        // 这里可以通过查询配置获取 Key，也可以直接定义Key
        return 'p0lo9x2h3m1!a@j$*b^f%lzd4~s@!lxy';
    }

    /**
     * 生成 token
     * @param array $data 用户数据
     * @param int $expire 过期时间（秒）
     */
    public static function create(array $data, int $expire = 86400*7): string
    {
        $payload = [
            'data' => $data,
            'expire' => time() + $expire
        ];

        return self::encrypt($payload);
    }

    /**
     * 验证并解析 token
     * @throws Exception 当 token 无效或过期时抛出异常
     */
    public static function verify(string $token): array
    {
        try {
            $payload = self::decrypt($token);

            // 验证格式
            if (!isset($payload['data']) || !isset($payload['expire'])) {
                throw new Exception('Invalid token format');
            }

            // 验证是否过期
            if (time() > $payload['expire']) {
                throw new Exception('Token has expired');
            }

            return $payload['data'];
        } catch (Exception $e) {
            throw new Exception('Invalid token: ' . $e->getMessage());
        }
    }

    /**
     * AES 加密
     */
    public static function encrypt(array $data): string
    {
        // 生成随机 IV
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(self::$method));

        // 数据序列化
        $value = json_encode($data);

        // 加密
        $encrypted = openssl_encrypt(
            $value,
            self::$method,
            self::getKey(),
            OPENSSL_RAW_DATA,
            $iv
        );

        // 组合 IV 和加密数据，并进行 base64 编码
        return base64_encode($iv . $encrypted);
    }

    /**
     * AES 解密
     * @throws Exception
     */
    public static function decrypt(string $token): array
    {
        // base64 解码
        $decoded = base64_decode($token);

        // 提取 IV
        $ivLength = openssl_cipher_iv_length(self::$method);
        $iv = substr($decoded, 0, $ivLength);
        $encrypted = substr($decoded, $ivLength);

        // 解密
        $decrypted = openssl_decrypt(
            $encrypted,
            self::$method,
            self::getKey(),
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($decrypted === false) {
            throw new Exception('Decryption failed');
        }

        // JSON 解码
        $data = json_decode($decrypted, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON data');
        }

        return $data;
    }

    /**
     * 获取剩余有效期（秒）
     */
    public static function ttl(string $token): int
    {
        try {
            $payload = self::decrypt($token);
            return max(0, $payload['expire'] - time());
        } catch (Exception $e) {
            // print_r($e->getMessage());
            return 0;
        }
    }

    /**
     * 刷新 token
     */
    public static function refresh(string $token, int $expire = 86400*7): string
    {
        try {
            $payload = self::decrypt($token);

            return self::create($payload['data'], $expire);

        } catch (Exception $e) {
            return '';
        }
    }

}