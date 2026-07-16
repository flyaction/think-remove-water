<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class BaiduVideoParser extends BaseParser
{
    protected array $domains = ['mbd.baidu.com', 'baijiahao.baidu.com'];

    public function getPlatform(): string
    {
        return 'baidu';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);
        $html = HttpClient::get($url, $this->mobileHeaders('https://www.baidu.com/'));

        if (!$html) {
            throw new \RuntimeException('无法获取百度短视频页面');
        }

        if (preg_match('/"mp4_url"\s*:\s*"([^"]+)"/', $html, $m)) {
            return $this->buildResult([
                'title' => $this->extractOgMeta($html)['title'],
                'video' => stripslashes($m[1]),
            ]);
        }
        if (preg_match('/"playUrl"\s*:\s*"([^"]+)"/', $html, $m)) {
            return $this->buildResult([
                'title' => $this->extractOgMeta($html)['title'],
                'video' => stripslashes($m[1]),
            ]);
        }

        $og = $this->resultFromOg($html);
        if ($og) {
            return $og;
        }

        throw new \RuntimeException('百度APP短视频解析失败');
    }
}
