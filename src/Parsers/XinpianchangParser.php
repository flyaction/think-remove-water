<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class XinpianchangParser extends BaseParser
{
    protected array $domains = ['xinpianchang.com', 'xpc.com'];

    public function getPlatform(): string
    {
        return 'xinpianchang';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);
        $vid = $this->extractId($url, ['/a(\d+)/', '/article\/(\d+)/', '/video\/(\d+)/']);
        $headers = $this->mobileHeaders('https://www.xinpianchang.com/');

        if ($vid) {
            $api = "https://mod-api.xinpianchang.com/mod/api/v2/media/{$vid}?appKey=media_backend&extend=userInfo%2CuserStatus";
            $res = HttpClient::get($api, $headers);
            if ($res) {
                $json = json_decode($res, true);
                $data = $json['data'] ?? null;
                if ($data) {
                    $video = $data['resource']['progressive'][0]['url'] ?? $data['video']['url'] ?? null;
                    return $this->buildResult([
                        'title'  => $data['title'] ?? '',
                        'author' => $data['user']['username'] ?? '',
                        'cover'  => $data['cover'] ?? '',
                        'video'  => $video,
                    ]);
                }
            }
        }

        $html = HttpClient::get($url, $headers);
        if ($html) {
            $og = $this->resultFromOg($html);
            if ($og) {
                return $og;
            }
        }

        throw new \RuntimeException('新片场解析失败');
    }
}
