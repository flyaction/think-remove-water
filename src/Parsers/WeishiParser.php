<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class WeishiParser extends BaseParser
{
    protected array $domains = ['weishi.qq.com'];

    public function getPlatform(): string
    {
        return 'weishi';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);
        $feedId = $this->extractId($url, ['/feed\/(\w+)/', '/id[=\/](\w+)/']);

        $headers = $this->mobileHeaders('https://weishi.qq.com/');

        if ($feedId) {
            $api = "https://h5.weishi.qq.com/webapp/json/weishi/WSH5GetPlayPage?feedid={$feedId}";
            $res = HttpClient::get($api, $headers);
            if ($res) {
                $json = json_decode($res, true);
                $feed = $json['data']['feeds'][0] ?? $json['data'] ?? null;
                if ($feed) {
                    $video = $feed['video_url'] ?? $feed['video']['video_url'] ?? null;
                    $images = $feed['images'] ?? [];
                    return $this->buildResult([
                        'title'  => $feed['feed_desc'] ?? $feed['desc'] ?? '',
                        'author' => $feed['poster']['nick'] ?? '',
                        'cover'  => $feed['images'][0] ?? '',
                        'type'   => $video ? 'video' : 'image',
                        'video'  => $video,
                        'images' => $images,
                    ]);
                }
            }
        }

        $html = HttpClient::get($url, $headers);
        if ($html) {
            if (preg_match('/"video_url"\s*:\s*"([^"]+)"/', $html, $m)) {
                return $this->buildResult([
                    'title' => $this->extractOgMeta($html)['title'],
                    'video' => stripslashes($m[1]),
                ]);
            }
            $og = $this->resultFromOg($html);
            if ($og) {
                return $og;
            }
        }

        throw new \RuntimeException('微视解析失败');
    }
}
