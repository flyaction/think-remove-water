<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class MeipaiParser extends BaseParser
{
    protected array $domains = ['meipai.com'];

    public function getPlatform(): string
    {
        return 'meipai';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);
        $mediaId = $this->extractId($url, ['/media\/(\d+)/', '/video\/(\d+)/']);
        $headers = $this->mobileHeaders('https://www.meipai.com/');

        if ($mediaId) {
            $api = "https://www.meipai.com/media/{$mediaId}";
            $html = HttpClient::get($api, $headers);
            if ($html && preg_match('/data-video="([^"]+)"/', $html, $m)) {
                return $this->buildResult([
                    'title' => $this->extractOgMeta($html)['title'],
                    'video' => html_entity_decode($m[1]),
                ]);
            }
        }

        $html = HttpClient::get($url, $headers);
        if ($html) {
            if (preg_match('/data-video="([^"]+)"/', $html, $m)) {
                return $this->buildResult([
                    'title' => $this->extractOgMeta($html)['title'],
                    'video' => html_entity_decode($m[1]),
                ]);
            }
            $og = $this->resultFromOg($html);
            if ($og) {
                return $og;
            }
        }

        throw new \RuntimeException('美拍解析失败');
    }
}
