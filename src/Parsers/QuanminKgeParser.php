<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class QuanminKgeParser extends BaseParser
{
    protected array $domains = ['kg.qq.com', 'node.kg.qq.com'];

    public function getPlatform(): string
    {
        return 'quanminkge';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);
        $shareId = $this->extractId($url, ['/s\/(\w+)/', '/share\/(\w+)/', '/ksong\/(\d+)/']);
        $headers = $this->mobileHeaders('https://kg.qq.com/');

        if ($shareId) {
            $api = "https://node.kg.qq.com/cgi/fcgi-bin/kg_ugc_get_homepage?jsonpCallback=callback&shareuid={$shareId}&start=1";
            $res = HttpClient::get($api, $headers);
            if ($res && preg_match('/"playurl"\s*:\s*"([^"]+)"/', $res, $m)) {
                return $this->buildResult([
                    'title' => '全民K歌',
                    'video' => stripslashes($m[1]),
                    'type'  => 'video',
                ]);
            }
        }

        $html = HttpClient::get($url, $headers);
        if ($html && preg_match('/"playurl"\s*:\s*"([^"]+)"/', $html, $m)) {
            return $this->buildResult([
                'title' => $this->extractOgMeta($html)['title'] ?: '全民K歌',
                'video' => stripslashes($m[1]),
            ]);
        }

        throw new \RuntimeException('全民K歌解析失败');
    }
}
