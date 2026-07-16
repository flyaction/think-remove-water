<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class QuanminParser extends BaseParser
{
    protected array $domains = ['quanmin.baidu.com', 'quanmin.tv'];

    public function getPlatform(): string
    {
        return 'quanmin';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);
        $headers = $this->mobileHeaders('https://quanmin.baidu.com/');
        $html = HttpClient::get($url, $headers);

        if (!$html) {
            throw new \RuntimeException('无法获取度小视页面');
        }

        if (preg_match('/"play_url"\s*:\s*"([^"]+)"/', $html, $m)) {
            return $this->buildResult([
                'title' => $this->extractOgMeta($html)['title'],
                'video' => stripslashes($m[1]),
            ]);
        }

        $item = $this->parseByteShareHtml($html);
        if ($item) {
            return $this->buildFromAwemeItem($item);
        }

        $og = $this->resultFromOg($html);
        if ($og) {
            return $og;
        }

        throw new \RuntimeException('度小视解析失败');
    }
}
