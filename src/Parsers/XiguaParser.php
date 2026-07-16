<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

class XiguaParser extends BytedanceShareParser
{
    protected array $domains = ['ixigua.com'];

    public function getPlatform(): string
    {
        return 'xigua';
    }

    protected function getShareHost(): string
    {
        return 'www.ixigua.com';
    }

    protected function getReferer(): string
    {
        return 'https://www.ixigua.com/';
    }
}
