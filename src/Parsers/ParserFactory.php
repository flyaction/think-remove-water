<?php
namespace Flyaction\ThinkRemoveWater\Parsers;

class ParserFactory
{
    /** 顺序：域名越特殊越靠前 */
    private static array $parsers = [
        JimengParser::class,
        DoubaoParser::class,
        WeixinChannelsParser::class,
        QishuiParser::class,
        DouyinParser::class,
        HuoshanParser::class,
        XiguaParser::class,
        ToutiaoParser::class,
        KuaishouParser::class,
        XiaohongshuParser::class,
        PipixiaParser::class,
        PipigaoxiaoParser::class,
        PinduoduoParser::class,
        WeishiParser::class,
        ZuiyouParser::class,
        PearVideoParser::class,
        QuanminParser::class,
        DoupaiParser::class,
        LvzhouParser::class,
        QuanminKgeParser::class,
        SixRoomsParser::class,
        MeipaiParser::class,
        XinpianchangParser::class,
        HaokanParser::class,
        BaiduVideoParser::class,
        HuyaParser::class,
        AcfunParser::class,
        BilibiliParser::class,
        WeiboParser::class,
        MomoParser::class,
        TantanParser::class,
        ZhihuParser::class,
        TikTokParser::class,
        YoutubeParser::class,
        InstagramParser::class,
        TwitterParser::class,
    ];

    public static function getParser(string $url): ?ParserInterface
    {
        foreach (self::$parsers as $parserClass) {
            $parser = new $parserClass();
            if ($parser->supports($url)) {
                return $parser;
            }
        }
        return null;
    }

    public static function getSupportedPlatforms(): array
    {
        return array_map(fn($class) => (new $class())->getPlatform(), self::$parsers);
    }

    public static function detectPlatform(string $url): ?string
    {
        $parser = self::getParser($url);
        return $parser ? $parser->getPlatform() : null;
    }

    public static function getPlatformMeta(): array
    {
        return [
            ['code' => 'jimeng',       'name' => '即梦',         'icon' => '✨', 'domains' => ['jimeng.jianying.com']],
            ['code' => 'doubao',       'name' => '豆包',         'icon' => '🤖', 'domains' => ['doubao.com']],
            ['code' => 'douyin',       'name' => '抖音',         'icon' => '🎵', 'domains' => ['douyin.com', 'iesdouyin.com', 'v.douyin.com']],
            ['code' => 'channels',     'name' => '视频号',       'icon' => '💬', 'domains' => ['channels.weixin.qq.com', 'finder.video.qq.com']],
            ['code' => 'kuaishou',     'name' => '快手',         'icon' => '⚡', 'domains' => ['kuaishou.com', 'gifshow.com', 'chenzhongtech.com', 'v.kuaishou.com', 'f.kuaishou.com', 'c.kuaishou.com']],
            ['code' => 'xiaohongshu',  'name' => '小红书',       'icon' => '📕', 'domains' => ['xiaohongshu.com', 'xhslink.com']],
            ['code' => 'pipixia',      'name' => '皮皮虾',       'icon' => '🦐', 'domains' => ['pipix.com', 'hulushequ.com']],
            ['code' => 'momo',         'name' => '陌陌',         'icon' => '💜', 'domains' => ['immomo.com']],
            ['code' => 'tantan',       'name' => '探探',         'icon' => '❤️', 'domains' => ['tantanapp.com']],
            ['code' => 'weibo',        'name' => '微博',         'icon' => '🌐', 'domains' => ['weibo.com', 'weibo.cn']],
            ['code' => 'pinduoduo',    'name' => '拼多多',       'icon' => '🛒', 'domains' => ['yangkeduo.com', 'pinduoduo.com']],
            ['code' => 'toutiao',      'name' => '今日头条',     'icon' => '📰', 'domains' => ['toutiao.com']],
            ['code' => 'huoshan',      'name' => '火山短视频',   'icon' => '🌋', 'domains' => ['huoshan.com']],
            ['code' => 'pipigaoxiao',  'name' => '皮皮搞笑',     'icon' => '😂', 'domains' => ['pipigx.com']],
            ['code' => 'bilibili',     'name' => '哔哩哔哩',     'icon' => '📺', 'domains' => ['bilibili.com', 'b23.tv']],
            ['code' => 'weishi',       'name' => '微视',         'icon' => '🎬', 'domains' => ['weishi.qq.com']],
            ['code' => 'xigua',        'name' => '西瓜视频',     'icon' => '🍉', 'domains' => ['ixigua.com']],
            ['code' => 'zuiyou',       'name' => '最右',         'icon' => '👉', 'domains' => ['izuiyou.com']],
            ['code' => 'pearvideo',    'name' => '梨视频',       'icon' => '🍐', 'domains' => ['pearvideo.com']],
            ['code' => 'quanmin',      'name' => '度小视',       'icon' => '📹', 'domains' => ['quanmin.baidu.com']],
            ['code' => 'doupai',       'name' => '逗拍',         'icon' => '🎭', 'domains' => ['doupai.cc']],
            ['code' => 'lvzhou',       'name' => '绿洲',         'icon' => '🌴', 'domains' => ['oasis.weibo.com']],
            ['code' => 'quanminkge',   'name' => '全民K歌',      'icon' => '🎤', 'domains' => ['kg.qq.com']],
            ['code' => 'sixrooms',     'name' => '6间房',        'icon' => '🏠', 'domains' => ['6.cn']],
            ['code' => 'meipai',       'name' => '美拍',         'icon' => '📸', 'domains' => ['meipai.com']],
            ['code' => 'xinpianchang', 'name' => '新片场',       'icon' => '🎞️', 'domains' => ['xinpianchang.com']],
            ['code' => 'haokan',       'name' => '好看视频',     'icon' => '👀', 'domains' => ['haokan.baidu.com']],
            ['code' => 'huya',         'name' => '虎牙',         'icon' => '🐯', 'domains' => ['huya.com']],
            ['code' => 'acfun',        'name' => 'AcFun',        'icon' => '🅰️', 'domains' => ['acfun.cn']],
            ['code' => 'baidu',        'name' => '百度短视频',   'icon' => '🔍', 'domains' => ['mbd.baidu.com', 'baijiahao.baidu.com']],
            ['code' => 'zhihu',        'name' => '知乎',         'icon' => '💡', 'domains' => ['zhihu.com']],
            ['code' => 'qishui',       'name' => '汽水音乐',     'icon' => '🎧', 'domains' => ['qishui.douyin.com']],
        ];
    }
}
