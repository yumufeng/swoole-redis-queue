<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2019/2/20
 * Time: 9:36
 */

namespace app;

use app\facades\Redis;
use app\traits\JsonResult;

class App extends Container
{
    use JsonResult;
    protected $initialized = false;
    private $app_path;
    private $configPath;
    private $jobConfig;

    public function init()
    {
        if ($this->initialized) {
            return;
        }
        date_default_timezone_set('Asia/Shanghai');
        $this->initialized = true;

        $corePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
        $rootPath = dirname($this->app_path) . DIRECTORY_SEPARATOR;
        $configPath = $rootPath . 'config' . DIRECTORY_SEPARATOR;
        $this->setConfigPath($configPath);
        static::setInstance($this);
        $this->instance('app', $this);

        $env = [
            'core_path' => $corePath,
            'root_path' => $rootPath,
            'config_path' => $configPath,
        ];
        $this->env->set($env);

    }

    public function run()
    {
        $this->init();
        $this->work();
    }

    /**
     * @param mixed $configPath
     */
    public function setConfigPath($configPath): void
    {
        $this->configPath = $configPath;
    }

    /**
     * @param mixed $app_path
     * @return App
     */
    public function setAppPath($app_path): App
    {
        $this->app_path = $app_path;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getConfigPath()
    {
        return $this->configPath;
    }

    /**
     * 启动工作台
     */
    public function work()
    {
        $http = new \swoole_http_server("0.0.0.0", 10000);
        $http->set(array(
            'worker_num' => 8,
            'daemonize' => 1,
            'enable_static_handler' => false,
            'max_request' => 15000,
            'reload_async' => true, // 柔性异步重启，会等待所有协程退出后重启+ps
        ));
        $http->on('Start', [$this, 'onStart']);
        $http->on('WorkerStart', [$this, 'onWorkerStart']);
        $http->on('request', [$this, 'onRequest']);
        $http->start();
    }

    public function onStart(\swoole_server $server)
    {
        echo "swoole is start 0.0.0.0:10000" . PHP_EOL;
    }

    public function onRequest(\swoole_http_request $request, \swoole_http_response $response)
    {
        $requestUri = $request->server['request_uri'];
        $requestMethod = $request->server['request_method'];
        if ($requestUri == '/favicon.ico') {
            return $response->end('');
        }
        $params = array_merge($request->get ? $request->get : [], $request->post ? $request->post : []);
        if ($requestMethod == 'POST') {
            if (!isset($params['time'])) {
                return $response->end($this->error('', 'time参数不能为空'));
            }
            if (!isset($params['job_id']) || empty($params['job_id'])) {
                return $response->end($this->error('', 'job_id不能为空哦'));
            }

            try {
                go(function () use ($params) {
                    Redis::setDefer(false)->zAdd($this->jobConfig['topic'], $params['time'], $params['job_id']);
                });
                return $response->end($this->success('', '任务添加成功'));
            } catch (\RedisException $exception) {
                return $response->end($this->error('', '任务添加失败，请重试'));
            }

        } else {
            return $response->end($this->error('', '不支持当前类型请求哦'));
        }
    }

    public function onWorkerStart(\swoole_server $server, $worker_id)
    {

        $this->jobConfig = $this->config->pull('job');
        /**
         * 开启redis连接池
         */
        $this->redis->clearTimer($server);
        /**
         * 定时检测
         */
        if (0 == $worker_id) {
            $this->job($server, $worker_id);
        }
    }

    /**
     * 执行任务
     */
    protected function job(\swoole_server $server, $worker_id)
    {
        $server->tick(500, function () {
            $time = time(); // 当前系统时间
            $jobList = Redis::zRangeByScore($this->jobConfig['topic'], 0, $time);
            if (!empty($jobList)) {
                $this->excute($jobList);
            }
        });
    }

    private function excute($jobList)
    {
        $result = $this->curl($this->jobConfig['call_url'], $jobList);
        if ($result) {
            foreach ($jobList as $item) {
                Redis::zDelete($this->jobConfig['topic'], $item);
            }
        } else {
            foreach ($jobList as $item) {
                $rediskey = md5($item);
                $count = Redis::get($rediskey) ?: 0;
                if ($count) {
                    if ($count > $this->jobConfig['max_try']) {
                        Redis::zRem($this->jobConfig['topic'], $item);
                        Redis::del($rediskey);
                        continue;
                    }
                    Redis::incrBy($rediskey, 1);
                } else {
                    Redis::set($rediskey, 1, $this->jobConfig['out_time']);
                }
                Redis::zAdd($this->jobConfig['topic'], time() + 5 * ($count + 1), $item);
            }
        }

        return true;
    }

    private function curl($url, $data_string)
    {
        if (is_array($data_string)) {
            $data_string = json_encode($data_string);
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data_string)
        ));
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            return false;
        } else {
            //释放curl句柄
            $info = curl_getinfo($ch);
            curl_close($ch);
            if ($info['http_code'] == 200) {
                return true;
            }
            return false;
        }
    }
}