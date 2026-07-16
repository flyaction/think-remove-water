<?php
namespace Flyaction\ThinkRemoveWater\Core;

use App\Core\Database;

class Security
{
    private static ?bool $debug = null;

    public static function isDebug(): bool
    {
        if (self::$debug === null) {
            $config = require __DIR__ . '/../../config/app.php';
            self::$debug = !empty($config['debug']);
        }
        return self::$debug;
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        ]);
        session_start();
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        self::startSession();
        return is_string($token) && !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $token);
    }

    public static function clientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }
            $ip = trim(explode(',', (string) $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return '0.0.0.0';
    }

    /** 蜜罐字段应为空 */
    public static function checkHoneypot(?string $value): bool
    {
        return $value === null || $value === '';
    }

    public static function sanitizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        return preg_replace('/[\x00-\x1F\x7F]/', '', $email) ?? '';
    }

    public static function validateEmail(string $email): string
    {
        $email = self::sanitizeEmail($email);
        if ($email === '' || strlen($email) > 254) {
            throw new \RuntimeException('邮箱格式不正确');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('邮箱格式不正确');
        }
        return $email;
    }

    public static function sanitizeNickname(string $nickname): string
    {
        $nickname = trim($nickname);
        $nickname = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $nickname) ?? '';
        if ($nickname === '') {
            return '';
        }
        if (mb_strlen($nickname) > 32) {
            throw new \RuntimeException('昵称最多 32 个字符');
        }
        if (!preg_match('/^[\p{L}\p{N}\s_\-\.]+$/u', $nickname)) {
            throw new \RuntimeException('昵称仅支持中英文、数字及 _ - .');
        }
        return $nickname;
    }

    public static function validatePassword(string $password): ?string
    {
        if (strlen($password) > 128) {
            return '密码过长';
        }
        if (strlen($password) < 8) {
            return '密码至少 8 位';
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            return '密码需包含字母和数字';
        }
        return null;
    }

    public static function validateApiKey(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }
        $key = trim($key);
        if (strlen($key) > 128 || !preg_match('/^[a-zA-Z0-9_\-]+$/', $key)) {
            return null;
        }
        return $key;
    }

    /** 解析链接校验 + SSRF 防护 */
    public static function validateParseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048) {
            throw new \InvalidArgumentException('链接无效或过长');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
            throw new \InvalidArgumentException('链接包含非法字符');
        }

        $url = self::extractHttpUrl($url);
        if ($url === '') {
            throw new \InvalidArgumentException('链接格式不正确');
        }
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            throw new \InvalidArgumentException('链接格式不正确');
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('仅支持 http/https 链接');
        }

        $host = strtolower($parts['host']);
        if (self::isBlockedHost($host)) {
            throw new \InvalidArgumentException('不允许解析该链接');
        }

        foreach (self::resolveHostIps($host) as $ip) {
            if (self::isPrivateOrReservedIp($ip)) {
                throw new \InvalidArgumentException('不允许解析内网地址');
            }
        }

        return $url;
    }

    public static function whitelistAction(string $action, array $allowed): string
    {
        $action = preg_replace('/[^a-z_]/', '', strtolower(trim($action)));
        if ($action === '' || !in_array($action, $allowed, true)) {
            throw new \RuntimeException('未知操作');
        }
        return $action;
    }

    public static function limitJsonBodySize(int $maxBytes = 65536): void
    {
        $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($len > $maxBytes) {
            throw new \RuntimeException('请求体过大');
        }
    }

    public static function safeErrorMessage(\Throwable $e, string $fallback = '操作失败，请稍后重试'): string
    {
        if ($e instanceof \RuntimeException || $e instanceof \InvalidArgumentException) {
            return $e->getMessage();
        }
        return self::isDebug() ? $e->getMessage() : $fallback;
    }

    public static function escapeHtml(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function isBlockedHost(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPrivateOrReservedIp($host);
        }
        return false;
    }

    /** @return string[] */
    private static function resolveHostIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (!empty($record['ip'])) {
                        $ips[] = $record['ip'];
                    }
                    if (!empty($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        if (empty($ips)) {
            $resolved = @gethostbyname($host);
            if ($resolved && $resolved !== $host) {
                $ips[] = $resolved;
            }
        }

        return array_values(array_unique($ips));
    }

    private static function isPrivateOrReservedIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /** 从分享文案中提取 http(s) 链接 */
    private static function extractHttpUrl(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if (preg_match('/^https?:\/\//i', $text)) {
            $url = preg_split('/\s/u', $text, 2)[0] ?? $text;
            return rtrim($url, "，。！？,.!?");
        }
        if (preg_match('/https?:\/\/[^\s\x{4e00}-\x{9fff}"\'<>]+/iu', $text, $match)) {
            return rtrim($match[0], "，。！？,.!?");
        }
        return $text;
    }
}
