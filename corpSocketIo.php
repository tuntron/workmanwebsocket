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
$uidConnectionMap1 = array();
// 全局记录客户端token的数据
$clientTokenMap1 = array();

try {
    // PHPSocketIO服务
    $sender_io1 = new SocketIO(2122);
    // 客户端发起连接事件时，设置连接socket的各种事件回调
    $sender_io1->on('connection', function($socket1){
        // 当客户端发来登录事件时触发
        $socket1->on('login', function ($uid, $group, $clientToken)use($socket1){
            global $uidConnectionMap1,$clientTokenMap1,$private_key;

            if(!in_array($clientToken, $clientTokenMap1)){
                $pi_key =  openssl_pkey_get_private($private_key);
                $encryptData = base64_decode($clientToken);
                openssl_private_decrypt($encryptData, $data, $pi_key);
                if(!empty($data)){
                    $data_array = json_decode($data, true);
                    if(!empty($data_array)){
                        if($data_array['uid'] == $uid && time() - (int)$data_array['time'] <= 300 ){
                            $clientTokenMap1[] = $clientToken;
                            // 已经登录过了
                            if(isset($socket1->uid)){
                                return;
                            }
                            // 更新对应uid的在线数据
                            $uid = (string)$uid;
                            if(!isset($uidConnectionMap1[$uid]))
                            {
                                $uidConnectionMap1[$uid] = 0;
                            }
                            // 这个uid有++$uidConnectionMap[$uid]个socket连接
                            ++$uidConnectionMap1[$uid];
                            // 将这个连接加入到uid分组，方便针对uid推送数据
                            $socket1->join('token');
                            if(!empty($group)){
                                $socket1->join($group);
                            }
                            $socket1->join($uid);
                            $socket1->uid = $uid;
                        }
                    }
                }
            }
        });

        // 当客户端断开连接是触发（一般是关闭网页或者跳转刷新导致）
        $socket1->on('disconnect', function () use($socket1) {
            if(!isset($socket1->uid))
            {
                return;
            }
            global $uidConnectionMap1;
            // 将uid的在线socket数减一
            if(--$uidConnectionMap1[$socket1->uid] <= 0)
            {
                unset($uidConnectionMap1[$socket1->uid]);
            }
        });
    });

    // 当$sender_io启动后监听一个http端口，通过这个端口可以给任意uid或者所有uid推送数据
    $sender_io1->on('workerStart', function(){
        // 监听一个http端口
        $inner_http_worker1 = new Worker('http://0.0.0.0:2123');
        // 当http客户端发来数据时触发
        $inner_http_worker1->onMessage = function($http_connection1, $data){
            global $uidConnectionMap1,$public_key;
            $_POST = $_POST ? $_POST : $_GET;
            $serviceToken = @$_POST['token'];
            if(!empty($serviceToken)){
                $pu_key = openssl_pkey_get_public($public_key);
                $encryptData = base64_decode($serviceToken);
                openssl_public_decrypt($encryptData, $data, $pu_key);
                if(!empty($data)){
                    $data_array = json_decode($data, true);
                    if(!empty($data_array) && time() - (int)$data_array['time'] <= 2){
                        // 推送数据的url格式 action=actionName&to=uid&content=xxxx&group=xxx
                        global $sender_io1;
                        $to = @$_POST['to'];
                        $group = @$_POST['group'];
                        $_POST['content'] = @$_POST['content'];
                        // uid有指定组则向uid所在group组发送数据
                        if ($group) {
                            $sender_io1->to($group)->emit(@$_POST['action'], @$_POST['content']);
                        }
                        // 有指定uid则向uid发送数据
                        else if($to){
                            $sender_io1->to($to)->emit(@$_POST['action'], @$_POST['content']);
                        }
                        else{
                            // 否则向所有uid推送数据
                            $sender_io1->to('token')->emit(@$_POST['action'], @$_POST['content']);
                        }
                        // http接口返回，如果用户离线socket返回fail
                        if($to && !isset($uidConnectionMap1[$to])){
                            return $http_connection1->send('offline');
                        }else{
                            return $http_connection1->send('ok');
                        }
                    }
                }
            }
            return $http_connection1->send('No permission!!!');
        };
        // 执行监听
        $inner_http_worker1->listen();

        // 一个定时器，定时(1小时)清空$clientTokenMap全局数组，释放内存
        Timer::add(3600, function(){
            global $clientTokenMap1;
            $clientTokenMap1 = array();
        });
    });

    if(!defined('GLOBAL_START'))
    {
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

    $api = '包网webSocket报错';
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