<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class KuaishouParser extends BaseParser
{
    protected array $domains = [
        'kuaishou.com', 'gifshow.com', 'chenzhongtech.com',
        'kuaishou.cn', 'yximgs.com',
    ];

    public function getPlatform(): string
    {
        return 'kuaishou';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);
        $photoId = $this->extractPhotoId($url);
        $headers = $this->mobileHeaders('https://www.kuaishou.com/');

        // 方案1：移动端作品页（c.kuaishou.com / chenzhongtech）
        if ($photoId) {
            $candidates = [
                "https://c.kuaishou.com/fw/photo/{$photoId}",
                "https://v.m.chenzhongtech.com/fw/photo/{$photoId}",
                "https://www.kuaishou.com/short-video/{$photoId}",
            ];
            foreach ($candidates as $pageUrl) {
                $html = $this->fetchWithCookie($pageUrl, $headers);
                if ($html) {
                    $result = $this->parseFromHtml($html);
                    if ($result) {
                        return $this->ensureCover($result, $html);
                    }
                }
            }
        }

        // 方案2：原始分享链接页面
        $html = $this->fetchWithCookie($url, $headers);
        if ($html) {
            if (!$photoId) {
                $photoId = $this->extractPhotoIdFromHtml($html);
            }
            $result = $this->parseFromHtml($html);
            if ($result) {
                return $this->ensureCover($result, $html);
            }
        }

        // 方案3：REST 接口
        if ($photoId) {
            $result = $this->parseFromRestApi($photoId, $headers);
            if ($result) {
                return $result;
            }
        }

        throw new \RuntimeException('无法解析快手视频，请使用最新分享链接');
    }

    private function extractPhotoId(string $url): ?string
    {
        return $this->extractId($url, [
            '/fw\/photo\/([^?&\/]+)/',
            '/short-video\/([^?&\/]+)/',
            '/photo\/([^?&\/]+)/',
            '/[?&]photoId=([^&]+)/',
            '/[?&]photo_id=([^&]+)/',
        ]);
    }

    private function extractPhotoIdFromHtml(string $html): ?string
    {
        if (preg_match('/"photoId"\s*:\s*"([^"]+)"/', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/id="hide-pagedata"[^>]*data-pagedata="[^"]*"photoId&quot;:&quot;([^&]+)&quot;/', $html, $m)) {
            return $m[1];
        }
        return null;
    }

    private function fetchWithCookie(string $url, array $headers): ?string
    {
        $jar = tempnam(sys_get_temp_dir(), 'ks_cookie_');
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_COOKIEJAR      => $jar,
            CURLOPT_COOKIEFILE     => $jar,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        @unlink($jar);
        return $body ?: null;
    }

    private function parseFromHtml(string $html): ?array
    {
        $decoded = htmlspecialchars_decode($html, ENT_QUOTES);

        // window.pageData = {...}
        if (preg_match('/window\.pageData\s*=\s*(\{[\s\S]*?\})\s*;?\s*<\/script>/i', $decoded, $m)) {
            $data = json_decode($m[1], true);
            if ($data) {
                $result = $this->buildFromPageData($data);
                if ($result) {
                    return $result;
                }
            }
        }

        // hide-pagedata
        if (preg_match('/id="hide-pagedata"[^>]*data-pagedata="([^"]+)"/', $html, $m)) {
            $json = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            $data = json_decode($json, true);
            if ($data) {
                $result = $this->buildFromPageData(['video' => $data] + $data);
                if ($result) {
                    return $result;
                }
            }
        }

        // 直接匹配 srcNoMark（无水印）
        if (preg_match('/"srcNoMark"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/', $decoded, $m)) {
            $video = stripcslashes($m[1]);
            $poster = '';
            $title = '';
            if (preg_match('/"poster"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/', $decoded, $pm)) {
                $poster = stripcslashes($pm[1]);
            }
            if (preg_match('/"caption"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/', $decoded, $tm)) {
                $title = stripcslashes($tm[1]);
            }
            if ($video) {
                return $this->buildResult([
                    'title' => $title,
                    'cover' => $poster,
                    'video' => $video,
                    'type'  => 'video',
                ]);
            }
        }

        // INIT_STATE / Apollo（新版页面）
        $state = $this->extractJson($decoded, '/window\.INIT_STATE\s*=\s*(\{[\s\S]*?\});/s')
              ?? $this->extractJson($decoded, '/window\.__APOLLO_STATE__\s*=\s*(\{[\s\S]*?\});/s');
        if ($state) {
            $result = $this->parseFromInitState($state);
            if ($result) {
                return $result;
            }
        }

        // 兜底：页面内 mp4 直链（upic 无水印）
        if (preg_match('/https?:\/\/[^"\']+?\.yximgs\.com\/upic\/[^"\']+\.mp4/i', $decoded, $m)) {
            $og = $this->extractOgMeta($html);
            $cover = $this->extractCoverFromHtml($html) ?? $og['cover'] ?? '';
            return $this->buildResult([
                'title' => $og['title'],
                'cover' => $cover,
                'video' => $m[0],
                'type'  => 'video',
            ]);
        }

        return $this->resultFromOg($html);
    }

    private function buildFromPageData(array $data): ?array
    {
        $video = $data['video'] ?? $data;
        if (!is_array($video)) {
            return null;
        }

        $videoUrl = $video['srcNoMark'] ?? $video['photoUrl'] ?? $video['mainMvUrls'][0]['url'] ?? null;
        $images = [];
        $cdn = $video['imageCDN'] ?? '';

        if (!empty($video['images']) && is_array($video['images'])) {
            foreach ($video['images'] as $img) {
                if (is_string($img)) {
                    $images[] = str_starts_with($img, 'http') ? $img : 'https://' . $cdn . ltrim($img, '/');
                } elseif (is_array($img)) {
                    $path = $img['path'] ?? $img['url'] ?? '';
                    if ($path) {
                        $images[] = str_starts_with($path, 'http') ? $path : 'https://' . $cdn . ltrim($path, '/');
                    }
                }
            }
        }

        if (!empty($video['atlas']['list']) && $cdn) {
            foreach ($video['atlas']['list'] as $path) {
                $images[] = 'https://' . $cdn . ltrim($path, '/');
            }
        }

        $type = $videoUrl ? 'video' : (!empty($images) ? 'image' : 'video');
        $music = null;
        if (!empty($video['audio']) && $cdn) {
            $music = 'https://' . $cdn . ltrim($video['audio'], '/');
        }

        if (!$videoUrl && empty($images)) {
            return null;
        }

        return $this->buildResult([
            'title'  => $video['caption'] ?? $data['share']['title'] ?? '',
            'author' => $data['user']['name'] ?? $video['userName'] ?? '',
            'cover'  => $this->extractMediaUrl($video['poster'] ?? null)
                     ?? $this->extractMediaUrl($video['coverUrls'][0] ?? null)
                     ?? ($images[0] ?? ''),
            'type'   => $type,
            'video'  => $videoUrl,
            'images' => array_filter($images),
            'music'  => $music,
        ]);
    }

    private function parseFromInitState(array $state): ?array
    {
        foreach ($state as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            if (isset($value['photo']) || isset($value['atlas'])) {
                $photo = $value['photo'] ?? [];
                $atlas = $value['atlas'] ?? null;
                $videoUrl = $photo['mainMvUrls'][0]['url'] ?? $photo['photoUrl'] ?? null;
                $images = [];
                if ($atlas && !empty($atlas['list'])) {
                    $cdn = $atlas['cdn'][0] ?? '';
                    foreach ($atlas['list'] as $path) {
                        $images[] = 'https://' . $cdn . ltrim($path, '/');
                    }
                }
                if ($videoUrl || $images) {
                    return $this->buildResult([
                        'title'  => $photo['caption'] ?? '',
                        'author' => $photo['userName'] ?? '',
                        'cover'  => $photo['coverUrls'][0]['url'] ?? ($images[0] ?? ''),
                        'type'   => $videoUrl ? 'video' : 'image',
                        'video'  => $videoUrl,
                        'images' => $images,
                    ]);
                }
            }
            if (isset($value['photoUrl']) || isset($value['mainMvUrls'])) {
                return $this->buildFromPageData(['video' => $value]);
            }
        }
        return null;
    }

    private function parseFromRestApi(string $photoId, array $headers): ?array
    {
        $apiUrl = "https://www.kuaishou.com/rest/wd/photo/info?photoId={$photoId}&pcursor=";
        $res = $this->fetchWithCookie($apiUrl, $headers);
        if (!$res) {
            return null;
        }

        $json = json_decode($res, true);
        if (($json['result'] ?? 0) !== 1) {
            return null;
        }

        $photo = $json['photo'] ?? $json['atlas'] ?? null;
        if (!$photo) {
            return null;
        }

        return $this->buildFromPageData(['video' => $photo, 'user' => ['name' => $photo['userName'] ?? '']]);
    }
}
