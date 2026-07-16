<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class PipixiaParser extends BaseParser
{
    protected array $domains = ['pipix.com', 'hulushequ.com', 'ippzone.com'];

    public function getPlatform(): string
    {
        return 'pipixia';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);
        $itemId = $this->extractId($url, ['/item\/(\d+)/', '/post\/(\d+)/', '/share\/(\w+)/']);

        $headers = $this->mobileHeaders('https://hulushequ.com/');

        if ($itemId) {
            $api = "https://hulushequ.com/bds/webapi/item/detail/?item_id={$itemId}";
            $res = HttpClient::get($api, $headers);
            if ($res) {
                $json = json_decode($res, true);
                $item = $json['data']['item'] ?? $json['data'] ?? null;
                if ($item) {
                    $video = $item['video']['url_list'][0]
                          ?? $item['video']['url']
                          ?? $item['video']['video_download_url']
                          ?? null;
                    $images = [];
                    if (!empty($item['note']['multi_image'])) {
                        foreach ($item['note']['multi_image'] as $img) {
                            $images[] = $img['url_list'][0] ?? $img['url'] ?? '';
                        }
                    }
                    return $this->buildResult([
                        'title'  => $item['content'] ?? '',
                        'author' => $item['author']['name'] ?? '',
                        'cover'  => $item['video']['cover_image']['url_list'][0] ?? '',
                        'type'   => $video ? 'video' : 'image',
                        'video'  => $video,
                        'images' => array_filter($images),
                    ]);
                }
            }
        }

        $html = HttpClient::get($url, $headers);
        if ($html) {
            $item = $this->parseByteShareHtml($html);
            if ($item) {
                return $this->buildFromAwemeItem($item);
            }
            $og = $this->resultFromOg($html);
            if ($og) {
                return $og;
            }
        }

        throw new \RuntimeException('皮皮虾解析失败');
    }
}
