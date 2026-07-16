<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class PearVideoParser extends BaseParser
{
    protected array $domains = ['pearvideo.com'];

    public function getPlatform(): string
    {
        return 'pearvideo';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);
        $headers = $this->mobileHeaders('https://www.pearvideo.com/');
        $html = HttpClient::get($url, $headers);

        if (!$html) {
            throw new \RuntimeException('无法获取梨视频页面');
        }

        if (preg_match('/ld\+json">\s*(\{.*?"@type"\s*:\s*"VideoObject".*?\})\s*<\/script>/s', $html, $m)) {
            $data = json_decode($m[1], true);
            if ($data) {
                return $this->buildResult([
                    'title'  => $data['name'] ?? '',
                    'cover'  => $data['thumbnailUrl'] ?? '',
                    'video'  => $data['contentUrl'] ?? null,
                ]);
            }
        }

        if (preg_match('/srcUrl\s*=\s*"([^"]+\.mp4[^"]*)"/', $html, $m)) {
            return $this->buildResult([
                'title' => $this->extractOgMeta($html)['title'],
                'video' => $m[1],
            ]);
        }

        $og = $this->resultFromOg($html);
        if ($og) {
            return $og;
        }

        throw new \RuntimeException('梨视频解析失败');
    }
}
