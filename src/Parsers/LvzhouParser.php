<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class LvzhouParser extends BaseParser
{
    protected array $domains = ['oasis.weibo.com', 'lvzhou.com'];

    public function getPlatform(): string
    {
        return 'lvzhou';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);
        $html = HttpClient::get($url, $this->mobileHeaders('https://weibo.com/'));

        if (!$html) {
            throw new \RuntimeException('无法获取绿洲页面');
        }

        if (preg_match('/"video_url"\s*:\s*"([^"]+)"/', $html, $m)) {
            $images = [];
            if (preg_match_all('/"large"\s*:\s*\{\s*"url"\s*:\s*"([^"]+)"/', $html, $im)) {
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

        throw new \RuntimeException('绿洲解析失败');
    }
}
