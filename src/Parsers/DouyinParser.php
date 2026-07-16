<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

class DouyinParser extends BytedanceShareParser
{
    protected array $domains = ['douyin.com', 'iesdouyin.com', 'v.douyin.com'];

    public function getPlatform(): string
    {
        return 'douyin';
    }

    protected function getShareHost(): string
    {
        return 'www.iesdouyin.com';
    }

    protected function getReferer(): string
    {
        return 'https://www.douyin.com/';
    }

    protected function getShareUrls(string $videoId, bool $isNote = false): array
    {
        if ($isNote) {
            return array_values(array_unique([
                'https://' . $this->getShareHost() . '/share/note/' . $videoId . '/',
                'https://www.douyin.com/share/note/' . $videoId . '/',
                'https://m.douyin.com/share/note/' . $videoId . '/',
            ]));
        }

        return array_values(array_unique([
            'https://' . $this->getShareHost() . '/share/video/' . $videoId . '/',
            'https://www.douyin.com/share/video/' . $videoId . '/',
            'https://m.douyin.com/share/video/' . $videoId . '/',
        ]));
    }

    protected function allowOgFallback(): bool
    {
        return false;
    }
}
