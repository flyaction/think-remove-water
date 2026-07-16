<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class AcfunParser extends BaseParser
{
    protected array $domains = ['acfun.cn', 'acfun.tv'];

    public function getPlatform(): string
    {
        return 'acfun';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);
        $acid = $this->extractId($url, ['/ac(\d+)/', '/v\/ac(\d+)/', '/v\/(\d+)/']);
        $headers = $this->mobileHeaders('https://www.acfun.cn/');

        if ($acid) {
            $api = "https://www.acfun.cn/rest/pc-direct/video/info?videoId={$acid}";
            $res = HttpClient::get($api, $headers);
            if ($res) {
                $json = json_decode($res, true);
                $ks = $json['currentVideoInfo']['ksPlayJson'] ?? null;
                if ($ks) {
                    $play = json_decode($ks, true);
                    $video = $play['adaptationSet'][0]['representation'][0]['url'] ?? null;
                    return $this->buildResult([
                        'title'  => $json['title'] ?? '',
                        'author' => $json['user']['name'] ?? '',
                        'cover'  => $json['coverInfo']['coverUrl'] ?? '',
                        'video'  => $video,
                    ]);
                }
            }
        }

        $html = HttpClient::get($url, $headers);
        if ($html) {
            if (preg_match('/"url"\s*:\s*"(https?:[^"]+\.mp4[^"]*)"/', $html, $m)) {
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

        throw new \RuntimeException('AcFun解析失败');
    }
}
