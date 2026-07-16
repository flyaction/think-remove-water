<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class SixRoomsParser extends BaseParser
{
    protected array $domains = ['6.cn', 'v.6.cn'];

    public function getPlatform(): string
    {
        return 'sixrooms';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);
        $html = HttpClient::get($url, $this->mobileHeaders('https://www.6.cn/'));

        if (!$html) {
            throw new \RuntimeException('无法获取6间房页面');
        }

        if (preg_match('/"flvurl"\s*:\s*"([^"]+)"/', $html, $m)) {
            return $this->buildResult([
                'title' => $this->extractOgMeta($html)['title'],
                'video' => stripslashes($m[1]),
            ]);
        }
        if (preg_match('/"mp4url"\s*:\s*"([^"]+)"/', $html, $m)) {
            return $this->buildResult([
                'title' => $this->extractOgMeta($html)['title'],
                'video' => stripslashes($m[1]),
            ]);
        }

        $og = $this->resultFromOg($html);
        if ($og) {
            return $og;
        }

        throw new \RuntimeException('6间房解析失败');
    }
}
