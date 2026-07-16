<?php
namespace Flyaction\ThinkRemoveWater\Services;

use Flyaction\ThinkRemoveWater\App;

class ParseCacheService
{
    /** 解析逻辑变更时递增，使旧缓存失效 */
    private const CACHE_VERSION = 4;
    private static function config(): array
    {
        static $cfg = null;
        if ($cfg === null) {
            $app = App::getAppConfig();
            $cfg = $app['parse_cache'] ?? ['enabled' => true, 'ttl' => 900];
        }
        return $cfg;
    }

    public static function isEnabled(): bool
    {
        return (bool) (self::config()['enabled'] ?? true);
    }

    public static function ttl(): int
    {
        return max(60, (int) (self::config()['ttl'] ?? 900));
    }

    public static function cacheKey(string $url): string
    {
        return hash('sha256', self::CACHE_VERSION . '|' . self::normalizeUrl($url));
    }

    /** @return array<string, mixed>|null */
    public static function get(string $url): ?array
    {
        if (!self::isEnabled()) {
            return null;
        }

        $file = self::cacheDir() . '/' . self::cacheKey($url) . '.json';
        if (!is_file($file)) {
            return null;
        }

        $payload = json_decode((string) file_get_contents($file), true);
        if (!is_array($payload) || empty($payload['result']) || !is_array($payload['result'])) {
            @unlink($file);
            return null;
        }
        if (($payload['expires'] ?? 0) < time()) {
            @unlink($file);
            return null;
        }

        return $payload['result'];
    }

    public static function set(string $url, array $result): void
    {
        if (!self::isEnabled() || !self::isCacheable($result)) {
            return;
        }

        self::maybeCleanup();

        $file = self::cacheDir() . '/' . self::cacheKey($url) . '.json';
        file_put_contents($file, json_encode([
            'url'     => self::normalizeUrl($url),
            'expires' => time() + self::ttl(),
            'result'  => $result,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private static function isCacheable(array $result): bool
    {
        $platform = $result['platform'] ?? '';
        if (in_array($platform, ['douyin', 'huoshan', 'xigua', 'toutiao', 'qishui'], true) && empty($result['video'])) {
            return false;
        }

        if (!empty($result['video']) && is_string($result['video'])) {
            return true;
        }
        foreach ($result['images'] ?? [] as $img) {
            if (is_string($img) && $img !== '') {
                return true;
            }
        }
        if (!empty($result['music']) && is_string($result['music'])) {
            return true;
        }
        return false;
    }

    private static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

        return $scheme . '://' . $host . $port . $path . $query;
    }

    private static function cacheDir(): string
    {
        $dir = dirname(__DIR__, 2) . '/runtime/parse_cache';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private static function maybeCleanup(): void
    {
        if (random_int(1, 100) > 2) {
            return;
        }

        $dir = self::cacheDir();
        $now = time();
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $payload = json_decode((string) @file_get_contents($file), true);
            if (!is_array($payload) || ($payload['expires'] ?? 0) < $now) {
                @unlink($file);
            }
        }
    }
}
