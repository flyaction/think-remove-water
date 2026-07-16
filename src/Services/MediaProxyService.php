<?php
namespace Flyaction\ThinkRemoveWater\Services;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class MediaProxyService
{
    private const TTL = 7200;

    private static function cacheDir(): string
    {
        $dir = __DIR__ . '/../../storage/media_tokens';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    public static function isDirectDownloadPlatform(string $platform): bool
    {
        $config = require __DIR__ . '/../../config/app.php';
        $list = $config['direct_download_platforms']
            ?? $config['direct_media_platforms']
            ?? [];
        $platform = self::sanitizePlatform($platform);
        return in_array($platform, $list, true);
    }

    /** @deprecated use isDirectDownloadPlatform */
    public static function isDirectMediaPlatform(string $platform): bool
    {
        return self::isDirectDownloadPlatform($platform);
    }

    /** 解析为可直链下载的 CDN 地址（抖音 play 接口等会在此展开） */
    public static function resolveDownloadUrl(string $url, string $platform): string
    {
        $url = self::normalizeUrl($url);
        $platform = self::sanitizePlatform($platform);
        return self::resolveStreamUrl($url, $platform);
    }

    /** 下载跳转地址（302 到 CDN，不代理视频流） */
    public static function buildDownloadJumpUrl(string $token, ?string $baseUrl = null): string
    {
        $token = preg_replace('/[^a-f0-9]/', '', strtolower($token)) ?? '';
        $query = 't=' . $token . '&dl=1&jump=1';
        $path = '/api/media.php?' . $query;
        if ($baseUrl !== null && $baseUrl !== '') {
            return rtrim($baseUrl, '/') . $path;
        }
        return $path;
    }

    /**
     * 302 跳转到 CDN 直链并提示下载（服务器只解析地址，不转发视频流量）
     */
    public static function redirectDownload(string $url, string $platform, string $filename = 'media.mp4'): void
    {
        while (ob_get_level()) {
            ob_end_clean();
        }

        $url = self::resolveDownloadUrl($url, $platform);

        try {
            self::assertAllowedUrl($url);
        } catch (\Throwable $e) {
            http_response_code(502);
            header('Content-Type: text/plain; charset=utf-8');
            header('Access-Control-Allow-Origin: *');
            echo '媒体地址不可用';
            return;
        }

        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename) ?: 'media.mp4';

        header('Access-Control-Allow-Origin: *');
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Location: ' . $url, true, 302);
        exit;
    }

    public static function register(string $url, string $platform = 'douyin'): string
    {
        $url = self::normalizeUrl($url);
        $platform = self::sanitizePlatform($platform);
        // 注册时保留 play 地址；CDN 在播放/下载时再解析，避免直链过期
        if (!preg_match('#aweme\.snssdk\.com#i', $url)) {
            $url = self::maybeResolvePlayApi($url, $platform);
        }
        self::assertAllowedUrl($url);

        self::cleanup();

        $token = bin2hex(random_bytes(16));
        $file = self::cacheDir() . '/' . $token . '.json';
        file_put_contents($file, json_encode([
            'url'      => $url,
            'platform' => $platform,
            'expires'  => time() + self::TTL,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $token;
    }

    /** @return array{url:string,platform:string}|null */
    public static function resolve(string $token): ?array
    {
        $token = preg_replace('/[^a-f0-9]/', '', strtolower($token)) ?? '';
        if (strlen($token) !== 32) {
            return null;
        }

        $file = self::cacheDir() . '/' . $token . '.json';
        if (!is_file($file)) {
            return null;
        }

        $meta = json_decode((string) file_get_contents($file), true);
        if (!is_array($meta) || empty($meta['url']) || ($meta['expires'] ?? 0) < time()) {
            @unlink($file);
            return null;
        }

        return [
            'url'      => (string) $meta['url'],
            'platform' => self::sanitizePlatform($meta['platform'] ?? 'douyin'),
        ];
    }

    public static function stream(string $url, string $platform, bool $download = false): void
    {
        $url = self::normalizeUrl($url);
        $platform = self::sanitizePlatform($platform);
        $url = self::resolveStreamUrl($url, $platform);

        try {
            self::assertAllowedUrl($url);
        } catch (\Throwable $e) {
            while (ob_get_level()) {
                ob_end_clean();
            }
            http_response_code(502);
            header('Content-Type: text/plain; charset=utf-8');
            header('Access-Control-Allow-Origin: *');
            echo '媒体地址不可用';
            return;
        }

        $referer = self::refererFor($platform);
        $isVideo = self::looksLikeVideo($url);
        $followRedirects = (bool) preg_match('#aweme\.snssdk\.com/aweme/v\d+/#i', $url);
        $accept = ($platform === 'xiaohongshu' && !$isVideo)
            ? 'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8'
            : 'Accept: */*';
        $reqHeaders = [
            'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
            'Referer: ' . $referer,
            'Origin: ' . rtrim($referer, '/'),
            $accept,
        ];
        if (!empty($_SERVER['HTTP_RANGE'])) {
            $reqHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
        }

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges');
        if ($download) {
            $ext = 'mp4';
            if (preg_match('/\.(m4s)(\?|$)/i', $url)) {
                $ext = 'm4s';
            } elseif (preg_match('/\.(flv)(\?|$)/i', $url)) {
                $ext = 'flv';
            } elseif (preg_match('/\.(m3u8)(\?|$)/i', $url)) {
                $ext = 'm3u8';
            } elseif (preg_match('/\.(jpe?g|png|webp|gif)(\?|$)/i', $url, $em)) {
                $ext = strtolower($em[1]);
            }
            header('Content-Disposition: attachment; filename="media_' . time() . '.' . $ext . '"');
        }

        $forwardHeaders = ['content-type', 'content-length', 'content-range', 'accept-ranges'];
        $gotContentType = false;
        $gotAcceptRanges = false;
        $passHeaders = !$followRedirects;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_MAXREDIRS      => $followRedirects ? 8 : 0,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER     => $reqHeaders,
            CURLOPT_HEADERFUNCTION => function ($curl, $header) use ($forwardHeaders, $url, $isVideo, &$gotContentType, &$gotAcceptRanges, &$passHeaders) {
                $len = strlen($header);
                $trim = trim($header);

                if (stripos($trim, 'HTTP/') === 0) {
                    if (preg_match('/HTTP\/[\d.]+\s+(\d+)/', $trim, $m)) {
                        $code = (int) $m[1];
                        $passHeaders = ($code >= 200 && $code < 400);
                        if ($passHeaders) {
                            http_response_code($code);
                        }
                    }
                    return $len;
                }

                if (!$passHeaders) {
                    return $len;
                }

                $parts = explode(':', $trim, 2);
                if (count($parts) !== 2) {
                    return $len;
                }

                $name = strtolower($parts[0]);
                $value = trim($parts[1]);

                if ($name === 'content-type') {
                    $gotContentType = true;
                    if ($isVideo || self::looksLikeVideo($url)) {
                        $value = self::normalizeVideoContentType($value, $url);
                    }
                    header('Content-Type: ' . $value, true);
                    return $len;
                }

                if ($name === 'accept-ranges') {
                    $gotAcceptRanges = true;
                    header('Accept-Ranges: ' . $value, true);
                    return $len;
                }

                if (in_array($name, $forwardHeaders, true)) {
                    header($parts[0] . ':' . $parts[1], false);
                }
                return $len;
            },
            CURLOPT_WRITEFUNCTION => function ($curl, $data) {
                echo $data;
                if (function_exists('flush')) {
                    flush();
                }
                return strlen($data);
            },
        ]);

        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!$ok || $code >= 400) {
            if (!headers_sent()) {
                http_response_code($code >= 400 ? $code : 502);
                header('Content-Type: text/plain; charset=utf-8');
                echo '媒体拉取失败(' . ($code ?: 502) . ')' . ($curlError ? ': ' . $curlError : '');
            }
        }
    }

    private static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }
        return $url;
    }

    private static function sanitizePlatform(string $platform): string
    {
        $platform = preg_replace('/[^a-z0-9_\-]/', '', strtolower($platform)) ?? 'douyin';
        return $platform !== '' ? $platform : 'douyin';
    }

    private static function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \InvalidArgumentException('invalid url');
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('invalid scheme');
        }

        $host = strtolower($parts['host']);
        $full = $host . strtolower($parts['path'] ?? '');

        if (self::isBlockedHost($host)) {
            throw new \InvalidArgumentException('domain not allowed');
        }

        if (preg_match('#douyinvod|byte(?:img|cdn|acctimg)|ibytedtos|tiktok(?:v|cdn)|muscdn|amemv|toutiaovod|365yg|ixigua|huoshan#i', $full)) {
            return;
        }

        $allowed = [
            'douyinvod', 'douyinpic', 'douyinstatic', 'douyincdn', 'amemv', 'zjcdn', 'z1cdn', 'zijiecdn', 'bytecdn', 'byteimg', 'bytedance', 'bytescm',
            'ibytedtos', 'ibyteimg', 'byteacctimg', 'bytednsdoc', 'pstatp', 'snssdk', 'ixigua', 'goofy', 'tos-cn', 'tiktokv', 'tiktokcdn', 'muscdn',
            'kuaishou', 'gifshow', 'chenzhongtech', 'yximgs', 'kwimgs', 'ks-cdn', 'ksapisrv',
            'hdslb', 'bilibili', 'bilivideo', 'upos', 'akamaized', 'bfs',
            'xhscdn', 'xiaohongshu', 'ci.xiaohongshu', 'sns-webpic', 'sns-img', 'sns-video', 'sns-avatar',
            'picasso', 'edith', 'rednotecdn', 'ros-preview', 'ros-upload',
            'weibocdn', 'weibo', 'sinaimg', 'sina',
            'pipix', 'hulushequ', 'ippzone', 'pipigx', 'pddpic', 'yangkeduo', 'pinduoduo', 'weishi', 'qq.com',
            'izuiyou', 'pearvideo', 'quanmin', 'doupai', 'oasis', 'kg.qq.com', '6.cn', 'meipai', 'xinpianchang', 'xpc.com',
            'haokan', 'baidu', 'bdstatic', 'huya', 'acfun', 'zhihu', 'zhimg', 'immomo', 'tantan',
            'googlevideo', 'cdninstagram', 'fbcdn', 'twimg', 'ytimg', 'volccdn',
            'pstatp', 'toutiaovod', 'toutiaoimg', '365yg', 'huoshan', 'qishui',
            'dreamina', 'vlabstatic', 'heycan', 'jianying', 'flow-imagex', 'a9rns2rl98',
            'volccdn', 'lf-flow-web-cdn',
            'myqcloud', 'aliyuncs', 'qpic', 'gtimg', 'wxapp', 'qlogo',
        ];

        foreach ($allowed as $pattern) {
            if (strpos($host, $pattern) !== false) {
                return;
            }
        }

        throw new \InvalidArgumentException('domain not allowed: ' . $host);
    }

    private static function looksLikeVideo(string $url): bool
    {
        if (preg_match('#\.m3u8(\?|$)#i', $url)) {
            return true;
        }
        if (preg_match('#\.mp4(\?|$)|\.m4s(\?|$)|\.flv(\?|$)|\.mov(\?|$)|\.webm(\?|$)#i', $url)) {
            return true;
        }
        return (bool) preg_match(
            '#douyinvod|douyinpic|z1cdn|zjcdn|bytecdn|ixigua|toutiaovod|365yg|bilivideo|sns-video|aweme\.snssdk|video/#i',
            $url
        );
    }

    private static function videoContentTypeFor(string $url): string
    {
        if (preg_match('#\.m3u8(\?|$)#i', $url)) {
            return 'application/vnd.apple.mpegurl';
        }
        if (preg_match('#\.webm(\?|$)#i', $url)) {
            return 'video/webm';
        }
        return 'video/mp4';
    }

    private static function normalizeVideoContentType(string $contentType, string $url): string
    {
        $contentType = strtolower(trim(explode(';', $contentType)[0]));
        if ($contentType === '' || $contentType === 'application/octet-stream' || $contentType === 'binary/octet-stream') {
            return self::videoContentTypeFor($url);
        }
        if (str_starts_with($contentType, 'video/') || $contentType === 'application/vnd.apple.mpegurl') {
            return $contentType;
        }
        return self::videoContentTypeFor($url);
    }

    private static function guessContentType(string $url): void
    {
        if (self::looksLikeVideo($url)) {
            header('Content-Type: ' . self::videoContentTypeFor($url));
            return;
        }
        self::guessImageContentType($url);
    }

    private static function guessImageContentType(string $url): void
    {
        if (preg_match('/\.(jpe?g)(\?|$)/i', $url)) {
            header('Content-Type: image/jpeg');
        } elseif (preg_match('/\.(png)(\?|$)/i', $url)) {
            header('Content-Type: image/png');
        } elseif (preg_match('/\.(gif)(\?|$)/i', $url)) {
            header('Content-Type: image/gif');
        } elseif (preg_match('/(?:\.webp|!nc_n_webp)/i', $url)) {
            header('Content-Type: image/webp');
        }
    }

    private static function refererFor(string $platform): string
    {
        $map = [
            'douyin' => 'https://www.douyin.com/', 'channels' => 'https://channels.weixin.qq.com/',
            'jimeng' => 'https://jimeng.jianying.com/', 'doubao' => 'https://www.doubao.com/',
            'tiktok' => 'https://www.tiktok.com/', 'kuaishou' => 'https://www.kuaishou.com/',
            'bilibili' => 'https://www.bilibili.com/', 'xiaohongshu' => 'https://www.xiaohongshu.com/',
            'weibo' => 'https://weibo.com/', 'pipixia' => 'https://hulushequ.com/',
            'pinduoduo' => 'https://mobile.yangkeduo.com/', 'toutiao' => 'https://www.toutiao.com/',
            'huoshan' => 'https://www.huoshan.com/', 'xigua' => 'https://www.ixigua.com/',
            'weishi' => 'https://weishi.qq.com/', 'zuiyou' => 'https://www.izuiyou.com/',
            'zhihu' => 'https://www.zhihu.com/', 'haokan' => 'https://haokan.baidu.com/',
            'baidu' => 'https://www.baidu.com/', 'huya' => 'https://www.huya.com/',
            'acfun' => 'https://www.acfun.cn/', 'qishui' => 'https://www.douyin.com/',
            'momo' => 'https://www.immomo.com/', 'tantan' => 'https://www.tantanapp.com/',
        ];
        return $map[$platform] ?? 'https://www.douyin.com/';
    }

    private static function resolveStreamUrl(string $url, string $platform): string
    {
        if (preg_match('#aweme\.snssdk\.com/aweme/v\d+/#i', $url)) {
            $referer = self::refererFor($platform);
            $resolved = HttpClient::resolveDouyinPlayUrl($url, $referer);
            if ($resolved && !preg_match('#aweme\.snssdk\.com#i', $resolved)) {
                return $resolved;
            }
            return str_replace('playwm', 'play', $url);
        }

        if (preg_match('#aweme\.snssdk\.com#i', $url)) {
            return str_replace('playwm', 'play', $url);
        }

        return self::maybeResolvePlayApi($url, $platform);
    }

    private static function maybeResolvePlayApi(string $url, string $platform): string
    {
        if (!preg_match('#aweme\.snssdk\.com/aweme/v\d+/#i', $url)) {
            return $url;
        }
        $referer = self::refererFor($platform);
        if (in_array($platform, ['douyin', 'qishui', 'huoshan', 'xigua', 'toutiao'], true)) {
            $resolved = HttpClient::resolveDouyinPlayUrl($url, $referer);
            if ($resolved) {
                return $resolved;
            }
        }
        $url = str_replace('playwm', 'play', $url);
        $resolved = HttpClient::resolveMediaUrl($url, [
            'Referer: ' . $referer,
            'Origin: ' . rtrim($referer, '/'),
        ]);
        return $resolved ?: $url;
    }

    private static function isBlockedHost(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return true;
        }
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }
        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    private static function cleanup(): void
    {
        $dir = self::cacheDir();
        $now = time();
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $meta = json_decode((string) @file_get_contents($file), true);
            if (!is_array($meta) || ($meta['expires'] ?? 0) < $now) {
                @unlink($file);
            }
        }
    }
}
