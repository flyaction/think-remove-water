<?php
namespace Flyaction\ThinkRemoveWater\Services;

use Flyaction\ThinkRemoveWater\Parsers\ParserFactory;

class WatermarkService
{
    public static function parse(string $url, ?int $apiKeyId = null): array
    {
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

}
