<?php
namespace Flyaction\ThinkRemoveWater\Services;

use Flyaction\ThinkRemoveWater\Core\Database;
use Flyaction\ThinkRemoveWater\Parsers\ParserFactory;

class WatermarkService
{
    public static function parse(string $url, ?int $apiKeyId = null): array
    {
        $startTime = microtime(true);
        $platform = 'unknown';
        $status = 'failed';
        $error = null;
        $result = null;
        $fromCache = false;

        try {
            $url = \Flyaction\ThinkRemoveWater\Core\Security::validateParseUrl($url);
            $parser = ParserFactory::getParser($url);
            if (!$parser) {
                throw new \RuntimeException('不支持的平台，请检查链接是否正确');
            }

            $platform = $parser->getPlatform();

            $cached = ParseCacheService::get($url);
            if ($cached !== null) {
                $result = $cached;
                $fromCache = true;
            } else {
                $result = $parser->parse($url);
                ParseCacheService::set($url, $result);
            }
            $status = 'success';

        } catch (\Exception $e) {
            $error = $e->getMessage();
            throw $e;
        } finally {
            $responseTime = (int) ((microtime(true) - $startTime) * 1000);
            self::logRequest($apiKeyId, $platform, $url, $status, $error, $responseTime);
        }

        return self::formatResult($result, $platform, $fromCache);
    }

    private static function formatResult(array $result, string $platformCode = 'unknown', bool $fromCache = false): array
    {
        $caption = trim($result['caption'] ?? $result['title'] ?? $result['desc'] ?? '');
        $cover   = $result['cover'] ?? '';
        if (is_array($cover)) {
            $cover = '';
        }
        if ($cover !== '' && str_starts_with($cover, '//')) {
            $cover = 'https:' . $cover;
        }
        if (empty($cover) && !empty($result['images'][0])) {
            $cover = $result['images'][0];
        }

        $video = $result['video'] ?? null;
        if (is_string($video) && str_starts_with($video, '//')) {
            $video = 'https:' . $video;
        }

        $images = [];
        foreach ($result['images'] ?? [] as $img) {
            if (is_string($img) && $img !== '') {
                $images[] = str_starts_with($img, '//') ? 'https:' . $img : $img;
            }
        }
        $images = array_values(array_unique($images));

        $formatted = [
            'platform'     => $result['platform'] ?? $platformCode,
            'video'        => $video,
            'cover'        => $cover,
            'caption'      => $caption,
            'author'       => $result['author'] ?? '',
            'type'         => $result['type'] ?? 'video',
            'images'       => $images,
            'music'        => $result['music'] ?? null,
            'title'        => $caption,
            'video_direct' => (bool) ($result['video_direct'] ?? $result['extra']['video_direct'] ?? false),
            'from_cache'   => $fromCache,
        ];

        return self::attachMediaTokens($formatted);
    }

    private static function attachMediaTokens(array $data): array
    {
        $platform = $data['platform'] ?: 'douyin';
        $items = [];

        if (!empty($data['video']) && is_string($data['video'])) {
            $items['video'] = $data['video'];
        }
        if (!empty($data['cover']) && is_string($data['cover'])) {
            $items['cover'] = $data['cover'];
        }
        foreach ($data['images'] ?? [] as $i => $img) {
            if (is_string($img) && $img !== '') {
                $items['img' . $i] = $img;
            }
        }
        if (!empty($data['music']) && is_string($data['music'])) {
            $items['music'] = $data['music'];
        }

        if (empty($items)) {
            return $data;
        }

        // 白名单平台：封面/图片下载可走 CDN 直链
        if (MediaProxyService::isDirectDownloadPlatform($platform)) {
            $data['download_direct'] = true;
            $data['media_urls'] = $items;
        }

        $tokens = [];
        foreach ($items as $id => $url) {
            try {
                $tokens[$id] = MediaProxyService::register($url, $platform);
            } catch (\Throwable $e) {
                if ($id === 'video') {
                    $data['video_register_error'] = $e->getMessage();
                }
            }
        }
        if (!empty($tokens)) {
            $data['media_tokens'] = $tokens;

            $baseUrl = self::requestBaseUrl();
            $mediaDownload = [];
            foreach ($tokens as $id => $token) {
                if ($id === 'video' || $id === 'music') {
                    $mediaDownload[$id] = MediaProxyService::buildDownloadJumpUrl($token, $baseUrl);
                }
            }
            if (!empty($mediaDownload)) {
                $data['media_download'] = $mediaDownload;
            }
        }

        return $data;
    }

    private static function requestBaseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }

    private static function logRequest(
        ?int $apiKeyId,
        string $platform,
        string $url,
        string $status,
        ?string $error,
        int $responseTime
    ): void {
        try {
            $db = Database::getInstance();
            $userId = null;
            if ($apiKeyId) {
                $stmt = $db->prepare('SELECT user_id FROM api_keys WHERE id = ?');
                $stmt->execute([$apiKeyId]);
                $userId = $stmt->fetchColumn() ?: null;
            }

            $stmt = $db->prepare(
                'INSERT INTO request_logs (api_key_id, user_id, platform, source_url, ip_address, user_agent, status, error_message, response_time)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $apiKeyId,
                $userId,
                $platform,
                $url,
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                $status,
                $error,
                $responseTime,
            ]);

            if ($apiKeyId && $status === 'success') {
                $db->prepare('UPDATE api_keys SET total_requests = total_requests + 1 WHERE id = ?')
                   ->execute([$apiKeyId]);
            }
        } catch (\Exception $e) {
            // 日志记录失败不影响主流程
        }
    }

    public static function getPlatforms(): array
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->query('SELECT code, name, icon, status FROM platforms WHERE status = 1 ORDER BY sort_order');
            $rows = $stmt->fetchAll();
            if (!empty($rows)) {
                return $rows;
            }
        } catch (\Exception $e) {
            // fallback
        }
        return ParserFactory::getPlatformMeta();
    }

    public static function getStats(?int $apiKeyId = null): array
    {
        $db = Database::getInstance();

        if ($apiKeyId) {
            $stmt = $db->prepare(
                'SELECT COUNT(*) as total,
                        SUM(status = "success") as success,
                        SUM(status = "failed") as failed
                 FROM request_logs WHERE api_key_id = ?'
            );
            $stmt->execute([$apiKeyId]);
        } else {
            $stmt = $db->query(
                'SELECT COUNT(*) as total,
                        SUM(status = "success") as success,
                        SUM(status = "failed") as failed
                 FROM request_logs'
            );
        }

        return $stmt->fetch() ?: ['total' => 0, 'success' => 0, 'failed' => 0];
    }
}
