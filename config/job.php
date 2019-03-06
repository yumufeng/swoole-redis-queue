<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2019/2/20
 * Time: 10:14
 */

return [
    'call_url' => 'http://tenyk.lead86.com:8087/index.php/home/Job/job_releases', //回调通知的URL
    'topic' => 'yk_index',// 消费主题
    'max_try' => 3,//最大重试次数
];