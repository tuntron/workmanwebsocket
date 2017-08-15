<?php
/**
 * Created by PhpStorm.
 * User: zilongs
 * Date: 17-8-8
 * Time: 上午10:26
 */
use Workerman\Worker;
// composer 的 autoload 文件
include __DIR__ . '/vendor/autoload.php';

if(strpos(strtolower(PHP_OS), 'win') === 0)
{
    exit("start.php not support windows, please use start_for_win.bat\n");
}

// 标记是全局启动
define('GLOBAL_START', 1);
//私钥
$private_key = '-----BEGIN RSA PRIVATE KEY-----
MIICWwIBAAKBgQC18vsu+nSxyvrtCjiujE8RPEbbpP74eX0mFbo1vAnN5TUD3NeD
s2EXe5iYOz8/kYgdN9t4VbKelne2FLXb0gQ7/lnUFka8uK7abeLobufklzekIATY
qHicispPg/5odHqR1oNoMWy/AJ7t9+O/GcEXMkpE0MYAK46i4NdHWgUDzwIDAQAB
AoGAJWbte5rAoku3iUKwpDDzj/d0GXKxdyKCN3H/9UvSOCEF5OVg6BHXw5wEokaL
meWwtVDmLLZxIWiM80EOoUFq3RD9tsUE66HE7v4NiK9L8dK/a7HZ0+k8pUnWIqpm
ZplTdIQE/RKZsPmTPA8KCrS2Fq2IHe//nsmVcu486onAhgECQQDx1Kk69GEecKjj
z0ff5FWaTtkybSyo2R4VFgvmlRkEmAY/hY9e1bX0XPS97BIYPw1WH+szB8UHyubr
WQ4ht/4pAkEAwJwgd63cYzOJp1SQM2kWAXI/WbmdWLIRfbIzy3PuwhaTqCQij0Tg
jHtUZQZjc8+n5BfjzJyBgGppzHNqWO1BNwJAe61kIzeKV9QMO/3tZ07SjMlYgVae
aXgoz2XoDjQgiF3rjB8VVM39cYz8ygjqtCXC/1HxqraFiNe3Q5PXC12bCQJAMyfE
P8T/aaGAh96fxee9Hnk3dh8kOTBiEN5Jf1m1KftREDE4tJB4ixceXQ6LT3DxiFUH
/Yn7ox2gJ9rnfeLVlQJAOiIaxx2pLWfy0drnMynFq9XMf16Ubk8uI1huQzszOUke
teX9T4hC/xCGJcG1YzskHTwZ20h+TYz4CO216/xJIQ==
-----END RSA PRIVATE KEY-----';
//公钥
$public_key = '-----BEGIN PUBLIC KEY-----
MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC18vsu+nSxyvrtCjiujE8RPEbb
pP74eX0mFbo1vAnN5TUD3NeDs2EXe5iYOz8/kYgdN9t4VbKelne2FLXb0gQ7/lnU
Fka8uK7abeLobufklzekIATYqHicispPg/5odHqR1oNoMWy/AJ7t9+O/GcEXMkpE
0MYAK46i4NdHWgUDzwIDAQAB
-----END PUBLIC KEY-----';

$localhost_ip = 'docker中';
$env = '正式环境';

function doJsonPost($url, $json_data = '', $timeout = 30)
{
    if (!is_string($json_data)) return false;
    $opts = array(
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_URL => $url,
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => $json_data,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json; charset=utf-8;',
            'Content-Length: ' . strlen($json_data),
        )
    );
    $ch = curl_init();
    curl_setopt_array($ch, $opts);
    $result = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    if(!empty($error))
    {
        return $error;
    }
    return $result;
}

// 加载IO
require_once __DIR__ . '/wechatSocketIo.php';
require_once __DIR__ . '/corpSocketIo.php';

// 运行所有服务
Worker::runAll();