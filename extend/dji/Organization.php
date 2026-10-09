<?php

namespace dji;

use think\facade\Db;
use think\facade\Log;

/**
 * 组织管理类
 * 处理大疆设备组织绑定相关的 MQTT 消息
 */
class Organization
{
    /**
     * 处理设备查询绑定状态请求
     * Method: airport_bind_status
     * Direction: up (设备 → 云端)
     * 
     * @param array $param MQTT消息参数
     * @return bool
     */
    public function handleBindStatusRequest($param)
    {
        Log::info('【组织绑定】收到设备绑定状态查询: ' . json_encode($param, JSON_UNESCAPED_UNICODE));
        
        $devices = $param['data']['devices'] ?? [];
        $bind_status = [];
        
        foreach ($devices as $device) {
            $sn = $device['sn'];
            
            // 从数据库查询设备绑定信息
            $equipment = Db::name('equipment')
                ->alias('e')
                ->leftJoin('project p', 'e.project_id = p.id')
                ->where('e.sn', $sn)
                ->field('e.sn, e.is_bind_organization, e.project_id, e.device_callsign, p.name as project_name')
                ->find();
            
            if ($equipment) {
                $bind_status[] = [
                    'sn' => $sn,
                    'is_device_bind_organization' => $equipment['is_bind_organization'] == '1',
                    'organization_id' => (string)($equipment['project_id'] ?? ''),
                    'organization_name' => $equipment['project_name'] ?? '',
                    'device_callsign' => $equipment['device_callsign'] ?? ''
                ];
                
                Log::info("【组织绑定】设备 {$sn} 绑定状态: " . ($equipment['is_bind_organization'] == '1' ? '已绑定' : '未绑定'));
            } else {
                // 设备不存在，返回未绑定状态
                $bind_status[] = [
                    'sn' => $sn,
                    'is_device_bind_organization' => false,
                    'organization_id' => '',
                    'organization_name' => '',
                    'device_callsign' => ''
                ];
                
                Log::warning("【组织绑定】设备 {$sn} 不存在于数据库");
            }
        }
        
        // 构建回复消息
        $reply = [
            'bid' => $param['bid'],
            'tid' => $param['tid'],
            'timestamp' => round(microtime(true) * 1000),
            'method' => 'airport_bind_status',
            'topic' => 'thing/product/' . $param['sn'] . '/requests_reply',
            'data' => [
                'result' => 0,
                'output' => [
                    'bind_status' => $bind_status
                ]
            ]
        ];
        
        Log::info('【组织绑定】回复绑定状态: ' . json_encode($reply, JSON_UNESCAPED_UNICODE));
        
        return publish($reply);
    }
    
    /**
     * 处理设备查询组织信息请求
     * Method: airport_organization_get
     * Direction: up (设备 → 云端)
     * 
     * @param array $param MQTT消息参数
     * @return bool
     */
    public function handleOrganizationGetRequest($param)
    {
        Log::info('【组织绑定】收到组织信息查询: ' . json_encode($param, JSON_UNESCAPED_UNICODE));
        
        $device_binding_code = $param['data']['device_binding_code'] ?? '';
        $organization_id = $param['data']['organization_id'] ?? '';
        
        // organization_id 就是 project_id
        $project = Db::name('project')
            ->where('id', $organization_id)
            ->field('id, name')
            ->find();
        
        $organization_name = $project['name'] ?? '';
        $result = $organization_name ? 0 : 1; // 0成功，非0失败
        
        if ($result === 0) {
            Log::info("【组织绑定】查询到组织: ID={$organization_id}, Name={$organization_name}");
        } else {
            Log::warning("【组织绑定】组织 ID={$organization_id} 不存在");
        }
        
        // 构建回复消息
        $reply = [
            'bid' => $param['bid'],
            'tid' => $param['tid'],
            'timestamp' => round(microtime(true) * 1000),
            'method' => 'airport_organization_get',
            'topic' => 'thing/product/' . $param['sn'] . '/requests_reply',
            'data' => [
                'result' => $result,
                'output' => [
                    'organization_name' => $organization_name
                ]
            ]
        ];
        
        Log::info('【组织绑定】回复组织信息: ' . json_encode($reply, JSON_UNESCAPED_UNICODE));
        
        return publish($reply);
    }
    
    /**
     * 处理设备绑定到组织请求
     * Method: airport_organization_bind
     * Direction: up (设备 → 云端)
     * 
     * @param array $param MQTT消息参数
     * @return bool
     */
    public function handleOrganizationBindRequest($param)
    {
        Log::info('【组织绑定】收到设备绑定请求: ' . json_encode($param, JSON_UNESCAPED_UNICODE));
        
        $bind_devices = $param['data']['bind_devices'] ?? [];
        $err_infos = [];
        $all_success = true;
        
        // 加载产品配置
        $productConfig = include app()->getConfigPath() . 'dji_products.php';
        
        Db::startTrans();
        try {
            // ========== 第一阶段：先创建/更新所有设备（不处理parent_id） ==========
            $created_drones = []; // 记录新创建的飞机信息，用于第二阶段建立父子关系
            $new_devices = []; // 记录所有新创建的设备SN，用于触发MQTT订阅
            
            foreach ($bind_devices as $device) {
                $sn = $device['sn'];
                $device_binding_code = $device['device_binding_code'];
                $organization_id = $device['organization_id'];
                $device_callsign = $device['device_callsign'];
                $device_model_key = $device['device_model_key'];
                
                // 验证组织（项目）是否存在
                $project = Db::name('project')->where('id', $organization_id)->find();
                if (!$project) {
                    Log::warning("【组织绑定】组织 {$organization_id} 不存在");
                    $err_infos[] = [
                        'sn' => $sn,
                        'err_code' => 210232 // 组织不存在
                    ];
                    $all_success = false;
                    continue;
                }
                
                // 解析 device_model_key 获取 domain, type, sub_type
                $modelInfo = $productConfig['parse_model_key']($device_model_key);
                $domain = $modelInfo['domain'];
                $type_code = $modelInfo['type'];
                $sub_type = $modelInfo['sub_type'];
                
                // 获取产品信息
                $productInfo = $productConfig['get_product_info']($device_model_key);
                $model_name = $productInfo['name'] ?? '未知设备';
                
                // 判断设备类型（机场/飞机）
                $device_category = $domain == 3 ? '3' : '0'; // 3=机场, 0=飞机
                if ($domain == 0) {
                    $created_drones[] = $sn;
                }
                
                // 查询设备是否存在
                $equipment = Db::name('equipment')->where('sn', $sn)->find();
                
                if ($equipment) {
                    // 设备存在，更新绑定信息
                    $updateData = [
                        'device_binding_code' => $device_binding_code,
                        'project_id' => $organization_id,
                        'device_callsign' => $device_callsign,
                        'domain' => $domain,
                        'type_code' => $type_code,
                        'sub_type' => $sub_type,
                        'model' => $model_name,
                        'is_bind_organization' => '1',
                        'bind_time' => time(),
                        'update_time' => time()
                    ];
                    
                    Db::name('equipment')
                        ->where('id', $equipment['id'])
                        ->update($updateData);
                    
                    Log::info("【组织绑定】更新设备 {$sn} 绑定信息成功");
                } else {
                    // 设备不存在，自动创建（第一阶段不设置parent_id）
                    $insertData = [
                        'sn' => $sn,
                        'nickname' => $device_callsign, // 默认使用组织名称作为昵称
                        'device_binding_code' => $device_binding_code,
                        'project_id' => $organization_id,
                        'device_callsign' => $device_callsign,
                        'domain' => $domain,
                        'type_code' => $type_code,
                        'sub_type' => $sub_type,
                        'model' => $model_name,
                        'device_category' => $device_category,
                        'manufacturer' => '0', // 0=大疆
                        'is_bind_organization' => '1',
                        'bind_time' => time(),
                        'firmware_version' => '',
                        'create_time' => time(),
                        'update_time' => time()
                    ];
                    
                    Db::name('equipment')->insert($insertData);
                    
                    Log::info("【组织绑定】自动创建设备 {$sn} 成功: {$model_name}, 组织ID={$organization_id}");
                    
                    // 记录所有新创建的设备，用于第三阶段触发MQTT订阅
                    $new_devices[] = $sn;
                    
                    // 记录新创建的飞机，用于第二阶段建立父子关系
                }
            }
            
            // ========== 第二阶段：处理飞机的parent_id关系 ==========
            // 只处理本次新创建的飞机设备
            if (!empty($created_drones)) {
                // 从本批次设备中查找机场SN
                $dock_sn = null;
                foreach ($bind_devices as $device) {
                    $model_info = $productConfig['parse_model_key']($device['device_model_key']);
                    if ($model_info['domain'] == 3) { // 机场
                        $dock_sn = $device['sn'];
                        break;
                    }
                }
                
                // 为所有新创建的飞机建立父子关系
                if ($dock_sn) {
                    foreach ($created_drones as $drone_sn) {
                        $this->setParentRelation($drone_sn, $dock_sn);
                    }
                } else {
                    Log::warning("【组织绑定】本批次设备中未找到机场，新创建的飞机暂无父设备");
                }
            }
            
            Db::commit();
            
            // ========== 第三阶段：触发MQTT订阅新创建的设备 ==========
            foreach ($new_devices as $new_sn) {
                $this->notifyMqttSubscribe($new_sn);
            }
            
            Log::info('【组织绑定】所有设备绑定完成');
        } catch (\Exception $e) {
            Db::rollback();
            Log::error('【组织绑定】绑定失败: ' . $e->getMessage());
            $all_success = false;
            
            // 如果是全部失败，添加通用错误
            if (empty($err_infos)) {
                foreach ($bind_devices as $device) {
                    $err_infos[] = [
                        'sn' => $device['sn'],
                        'err_code' => 500000 // 系统错误
                    ];
                }
            }
        }
        
        // 构建回复消息
        $reply = [
            'bid' => $param['bid'],
            'tid' => $param['tid'],
            'timestamp' => round(microtime(true) * 1000),
            'method' => 'airport_organization_bind',
            'topic' => 'thing/product/' . $param['sn'] . '/requests_reply',
            'data' => [
                'result' => $all_success ? 0 : 1,
                'output' => [
                    'err_infos' => $err_infos
                ]
            ]
        ];
        
        Log::info('【组织绑定】回复绑定结果: ' . json_encode($reply, JSON_UNESCAPED_UNICODE));
        
        return publish($reply);
    }
    
    /**
     * 处理机场和飞机的父子关系
     * 在飞机创建后，如果机场也在绑定列表中，建立父子关系
     * 
     * @param string $drone_sn 飞机SN
     * @param string $dock_sn 机场SN
     * @return bool
     */
    protected function setParentRelation($drone_sn, $dock_sn)
    {
        $dock = Db::name('equipment')->where('sn', $dock_sn)->find();
        if (!$dock) {
            Log::warning("【组织绑定】机场 {$dock_sn} 不存在，无法建立父子关系");
            return false;
        }
        
        $result = Db::name('equipment')
            ->where('sn', $drone_sn)
            ->update([
                'parent_id' => $dock['id'],
                'update_time' => time(),
            ]);
        
        if ($result) {
            Log::info("【组织绑定】设置飞机 {$drone_sn} 的父设备为机场 {$dock_sn}");
            return true;
        }
        
        return false;
    }
    
    /**
     * 通知MQTT订阅新设备
     * 发布消息到 system/equipment/set/equipment_add 主题，触发MQTT订阅该设备
     * 
     * @param string $sn 设备序列号
     * @return bool
     */
    protected function notifyMqttSubscribe($sn)
    {
        $message = [
            'topic' => 'system/equipment/set/equipment_add',
            'new_sn' => $sn
        ];
        
        $result = publish($message);
        
        if ($result) {
            Log::info("【组织绑定】已通知MQTT订阅新设备: {$sn}");
        } else {
            Log::warning("【组织绑定】通知MQTT订阅失败: {$sn}");
        }
        
        return $result;
    }
    
    /**
     * 处理子设备自动绑定
     * 当收到机场OSD消息包含子设备信息时，自动为子设备绑定组织
     * 
     * @param array $param OSD消息参数
     * @return void
     */
    public function handleSubDeviceBind($param)
    {
        $dock_sn = $param['sn'];  // 机场SN（gateway）
        $drone_sn = $param['data']['sub_device']['device_sn'];  // 飞机SN
        
        Log::info("【子设备绑定】收到OSD消息，机场: {$dock_sn}, 子设备: {$drone_sn}");
        
        // 查询机场信息
        $dock = Db::name('equipment')->where('sn', $dock_sn)->find();
        
        if (!$dock) {
            Log::warning("【子设备绑定】机场 {$dock_sn} 不存在，跳过子设备绑定");
            return;
        }
        
        // 检查机场是否有项目ID（支持手动添加和组织绑定两种方式）
        if (empty($dock['project_id'])) {
            Log::info("【子设备绑定】机场 {$dock_sn} 未分配项目，跳过子设备绑定");
            return;
        }
        
        // 查询子设备是否存在
        $drone = Db::name('equipment')->where('sn', $drone_sn)->find();
        
        if (!$drone) {
            // 子设备不存在，自动创建并绑定
            $this->autoCreateAndBindSubDevice($drone_sn, $dock, $param['data']['sub_device']);
        } elseif (empty($drone['project_id']) || $drone['project_id'] != $dock['project_id']) {
            // 子设备存在但未分配项目，或项目与机场不一致
            $this->updateSubDeviceBind($drone_sn, $dock);
        } else {
            Log::info("【子设备绑定】子设备 {$drone_sn} 已正确绑定到项目 {$dock['project_id']}");
        }
    }
    
    /**
     * 自动创建并绑定子设备
     * 
     * @param string $drone_sn 飞机SN
     * @param array $dock 机场信息
     * @param array $sub_device_data OSD中的子设备数据
     * @return bool
     */
    protected function autoCreateAndBindSubDevice($drone_sn, $dock, $sub_device_data)
    {
        try {
            // 加载产品配置
            $productConfig = include app()->getConfigPath() . 'dji_products.php';
            $existingDrone = Db::name('equipment')->where('sn', $drone_sn)->find();
            if ($existingDrone) {
                Log::info("【子设备绑定】子设备已存在，改为更新绑定: {$drone_sn}");
                return $this->updateSubDeviceBind($drone_sn, $dock);
            }
            
            // 根据机场类型判断飞机型号
            $droneModelKey = '';
            if ((int)$dock['type_code'] === 2) {
                // 大疆机场 2 配套 M3D
                $droneModelKey = '0-91-0';
            } elseif ((int)$dock['type_code'] === 3) {
                // 大疆机场 3 配套 M4D
                $droneModelKey = '0-100-0';
            } else {
                // 其他机场默认 M4D
                $droneModelKey = '0-100-0';
            }
            
            // 解析飞机产品信息
            $droneModelInfo = $productConfig['parse_model_key']($droneModelKey);
            $droneProductInfo = $productConfig['get_product_info']($droneModelKey);
            
            $insertData = [
                'sn' => $drone_sn,
                'parent_id' => $dock['id'],
                'nickname' => $dock['nickname'] . '-' . $droneProductInfo['name'],
                'domain' => $droneModelInfo['domain'],        // 0
                'type_code' => $droneModelInfo['type'],       // 91 或 100
                'sub_type' => $droneModelInfo['sub_type'],    // 0
                'model' => $droneProductInfo['name'],         // "M3D" 或 "M4D"
                'device_category' => '0',                     // 0=飞机
                'manufacturer' => '0',                        // 0=大疆
                'project_id' => $dock['project_id'],          // ⭐ 继承机场的组织
                'is_bind_organization' => '1',                // ⭐ 自动绑定
                'bind_time' => $dock['bind_time'],            // 使用机场的绑定时间
                'device_callsign' => ($dock['device_callsign'] ?? $dock['nickname']) . '-' . $droneProductInfo['name'],
                'firmware_version' => '',
                'create_time' => time(),
                'update_time' => time(),
            ];
            
            Db::name('equipment')->insert($insertData);
            
            // 触发MQTT订阅（通过现有机制）
            $this->notifyMqttSubscribe($drone_sn);
            
            Log::info("【子设备绑定】自动创建并绑定子设备成功: {$drone_sn} → 组织ID={$dock['project_id']}, 型号={$droneProductInfo['name']}");
            
            return true;
        } catch (\Exception $e) {
            Log::error("【子设备绑定】创建子设备失败: {$drone_sn}, 错误: {$e->getMessage()}");
            return false;
        }
    }
    
    /**
     * 更新已存在但未绑定的子设备
     * 
     * @param string $drone_sn 飞机SN
     * @param array $dock 机场信息
     * @return bool
     */
    protected function updateSubDeviceBind($drone_sn, $dock)
    {
        try {
            $updateData = [
                'parent_id' => $dock['id'],
                'project_id' => $dock['project_id'],
                'is_bind_organization' => '1',
                'bind_time' => $dock['bind_time'],
                'update_time' => time(),
            ];
            
            // 如果子设备没有 device_callsign，则自动设置
            $drone = Db::name('equipment')->where('sn', $drone_sn)->find();
            if (empty($drone['device_callsign'])) {
                $updateData['device_callsign'] = ($dock['device_callsign'] ?? $dock['nickname']) . '-' . $drone['model'];
            }
            
            Db::name('equipment')
                ->where('sn', $drone_sn)
                ->update($updateData);
            
            Log::info("【子设备绑定】更新子设备绑定成功: {$drone_sn} → 组织ID={$dock['project_id']}");
            
            return true;
        } catch (\Exception $e) {
            Log::error("【子设备绑定】更新子设备绑定失败: {$drone_sn}, 错误: {$e->getMessage()}");
            return false;
        }
    }
}
