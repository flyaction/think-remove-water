<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class ZuiyouParser extends BaseParser
{
    protected array $domains = ['izuiyou.com', 'share.izuiyou.com'];

    public function getPlatform(): string
    {
        return 'zuiyou';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);
        $pid = $this->extractId($url, ['/detail\/(\d+)/', '/post\/(\d+)/', '/pid[=\/](\d+)/']);
        $headers = $this->mobileHeaders('https://www.izuiyou.com/');

        if ($pid) {
            $api = 'https://api.izuiyou.com/detail';
            $payload = json_encode(['pid' => (int) $pid, 'h_av' => '5.12.0']);
            $res = HttpClient::post($api, $payload, array_merge($headers, [
                'Content-Type: application/json',
            ]));
            if ($res) {
                $json = json_decode($res, true);
                $post = $json['data']['post'] ?? null;
                if ($post) {
                    $video = $post['videos'][$post['id']]['url'] ?? null;
                    $images = [];
                    if (!empty($post['imgs'])) {
                        foreach ($post['imgs'] as $img) {
                            $images[] = is_array($img) ? ($img['urls']['540'] ?? $img['url'] ?? '') : $img;
                        }
                    }
                    return $this->buildResult([
                        'title'  => $post['content'] ?? '',
                        'author' => $post['member']['name'] ?? '',
                        'cover'  => $images[0] ?? '',
                        'type'   => $video ? 'video' : 'image',
                        'video'  => $video,
                        'images' => array_filter($images),
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

        throw new \RuntimeException('最右解析失败');
    }
}
