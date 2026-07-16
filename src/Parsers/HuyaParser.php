<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class HuyaParser extends BaseParser
{
    protected array $domains = ['huya.com', 'huya.tv'];

    public function getPlatform(): string
    {
        return 'huya';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);
        $vid = $this->extractId($url, ['/video\/(\w+)/', '/play\/(\w+)/', '/v\/(\w+)/']);
        $headers = $this->mobileHeaders('https://www.huya.com/');

        if ($vid) {
            $api = "https://liveapi.huya.com/moment/getMomentContent?momentId={$vid}";
            $res = HttpClient::get($api, $headers);
            if ($res) {
                $json = json_decode($res, true);
                $data = $json['data']['moment']['momentDetail'] ?? null;
                if ($data) {
                    $video = $data['videoInfo']['definitions'][0]['url'] ?? $data['videoUrl'] ?? null;
                    return $this->buildResult([
                        'title'  => $data['content'] ?? '',
                        'author' => $data['userInfo']['nickName'] ?? '',
                        'cover'  => $data['videoInfo']['coverUrl'] ?? '',
                        'video'  => $video,
                    ]);
                }
            }
        }

        $html = HttpClient::get($url, $headers);
        if ($html) {
            if (preg_match('/"videoUrl"\s*:\s*"([^"]+)"/', $html, $m)) {
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

        throw new \RuntimeException('虎牙解析失败');
    }
}
