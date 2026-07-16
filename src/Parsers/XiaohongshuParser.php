<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class XiaohongshuParser extends BaseParser
{
    protected array $domains = ['xiaohongshu.com', 'xhslink.com'];

    public function getPlatform(): string
    {
        return 'xiaohongshu';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);
        $noteId = $this->extractNoteId($url);
        $headers = $this->mobileHeaders('https://www.xiaohongshu.com/');

        $html = $this->fetchPage($url, $headers);
        if (!$html) {
            throw new \RuntimeException('无法获取小红书页面');
        }

        if (!$noteId) {
            $noteId = $this->extractNoteIdFromHtml($html);
        }

        $state = $this->parseInitialState($html);
        if ($state) {
            $note = $this->findNote($state, $noteId);
            if ($note) {
                $result = $this->buildFromNote($note);
                if ($result['video'] || !empty($result['images'])) {
                    return $this->ensureCover($result, $html);
                }
            }
        }

        $fallback = $this->parseFromHtmlFragments($html);
        if ($fallback && ($fallback['video'] || !empty($fallback['images']))) {
            return $this->ensureCover($fallback, $html);
        }

        $og = $this->resultFromOg($html);
        if ($og && ($og['video'] || !empty($og['images']))) {
            return $og;
        }

        throw new \RuntimeException('无法解析小红书内容，请使用最新分享链接');
    }

    private function extractNoteId(string $url): ?string
    {
        return $this->extractId($url, [
            '/explore\/([a-f0-9]+)/i',
            '/discovery\/item\/([a-f0-9]+)/i',
            '/item\/([a-f0-9]+)/i',
            '/note\/([a-f0-9]+)/i',
            '/[?&]noteId=([a-f0-9]+)/i',
            '/[?&]note_id=([a-f0-9]+)/i',
        ]);
    }

    private function extractNoteIdFromHtml(string $html): ?string
    {
        if (preg_match('/"noteId"\s*:\s*"([a-f0-9]+)"/i', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/"firstNoteId"\s*:\s*"([a-f0-9]+)"/i', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/noteDetailMap["\']?\s*:\s*\{["\']([a-f0-9]+)["\']/', $html, $m)) {
            return $m[1];
        }
        return null;
    }

    private function fetchPage(string $url, array $headers): ?string
    {
        $jar = tempnam(sys_get_temp_dir(), 'xhs_cookie_');
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

    private function parseInitialState(string $html): ?array
    {
        if (!preg_match('/window\.__INITIAL_STATE__\s*=\s*(.+?)(?=<\/script>)/s', $html, $m)) {
            return null;
        }

        $json = trim($m[1]);
        $json = preg_replace('/:undefined\b/', ':"undefined"', $json);
        $json = str_replace(['\u002F', '\u0026'], ['/', '&'], $json);
        $json = rtrim($json, ';');

        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    private function findNote(array $data, ?string $noteId): ?array
    {
        $map = $data['note']['noteDetailMap'] ?? null;
        if (is_array($map) && !empty($map)) {
            $tryId = $noteId ?: ($data['note']['firstNoteId'] ?? $data['note']['currentNoteId'] ?? null);
            if ($tryId && isset($map[$tryId])) {
                $item = $map[$tryId];
                return $item['note'] ?? (is_array($item) ? $item : null);
            }
            foreach ($map as $item) {
                $note = is_array($item) ? ($item['note'] ?? $item) : null;
                if ($this->isValidNote($note)) {
                    return $note;
                }
            }
        }

        // 递归查找含 imageList 的 note 对象
        $found = $this->findNoteRecursive($data);
        if ($found) {
            return $found;
        }

        $note = $this->findNestedValue($data, 'note');
        return $this->isValidNote($note) ? $note : null;
    }

    private function isValidNote(mixed $note): bool
    {
        if (!is_array($note)) {
            return false;
        }
        return isset($note['imageList']) || isset($note['image_list'])
            || isset($note['video']) || !empty($note['desc']) || !empty($note['title']);
    }

    private function findNoteRecursive(array $data, int $depth = 0): ?array
    {
        if ($depth > 12) {
            return null;
        }
        if ($this->isValidNote($data) && (isset($data['imageList']) || isset($data['image_list']))) {
            return $data;
        }
        foreach ($data as $value) {
            if (!is_array($value)) {
                continue;
            }
            $result = $this->findNoteRecursive($value, $depth + 1);
            if ($result) {
                return $result;
            }
        }
        return null;
    }

    private function buildFromNote(array $note): array
    {
        $images = $this->extractImages($note);
        $video = $this->extractVideo($note);
        $type = $video ? 'video' : 'image';
        $cover = $images[0] ?? $this->extractVideoCover($note) ?? '';

        return $this->buildResult([
            'title'  => $note['title'] ?? $note['desc'] ?? '',
            'author' => $note['user']['nickname'] ?? $note['user']['nickName'] ?? '',
            'cover'  => $cover,
            'type'   => $type,
            'video'  => $video,
            'images' => $images,
        ]);
    }

    private function extractVideoCover(array $note): ?string
    {
        $video = $note['video'] ?? null;
        if (!is_array($video)) {
            return null;
        }

        $url = $this->extractCoverFromVideo($video, $note);
        return $url !== '' ? $url : null;
    }

    private function extractImages(array $note): array
    {
        $images = [];
        $list = $note['imageList'] ?? $note['image_list'] ?? [];

        foreach ($list as $img) {
            if (!is_array($img)) {
                continue;
            }
            $url = $this->resolveImageItem($img);
            if ($url) {
                $images[] = $url;
            }
        }

        return array_values(array_unique(array_filter($images)));
    }

    private function resolveImageItem(array $img): ?string
    {

        // 3. fileId -> ci 接口（部分需签名，作为备选）
        $fileId = $img['fileId'] ?? $img['file_id'] ?? null;
        if ($fileId) {
//            $id = basename(str_replace('\\', '/', (string) $fileId));
//            return 'https://ci.xiaohongshu.com/' . $id . '?imageView2/2/format/png';
            return 'https://ci.xiaohongshu.com/' . $fileId . '?imageView2/2/format/png';
        }

        // 1. infoList 优先 WB_DFT（sns-webpic CDN 直链，代理可访问）
        $infoList = $img['infoList'] ?? $img['info_list'] ?? [];
        if (is_array($infoList)) {
            $best = null;
            foreach ($infoList as $info) {
                if (!is_array($info) || empty($info['url'])) {
                    continue;
                }
                $scene = $info['imageScene'] ?? $info['image_scene'] ?? '';
                $url = $this->stripImageWatermark($info['url']);
                if ($this->isXhsCdnImageUrl($url)) {
                    if ($scene === 'WB_DFT' || str_contains($url, '!nc_n_webp_mw')) {
                        return $url;
                    }
                    $best = $best ?? $url;
                }
            }
            if ($best) {
                return $best;
            }
        }

        // 2. urlDefault / urlPre / url（CDN 直链）
        foreach (['urlDefault', 'url_default', 'urlPre', 'url_pre', 'url'] as $key) {
            if (!empty($img[$key])) {
                $url = $this->stripImageWatermark((string) $img[$key]);
                if ($this->isXhsCdnImageUrl($url)) {
                    return $url;
                }
            }
        }

        // 4. traceId -> ci 接口（最后备选）
        $traceId = $img['traceId'] ?? $img['trace_id'] ?? null;
        if ($traceId) {
            return 'https://ci.xiaohongshu.com/' . $traceId . '?imageView2/2/format/png';
        }

        return null;
    }

    private function isXhsCdnImageUrl(string $url): bool
    {
        return (bool) preg_match('#(?:xhscdn|sns-webpic|sns-img|xiaohongshu|picasso|edith)#i', $url);
    }

    private function stripImageWatermark(string $url): string
    {
        $url = $this->normalizeMediaUrl($url);
        // prv 为预览水印图，替换为 mw 或无后缀版本
        $url = str_replace(['!nc_n_webp_prv_1', '!nc_n_webp_prv', '_prv'], ['!nc_n_webp_mw_1', '!nc_n_webp_mw', '_mw'], $url);
        $url = preg_replace('/[?&]watermark[^&]*/', '', $url) ?? $url;
        return $url;
    }

    private function extractVideo(array $note): ?string
    {
        $video = $note['video'] ?? null;
        if (!is_array($video)) {
            return null;
        }

        $stream = $video['media']['stream'] ?? [];
        foreach (['h265', 'h264', 'av1'] as $codec) {
            if (!empty($stream[$codec][0]['masterUrl'])) {
                return $this->normalizeMediaUrl($stream[$codec][0]['masterUrl']);
            }
            if (!empty($stream[$codec][0]['backupUrls'][0])) {
                return $this->normalizeMediaUrl($stream[$codec][0]['backupUrls'][0]);
            }
        }

        if (!empty($video['consumer']['originVideoKey'])) {
            $key = $video['consumer']['originVideoKey'];
            return str_starts_with($key, 'http') ? $key : 'https://sns-video-bd.xhscdn.com/' . ltrim($key, '/');
        }

        return null;
    }

    private function parseFromHtmlFragments(string $html): ?array
    {
        $decoded = str_replace(['\u002F', '\u0026'], ['/', '&'], $html);
        $images = [];
        $video = null;
        $title = '';

        if (preg_match('/"title"\s*:\s*"([^"]{1,300})"/', $decoded, $tm)) {
            $title = stripcslashes($tm[1]);
        }

        // sns-webpic 直链（图文笔记常见，优先于 traceId）
        if (preg_match_all('/https?:\\/\\/sns-webpic[^"\'\\\\]+!nc_n_webp_mw[^"\'\\\\]*/', $decoded, $pics)) {
            foreach (array_unique($pics[0]) as $pic) {
                $images[] = $this->stripImageWatermark(stripcslashes($pic));
            }
        }
        if (preg_match_all('/https?:\\/\\/sns-img[^"\'\\\\]+\.(?:jpg|jpeg|png|webp)[^"\'\\\\]*/i', $decoded, $pics2)) {
            foreach (array_unique($pics2[0]) as $pic) {
                $images[] = $this->stripImageWatermark(stripcslashes($pic));
            }
        }

        // traceId 批量提取（ci 接口，备选）
        if (empty($images) && preg_match_all('/"traceId"\s*:\s*"([a-zA-Z0-9]+)"/', $decoded, $traces)) {
            foreach (array_unique($traces[1]) as $traceId) {
                $images[] = 'https://ci.xiaohongshu.com/' . $traceId . '?imageView2/2/format/png';
            }
        }

        // info_list / url_default 片段
        if (preg_match_all('/"url(?:Default|_default|)"\s*:\s*"(https?:[^"]+)"/', $decoded, $urls)) {
            foreach ($urls[1] as $u) {
                if (str_contains($u, 'xhscdn') || str_contains($u, 'xiaohongshu')) {
                    $images[] = $this->stripImageWatermark(stripcslashes($u));
                }
            }
        }

        if (preg_match('/"masterUrl"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/', $decoded, $vm)) {
            $video = $this->normalizeMediaUrl(stripcslashes($vm[1]));
        }

        $cover = $images[0] ?? '';
        if ($cover === '' && $video) {
            $cover = $this->extractCoverFromHtml($html) ?? '';
        }

        $images = array_values(array_unique(array_filter($images)));

        if (!$video && empty($images)) {
            return null;
        }

        return $this->buildResult([
            'title'  => $title,
            'type'   => $video ? 'video' : 'image',
            'video'  => $video,
            'images' => $images,
            'cover'  => $cover,
        ]);
    }
}
