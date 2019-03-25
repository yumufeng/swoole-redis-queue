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
            'worker_num' =>
                2,
            'dispatch_mode' => 2,
            'task_worker_num' => 4,
            'task_max_request' => 1000,
            'reload_async' => true,
            'max_wait_time' => 50,
            'task_enable_coroutine' => true,
            'task_tmpdir' => $this->env->get('root_path') . 'log/task',
            'daemonize' => 1,
            'log_file' => $this->env->get('root_path') . 'swoole.log',
            'pid_file' => $this->env->get('root_path') . 'server.pid',
            'max_request' => 20000
        ));
        $http->on('Start', [$this, 'onStart']);
        $http->on('WorkerStart', [$this, 'onWorkerStart']);
        $http->on("request", [$this, 'onRequest']);
        $http->on('task', [$this, 'onTask']);
        $http->on('finish', [$this, 'onFinish']);
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
                if (isset($params['debug']) && $params['debug'] == 1) {
                    for ($i = 0; $i < 200; $i++) {
                        Redis::setDefer(false)->zAdd($this->jobConfig['topic'], $params['time'], $i);
                    }
                } else {
                    Redis::zAdd($this->jobConfig['topic'], $params['time'], $params['job_id']);
                }
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
        $this->jobConfig = $jobConfig = $this->config->pull('job');
        /**
         * 开启redis连接池
         */
        $this->redis->clearTimer($server);
        if (0 === $worker_id) {
            $server->tick(1000, function () use ($server, $jobConfig) {
                $time = \time();
                $jobList = Redis::zRangeByScore($jobConfig['topic'], 0, $time);
                if (!empty($jobList)) {
                    $server->task([
                        'jobList' => $jobList,
                        'time' => $time
                    ]);
                }
            });
        }
    }


    public function onTask($serv, \Swoole\Server\Task $task)
    {
        $this->pageLimit($task->data['jobList'], $task->data['time']);
        $task->finish($task->data['time']);
    }

    public function onFinish(\swoole_server $serv, int $task_id, string $data)
    {
        var_dump($data);
    }

    private function pageLimit($jobList, $time)
    {
        $jobCount = \count($jobList);
        Redis::zRemRangeByScore($this->jobConfig['topic'], 0, $time);
        $pageSize = 50;
        $pageCount = ceil($jobCount / $pageSize);
        for ($page = 0; $page < $pageCount; $page++) {
            $execArray = array_splice($jobList, 0, $pageSize);
            if (!empty($execArray)) {
                go(function () use ($execArray) {
                    $this->exec($execArray);
                });
            }
        }
        return true;
    }

    protected function exec($jobList)
    {
        $result = $this->curl($this->jobConfig['call_url'], $jobList);
        if (!$result) {
            $rediskey = $this->jobConfig['topic'] . md5(http_build_query($jobList));
            $count = Redis::get($rediskey) ? Redis::get($rediskey) : 0;
            if ($count > 0) {
                if ($count > $this->jobConfig['max_try'] - 1) {
                    Redis::setDefer(false)->del($rediskey);
                    return false;
                }
                Redis::incrBy($rediskey, 1);
            } else {
                Redis::set($rediskey, 1, 120);
            }
            if ($count > 0) {
                \co::sleep($count * 5);
                $this->exec($jobList);
            } else {
                \co::sleep(1);
                $this->exec($jobList);
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
            $root_path = \app\facades\Env::get('root_path') . 'log';
            if (!is_dir($root_path)) {
                @mkdir($root_path);
            }
            file_put_contents($root_path . '/' . date('m_d') . '.log', json_encode($result) . PHP_EOL, FILE_APPEND);
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