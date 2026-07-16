<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

abstract class BaseParser implements ParserInterface
{
    protected array $domains = [];

    protected const MOBILE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1';

    public function supports(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return false;
        }
        $host = strtolower($host);
        foreach ($this->domains as $domain) {
            if (str_contains($host, $domain)) {
                return true;
            }
        }
        return false;
    }

    protected function resolveUrl(string $url): string
    {
        $final = HttpClient::getFinalUrl($url);
        return $final ?: $url;
    }

    protected function mobileHeaders(string $referer = 'https://www.douyin.com/'): array
    {
        return [
            'User-Agent: ' . self::MOBILE_UA,
            'Referer: ' . $referer,
        ];
    }

    protected function extractBetween(string $content, string $start, string $end): ?string
    {
        $pos = strpos($content, $start);
        if ($pos === false) {
            return null;
        }
        $pos += strlen($start);
        $endPos = strpos($content, $end, $pos);
        if ($endPos === false) {
            return null;
        }
        return substr($content, $pos, $endPos - $pos);
    }

    protected function extractJson(string $content, string $pattern): ?array
    {
        if (preg_match($pattern, $content, $matches)) {
            $json = json_decode($matches[1], true);
            return is_array($json) ? $json : null;
        }
        return null;
    }

    protected function findNestedValue(array $data, string $key): mixed
    {
        if (isset($data[$key])) {
            return $data[$key];
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                $result = $this->findNestedValue($value, $key);
                if ($result !== null) {
                    return $result;
                }
            }
        }
        return null;
    }

    protected function extractMediaUrl(mixed $media): ?string
    {
        if (is_string($media)) {
            $media = trim($media);
            return $media !== '' ? $this->normalizeMediaUrl($media) : null;
        }
        if (!is_array($media)) {
            return null;
        }

        foreach (['url_list', 'urlList'] as $listKey) {
            if (!empty($media[$listKey]) && is_array($media[$listKey])) {
                foreach ($media[$listKey] as $url) {
                    if (is_string($url) && $url !== '') {
                        return $this->normalizeMediaUrl($url);
                    }
                }
            }
        }

        foreach (['url', 'urlDefault', 'url_default', 'uri', 'src', 'img_url', 'imgUrl', 'poster'] as $key) {
            if (!empty($media[$key]) && is_string($media[$key])) {
                return $this->normalizeMediaUrl($media[$key]);
            }
        }

        if (!empty($media['infoList']) && is_array($media['infoList'])) {
            foreach ($media['infoList'] as $info) {
                if (is_array($info) && !empty($info['url'])) {
                    return $this->normalizeMediaUrl($info['url']);
                }
            }
        }

        return null;
    }

    protected function extractCoverFromVideo(array $video, array $item = []): string
    {
        $candidates = [
            $video['cover'] ?? null,
            $video['origin_cover'] ?? null,
            $video['originCover'] ?? null,
            $video['dynamic_cover'] ?? null,
            $video['dynamicCover'] ?? null,
            $video['animated_cover'] ?? null,
            $video['poster'] ?? null,
            $video['poster_url'] ?? null,
            $video['cover_url'] ?? null,
            $video['thumbnail'] ?? null,
            $video['thumb'] ?? null,
            $video['first_frame'] ?? null,
            $video['firstFrame'] ?? null,
            $item['cover'] ?? null,
            $item['video_cover'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $url = $this->extractMediaUrl($candidate);
            if ($url) {
                return $url;
            }
        }

        if (!empty($video['big_thumbs']) && is_array($video['big_thumbs'])) {
            foreach ($video['big_thumbs'] as $thumb) {
                $url = $this->extractMediaUrl($thumb);
                if ($url) {
                    return $url;
                }
            }
        }

        if (!empty($video['image']) && is_array($video['image'])) {
            $url = $this->extractMediaUrl($video['image']['thumbnail'] ?? $video['image']['firstFrame'] ?? $video['image']);
            if ($url) {
                return $url;
            }
        }

        return $this->findNestedMediaUrl($video, ['cover', 'origin_cover', 'poster', 'thumbnail', 'thumb'])
            ?? $this->findNestedMediaUrl($item, ['cover', 'poster', 'thumbnail'])
            ?? '';
    }

    protected function findNestedMediaUrl(array $data, array $keys, int $depth = 0): ?string
    {
        if ($depth > 10) {
            return null;
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $url = $this->extractMediaUrl($data[$key]);
                if ($url) {
                    return $url;
                }
            }
        }

        foreach ($data as $value) {
            if (!is_array($value)) {
                continue;
            }
            $url = $this->findNestedMediaUrl($value, $keys, $depth + 1);
            if ($url) {
                return $url;
            }
        }

        return null;
    }

    protected function extractCoverFromHtml(string $html): ?string
    {
        if (preg_match('/<img[^>]+class="[^"]*poster[^"]*"[^>]+src="([^"]+)"/i', $html, $m)
            || preg_match('/<img[^>]+src="([^"]+)"[^>]+class="[^"]*poster/i', $html, $m)) {
            return $this->normalizeMediaUrl(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }

        if (preg_match('/property="og:image(?::secure_url)?"\s+content="([^"]+)"/i', $html, $m)
            || preg_match('/<meta[^>]+name="twitter:image"[^>]+content="([^"]+)"/i', $html, $m)
            || preg_match('/itemprop="image"[^>]+content="([^"]+)"/i', $html, $m)) {
            return $this->normalizeMediaUrl(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }

        if (preg_match('/https?:\/\/[^"\'\s<>]+(?:douyinpic|byteimg|xhscdn|sns-webpic|kwimgs|yximgs|hdslb|bfs)[^"\'\s<>]*/i', $html, $m)) {
            return $this->normalizeMediaUrl(html_entity_decode($m[0], ENT_QUOTES, 'UTF-8'));
        }

        return null;
    }

    protected function ensureCover(array $result, string $html): array
    {
        if (!empty($result['cover'])) {
            return $result;
        }
        $cover = $this->extractCoverFromHtml($html);
        if ($cover) {
            $result['cover'] = $cover;
        }
        return $result;
    }

    protected function extractOgMeta(string $html): array
    {
        $meta = ['title' => '', 'cover' => '', 'video' => null, 'images' => []];

        $map = [
            'title' => '/property="og:title"\s+content="([^"]+)"/i',
            'cover' => '/property="og:image(?::secure_url)?"\s+content="([^"]+)"/i',
            'video' => '/property="og:video(?::url)?"\s+content="([^"]+)"/i',
        ];
        foreach ($map as $key => $pattern) {
            if (preg_match($pattern, $html, $m)) {
                $val = $this->normalizeMediaUrl(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
                if ($key === 'video') {
                    $meta['video'] = $val;
                } else {
                    $meta[$key] = $val;
                }
            }
        }

        if ($meta['cover'] === '') {
            $meta['cover'] = $this->extractCoverFromHtml($html) ?? '';
        }

        if (preg_match_all('/property="og:image(?::secure_url)?"\s+content="([^"]+)"/i', $html, $imgs)) {
            $meta['images'] = array_values(array_unique(array_map(
                fn($u) => $this->normalizeMediaUrl(html_entity_decode($u, ENT_QUOTES, 'UTF-8')),
                $imgs[1]
            )));
        }

        return $meta;
    }

    protected function buildFromAwemeItem(array $item): array
    {
        if ($this->isImageAwemeItem($item)) {
            $images = $this->extractImagesFromAwemeItem($item);
            $cover = $images[0] ?? $this->extractCoverFromVideo($item['video'] ?? [], $item);
            $music = $this->extractMusicUrl($item);

            return $this->buildResult([
                'title'  => $item['desc'] ?? $item['title'] ?? '',
                'author' => $item['author']['nickname'] ?? $item['author_info']['nickname'] ?? '',
                'cover'  => $cover,
                'type'   => 'image',
                'video'  => null,
                'images' => $images,
                'music'  => $music,
            ]);
        }

        $video = $item['video'] ?? [];
        $videoUrl = $this->extractByteVideoUrl($video);

        $cover = $this->extractCoverFromVideo($video, $item);

        $images = [];
        if (!empty($item['images'])) {
            foreach ($item['images'] as $img) {
                $url = $this->extractMediaUrl($img);
                if ($url) {
                    $images[] = $url;
                }
            }
        }

        if (!$videoUrl) {
            $videoUrl = $this->fallbackBytePlayUrl($video);
        }

        $type = $videoUrl ? 'video' : (!empty($images) ? 'image' : 'video');

        return $this->buildResult([
            'title'        => $item['desc'] ?? $item['title'] ?? '',
            'author'       => $item['author']['nickname'] ?? $item['author_info']['nickname'] ?? '',
            'cover'        => $cover,
            'type'         => $type,
            'video'        => $videoUrl,
            'images'       => array_filter($images),
            'music'        => $this->extractMusicUrl($item),
            'video_direct' => $videoUrl && !preg_match('#aweme\.snssdk\.com#i', (string) $videoUrl),
        ]);
    }

    protected function isImageAwemeItem(array $item): bool
    {
        $type = (int) ($item['aweme_type'] ?? 0);
        $images = $this->extractImagesFromAwemeItem($item);

        if (in_array($type, [2, 68, 150], true)) {
            return !empty($images);
        }

        if (empty($images)) {
            return false;
        }

        $video = $item['video'] ?? [];
        $duration = (int) ($video['duration'] ?? 0);
        $hasRealVideo = $this->extractByteVideoUrl($video) !== null
            || $this->extractPlayUrlFromVideoMeta($video) !== null;

        return !$hasRealVideo && $duration === 0;
    }

    /** @return string[] */
    protected function extractImagesFromAwemeItem(array $item): array
    {
        $images = [];
        foreach ($item['images'] ?? [] as $img) {
            $url = $this->extractMediaUrl($img);
            if ($url) {
                $images[] = $url;
            }
        }

        if (empty($images) && !empty($item['image_post_info']['images']) && is_array($item['image_post_info']['images'])) {
            foreach ($item['image_post_info']['images'] as $img) {
                $url = $this->extractMediaUrl($img);
                if ($url) {
                    $images[] = $url;
                }
            }
        }

        return $this->uniqueImageUrls($images);
    }

    protected function extractMusicUrl(array $item): ?string
    {
        $music = $item['music']['play_url'] ?? null;
        if (is_array($music)) {
            $url = $music['uri'] ?? ($music['url_list'][0] ?? null);
            if (is_string($url) && $url !== '') {
                return $this->normalizeMediaUrl($url);
            }
        }
        return null;
    }

    protected function isValidDouyinVideoId(string $videoId): bool
    {
        return (bool) preg_match('/^v[a-zA-Z0-9_]{10,}$/', $videoId);
    }

    /** 从页面 HTML 片段提取 play 地址（JSON 解析失败时的兜底） */
    protected function extractPlayUrlFromHtml(string $html): ?string
    {
        $decoded = str_replace(['\\u002F', '\\/', '\u002F'], '/', $html);

        if (preg_match('#https://aweme\.snssdk\.com/aweme/v1/playwm/?\?([^"\'\\\\\s<>]+)#i', $decoded, $m)) {
            if (!$this->isDouyinPlayQueryValid($m[1])) {
                return null;
            }
            return $this->normalizeMediaUrl(html_entity_decode('https://aweme.snssdk.com/aweme/v1/playwm/?' . $m[1], ENT_QUOTES, 'UTF-8'));
        }
        if (preg_match('#https://aweme\.snssdk\.com/aweme/v1/play/?\?([^"\'\\\\\s<>]+)#i', $decoded, $m)) {
            if (!$this->isDouyinPlayQueryValid($m[1])) {
                return null;
            }
            return $this->normalizeMediaUrl(html_entity_decode('https://aweme.snssdk.com/aweme/v1/play/?' . $m[1], ENT_QUOTES, 'UTF-8'));
        }
        if (preg_match('#playwm/\?video_id=([a-zA-Z0-9_]+)#', $decoded, $m)) {
            if (!$this->isValidDouyinVideoId($m[1])) {
                return null;
            }
            return 'https://aweme.snssdk.com/aweme/v1/play/?video_id=' . $m[1] . '&ratio=720p&line=0';
        }
        if (preg_match('#"video_id"\s*:\s*"(v[a-zA-Z0-9_]+)"#', $decoded, $m)) {
            return 'https://aweme.snssdk.com/aweme/v1/play/?video_id=' . $m[1] . '&ratio=720p&line=0';
        }

        return null;
    }

    protected function isDouyinPlayQueryValid(string $query): bool
    {
        parse_str(html_entity_decode($query, ENT_QUOTES, 'UTF-8'), $params);
        $videoId = (string) ($params['video_id'] ?? '');
        return $this->isValidDouyinVideoId($videoId);
    }

    /** 结构化 JSON 失败时，从 HTML 提取 play 地址或图文图片 */
    protected function buildResultFromHtmlFallback(string $html): ?array
    {
        $item = $this->parseByteShareHtml($html);
        if (is_array($item)) {
            $result = $this->buildFromAwemeItem($item);
            if (!empty($result['video']) || ($result['type'] === 'image' && !empty($result['images']))) {
                return $result;
            }
        }

        $playRaw = $this->extractPlayUrlFromHtml($html);
        if (!$playRaw) {
            return null;
        }

        $videoUrl = $this->normalizeMediaUrl($playRaw);
        if ($videoUrl === '') {
            return null;
        }

        $og = $this->extractOgMeta($html);

        return $this->buildResult([
            'title'        => $og['title'],
            'cover'        => $og['cover'] ?: ($og['images'][0] ?? ''),
            'type'         => 'video',
            'video'        => $videoUrl,
            'images'       => [],
            'video_direct' => false,
        ]);
    }

    protected function getMediaReferer(): string
    {
        return 'https://www.douyin.com/';
    }

    protected function extractByteVideoUrl(array $video): ?string
    {
        if ($this->isMusicMediaArray($video)) {
            return null;
        }

        // H.264 优先：移动端 Safari/微信对 HEVC(bit_rate) 兼容性差
        $lists = [
            $video['play_addr_h264']['url_list'] ?? [],
            $video['play_addr']['url_list'] ?? [],
            $video['download_addr']['url_list'] ?? [],
        ];
        foreach ($lists as $urls) {
            foreach ($urls as $url) {
                if (is_string($url) && $url !== '' && !$this->isMusicMediaUrl($url)) {
                    $resolved = $this->resolveBytePlayUrl($url);
                    if ($resolved) {
                        return $resolved;
                    }
                }
            }
        }

        if (!empty($video['bit_rate']) && is_array($video['bit_rate'])) {
            foreach ($video['bit_rate'] as $br) {
                if (!is_array($br)) {
                    continue;
                }
                foreach ($br['play_addr']['url_list'] ?? [] as $url) {
                    if (is_string($url) && $url !== '' && !$this->isMusicMediaUrl($url)) {
                        $resolved = $this->resolveBytePlayUrl($url);
                        if ($resolved) {
                            return $resolved;
                        }
                    }
                }
            }
        }

        if (!empty($video['playApi']) && is_string($video['playApi']) && !$this->isMusicMediaUrl($video['playApi'])) {
            return $this->resolveBytePlayUrl($video['playApi']);
        }

        return $this->extractPlayUrlFromVideoMeta($video);
    }

    protected function buildPlayUrlFromVideoId(string $videoId): ?string
    {
        $videoId = trim($videoId);
        if (!$this->isValidDouyinVideoId($videoId)) {
            return null;
        }

        return 'https://aweme.snssdk.com/aweme/v1/play/?video_id=' . $videoId . '&ratio=720p&line=0';
    }

    protected function extractPlayUrlFromVideoMeta(array $video): ?string
    {
        if ($this->isMusicMediaArray($video)) {
            return null;
        }

        foreach ([
            $video['play_addr']['uri'] ?? '',
            $video['vid'] ?? '',
            $video['video_id'] ?? '',
        ] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                $url = $this->buildPlayUrlFromVideoId($candidate);
                if ($url) {
                    return $url;
                }
            }
        }

        return null;
    }

    protected function isMusicMediaUrl(string $url): bool
    {
        return (bool) preg_match('#(?:^|/)(?:ies-music|music)/|\.mp3(?:\?|$|/)|\.m4a(?:\?|$)#i', $url);
    }

    protected function isMusicMediaArray(array $video): bool
    {
        $uri = (string) ($video['play_addr']['uri'] ?? '');
        if ($uri !== '' && $this->isMusicMediaUrl($uri)) {
            return true;
        }
        foreach ($video['play_addr']['url_list'] ?? [] as $url) {
            if (is_string($url) && $this->isMusicMediaUrl($url)) {
                return true;
            }
        }
        return false;
    }

    protected function resolveBytePlayUrl(string $url): ?string
    {
        $url = $this->normalizeMediaUrl($url);
        return $url !== '' ? $url : null;
    }

    /** play 接口解析失败时的兜底地址 */
    protected function fallbackBytePlayUrl(array $video): ?string
    {
        if ($this->isMusicMediaArray($video)) {
            return null;
        }

        foreach ([
            $video['play_addr']['url_list'] ?? [],
            $video['download_addr']['url_list'] ?? [],
        ] as $urls) {
            foreach ($urls as $url) {
                if (is_string($url) && $url !== '' && !$this->isMusicMediaUrl($url)) {
                    return $this->normalizeMediaUrl($url);
                }
            }
        }
        if (!empty($video['playApi']) && is_string($video['playApi']) && !$this->isMusicMediaUrl($video['playApi'])) {
            return $this->normalizeMediaUrl($video['playApi']);
        }

        return $this->extractPlayUrlFromVideoMeta($video);
    }

    protected function normalizeMediaUrl(string $url): string
    {
        $url = trim($url);
        $url = str_replace(['playwm', '\\u002F'], ['play', '/'], $url);
        $url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }
        return $url;
    }

    protected function parseByteShareHtml(string $html): ?array
    {
        if (preg_match('/"videoInfoRes"\s*:\s*\{/s', $html, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1] + strpos($m[0][0], '{');
            $chunk = $this->extractBalancedJson($html, $start);
            if ($chunk) {
                $data = json_decode('{"videoInfoRes":' . $chunk . '}', true);
                if ($data) {
                    $item = $this->extractAwemeItem($data);
                    if ($item) {
                        return $item;
                    }
                }
                unset($chunk, $data);
            }
        }

        foreach (['window\._ROUTER_DATA\s*=\s*', '_ROUTER_DATA\s*=\s*', 'window\.__INIT_PROPS__\s*=\s*'] as $pattern) {
            if (preg_match('/' . $pattern . '/', $html, $m, PREG_OFFSET_CAPTURE)) {
                $json = $this->extractBalancedJson($html, $m[0][1] + strlen($m[0][0]));
                if ($json && strlen($json) < 2_000_000) {
                    $data = json_decode($json, true);
                    unset($json);
                    if ($data) {
                        $item = $this->extractAwemeItem($data);
                        unset($data);
                        if ($item) {
                            return $item;
                        }
                    }
                }
            }
        }

        if (preg_match('/<script id="RENDER_DATA"[^>]*>(.*?)<\/script>/s', $html, $m)) {
            $data = json_decode(urldecode($m[1]), true);
            if ($data) {
                $item = $this->extractAwemeItem($data);
                if ($item) {
                    return $item;
                }
            }
        }

        return null;
    }

    protected function extractBalancedJson(string $text, int $start): ?string
    {
        if (!isset($text[$start]) || $text[$start] !== '{') {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        $len = strlen($text);

        for ($i = $start; $i < $len; $i++) {
            $ch = $text[$i];
            if ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inString = true;
                continue;
            }
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    protected function extractAwemeItem(array $data): ?array
    {
        $videoInfoRes = $this->findNestedValue($data, 'videoInfoRes');
        if (is_array($videoInfoRes) && !empty($videoInfoRes['item_list'][0])) {
            return $this->normalizeAwemeItem($videoInfoRes['item_list'][0]);
        }

        foreach (['item_list', 'aweme_list'] as $key) {
            $list = $this->findNestedValue($data, $key);
            if (is_array($list) && !empty($list[0])) {
                return $this->normalizeAwemeItem($list[0]);
            }
        }

        return null;
    }

    protected function normalizeAwemeItem(array $item): array
    {
        if (isset($item['aweme_detail']) && is_array($item['aweme_detail'])) {
            return $item['aweme_detail'];
        }
        return $item;
    }

    protected function extractId(string $url, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    protected function resultFromOg(string $html, string $referer = ''): ?array
    {
        $og = $this->extractOgMeta($html);
        if (!$og['video'] && empty($og['images'])) {
            return null;
        }
        return $this->buildResult([
            'title'  => $og['title'],
            'cover'  => $og['cover'] ?: ($og['images'][0] ?? ''),
            'type'   => $og['video'] ? 'video' : 'image',
            'video'  => $og['video'],
            'images' => $og['images'],
        ]);
    }

    protected function buildResult(array $data): array
    {
        $caption = trim($data['caption'] ?? $data['title'] ?? $data['desc'] ?? '');
        $cover   = $this->extractMediaUrl($data['cover'] ?? null) ?? '';
        $video   = is_string($data['video'] ?? null) ? $this->normalizeMediaUrl($data['video']) : ($data['video'] ?? null);
        $images  = [];

        foreach ($data['images'] ?? [] as $img) {
            $url = $this->extractMediaUrl($img) ?? (is_string($img) ? $this->normalizeMediaUrl($img) : null);
            if ($url) {
                $images[] = $url;
            }
        }

        $images = $this->uniqueImageUrls($images);

        if ($cover === '' && !empty($images[0])) {
            $cover = $images[0];
        }

        $type = $data['type'] ?? ($video ? 'video' : (!empty($images) ? 'image' : 'video'));

        return [
            'platform' => $this->getPlatform(),
            'video'    => $video ?: null,
            'cover'    => $cover,
            'caption'  => $caption,
            'author'   => $data['author'] ?? '',
            'type'     => $type,
            'images'   => array_values($images),
            'music'    => is_string($data['music'] ?? null) ? $this->normalizeMediaUrl($data['music']) : ($data['music'] ?? null),
            'title'    => $caption,
            'video_direct' => (bool) ($data['video_direct'] ?? false),
        ];
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
            if (isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            $unique[] = $url;
        }

        return $unique;
    }
}
