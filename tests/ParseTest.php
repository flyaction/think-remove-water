<?php

namespace Flyaction\ThinkRemoveWater\Tests;

use PHPUnit\Framework\TestCase;
use Flyaction\ThinkRemoveWater\Services\WatermarkService;

class ParseTest extends TestCase
{
    private WatermarkService $watermarkService;

    protected function setUp(): void
    {
        $this->watermarkService = new WatermarkService();
    }

    public function testParse()
    {
        $url = 'https://weibo.com/2933711627/R8KSooYNl';
        $url = "来青岛第一顿痛风套餐安排！干了奶皮子精酿 终于找到... http://xhslink.com/o/4ZHPnEEqi2o 快戳【小红书】瞧瞧这篇笔记！";

        echo "<pre>";
        print_r($this->watermarkService->parse($url));

    }


}