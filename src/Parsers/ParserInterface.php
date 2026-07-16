<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

interface ParserInterface
{
    public function supports(string $url): bool;

    public function parse(string $url): array;

    public function getPlatform(): string;
}
