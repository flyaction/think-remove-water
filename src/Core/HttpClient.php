<?php
namespace Flyaction\ThinkRemoveWater\Core;

class HttpClient
{
    /** @var \CurlShareHandle|null */
    private static $shareHandle = null;

    private static function shareHandle(): \CurlShareHandle
    {
        if (self::$shareHandle === null) {
            self::$shareHandle = curl_share_init();
            curl_share_setopt(self::$shareHandle, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
            curl_share_setopt(self::$shareHandle, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);
        }
        return self::$shareHandle;
    }

    private static function createHandle(): \CurlHandle
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SHARE, self::shareHandle());
        curl_setopt($ch, CURLOPT_FORBID_REUSE, false);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, false);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        if (defined('CURLOPT_TCP_KEEPALIVE')) {
            curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
        }
        return $ch;
    }

    private static function defaultHeaders(): array
    {
        return [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
        ];
    }

    public static function get(string $url, array $headers = [], int $timeout = 15): ?string
    {
        $ch = self::createHandle();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_MAXFILESIZE    => 6 * 1024 * 1024,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => array_merge(self::defaultHeaders(), $headers),
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($httpCode >= 200 && $httpCode < 400) ? ($response ?: null) : null;
    }

    public static function post(string $url, $data = null, array $headers = [], int $timeout = 15): ?string
    {
        $ch = self::createHandle();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => array_merge([
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ], $headers),
        ];
        if ($data !== null) {
            $opts[CURLOPT_POSTFIELDS] = is_array($data) ? http_build_query($data) : $data;
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response ?: null;
    }

    public static function getFinalUrl(string $url, array $headers = []): ?string
    {
        return self::resolveMediaUrl($url, $headers);
    }

    /** 跟随重定向，不下载正文（避免大视频占满内存） */
    public static function resolveMediaUrl(string $url, array $headers = [], int $timeout = 20): ?string
    {
        $final = self::curlResolveUrl($url, $headers, $timeout, true);
        if ($final) {
            return $final;
        }
        return self::curlResolveUrl($url, $headers, $timeout, false);
    }

    /** 抖音 play/playwm 接口 → 直链 CDN */
    public static function resolveDouyinPlayUrl(string $url, string $referer): ?string
    {
        $url = str_replace('playwm', 'play', trim($url));
        if ($url === '' || !preg_match('#aweme\.snssdk\.com/aweme/v\d+/#i', $url)) {
            return $url !== '' ? $url : null;
        }

        $referers = array_values(array_unique([
            $referer,
            'https://www.douyin.com/',
            'https://www.iesdouyin.com/',
        ]));

        foreach ($referers as $ref) {
            $headers = [
                'Referer: ' . $ref,
                'Origin: ' . rtrim($ref, '/'),
                'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
                'Accept: */*',
            ];

            $resolved = self::followRedirectChain($url, $headers, 8);
            if ($resolved && !preg_match('#aweme\.snssdk\.com#i', $resolved)) {
                return $resolved;
            }

            $resolved = self::resolveMediaUrl($url, $headers, 25);
            if ($resolved && !preg_match('#aweme\.snssdk\.com#i', $resolved)) {
                return $resolved;
            }

            $fromBody = self::extractCdnFromPlayApi($url, $headers);
            if ($fromBody) {
                return $fromBody;
            }
        }

        return null;
    }

    /** 手动跟随 302，拿到最终 CDN 地址 */
    private static function followRedirectChain(string $url, array $headers, int $maxHops = 8): ?string
    {
        $current = $url;
        for ($hop = 0; $hop < $maxHops; $hop++) {
            $ch = self::createHandle();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $current,
                CURLOPT_NOBODY         => true,
                CURLOPT_HEADER         => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER     => array_merge([
                    'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
                    'Accept: */*',
                ], $headers),
            ]);
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code >= 300 && $code < 400 && is_string($raw) && preg_match('/^Location:\s*(\S+)/im', $raw, $m)) {
                $next = trim($m[1]);
                if (str_starts_with($next, '/')) {
                    $parts = parse_url($current);
                    $next = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . $next;
                }
                $current = $next;
                continue;
            }

            if ($code >= 200 && $code < 400 && !preg_match('#aweme\.snssdk\.com#i', $current)) {
                return $current;
            }

            break;
        }

        return null;
    }

    /** play 接口 JSON/文本里提取 CDN 直链 */
    private static function extractCdnFromPlayApi(string $url, array $headers): ?string
    {
        $ch = self::createHandle();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_RANGE          => '0-4095',
            CURLOPT_HTTPHEADER     => array_merge([
                'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
                'Accept: */*',
            ], $headers),
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if (!is_string($body) || $body === '' || $code >= 400) {
            return null;
        }

        if (str_starts_with($body, '{')) {
            $json = json_decode($body, true);
            if (is_array($json)) {
                foreach (self::collectUrlsFromArray($json) as $candidate) {
                    if (!preg_match('#aweme\.snssdk\.com#i', $candidate)) {
                        return $candidate;
                    }
                }
            }
        }

        if (str_contains($type, 'video') || str_starts_with($body, "\x00\x00\x00") || str_contains($body, 'ftyp')) {
            return null;
        }

        if (preg_match('#https?://[^"\s<>]+(?:douyinvod|z1cdn|zjcdn|bytecdn|ibytedtos)[^"\s<>]*#i', $body, $m)) {
            return $m[0];
        }

        return null;
    }

    /** @return string[] */
    private static function collectUrlsFromArray(array $data): array
    {
        $urls = [];
        $walk = function ($node) use (&$walk, &$urls): void {
            if (is_string($node)) {
                if (preg_match('#^https?://#i', $node)) {
                    $urls[] = $node;
                }
                return;
            }
            if (!is_array($node)) {
                return;
            }
            foreach ($node as $key => $value) {
                if (is_string($key) && in_array($key, ['url_list', 'download_addr', 'play_addr'], true) && is_array($value)) {
                    foreach ($value as $item) {
                        if (is_string($item) && preg_match('#^https?://#i', $item)) {
                            $urls[] = $item;
                        }
                    }
                }
                $walk($value);
            }
        };
        $walk($data);
        return array_values(array_unique($urls));
    }

    private static function curlResolveUrl(string $url, array $headers, int $timeout, bool $headOnly): ?string
    {
        $ch = self::createHandle();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => array_merge([
                'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
                'Accept: */*',
            ], $headers),
        ];
        if ($headOnly) {
            $opts[CURLOPT_NOBODY] = true;
        } else {
            $opts[CURLOPT_RANGE] = '0-0';
        }
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            return null;
        }
        if ($finalUrl && $finalUrl !== $url) {
            return $finalUrl;
        }
        if (!$headOnly && (str_contains($contentType, 'video') || str_contains($contentType, 'octet-stream'))) {
            return $url;
        }
        return null;
    }
}
