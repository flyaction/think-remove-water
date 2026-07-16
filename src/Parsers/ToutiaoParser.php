<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class ToutiaoParser extends BaseParser
{
    protected array $domains = ['toutiao.com', 'toutiaocdn.com', '365yg.com', 'snssdk.com'];

    private const MOBILE_REFERER = 'https://m.toutiao.com/';

    public function getPlatform(): string
    {
        return 'toutiao';
    }

    public function parse(string $url): array
    {
        $originalUrl = trim($url);
        $resolvedUrl = $this->resolveUrl($originalUrl) ?: $originalUrl;
        $groupId = $this->extractGroupId($originalUrl) ?: $this->extractGroupId($resolvedUrl);

        if (!$groupId && preg_match('/[?&]url=([^&]+)/', $resolvedUrl, $m)) {
            $nested = urldecode($m[1]);
            $groupId = $this->extractGroupId($nested);
            if ($groupId) {
                $resolvedUrl = $nested;
            }
        }

        if (!$groupId) {
            throw new \RuntimeException('无法解析头条作品ID');
        }

        $fallback = null;

        foreach ($this->buildFetchPlan($groupId, $resolvedUrl) as $step) {
            $result = match ($step['type']) {
                'iesdouyin' => $this->parseViaIesDouyin($groupId),
                'mobile_info' => $this->parseViaMobileInfo($groupId, $step['version'] ?? ''),
                'html' => $this->parseFromHtml($step['url']),
                'snssdk' => $this->parseViaSnssdk($step['video_id'], $step['meta'] ?? []),
                default => null,
            };

            if (!$result) {
                continue;
            }

            if ($this->isAcceptableResult($result, $resolvedUrl)) {
                return $result;
            }

            if (!$fallback) {
                $fallback = $result;
            }
        }

        if ($fallback && !empty($fallback['cover'])) {
            throw new \RuntimeException('头条视频地址获取失败，请稍后重试或使用 App 最新分享链接');
        }

        throw new \RuntimeException('头条视频解析失败，请使用 App 最新分享链接');
    }

    private function buildFetchPlan(string $groupId, string $resolvedUrl): array
    {
        return [
            ['type' => 'mobile_info', 'version' => ''],
            ['type' => 'mobile_info', 'version' => 'v2'],
            ['type' => 'iesdouyin'],
            ['type' => 'html', 'url' => "https://m.toutiao.com/i{$groupId}/"],
            ['type' => 'html', 'url' => "https://m.toutiao.com/video/{$groupId}/"],
            ['type' => 'html', 'url' => $resolvedUrl],
            ['type' => 'html', 'url' => "https://www.toutiao.com/video/{$groupId}/"],
            ['type' => 'html', 'url' => "https://www.toutiao.com/a{$groupId}/"],
            ['type' => 'html', 'url' => "https://www.toutiao.com/article/{$groupId}/"],
        ];
    }

    private function isAcceptableResult(array $result, string $resolvedUrl): bool
    {
        if (!empty($result['video'])) {
            return true;
        }

        $images = $result['images'] ?? [];
        if (empty($images)) {
            return false;
        }

        if ($this->looksLikeVideoUrl($resolvedUrl)) {
            return false;
        }

        return ($result['type'] ?? '') === 'image';
    }

    private function looksLikeVideoUrl(string $url): bool
    {
        return (bool) preg_match('#/(?:video|group|a\d|i\d{10,})#i', $url);
    }

    private function extractGroupId(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $patterns = [
            '#/(?:video|article|a)/(\d{10,})#i',
            '#/(?:group|item)/(\d{10,})#i',
            '#/i(\d{10,})#i',
            '#[?&](?:group_id|item_id|aweme_id|gid)=(\d{10,})#i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    private function parseViaIesDouyin(string $groupId): ?array
    {
        $shareUrl = 'https://www.iesdouyin.com/share/video/' . $groupId . '/';
        $html = HttpClient::get($shareUrl, $this->mobileHeaders('https://www.toutiao.com/'));
        if (!$html) {
            return null;
        }

        $item = $this->parseByteShareHtml($html);
        if ($item) {
            $result = $this->buildFromAwemeItem($item);
            return $this->ensureCover($result, $html);
        }

        return $this->parseFromHtml($html);
    }

    private function parseViaMobileInfo(string $groupId, string $version = ''): ?array
    {
        $suffix = $version === 'v2' ? 'info/v2/' : 'info/';
        $apiUrl = "https://m.toutiao.com/i{$groupId}/{$suffix}";

        $response = HttpClient::get($apiUrl, array_merge($this->mobileHeaders(self::MOBILE_REFERER), [
            'Accept: application/json, text/plain, */*',
            'X-Requested-With: XMLHttpRequest',
        ]));

        if (!$response) {
            return null;
        }

        $json = json_decode($response, true);
        if (!is_array($json) || empty($json['data']) || !is_array($json['data'])) {
            return null;
        }

        return $this->buildFromToutiaoPayload($json['data']);
    }

    private function parseFromHtml(string $url): ?array
    {
        $html = HttpClient::get($url, $this->mobileHeaders(self::MOBILE_REFERER));
        if (!$html || $this->isAntiBotPage($html)) {
            return null;
        }

        $item = $this->parseByteShareHtml($html);
        if ($item) {
            $result = $this->buildFromAwemeItem($item);
            return $this->ensureCover($result, $html);
        }

        if (preg_match('/window\.__INITIAL_STATE__\s*=\s*(\{[\s\S]*?\});/s', $html, $m)) {
            $data = json_decode($m[1], true);
            if (is_array($data)) {
                $result = $this->buildFromToutiaoPayload($data);
                if ($result) {
                    return $result;
                }
            }
        }

        $playToken = $this->extractPlayAuthToken($html);
        if ($playToken) {
            $meta = $this->extractOgMeta($html);
            $vod = $this->parseViaVodPlayInfo($playToken, [
                'title' => $meta['title'] ?? '',
                'cover' => $meta['cover'] ?? '',
            ]);
            if ($vod) {
                return $vod;
            }
        }

        $videoId = $this->extractVideoId($html);
        if ($videoId) {
            $meta = [
                'title' => $this->extractOgMeta($html)['title'] ?? '',
                'cover' => $this->extractOgMeta($html)['cover'] ?? '',
            ];
            return $this->parseViaSnssdk($videoId, $meta);
        }

        $og = $this->resultFromOg($html);
        return $og ?: null;
    }

    private function parseViaVodPlayInfo(string $tokenRaw, array $meta = []): ?array
    {
        $tokenRaw = trim($tokenRaw);
        if ($tokenRaw === '') {
            return null;
        }

        $decoded = json_decode(base64_decode($tokenRaw, true) ?: '', true);
        if (!is_array($decoded) || empty($decoded['GetPlayInfoToken']) || !is_string($decoded['GetPlayInfoToken'])) {
            return null;
        }

        $apiUrl = 'https://vod.bytedanceapi.com/?' . $decoded['GetPlayInfoToken'];
        $response = HttpClient::get($apiUrl, array_merge($this->mobileHeaders(self::MOBILE_REFERER), [
            'Accept: application/json, text/plain, */*',
        ]));

        if (!$response) {
            return null;
        }

        $json = json_decode($response, true);
        if (!is_array($json)) {
            return null;
        }

        $playList = $json['Result']['Data']['PlayInfoList'] ?? null;
        if (!is_array($playList)) {
            return null;
        }

        $videoUrl = $this->pickVodPlayUrl($playList);
        if (!$videoUrl) {
            return null;
        }

        $cover = $meta['cover'] ?? '';
        if ($cover === '' && !empty($json['Result']['Data']['CoverUrl'])) {
            $cover = (string) $json['Result']['Data']['CoverUrl'];
        }

        $title = trim($meta['title'] ?? $json['Result']['Data']['Title'] ?? '');

        return $this->buildResult([
            'title'        => $title,
            'cover'        => $cover,
            'video'        => $videoUrl,
            'type'         => 'video',
            'video_direct' => true,
        ]);
    }

    private function pickVodPlayUrl(array $playList): ?string
    {
        $candidates = [];

        foreach ($playList as $item) {
            if (!is_array($item)) {
                continue;
            }

            foreach (['MainPlayUrl', 'BackupPlayUrl', 'PlayUrl'] as $key) {
                if (empty($item[$key]) || !is_string($item[$key])) {
                    continue;
                }
                $url = $this->normalizeMediaUrl($item[$key]);
                if (!str_starts_with($url, 'http')) {
                    continue;
                }

                $score = (int) ($item['Height'] ?? 0);
                if (str_contains((string) ($item['Definition'] ?? ''), '720')) {
                    $score += 200;
                } elseif (str_contains((string) ($item['Definition'] ?? ''), '480')) {
                    $score += 100;
                }
                if (($item['Format'] ?? '') === 'mp4') {
                    $score += 20;
                }
                $score += (int) (($item['Bitrate'] ?? 0) / 10000);

                $candidates[] = ['score' => $score, 'url' => $url];
            }
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        return $candidates[0]['url'];
    }

    private function parseViaSnssdk(string $videoId, array $meta = []): ?array
    {
        $videoId = trim($videoId);
        if ($videoId === '') {
            return null;
        }

        $random = (string) random_int(1000000000000000, 9999999999999999);
        $path = '/video/urls/v/1/toutiao/mp4/' . $videoId . '?r=' . $random;
        $signature = $this->crc32Unsigned($path);

        foreach ([
            'https://ib.365yg.com' . $path . '&s=' . $signature,
            'https://i.snssdk.com' . $path . '&s=' . $signature,
        ] as $apiUrl) {
            $response = HttpClient::get($apiUrl, $this->mobileHeaders(self::MOBILE_REFERER));
            if (!$response) {
                continue;
            }

            $json = json_decode($response, true);
            if (!is_array($json)) {
                continue;
            }

            if (($json['data']['message'] ?? '') === 'video_play acl deny') {
                continue;
            }

            $videoUrl = $this->pickSnssdkVideoUrl($json);
            if (!$videoUrl) {
                continue;
            }

            $title = trim($meta['title'] ?? $json['data']['title'] ?? $json['data']['abstract'] ?? '');
            $cover = $meta['cover'] ?? $json['data']['poster_url'] ?? $json['data']['cover_url'] ?? '';

            return $this->buildResult([
                'title'        => $title,
                'cover'        => is_string($cover) ? $cover : '',
                'video'        => $videoUrl,
                'type'         => 'video',
                'video_direct' => true,
            ]);
        }

        return null;
    }

    private function buildFromToutiaoPayload(mixed $payload, int $depth = 0): ?array
    {
        if (!is_array($payload) || $depth > 2) {
            return null;
        }

        $title = trim((string) ($payload['title'] ?? $payload['abstract'] ?? $payload['desc'] ?? ''));
        $author = trim((string) ($payload['source'] ?? $payload['media_name'] ?? $payload['media_user']['screen_name'] ?? ''));
        $cover = $this->extractMediaUrl($payload['poster_url'] ?? null)
            ?? $this->extractMediaUrl($payload['middle_image']['url'] ?? null)
            ?? '';
        $video = null;
        $images = [];
        $isVideoArticle = !empty($payload['video_id'])
            || !empty($payload['video_duration'])
            || !empty($payload['play_auth_token_v2'])
            || str_contains((string) ($payload['content'] ?? ''), 'tt-video-box');

        $meta = ['title' => $title, 'cover' => $cover, 'author' => $author];

        $playToken = $payload['play_auth_token_v2'] ?? $this->extractPlayAuthToken((string) ($payload['content'] ?? ''));
        if (is_string($playToken) && $playToken !== '') {
            $vod = $this->parseViaVodPlayInfo($playToken, $meta);
            if ($vod) {
                if ($author !== '') {
                    $vod['author'] = $author;
                }
                return $vod;
            }
        }

        $videoInfo = $payload['video_play_info']
            ?? $payload['video_detail']
            ?? $payload['video_info']
            ?? $payload['video']
            ?? null;

        if (is_array($videoInfo)) {
            $video = $this->extractMediaUrl($videoInfo['video_url'] ?? null)
                ?? $this->extractMediaUrl($videoInfo['main_url'] ?? null)
                ?? $this->extractMediaUrl($videoInfo['play_url'] ?? null)
                ?? $this->extractMediaUrl($videoInfo['download_addr'] ?? null);

            if (!$cover) {
                $cover = $this->extractMediaUrl($videoInfo['cover_url'] ?? null)
                    ?? $this->extractMediaUrl($videoInfo['poster_url'] ?? null)
                    ?? $this->extractMediaUrl($videoInfo['origin_cover'] ?? null)
                    ?? '';
            }
        }

        if (!$video && !empty($payload['video_id']) && is_string($payload['video_id'])) {
            $snssdk = $this->parseViaSnssdk($payload['video_id'], $meta);
            if ($snssdk) {
                if ($author !== '') {
                    $snssdk['author'] = $author;
                }
                return $snssdk;
            }
        }

        if (!$video) {
            $videoList = $payload['video_list'] ?? $payload['videoList'] ?? null;
            if (is_array($videoList)) {
                foreach ($videoList as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $url = $this->extractMediaUrl($item['main_url'] ?? null)
                        ?? $this->extractMediaUrl($item['video_url'] ?? null)
                        ?? $this->extractMediaUrl($item['play_url'] ?? null);
                    if ($url) {
                        $video = $url;
                        break;
                    }
                    if (!empty($item['main_url']) && is_string($item['main_url'])) {
                        $decoded = base64_decode($item['main_url'], true);
                        if ($decoded && str_starts_with($decoded, 'http')) {
                            $video = $decoded;
                            break;
                        }
                    }
                }
            }
        }

        foreach ($payload['image_list'] ?? [] as $image) {
            $url = $this->extractMediaUrl(is_array($image) ? ($image['url'] ?? null) : $image);
            if ($url) {
                $images[] = $url;
            }
        }

        if (!$video && empty($images)) {
            $nested = $this->findNestedValue($payload, 'video_list')
                ?? $this->findNestedValue($payload, 'video_info')
                ?? $this->findNestedValue($payload, 'video_play_info');

            if (is_array($nested)) {
                return $this->buildFromToutiaoPayload(array_merge($payload, $nested), $depth + 1);
            }

            return null;
        }

        if (!$video && $isVideoArticle) {
            return null;
        }

        return $this->buildResult([
            'title'        => $title,
            'author'       => $author,
            'cover'        => $cover ?: ($images[0] ?? ''),
            'video'        => $video,
            'images'       => $images,
            'type'         => $video ? 'video' : 'image',
            'video_direct' => true,
        ]);
    }

    private function extractPlayAuthToken(string $html): ?string
    {
        if ($html === '') {
            return null;
        }

        $patterns = [
            '/data-token=[\'"]([^\'"]+)[\'"]/',
            '/"play_auth_token_v2"\s*:\s*"([^"]+)"/',
            '/play_auth_token_v2[\'"]?\s*[:=]\s*[\'"]([^\'"]+)[\'"]/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            }
        }

        return null;
    }

    private function pickSnssdkVideoUrl(array $json): ?string
    {
        $videoList = $json['data']['video_list'] ?? null;
        if (!is_array($videoList)) {
            return null;
        }

        $candidates = [];
        foreach ($videoList as $key => $item) {
            if (!is_array($item) || empty($item['main_url']) || !is_string($item['main_url'])) {
                continue;
            }

            $decoded = base64_decode($item['main_url'], true);
            if (!$decoded || !str_starts_with($decoded, 'http')) {
                continue;
            }

            $score = 0;
            $definition = (string) ($item['definition'] ?? $key);
            if (str_contains($definition, '720')) {
                $score += 30;
            } elseif (str_contains($definition, '480')) {
                $score += 20;
            } elseif (str_contains($definition, '360')) {
                $score += 10;
            }
            if (str_contains($decoded, '.mp4')) {
                $score += 5;
            }

            $candidates[] = ['score' => $score, 'url' => $decoded];
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        return $this->normalizeMediaUrl($candidates[0]['url']);
    }

    private function extractVideoId(string $html): ?string
    {
        $patterns = [
            '/tt-videoid=[\'"]([^\'"]+)[\'"]/',
            '/videoId\s*:\s*[\'"]([^\'"]+)[\'"]/',
            '/"video_id"\s*:\s*"([^"]+)"/',
            '/"vid"\s*:\s*"([a-z0-9]{16,32})"/i',
            '/"uri"\s*:\s*"(v[^"]+)"/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    private function isAntiBotPage(string $html): bool
    {
        return str_contains($html, 'byted_acrawler')
            && !str_contains($html, '_ROUTER_DATA')
            && !str_contains($html, 'RENDER_DATA')
            && !str_contains($html, 'videoId')
            && !str_contains($html, 'tt-videoid');
    }

    private function crc32Unsigned(string $data): int
    {
        $crc = crc32($data);
        if ($crc < 0) {
            $crc += 0x100000000;
        }

        return $crc;
    }
}
