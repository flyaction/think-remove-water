<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class WeiboParser extends BaseParser
{
    protected array $domains = ['weibo.com', 'weibo.cn'];

    public function getPlatform(): string
    {
        return 'weibo';
    }

    public function parse(string $url): array
    {
        $originalUrl = trim($url);
        $statusId = $this->extractStatusId($originalUrl);

        if (!$statusId) {
            $final = HttpClient::getFinalUrl($originalUrl) ?: $originalUrl;
            $statusId = $this->extractStatusId($final);
            if (!$statusId && preg_match('/[?&]url=([^&]+)/', $final, $m)) {
                $statusId = $this->extractStatusId(urldecode($m[1]));
            }
        }

        if (!$statusId) {
            throw new \RuntimeException('无法解析微博ID');
        }

        if (str_contains($statusId, ':')) {
            $videoResult = $this->parseH5Video($statusId);
            if ($videoResult) {
                return $videoResult;
            }
        }

        $result = $this->fetchMobileStatus($statusId);
        if ($result) {
            return $result;
        }

        throw new \RuntimeException('无法解析微博内容');
    }

    private function extractStatusId(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $parsed = parse_url($url);
        $path = trim($parsed['path'] ?? '', '/');
        $query = $parsed['query'] ?? '';
        parse_str($query, $params);

        if (!empty($params['fid'])) {
            return urldecode((string) $params['fid']);
        }

        if (preg_match('#(?:status|detail)/([^/?#]+)#i', $path, $m)) {
            return $m[1];
        }

        if (preg_match('#(?:tv/show|show)/([^/?#]+)#i', $path, $m)) {
            return urldecode($m[1]);
        }

        if (preg_match('#^\d+/([^/?#]+)$#', $path, $m)) {
            return $m[1];
        }

        $segments = array_values(array_filter(explode('/', $path)));
        $last = end($segments);
        if (is_string($last) && preg_match('/^[A-Za-z0-9:_-]+$/', $last)) {
            return urldecode($last);
        }

        return null;
    }

    private function fetchMobileStatus(string $statusId): ?array
    {
        $headers = [
            'User-Agent: ' . self::MOBILE_UA,
            'Accept: application/json, text/plain, */*',
            'Referer: https://m.weibo.cn/detail/' . rawurlencode($statusId),
            'X-Requested-With: XMLHttpRequest',
        ];

        $response = HttpClient::get(
            'https://m.weibo.cn/statuses/show?id=' . rawurlencode($statusId),
            $headers
        );

        if (!$response) {
            return null;
        }

        $json = json_decode($response, true);
        if (!$json || (int) ($json['ok'] ?? 0) !== 1 || empty($json['data']) || !is_array($json['data'])) {
            return null;
        }

        return $this->buildFromStatusData($json['data']);
    }

    private function buildFromStatusData(array $status): array
    {
        $caption = $this->cleanWeiboText($status['text'] ?? $status['status_title'] ?? '');
        $author = $status['user']['screen_name'] ?? '';

        $images = [];

        foreach ($status['pic_ids'] ?? [] as $picId) {
            if (!is_string($picId) || $picId === '') {
                continue;
            }
            //$images[] = 'https://wx1.sinaimg.cn/large/' . $picId . '.jpg';
            $images[] = 'https://wx1.sinaimg.cn/osj1080/' . $picId . '.jpg';
        }

        foreach ($status['pics'] ?? [] as $pic) {
            $url = $this->extractMediaUrl($pic['large'] ?? null)
                ?? $this->extractMediaUrl($pic['url'] ?? null);
            if ($url) {
                $images[] = $url;
            }
        }

        $images = $this->uniqueImageUrls($images);

        $pageInfo = $status['page_info'] ?? null;
        $video = null;
        $cover = $images[0] ?? '';

        if (is_array($pageInfo) && ($pageInfo['type'] ?? '') === 'video') {
            $video = $this->pickBestWeiboVideo(
                is_array($pageInfo['media_info'] ?? null) ? $pageInfo['media_info'] : [],
                is_array($pageInfo['urls'] ?? null) ? $pageInfo['urls'] : []
            );
            $cover = $this->extractMediaUrl($pageInfo['page_pic'] ?? null) ?: $cover;
        }

        if (!$video && !$cover && !empty($pageInfo['page_pic']['url'])) {
            $cover = $this->normalizeMediaUrl($pageInfo['page_pic']['url']);
        }

        return $this->buildResult([
            'title'        => $caption,
            'author'       => $author,
            'cover'        => $cover,
            'type'         => $video ? 'video' : 'image',
            'video'        => $video,
            'images'       => $images,
            'video_direct' => true,
        ]);
    }

    private function parseH5Video(string $fid): ?array
    {
        $page = '/show/' . $fid;
        $payload = json_encode(['Component_Play_Playinfo' => ['oid' => $fid]], JSON_UNESCAPED_UNICODE);
        $apiUrl = 'https://h5.video.weibo.com/api/component?page=' . rawurlencode($page);

        $response = HttpClient::post(
            $apiUrl,
            'data=' . rawurlencode($payload),
            [
                'Content-Type: application/x-www-form-urlencoded',
                'Referer: https://h5.video.weibo.com/show/' . rawurlencode($fid),
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36',
            ]
        );

        if (!$response) {
            return null;
        }

        $json = json_decode($response, true);
        $playInfo = $json['data']['Component_Play_Playinfo'] ?? null;
        if (!$playInfo || !is_array($playInfo)) {
            return null;
        }

        $video = $this->pickBestWeiboVideo(
            is_array($playInfo['media_info'] ?? null) ? $playInfo['media_info'] : [],
            is_array($playInfo['urls'] ?? null) ? $playInfo['urls'] : []
        );

        if (!$video && !empty($playInfo['stream_url'])) {
            $video = $this->normalizeMediaUrl($playInfo['stream_url']);
        }

        if (!$video) {
            return null;
        }

        $cover = $this->extractMediaUrl($playInfo['cover_image'] ?? null)
            ?: $this->extractMediaUrl($playInfo['page_pic'] ?? null);

        $caption = $this->cleanWeiboText($playInfo['title'] ?? $playInfo['page_title'] ?? '');

        return $this->buildResult([
            'title'        => $caption,
            'cover'        => $cover,
            'type'         => 'video',
            'video'        => $video,
            'video_direct' => true,
        ]);
    }

    private function pickBestWeiboVideo(array $mediaInfo, array $urls): ?string
    {
        $candidates = [];

        foreach ($urls as $key => $url) {
            if (!is_string($url) || $url === '' || str_contains($url, '.m3u8')) {
                continue;
            }

            $score = 0;
            if (str_contains((string) $key, 'hd')) {
                $score += 100;
            }
            if (str_contains($url, 'mp4_hd') || str_contains($url, 'hd')) {
                $score += 50;
            }
            if (str_contains($url, '.mp4')) {
                $score += 10;
            }

            $candidates[] = ['score' => $score, 'url' => $url];
        }

        usort($candidates, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        if (!empty($candidates)) {
            return $this->normalizeMediaUrl($candidates[0]['url']);
        }

        foreach (['stream_url_hd', 'stream_url', 'mp4_hd_url', 'h5_url'] as $key) {
            if (!empty($mediaInfo[$key]) && is_string($mediaInfo[$key])) {
                $url = $mediaInfo[$key];
                if (!str_contains($url, '.m3u8')) {
                    return $this->normalizeMediaUrl($url);
                }
            }
        }

        return null;
    }

    private function cleanWeiboText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    protected function uniqueImageUrls(array $images): array
    {
        $unique = [];
        $seen = [];

        foreach ($images as $url) {
            if (!is_string($url) || $url === '') {
                continue;
            }
            $url = $this->normalizeMediaUrl($url);

            $path = parse_url($url, PHP_URL_PATH) ?: $url;

            $filename = basename($path);

            if (isset($seen[$filename])) {
                continue;
            }
            $seen[$filename] = true;
            $unique[] = $url;
        }

        return $unique;
    }
}
