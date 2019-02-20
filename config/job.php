<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2019/2/20
 * Time: 10:14
 */

return [
    'topic' => 'yk_index',// 消费主题
    'max_try' => 3,//最大重试次数
    'out_time' => 120, //120秒后服务器无响应，丢弃任务
];