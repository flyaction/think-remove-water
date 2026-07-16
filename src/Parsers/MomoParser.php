<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

/** 陌陌 */
class MomoParser extends BaseParser
{
    protected array $domains = ['immomo.com', 'momo.com'];

    public function getPlatform(): string
    {
        return 'momo';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);
        $html = HttpClient::get($url, $this->mobileHeaders('https://www.immomo.com/'));

        if (!$html) {
            throw new \RuntimeException('无法获取陌陌页面');
        }

        if (preg_match('/"video_url"\s*:\s*"([^"]+)"/', $html, $m)) {
            $images = [];
            if (preg_match_all('/"pic_url"\s*:\s*"([^"]+)"/', $html, $im)) {
                $images = $im[1];
            }
            return $this->buildResult([
                'title'  => $this->extractOgMeta($html)['title'],
                'video'  => stripslashes($m[1]),
                'images' => $images,
                'type'   => 'video',
            ]);
        }

        $og = $this->resultFromOg($html);
        if ($og) {
            return $og;
        }

        throw new \RuntimeException('陌陌解析失败');
    }
}
