<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class DoubaoParser extends BaseParser
{
    protected array $domains = ['doubao.com'];

    private const WECHAT_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 MicroMessenger/7.0.20.1781(0x6700143B) NetType/WIFI MiniProgramEnv/Windows WindowsWechat/WMPF';

    private const CHROME_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0';

    public function getPlatform(): string
    {
        return 'doubao';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);
        $kind = $this->detectLinkKind($url);

        if ($kind['type'] === 'video') {
            return $this->parseVideoShare($kind['url']);
        }

        if ($kind['type'] === 'thread') {
            return $this->parseThread($kind['fetchUrl'], $kind['threadId']);
        }

        throw new \RuntimeException('不支持的豆包链接，请使用视频分享或对话分享链接');
    }

    /** @return array{type:string,url?:string,threadId?:string,fetchUrl?:string} */
    private function detectLinkKind(string $url): array
    {
        $query = [];
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        $shareId = $query['share_id'] ?? null;
        $videoId = $query['video_id'] ?? null;

        if (
            str_contains($url, 'video-sharing')
            || str_contains($url, '/creativity/')
            || ($shareId && $videoId)
            || ($videoId && preg_match('/video/i', $url))
        ) {
            return ['type' => 'video', 'url' => $url];
        }

        $threadId = $this->extractThreadId($url);
        if ($threadId) {
            return [
                'type'     => 'thread',
                'threadId' => $threadId,
                'fetchUrl' => 'https://www.doubao.com/thread/' . $threadId,
            ];
        }

        return ['type' => 'unknown'];
    }

    private function extractThreadId(string $url): ?string
    {
        $patterns = [
            '#doubao\.com/(?:share/)?(?:thread|chat)/([a-zA-Z0-9_-]+)#i',
            '#doubao\.com/s/([a-zA-Z0-9_-]+)#i',
            '#doubao\.com/share/(?!thread/|chat/)([a-zA-Z0-9_-]+)(?:[/?#]|$)#i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $m)) {
                return $m[1];
            }
        }

        $query = [];
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        $threadId = $query['thread_id'] ?? null;
        if (is_string($threadId) && $threadId !== '' && empty($query['video_id'])) {
            return $threadId;
        }
        if (
            is_string($query['share_id'] ?? null)
            && $query['share_id'] !== ''
            && empty($query['video_id'])
            && !str_contains($url, 'video')
        ) {
            return $query['share_id'];
        }

        return null;
    }

    private function parseVideoShare(string $url): array
    {
        $query = [];
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        $shareId = $query['share_id'] ?? null;
        $videoId = $query['video_id'] ?? null;

        if (!$shareId || !$videoId) {
            if ($videoId) {
                $fallback = $this->parseVideoShareLegacy($videoId);
                if ($fallback) {
                    return $fallback;
                }
            }
            throw new \RuntimeException('豆包视频链接缺少 share_id 或 video_id 参数');
        }

        $apiUrl = 'https://www.doubao.com/creativity/share/get_video_share_info?' . http_build_query([
            'version_code'    => '20800',
            'language'        => 'zh-CN',
            'device_platform' => 'web',
            'aid'             => '497858',
            'real_aid'        => '497858',
            'pc_version'      => '2.51.7',
        ]);

        $response = HttpClient::post(
            $apiUrl,
            json_encode([
                'share_id'    => $shareId,
                'vid'         => $videoId,
                'creation_id' => '',
            ], JSON_UNESCAPED_UNICODE),
            [
                'Content-Type: application/json',
                'Origin: https://www.doubao.com',
                'Referer: https://www.doubao.com/',
                'User-Agent: ' . self::WECHAT_UA,
            ]
        );

        $result = $response ? json_decode($response, true) : null;
        $play = $result['data']['play_info'] ?? null;

        if (!$play || empty($play['main'])) {
            $fallback = $this->parseVideoShareLegacy($videoId);
            if ($fallback) {
                return $fallback;
            }
            throw new \RuntimeException('无法获取豆包视频播放地址');
        }

        return $this->buildResult([
            'title'        => $result['data']['title'] ?? $result['data']['desc'] ?? '',
            'cover'        => $play['poster_url'] ?? '',
            'video'        => $play['main'],
            'type'         => 'video',
            'video_direct' => true,
        ]);
    }

    private function parseVideoShareLegacy(string $videoId): ?array
    {
        $apiUrl = 'https://www.doubao.com/samantha/media/get_play_info?' . http_build_query([
            'version_code'        => '20800',
            'language'            => 'zh-CN',
            'device_platform'     => 'web',
            'aid'                 => '497858',
            'real_aid'            => '497858',
            'pkg_type'            => 'release_version',
            'pc_version'          => '2.51.7',
            'samantha_web'        => '1',
            'use-olympus-account' => '1',
        ]);

        $response = HttpClient::post(
            $apiUrl,
            json_encode(['key' => $videoId], JSON_UNESCAPED_UNICODE),
            [
                'Content-Type: application/json',
                'Origin: https://www.doubao.com',
                'Referer: https://www.doubao.com/',
                'User-Agent: ' . self::WECHAT_UA,
            ]
        );

        $result = $response ? json_decode($response, true) : null;
        $media = $result['data']['original_media_info'] ?? null;
        if (!$media || empty($media['main_url'])) {
            return null;
        }

        return $this->buildResult([
            'cover'        => $result['data']['poster_url'] ?? '',
            'video'        => $media['main_url'],
            'type'         => 'video',
            'video_direct' => true,
        ]);
    }

    private function parseThread(string $url, ?string $threadId = null): array
    {
        $html = $this->fetchThreadHtml($url);
        if (!$html) {
            throw new \RuntimeException('无法获取豆包对话页面');
        }

        $parsed = $this->extractThreadPayload($html);
        $images = $parsed['images'];
        $caption = $parsed['caption'];
        $video = $parsed['video'];

        if (empty($images) && !$video && $threadId) {
            $apiParsed = $this->parseThreadViaApi($threadId, $url);
            if ($apiParsed) {
                $images = $apiParsed['images'];
                $video = $apiParsed['video'];
                $caption = $caption ?: $apiParsed['caption'];
            }
        }

        if (empty($images) && !$video) {
            throw new \RuntimeException('未在豆包对话中找到图片或视频资源');
        }

        return $this->buildResult([
            'title'        => $caption,
            'cover'        => $images[0] ?? ($parsed['poster'] ?: ''),
            'video'        => $video,
            'type'         => $video ? 'video' : 'image',
            'images'       => $this->dedupeDoubaoImages($images),
            'video_direct' => true,
        ]);
    }

    private function fetchThreadHtml(string $url): ?string
    {
        $headers = [
            'User-Agent: ' . self::CHROME_UA,
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
            'Referer: https://www.doubao.com/',
        ];

        $html = HttpClient::get($url, $headers);
        if ($html) {
            return $html;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        return $body ?: null;
    }

    private function extractThreadPayload(string $html): array
    {
        $best = ['images' => [], 'caption' => '', 'video' => null, 'poster' => ''];

        foreach ($this->extractThreadJsonCandidates($html) as $jsonData) {
            $chunk = $this->collectMediaFromJson($jsonData);
            $chunk['images'] = $this->dedupeDoubaoImages($chunk['images']);
            $score = count($chunk['images']) * 10 + ($chunk['video'] ? 5 : 0);
            $bestScore = count($best['images']) * 10 + ($best['video'] ? 5 : 0);
            if ($score > $bestScore) {
                $best = $chunk;
            }
            if ($best['caption'] === '' && $chunk['caption'] !== '') {
                $best['caption'] = $chunk['caption'];
            }
        }

        if (empty($best['images']) && !$best['video']) {
            $fallback = $this->extractMediaFromHtmlFragments($html);
            $fallback['images'] = $this->dedupeDoubaoImages($fallback['images']);
            if (!empty($fallback['images']) || !empty($fallback['video'])) {
                $best = $fallback;
            }
        }

        $best['images'] = $this->dedupeDoubaoImages($best['images']);

        return [
            'images'  => $best['images'],
            'caption' => $best['caption'],
            'video'   => $best['video'],
            'poster'  => $best['poster'],
        ];
    }

    /** 尝试通过分享 API 获取 thread 内容 */
    private function parseThreadViaApi(string $threadId, string $referer): ?array
    {
        $endpoints = [
            'https://www.doubao.com/samantha/thread/get_thread_share_info',
            'https://www.doubao.com/samantha/thread/get_share_thread',
        ];

        $query = http_build_query([
            'version_code'    => '20800',
            'language'        => 'zh-CN',
            'device_platform' => 'web',
            'aid'             => '497858',
            'real_aid'        => '497858',
            'pc_version'      => '3.23.10',
        ]);

        $payloads = [
            ['thread_id' => $threadId, 'share_id' => $threadId],
            ['share_id' => $threadId],
            ['thread_id' => $threadId],
        ];

        foreach ($endpoints as $endpoint) {
            foreach ($payloads as $body) {
                $response = HttpClient::post(
                    $endpoint . '?' . $query,
                    json_encode($body, JSON_UNESCAPED_UNICODE),
                    [
                        'Content-Type: application/json',
                        'Origin: https://www.doubao.com',
                        'Referer: ' . $referer,
                        'User-Agent: ' . self::CHROME_UA,
                    ]
                );
                if (!$response) {
                    continue;
                }
                $json = json_decode($response, true);
                if (!is_array($json)) {
                    continue;
                }
                $chunk = $this->collectMediaFromJson($json);
                if (!empty($chunk['images']) || !empty($chunk['video'])) {
                    $chunk['images'] = $this->dedupeDoubaoImages($chunk['images']);
                    return $chunk;
                }
            }
        }

        return null;
    }

    private function extractThreadJsonCandidates(string $html): array
    {
        $candidates = [];

        foreach (['window\._ROUTER_DATA\s*=\s*', '_ROUTER_DATA\s*=\s*'] as $pattern) {
            if (preg_match('/' . $pattern . '/', $html, $m, PREG_OFFSET_CAPTURE)) {
                $bracePos = strpos($html, '{', $m[0][1] + strlen($m[0][0]));
                if ($bracePos !== false) {
                    $json = $this->extractBalancedJson($html, $bracePos);
                    if ($json && strlen($json) < 5_000_000) {
                        $data = json_decode($json, true);
                        if (is_array($data)) {
                            $candidates[] = $data;
                        }
                    }
                }
            }
        }

        $attrPatterns = [
            '/data-script-src="modern-run-router-data-fn"[^>]*data-fn-args="([\s\S]*?)"\s+nonce="/',
            '/data-script-src="modern-run-window-fn"[^>]*data-fn-name="mergeLoaderData"[^>]*data-fn-args="([\s\S]*?)"\s+nonce="/',
            '/data-fn-args="([\s\S]*?)"\s+nonce="[^"]*"\s*><\/script>/',
        ];

        foreach ($attrPatterns as $pattern) {
            if (!preg_match($pattern, $html, $m)) {
                continue;
            }
            $jsonData = json_decode($this->decodeHtmlJsonAttr($m[1]), true);
            if (is_array($jsonData)) {
                $candidates[] = $jsonData;
            }
        }

        if (preg_match('/id="__MODERN_ROUTER_DATA__"[^>]*>([\s\S]*?)<\/script>/', $html, $m)) {
            $jsonData = json_decode(trim($m[1]), true);
            if (is_array($jsonData)) {
                $candidates[] = $jsonData;
            }
        }

        if (preg_match('/type="application\/json"\s+id="__MODERN_ROUTER_DATA__"[^>]*>([\s\S]*?)<\/script>/', $html, $m)) {
            $jsonData = json_decode(trim($m[1]), true);
            if (is_array($jsonData)) {
                $candidates[] = $jsonData;
            }
        }

        return $candidates;
    }

    private function decodeHtmlJsonAttr(string $raw): string
    {
        $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return str_replace(["\u{201C}", "\u{201D}", '&#34;'], '"', $raw);
    }

    private function collectMediaFromJson(mixed $data): array
    {
        $images = [];
        $caption = '';
        $video = null;
        $poster = '';

        $this->walkDoubaoJson($data, $images, $caption, $video, $poster);

        return compact('images', 'caption', 'video', 'poster');
    }

    private function walkDoubaoJson(
        mixed $data,
        array &$images,
        string &$caption,
        ?string &$video,
        string &$poster,
        int $depth = 0
    ): void {
        if ($depth > 20 || !is_array($data)) {
            return;
        }

        if ($this->isListArray($data)) {
            foreach ($data as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (!empty($item['routerDataFnArgs'][0]) && is_string($item['routerDataFnArgs'][0])) {
                    $inner = json_decode($this->decodeHtmlJsonAttr($item['routerDataFnArgs'][0]), true);
                    if (is_array($inner)) {
                        $this->walkDoubaoJson($inner, $images, $caption, $video, $poster, $depth + 1);
                    }
                }
                if (
                    isset($item['key'], $item['data'])
                    && is_string($item['key'])
                    && str_contains($item['key'], 'thread')
                    && is_array($item['data'])
                ) {
                    $this->extractDoubaoMessages($item['data'], $images, $caption, $video, $poster);
                }
                $this->walkDoubaoJson($item, $images, $caption, $video, $poster, $depth + 1);
            }

            return;
        }

        if (isset($data['loaderData']) && is_array($data['loaderData'])) {
            foreach ($data['loaderData'] as $key => $loaderChunk) {
                if (!is_array($loaderChunk) || !str_contains((string) $key, 'thread')) {
                    continue;
                }
                $this->extractDoubaoMessages($loaderChunk, $images, $caption, $video, $poster);
            }
        }

        foreach ($data as $key => $value) {
            if (!is_string($key) || !is_array($value) || !str_contains($key, 'thread')) {
                continue;
            }
            $this->extractDoubaoMessages($value, $images, $caption, $video, $poster);
        }

        $this->extractDoubaoMessages($data, $images, $caption, $video, $poster);
    }

    private function isListArray(array $data): bool
    {
        if ($data === []) {
            return true;
        }

        return array_keys($data) === range(0, count($data) - 1);
    }

    private function extractDoubaoMessages(
        array $data,
        array &$images,
        string &$caption,
        ?string &$video,
        string &$poster
    ): void {
        $lists = [];
        if (isset($data['data']['message_snapshot']['message_list']) && is_array($data['data']['message_snapshot']['message_list'])) {
            $lists[] = $data['data']['message_snapshot']['message_list'];
        }
        if (isset($data['message_snapshot']['message_list']) && is_array($data['message_snapshot']['message_list'])) {
            $lists[] = $data['message_snapshot']['message_list'];
        }
        if (isset($data['message_list']) && is_array($data['message_list'])) {
            $lists[] = $data['message_list'];
        }

        foreach ($lists as $messageList) {
            foreach ($messageList as $message) {
                if (!is_array($message)) {
                    continue;
                }
                if ($caption === '' && !empty($message['content']) && is_string($message['content'])) {
                    $text = trim($message['content']);
                    if ($text !== '' && !str_starts_with($text, '[{')) {
                        $caption = $text;
                    }
                }

                $this->processDoubaoContentBlocks($message['content_block'] ?? [], $images, $video, $poster);

                $rawContent = $message['content'] ?? null;
                if (is_string($rawContent) && str_starts_with(trim($rawContent), '[{')) {
                    $blocks = json_decode($rawContent, true);
                    if (is_array($blocks)) {
                        $this->processDoubaoContentBlocks($blocks, $images, $video, $poster);
                    }
                }
            }
        }
    }

    private function processDoubaoContentBlocks(array $blocks, array &$images, ?string &$video, string &$poster): void
    {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $contentRaw = $block['content_v2'] ?? $block['content'] ?? null;
            if ($contentRaw === null) {
                continue;
            }

            $content = is_string($contentRaw) ? json_decode($contentRaw, true) : $contentRaw;
            if (!is_array($content)) {
                continue;
            }

            foreach ($content['creation_block']['creations'] ?? [] as $creation) {
                if (is_array($creation)) {
                    $this->appendCreationMedia($creation, $images, $video, $poster);
                }
            }
        }
    }

    private function appendCreationMedia(array $creation, array &$images, ?string &$video, string &$poster): void
    {
        $image = $creation['image'] ?? [];
        $url = $this->extractMediaUrl($image['image_ori_raw'] ?? null)
            ?? $this->extractMediaUrl($image['origin_image'] ?? null)
            ?? $this->extractMediaUrl($image['image_ori'] ?? null)
            ?? $this->extractMediaUrl($image['image_preview'] ?? null)
            ?? $this->extractMediaUrl($image['image_raw'] ?? null);

        if ($url && !$this->isDoubaoDecorativeImage($url)) {
            $images[] = $this->sanitizeDoubaoUrl($url);
        }

        $videoUrl = $creation['video']['video_ori']['url']
            ?? $creation['video']['play_url']
            ?? $creation['video']['url']
            ?? $creation['video_url']
            ?? null;

        if (is_string($videoUrl) && $videoUrl !== '') {
            $video = $this->sanitizeDoubaoUrl($videoUrl);
            $posterUrl = $creation['video']['cover_url'] ?? $creation['video']['poster_url'] ?? '';
            if (is_string($posterUrl) && $posterUrl !== '' && !$this->isDoubaoDecorativeImage($posterUrl)) {
                $poster = $this->sanitizeDoubaoUrl($posterUrl);
            }
        }
    }

    private function isDoubaoDecorativeImage(string $url): bool
    {
        return (bool) preg_match(
            '#user-avatar|icon-tiny|BIZ_BOT_ICON|logo-doubao|/avatar/|ocean-cloud-tos/FileBizType\.BIZ_BOT|lf-flow-web-cdn\.doubao\.com/obj/flow-doubao/doubao/logo#i',
            $url
        );
    }

    private function sanitizeDoubaoUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        // 截断误匹配的 JSON 碎片（如 ...%3D\",\"origin_url\":\"...）
        if (preg_match('#^([^"\\\\]+)#', $url, $m)) {
            $url = $m[1];
        }
        $url = stripcslashes($url);
        $url = str_replace(['\\u002F', '\\u0026'], ['/', '&'], $url);
        return $this->normalizeMediaUrl($url);
    }

    private function doubaoImageKey(string $url): string
    {
        if (preg_match('#rc_gen_image/([a-f0-9]{32})#i', $url, $m)) {
            return 'gen:' . strtolower($m[1]);
        }
        if (preg_match('#/([a-f0-9]{32})(?:\.|~|\?|/)#i', $url, $m)) {
            return 'id:' . strtolower($m[1]);
        }
        $path = parse_url($url, PHP_URL_PATH) ?? $url;

        return 'path:' . $path;
    }

    private function doubaoImageQualityScore(string $url): int
    {
        if (preg_match('#image_ori_raw|image_raw_b|image_raw|origin_image#i', $url)) {
            return 100;
        }
        if (preg_match('#image_dld_watermark#i', $url)) {
            return 85;
        }
        if (preg_match('#\.png(\?|$)#i', $url)) {
            return 70;
        }
        if (preg_match('#\.jpe?g(\?|$)#i', $url)) {
            return 65;
        }
        if (preg_match('#webp#i', $url)) {
            return 50;
        }
        if (preg_match('#\.heic#i', $url)) {
            return 5;
        }
        if (preg_match('#pre_watermark|pre_mark|downsize|resize|watermark_1_6#i', $url)) {
            return 25;
        }

        return 40;
    }

    /** @param string[] $images */
    private function dedupeDoubaoImages(array $images): array
    {
        $best = [];
        foreach ($images as $url) {
            if (!is_string($url) || $url === '') {
                continue;
            }
            $url = $this->sanitizeDoubaoUrl($url);
            if ($url === '' || $this->isDoubaoDecorativeImage($url)) {
                continue;
            }
            if (!preg_match('#^https?://#i', $url)) {
                continue;
            }

            $key = $this->doubaoImageKey($url);
            $score = $this->doubaoImageQualityScore($url);
            if (!isset($best[$key]) || $score > $best[$key]['score']) {
                $best[$key] = ['url' => $url, 'score' => $score];
            }
        }

        return array_values(array_map(static fn(array $item): string => $item['url'], $best));
    }

    private function extractMediaFromHtmlFragments(string $html): array
    {
        $images = [];
        $video = null;
        $poster = '';
        $caption = '';

        $decoded = str_replace(['\u002F', '\u0026', '\\/'], ['/', '&', '/'], $html);

        if (preg_match_all('/"image_ori_raw"\s*:\s*\{[\s\S]*?"url"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/', $decoded, $matches)) {
            foreach ($matches[1] as $url) {
                $images[] = $this->sanitizeDoubaoUrl(stripcslashes($url));
            }
        }

        if (preg_match_all('/"origin_image"\s*:\s*\{[\s\S]*?"url"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/', $decoded, $matches)) {
            foreach ($matches[1] as $url) {
                $images[] = $this->sanitizeDoubaoUrl(stripcslashes($url));
            }
        }

        if (preg_match_all('#https?://[^"\s\\\\]+/rc_gen_image/[a-f0-9]{32}[^"\s\\\\]*#i', $decoded, $matches)) {
            foreach ($matches[0] as $url) {
                $images[] = $this->sanitizeDoubaoUrl($url);
            }
        }

        if (preg_match('/"vid"\s*:\s*"([^"]+)"/', $html, $vm) && !$video) {
            $legacy = $this->parseVideoShareLegacy($vm[1]);
            if ($legacy) {
                $video = $legacy['video'] ?? null;
                $poster = $legacy['cover'] ?? '';
            }
        }

        if (preg_match('/"title"\s*:\s*"([^"]{2,200})"/', $html, $tm)) {
            $caption = stripcslashes($tm[1]);
        }

        return [
            'images'  => $this->dedupeDoubaoImages($images),
            'caption' => $caption,
            'video'   => $video,
            'poster'  => $poster,
        ];
    }
}
