<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

use Flyaction\ThinkRemoveWater\Core\HttpClient;

class HuoshanParser extends BytedanceShareParser
{
    protected array $domains = ['huoshan.com', 'huoshanzhibo.com'];

    public function getPlatform(): string
    {
        return 'huoshan';
    }

    protected function getShareHost(): string
    {
        return 'share.huoshan.com';
    }

    protected function getReferer(): string
    {
        return 'https://www.huoshan.com/';
    }

    public function parse(string $url): array
    {
        $url = $this->resolveUrl($url);
        // 火山部分链接与抖音结构相同，先尝试抖音系
        try {
            return parent::parse($url);
        } catch (\Exception $e) {
            $headers = $this->mobileHeaders($this->getReferer());
            $html = HttpClient::get($url, $headers);
            if ($html) {
                $item = $this->parseByteShareHtml($html);
                if ($item) {
                    return $this->buildFromAwemeItem($item);
                }
            }
            throw $e;
        }
    }
}
