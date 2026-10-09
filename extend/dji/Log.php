<?php

namespace dji;

use ba\Storage;
use think\facade\Db;

class Log
{
    /**
     * 获取可上传日志
     */
    public function getLog()
    {
        $data = [];
        $data['bid'] = uuid();
        $data['tid'] = uuid();
        $data['timestamp'] = round(microtime(true) * 1000);
        $data['method'] = 'fileupload_list';
        $data['data'] = [
            'module_list' => ['0', '3'],
        ];
        $res = publish($data);
        if ($res) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 日志入库
     */
    public function saveLog($param)
    {
        if (isset($param['data']['files']) && count($param['data']['files']) > 0) {
            foreach($param['data']['files'] as $key => $val){
                foreach($val['list'] as $listkey => $listvalue){
                    print_r($listvalue);
                    $row = [];
                    $row['boot_index'] = $listvalue['boot_index'];
                    $row['end_time'] = $listvalue['end_time'];
                    $row['module'] = $val['module'];
                    $row['size'] = $listvalue['size'];
                    $row['start_time'] = $listvalue['start_time'];
                    $row['sn'] = $val['device_sn'];
                    Db::name('djilog')->insert($row);
                }
            }
        }
        return true;
    }

    /**
     * 发起日志上传
     */
    public function uploadLog($sn) 
    {
        // 使用统一的 Storage 类获取配置
        $config = Storage::getConfig();
        $credentials = Storage::isMinio() 
            ? ['access_key_id' => $config['access_key'], 'access_key_secret' => $config['secret_key'], 'expire' => 86400]
            : Storage::getStsCredentials();
        
        $type = [0, 3];
        $data = [];
        $data['topic'] = 'thing/product/' . $sn . '/services';
        $data['bid'] = uuid();
        $data['tid'] = uuid();
        $data['timestamp'] = round(microtime(true) * 1000);
        $data['method'] = 'fileupload_start';
        $data['data'] = [];
        $data['data']['bucket'] = $config['bucket'];
        $data['data']['credentials'] = $credentials;
        $data['data']['endpoint'] = $config['endpoint'];
        $data['data']['provider'] = Storage::isMinio() ? 'minio' : 'ali';
        $data['data']['region'] = str_replace('oss-', '', $config['region']);
        
        foreach($type as $key => $value){
            $list = Db::name('djilog')->field('boot_index')->where('module', $value)->limit(1)->order('id', 'desc')->select();
            $data['data']['params']['files'][$key] = [
                'list' => $list,
                'module' => (string)$value,
                'object_key' => 'log/' . $value . '/' . time() . '.log'
            ];
        }
        publish($data);
    }
}
