<?php
/**
 * Created by PhpStorm.
 * User: zilongs
 * Date: 17-8-8
 * Time: 上午10:27
 */
use Workerman\Worker;
use PHPSocketIO\SocketIO;
use Workerman\Lib\Timer;

include __DIR__ . '/vendor/autoload.php';

// 全局数组保存uid在线数据
$uidConnectionMap = array();
// 全局记录客户端token的数据
$clientTokenMap = array();

try {
    // PHPSocketIO服务
    $sender_io = new SocketIO(2120);
    // 客户端发起连接事件时，设置连接socket的各种事件回调
    $sender_io->on('connection', function ($socket) {
        // 当客户端发来登录事件时触发
        $socket->on('login', function ($uid, $group, $clientToken) use ($socket) {
            global $uidConnectionMap, $clientTokenMap, $private_key;

            if (!in_array($clientToken, $clientTokenMap)) {
                $pi_key = openssl_pkey_get_private($private_key);
                $encryptData = base64_decode($clientToken);
                openssl_private_decrypt($encryptData, $data, $pi_key);
                if (!empty($data)) {
                    $data_array = json_decode($data, true);
                    if (!empty($data_array)) {
                        if ($data_array['uid'] == $uid && time() - (int)$data_array['time'] <= 300) {
                            $clientTokenMap[] = $clientToken;
                            // 已经登录过了
                            if (isset($socket->uid)) {
                                return;
                            }
                            // 更新对应uid的在线数据
                            $uid = (string)$uid;
                            if (!isset($uidConnectionMap[$uid])) {
                                $uidConnectionMap[$uid] = 0;
                            }
                            // 这个uid有++$uidConnectionMap[$uid]个socket连接
                            ++$uidConnectionMap[$uid];
                            // 将这个连接加入到uid分组，方便针对uid推送数据
                            $socket->join('token');
                            $socket->join($uid);
                            $socket->join($group);
                            $socket->uid = $uid;
                        }
                    }
                }
            }
        });

        // 当客户端断开连接是触发（一般是关闭网页或者跳转刷新导致）
        $socket->on('disconnect', function () use ($socket) {
            if (!isset($socket->uid)) {
                return;
            }
            global $uidConnectionMap, $sender_io;
            // 将uid的在线socket数减一
            if (--$uidConnectionMap[$socket->uid] <= 0) {
                unset($uidConnectionMap[$socket->uid]);
            }
        });
    });

    // 当$sender_io启动后监听一个http端口，通过这个端口可以给任意uid或者所有uid推送数据
    $sender_io->on('workerStart', function () {
        // 监听一个http端口
        $inner_http_worker = new Worker('http://0.0.0.0:2121');
        // 当http客户端发来数据时触发
        $inner_http_worker->onMessage = function ($http_connection, $data) {
            global $uidConnectionMap, $public_key;
            $_POST = $_POST ? $_POST : $_GET;
            $serviceToken = @$_POST['token'];
            if (!empty($serviceToken)) {
                $pu_key = openssl_pkey_get_public($public_key);
                $encryptData = base64_decode($serviceToken);
                openssl_public_decrypt($encryptData, $data, $pu_key);
                if (!empty($data)) {
                    $data_array = json_decode($data, true);
                    if (!empty($data_array) && time() - (int)$data_array['time'] <= 2) {
                        // 推送数据的url格式 action=actionName&to=uid&content=xxxx&group=xxx
                        global $sender_io;
                        $to = @$_POST['to'];
                        $group = @$_POST['group'];
                        $_POST['content'] = @$_POST['content'];
                        // uid有指定组则向uid所在group组发送数据
                        if ($group) {
                            $sender_io->to($group)->emit(@$_POST['action'], @$_POST['content']);
                        } // 有指定uid则向uid发送数据
                        else if ($to) {
                            $sender_io->to($to)->emit(@$_POST['action'], @$_POST['content']);
                        } else {
                            // 否则向所有uid推送数据
                            $sender_io->to('token')->emit(@$_POST['action'], @$_POST['content']);
                        }
                        // http接口返回，如果用户离线socket返回fail
                        if ($to && !isset($uidConnectionMap[$to])) {
                            return $http_connection->send('offline');
                        } else {
                            return $http_connection->send('ok');
                        }
                    }
                }
            }
            return $http_connection->send('No permission!!!');
        };
        // 执行监听
        $inner_http_worker->listen();

        // 一个定时器，定时(1小时)清空$clientTokenMap全局数组，释放内存
        Timer::add(3600, function () {
            global $clientTokenMap;
            $clientTokenMap = array();
        });
    });

    if (!defined('GLOBAL_START')) {
        Worker::runAll();
    }
} catch (\Exception $exception) {
    $webhook = "https://oapi.dingtalk.com/robot/send?access_token=abbd3350b05da3cd5a8a0cab565499a92f59b5d8480f8f10d47dc2ef94087609";

    $error_code = $exception->getCode();
    $error_msg = $exception->getMessage();
    $error_file = $exception->getFile();
    $error_line = $exception->getLine();
    $error_trace = (string)$exception;
    $now_time = date('Y-m-d H:i:s', time());

    $api = '微信webSocket报错';
    $message = sprintf("#### 错误报警(%s):%s\n##### 报错时间:%s\n##### 接口:%s\n##### 错误码:%s\n##### 错误文件:%s\n##### 报错行数:%s   错误信息:%s\n##### 跟踪信息:\n%s", $env, $localhost_ip, $now_time, $api, $error_code, $error_file, $error_line, $error_msg, $error_trace);

    $data_array = array(
        'msgtype' => 'markdown',
        'markdown' => array(
            'title' => '错误报警('.$env.')',
            'text' => $message
        )
    );
    $data_json = json_encode($data_array);

    doJsonPost($webhook, $data_json);
}