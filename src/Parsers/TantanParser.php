<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

/** 探探 */
class TantanParser extends BaseParser
{
    protected array $domains = ['tantanapp.com', 'tantanapp.net'];

    public function getPlatform(): string
    {
        return 'tantan';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);
        $html = HttpClient::get($url, $this->mobileHeaders('https://www.tantanapp.com/'));

        if (!$html) {
            throw new \RuntimeException('无法获取探探页面');
        }

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

        throw new \RuntimeException('探探解析失败');
    }
}
