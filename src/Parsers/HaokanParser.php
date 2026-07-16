<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class HaokanParser extends BaseParser
{
    protected array $domains = ['haokan.baidu.com', 'haokan.com'];

    public function getPlatform(): string
    {
        return 'haokan';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);
        $vid = $this->extractId($url, ['/v\?vid=(\d+)/', '/video\/(\d+)/', '/v\/(\d+)/']);
        $headers = $this->mobileHeaders('https://haokan.baidu.com/');

        if ($vid) {
            $api = "https://haokan.baidu.com/v?vid={$vid}&_format=json";
            $res = HttpClient::get($api, $headers);
            if ($res) {
                $json = json_decode($res, true);
                $cur = $json['data']['apiData']['curVideoMeta'] ?? null;
                if ($cur) {
                    $video = $cur['playurl'] ?? $cur['clarityUrl'][0]['url'] ?? null;
                    return $this->buildResult([
                        'title'  => $cur['title'] ?? '',
                        'author' => $cur['mthname'] ?? '',
                        'cover'  => $cur['poster'] ?? '',
                        'video'  => $video,
                    ]);
                }
            }
        }

        $html = HttpClient::get($url, $headers);
        if ($html) {
            if (preg_match('/"playurl"\s*:\s*"([^"]+)"/', $html, $m)) {
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

        throw new \RuntimeException('好看视频解析失败');
    }
}
