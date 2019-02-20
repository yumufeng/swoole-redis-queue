<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2019/2/20
 * Time: 9:50
 */

namespace app\facades;


use app\Facade;

/**
 * @see Config
 * @mixin Config
 * @method bool has(string $name) static 检测配置是否存在
 * @method array pull(string $name) static 获取一级配置
 * @method mixed get(string $name, mixed $default = null) static 获取配置参数
 * @method mixed set(string $name, mixed $value = null) static 设置配置参数
 * @method array reset(string $prefix = '') static 重置配置参数
 */
class Config extends Facade
{

    protected static function getFacadeClass()
    {
        return 'config';
    }
}