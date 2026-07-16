<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class JimengParser extends BaseParser
{
    protected array $domains = ['jimeng.jianying.com'];

    public function getPlatform(): string
    {
        return 'jimeng';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);
        $detailUrl = $this->resolveWorkDetailUrl($url);
        $html = HttpClient::get($detailUrl, $this->pageHeaders($detailUrl));

        if (!$html) {
            throw new \RuntimeException('无法获取即梦作品页面');
        }

        $work = $this->parseRouterWorkDetail($html);
        if (!$work) {
            throw new \RuntimeException('无法解析即梦作品内容，请使用最新分享链接');
        }

        return $this->buildFromWork($work);
    }

    private function pageHeaders(string $referer): array
    {
        return [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Referer: https://jimeng.jianying.com/',
        ];
    }

    private function resolveWorkDetailUrl(string $url): string
    {
        if (preg_match('#/ai-tool/work-detail/\d+#', $url)) {
            return $url;
        }

        if (preg_match('#/s/([A-Za-z0-9]+)#', $url, $m)) {
            $final = HttpClient::getFinalUrl($url);
            if ($final && preg_match('#/ai-tool/work-detail/\d+#', $final)) {
                return $final;
            }
        }

        $final = HttpClient::getFinalUrl($url);
        if ($final && preg_match('#/ai-tool/work-detail/\d+#', $final)) {
            return $final;
        }

        throw new \RuntimeException('无法解析即梦分享链接');
    }

    private function parseRouterWorkDetail(string $html): ?array
    {
        if (!preg_match('/window\._ROUTER_DATA\s*=\s*(\{[\s\S]*?\})\s*<\/script>/i', $html, $m)) {
            return null;
        }

        $data = json_decode($m[1], true);
        if (!$data || empty($data['loaderData'])) {
            return null;
        }

        foreach ($data['loaderData'] as $page) {
            if (!is_array($page)) {
                continue;
            }
            $workDetail = $page['workDetail']['value'] ?? $page['workDetail'] ?? null;
            if (is_array($workDetail) && !empty($workDetail['commonAttr'])) {
                return $workDetail;
            }
        }

        return null;
    }

    private function buildFromWork(array $work): array
    {
        $common = $work['commonAttr'] ?? [];
        $caption = trim(
            $work['text2imageParams']['prompt']
            ?? $work['text2ImageParams']['prompt']
            ?? $work['aigcImageParams']['prompt']
            ?? $work['videoParams']['prompt']
            ?? $common['description']
            ?? $common['title']
            ?? ''
        );

        $author = $work['author']['name']
            ?? $work['author']['nickName']
            ?? $work['author']['nickname']
            ?? '';

        $images = [];
        $largeImages = $work['image']['largeImages']
            ?? $work['image']['large_images']
            ?? [];
        foreach ($largeImages as $img) {
            $url = $this->extractMediaUrl($img['imageUrl'] ?? $img['image_url'] ?? $img);
            if ($url) {
                $images[] = $url;
            }
        }

        $cover = $this->pickBestCoverMap($common['coverUrlMap'] ?? $common['cover_url_map'] ?? [])
            ?: ($this->extractMediaUrl($common['coverUrl'] ?? $common['cover_url'] ?? null) ?? '')
            ?: ($images[0] ?? '');

        $video = $this->extractJimengVideo($work);

        $type = $video ? 'video' : (!empty($images) ? 'image' : 'video');

        return $this->buildResult([
            'title'  => $caption,
            'author' => $author,
            'cover'  => $cover,
            'type'   => $type,
            'video'  => $video,
            'images' => $images,
        ]);
    }

    private function extractJimengVideo(array $work): ?string
    {
        $video = $work['video'] ?? null;
        if (!is_array($video)) {
            return null;
        }

        $candidates = [
            $video['videoResource']['videoUrl'] ?? null,
            $video['video_resource']['video_url'] ?? null,
            $video['videoResource']['video_url'] ?? null,
            $video['playUrl'] ?? null,
            $video['play_url'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $url = $this->extractMediaUrl($candidate);
            if ($url) {
                return $url;
            }
        }

        $transcoded = $video['transcodedVideo']['origin']['videoUrl']
            ?? $video['transcoded_video']['origin']['video_url']
            ?? null;

        return $this->extractMediaUrl($transcoded);
    }

    private function pickBestCoverMap(array $map): string
    {
        if (empty($map)) {
            return '';
        }

        $keys = array_keys($map);
        usort($keys, static fn($a, $b) => (int) $b <=> (int) $a);

        foreach ($keys as $key) {
            $url = $this->extractMediaUrl($map[$key]);
            if ($url) {
                return $url;
            }
        }

        return '';
    }
}
