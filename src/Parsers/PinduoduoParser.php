<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class PinduoduoParser extends BaseParser
{
    protected array $domains = ['yangkeduo.com', 'pinduoduo.com', 'pddpic.com', 'p.pinduoduo.com'];

    public function getPlatform(): string
    {
        return 'pinduoduo';
    }

    public function parse(string $url): array
    {
        $originalUrl = trim($url);
        $resolvedUrl = $this->resolveUrl($originalUrl) ?: $originalUrl;
        $params = $this->extractUrlParams($originalUrl, $resolvedUrl);

        $lastHtml = null;
        $needLogin = false;

        foreach ($this->buildCandidateUrls($originalUrl, $resolvedUrl, $params) as $fetchUrl) {
            $html = $this->fetchPage($fetchUrl);
            if (!$html) {
                continue;
            }

            $lastHtml = $html;

            if ($this->isWechatOnlyPage($html)) {
                continue;
            }

            if ($this->detectNeedLogin($html)) {
                $needLogin = true;
            }

            $result = $this->parseFromHtml($html, $fetchUrl);
            if ($result) {
                return $result;
            }

            $apiResult = $this->fetchFromApis($params, $fetchUrl);
            if ($apiResult) {
                return $apiResult;
            }
        }

        if ($needLogin) {
            throw new \RuntimeException('拼多多内容需登录后查看，请复制 App 内「复制链接」的完整分享地址重试');
        }

        if ($lastHtml) {
            $og = $this->resultFromOg($lastHtml);
            if ($og) {
                return $og;
            }
        }

        throw new \RuntimeException('拼多多内容解析失败，请确认链接为 App 最新分享地址');
    }

    private function fetchPage(string $url): ?string
    {
        return HttpClient::get($url, $this->mobileHeaders('https://mobile.yangkeduo.com/'));
    }

    private function extractUrlParams(string ...$urls): array
    {
        $params = [];

        foreach ($urls as $url) {
            if ($url === '') {
                continue;
            }

            $parsed = parse_url($url);
            $query = $parsed['query'] ?? '';
            parse_str($query, $queryParams);
            $params = array_merge($params, $queryParams);

            if (!empty($queryParams['launch_url'])) {
                parse_str(parse_url(urldecode((string) $queryParams['launch_url']), PHP_URL_QUERY) ?? '', $nested);
                $params = array_merge($params, $nested);
            }

            foreach (['goods_id', 'goodsId', 'feed_id', 'feedId', 'refer_share_id'] as $key) {
                if (!empty($params[$key])) {
                    continue;
                }
                if (preg_match('#/(?:goods|goods1|goods2|duo_coupon_landing)\.html#i', $parsed['path'] ?? '')
                    && preg_match('/[?&]goods_id=(\d+)/', $url, $m)) {
                    $params['goods_id'] = $m[1];
                }
            }
        }

        return $params;
    }

    private function buildCandidateUrls(string $originalUrl, string $resolvedUrl, array $params): array
    {
        $urls = array_values(array_unique(array_filter([
            $resolvedUrl,
            $originalUrl,
        ])));

        $goodsId = $params['goods_id'] ?? $params['goodsId'] ?? null;
        if ($goodsId) {
            $query = http_build_query(array_filter([
                'goods_id' => $goodsId,
                'refer_share_id' => $params['refer_share_id'] ?? null,
                'refer_share_channel' => $params['refer_share_channel'] ?? null,
                'refer_share_uin' => $params['refer_share_uin'] ?? null,
                '_oak_share_detail_id' => $params['_oak_share_detail_id'] ?? null,
            ]));
            $urls[] = 'https://mobile.yangkeduo.com/goods.html?' . $query;
            $urls[] = 'https://mobile.yangkeduo.com/goods2.html?' . $query;
        }

        $feedId = $params['feed_id'] ?? $params['feedId'] ?? null;
        if ($feedId) {
            $urls[] = 'https://mobile.yangkeduo.com/video_share.html?feed_id=' . rawurlencode((string) $feedId);
            $urls[] = 'https://mobile.yangkeduo.com/pincard_video.html?feed_id=' . rawurlencode((string) $feedId);
        }

        return array_values(array_unique($urls));
    }

    private function parseFromHtml(string $html, string $pageUrl): ?array
    {
        $raw = $this->extractRawData($html);
        if ($raw) {
            $result = $this->buildFromRawData($raw);
            if ($result) {
                return $result;
            }
        }

        $payload = ['images' => [], 'video' => null, 'caption' => ''];
        $this->walkMediaJson($this->extractJsonCandidates($html), $payload);

        if (!$payload['video'] && empty($payload['images'])) {
            $this->extractMediaFromHtmlFragments($html, $payload);
        }

        $images = $this->uniqueImageUrls($payload['images']);
        $video = $payload['video'];
        $caption = trim($payload['caption']);

        if (!$caption) {
            $caption = $this->extractOgMeta($html)['title'] ?? '';
        }

        if ($video) {
            return $this->buildResult([
                'title'        => $caption,
                'video'        => $video,
                'cover'        => $images[0] ?? '',
                'images'       => $images,
                'type'         => 'video',
                'video_direct' => true,
            ]);
        }

        if (!empty($images)) {
            return $this->buildResult([
                'title'        => $caption,
                'images'       => $images,
                'cover'        => $images[0],
                'type'         => 'image',
                'video_direct' => true,
            ]);
        }

        return null;
    }

    private function fetchFromApis(array $params, string $referer): ?array
    {
        $goodsId = $params['goods_id'] ?? $params['goodsId'] ?? null;
        if (!$goodsId) {
            return null;
        }

        $headers = array_merge($this->mobileHeaders('https://mobile.yangkeduo.com/'), [
            'Accept: application/json, text/plain, */*',
            'Referer: ' . $referer,
            'X-Requested-With: XMLHttpRequest',
        ]);

        $query = http_build_query(array_filter([
            'goods_id' => $goodsId,
            'refer_share_id' => $params['refer_share_id'] ?? null,
        ]));

        foreach ([
            'https://mobile.yangkeduo.com/proxy/api/api/goods/detail?' . $query,
            'https://yangkeduo.com/proxy/api/api/goods/detail?' . $query,
        ] as $apiUrl) {
            $response = HttpClient::get($apiUrl, $headers);
            if (!$response) {
                continue;
            }

            $json = json_decode($response, true);
            if (!is_array($json)) {
                continue;
            }

            $result = $this->buildFromGoodsApi($json);
            if ($result) {
                return $result;
            }
        }

        $feedId = $params['feed_id'] ?? $params['feedId'] ?? null;
        if ($feedId) {
            foreach ([
                'https://mobile.yangkeduo.com/proxy/api/api/social/feed/detail?feed_id=' . rawurlencode((string) $feedId),
                'https://mobile.yangkeduo.com/proxy/api/api/oak/feed/detail?feed_id=' . rawurlencode((string) $feedId),
            ] as $apiUrl) {
                $response = HttpClient::get($apiUrl, $headers);
                if (!$response) {
                    continue;
                }
                $json = json_decode($response, true);
                if (!is_array($json)) {
                    continue;
                }
                $payload = ['images' => [], 'video' => null, 'caption' => ''];
                $this->walkMediaJson([$json], $payload, 0, true);
                $images = $this->uniqueImageUrls($payload['images']);
                if ($payload['video'] || !empty($images)) {
                    return $this->buildResult([
                        'title'        => $payload['caption'],
                        'video'        => $payload['video'],
                        'cover'        => $images[0] ?? '',
                        'images'       => $images,
                        'type'         => $payload['video'] ? 'video' : 'image',
                        'video_direct' => true,
                    ]);
                }
            }
        }

        return null;
    }

    private function buildFromRawData(array $raw): ?array
    {
        $store = $raw['store'] ?? ($raw['stores']['store'] ?? null);
        if (!is_array($store)) {
            $payload = ['images' => [], 'video' => null, 'caption' => ''];
            $this->walkMediaJson([$raw], $payload, 0, true);
            return $this->payloadToResult($payload);
        }

        if (!empty($store['initDataObj']['needLogin']) && empty($store['goods'])) {
            return null;
        }

        $goods = $store['goods'] ?? $store['goodsDetail'] ?? null;
        if (!is_array($goods)) {
            $payload = ['images' => [], 'video' => null, 'caption' => ''];
            $this->walkMediaJson([$store], $payload, 0, true);
            return $this->payloadToResult($payload);
        }

        $caption = trim($goods['goodsName'] ?? $goods['goods_name'] ?? $goods['shareDesc'] ?? '');
        $images = [];
        $video = null;

        foreach ([
            $goods['gallery'] ?? null,
            $goods['viewImageData'] ?? null,
            $goods['detailGallery'] ?? null,
            $goods['carouselGallery'] ?? null,
        ] as $gallery) {
            foreach ($this->normalizeGallery($gallery) as $url) {
                $images[] = $url;
            }
        }

        foreach ($this->normalizeGallery($goods['topGallery'] ?? null) as $url) {
            $images[] = $url;
        }

        foreach ($goods['videoGallery'] ?? $goods['video_gallery'] ?? [] as $item) {
            $url = $this->extractMediaUrl(is_array($item) ? ($item['video_url'] ?? $item['url'] ?? null) : $item);
            if ($url) {
                $video = $url;
                break;
            }
        }

        if (!$video) {
            $payload = ['images' => $images, 'video' => null, 'caption' => $caption];
            $this->walkMediaJson([$goods], $payload, 0, true);
            $images = $payload['images'];
            $video = $payload['video'] ?: $video;
            $caption = $payload['caption'] ?: $caption;
        }

        $images = $this->uniqueImageUrls($images);

        if (!$video && empty($images)) {
            return null;
        }

        return $this->buildResult([
            'title'        => $caption,
            'video'        => $video,
            'cover'        => $images[0] ?? '',
            'images'       => $images,
            'type'         => $video ? 'video' : 'image',
            'video_direct' => true,
        ]);
    }

    private function buildFromGoodsApi(array $json): ?array
    {
        if (isset($json['error_code']) && (int) $json['error_code'] !== 0) {
            return null;
        }

        $payload = ['images' => [], 'video' => null, 'caption' => ''];
        $this->walkMediaJson([$json], $payload, 0, true);

        return $this->payloadToResult($payload);
    }

    private function payloadToResult(array $payload): ?array
    {
        $images = $this->uniqueImageUrls($payload['images']);
        $video = $payload['video'];
        $caption = trim($payload['caption']);

        if (!$video && empty($images)) {
            return null;
        }

        return $this->buildResult([
            'title'        => $caption,
            'video'        => $video,
            'cover'        => $images[0] ?? '',
            'images'       => $images,
            'type'         => $video ? 'video' : 'image',
            'video_direct' => true,
        ]);
    }

    private function extractRawData(string $html): ?array
    {
        $pos = stripos($html, 'window.rawData');
        if ($pos === false) {
            return null;
        }

        $start = strpos($html, '{', $pos);
        if ($start === false) {
            return null;
        }

        $jsonText = $this->extractBalancedJson($html, $start);
        if (!$jsonText) {
            return null;
        }

        $data = json_decode($jsonText, true);

        return is_array($data) ? $data : null;
    }

    private function extractJsonCandidates(string $html): array
    {
        $candidates = [];

        if ($raw = $this->extractRawData($html)) {
            $candidates[] = $raw;
        }

        if (preg_match_all('/"video_url"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/', $html, $matches)) {
            foreach ($matches[0] as $snippet) {
                $json = json_decode('{' . $snippet . '}', true);
                if (is_array($json)) {
                    $candidates[] = $json;
                }
            }
        }

        return $candidates;
    }

    private function walkMediaJson(mixed $data, array &$payload, int $depth = 0, bool $preferGoodsFields = false): void
    {
        if ($depth > 18 || $data === null) {
            return;
        }

        if (is_array($data)) {
            if ($preferGoodsFields) {
                $caption = trim((string) ($data['goods_name'] ?? $data['goodsName'] ?? $data['desc'] ?? $data['title'] ?? $data['feed_desc'] ?? ''));
                if ($caption !== '' && $payload['caption'] === '') {
                    $payload['caption'] = $caption;
                }

                foreach (['video_url', 'videoUrl', 'play_url', 'playUrl', 'main_url', 'stream_url', 'mp4_url'] as $key) {
                    if (!empty($data[$key]) && is_string($data[$key]) && $this->looksLikeVideo($data[$key])) {
                        $payload['video'] = $this->normalizeMediaUrl($data[$key]);
                    }
                }

                foreach (['gallery', 'viewImageData', 'detailGallery', 'carouselGallery', 'topGallery', 'carousel_gallery_list', 'detail_gallery_list'] as $key) {
                    foreach ($this->normalizeGallery($data[$key] ?? null) as $url) {
                        $payload['images'][] = $url;
                    }
                }

                foreach (['videoGallery', 'video_gallery'] as $key) {
                    if (!is_array($data[$key] ?? null)) {
                        continue;
                    }
                    foreach ($data[$key] as $item) {
                        $url = $this->extractMediaUrl(is_array($item) ? ($item['video_url'] ?? $item['url'] ?? null) : $item);
                        if ($url && $this->looksLikeVideo($url)) {
                            $payload['video'] = $url;
                        }
                    }
                }

                foreach (['thumb_url', 'thumbUrl', 'cover', 'cover_url', 'coverUrl', 'image_url', 'imageUrl', 'url'] as $key) {
                    if (!empty($data[$key]) && is_string($data[$key]) && $this->looksLikeImage($data[$key])) {
                        $payload['images'][] = $this->normalizeMediaUrl($data[$key]);
                    }
                }
            }

            foreach ($data as $value) {
                if (is_array($value)) {
                    $this->walkMediaJson($value, $payload, $depth + 1, $preferGoodsFields);
                } elseif (is_string($value)) {
                    if ($this->looksLikeVideo($value)) {
                        $payload['video'] = $this->normalizeMediaUrl($value);
                    } elseif ($this->looksLikeImage($value)) {
                        $payload['images'][] = $this->normalizeMediaUrl($value);
                    }
                }
            }

            return;
        }

        if (is_string($data)) {
            if ($this->looksLikeVideo($data)) {
                $payload['video'] = $this->normalizeMediaUrl($data);
            } elseif ($this->looksLikeImage($data)) {
                $payload['images'][] = $this->normalizeMediaUrl($data);
            }
        }
    }

    private function extractMediaFromHtmlFragments(string $html, array &$payload): void
    {
        if (preg_match_all('/"(https?:\\\\\/\\\\\/[^"\\\\]+|https?:[^"\\\\]+)"/', $html, $matches)) {
            foreach ($matches[1] as $raw) {
                $url = $this->normalizeMediaUrl(stripcslashes($raw));
                if ($this->looksLikeVideo($url)) {
                    $payload['video'] = $url;
                } elseif ($this->looksLikeImage($url)) {
                    $payload['images'][] = $url;
                }
            }
        }

        if (preg_match_all('#https://[^"\s<>]+(?:pddpic|yangkeduo)[^"\s<>]+\.(?:jpg|jpeg|png|webp)(?:\?[^"\s<>]*)?#i', $html, $matches)) {
            foreach ($matches[0] as $url) {
                $url = $this->normalizeMediaUrl(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
                if ($this->looksLikeImage($url)) {
                    $payload['images'][] = $url;
                }
            }
        }

        if (!$payload['video'] && preg_match_all('#https://[^"\s<>]+\.mp4[^"\s<>]*#i', $html, $videos)) {
            foreach ($videos[0] as $url) {
                if ($this->looksLikeVideo($url)) {
                    $payload['video'] = $this->normalizeMediaUrl($url);
                    break;
                }
            }
        }
    }

    private function normalizeGallery(mixed $gallery): array
    {
        if ($gallery === null || $gallery === '') {
            return [];
        }

        $urls = [];

        if (is_string($gallery)) {
            foreach (preg_split('/[\s,]+/', $gallery) as $part) {
                $part = trim($part);
                if ($part !== '' && $this->looksLikeImage($part)) {
                    $urls[] = $this->normalizeMediaUrl($part);
                }
            }
            return $urls;
        }

        if (!is_array($gallery)) {
            return [];
        }

        foreach ($gallery as $item) {
            $url = $this->extractMediaUrl(is_array($item) ? ($item['url'] ?? $item['pic_url'] ?? $item) : $item);
            if ($url && $this->looksLikeImage($url)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private function looksLikeVideo(string $url): bool
    {
        if (!$this->isLikelyMediaUrl($url)) {
            return false;
        }

        return (bool) preg_match('/\.(?:mp4|mov|m3u8)(\?|$)/i', $url);
    }

    private function looksLikeImage(string $url): bool
    {
        if (!$this->isLikelyMediaUrl($url)) {
            return false;
        }

        if ($this->isJunkPddImage($url)) {
            return false;
        }

        return (bool) preg_match('/\.(?:jpg|jpeg|png|webp|gif)(\?|$)/i', $url)
            || str_contains($url, 'mms-goods-image')
            || str_contains($url, 'gaudit-image')
            || str_contains($url, '/goods/images/');
    }

    private function isLikelyMediaUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return false;
        }

        return str_contains($url, 'pddpic')
            || str_contains($url, 'yangkeduo')
            || str_contains($url, 'pinduoduo')
            || str_contains($url, 'video')
            || preg_match('/\.(?:mp4|mov|m3u8|jpg|jpeg|png|webp)/i', $url);
    }

    private function isJunkPddImage(string $url): bool
    {
        $patterns = [
            'share_logo',
            'base/logo',
            'favicon',
            'oms_img_ng',
            'timeline_card',
            'promotion/index',
            '/117/q/80',
            'commimg.pddpic.com/oms',
            'static.pddpic.com/assets',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($url, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function isWechatOnlyPage(string $html): bool
    {
        return str_contains($html, '请在微信客户端打开链接');
    }

    private function detectNeedLogin(string $html): bool
    {
        if (str_contains($html, '"needLogin":true') || str_contains($html, '"needLogin": true')) {
            return true;
        }

        return str_contains($html, 'login.html')
            && (str_contains($html, '手机登录') || str_contains($html, '打开拼多多'));
    }
}
