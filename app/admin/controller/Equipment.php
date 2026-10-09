<?php

namespace app\admin\controller;

use Throwable;
use app\common\controller\Backend;

/**
 * 设备厂商管理
 */
class Equipment extends Backend
{
    /**
     * Equipment模型对象
     * @var object
     * @phpstan-var \app\admin\model\Equipment
     */
    protected object $model;

    protected array|string $preExcludeFields = ['id', 'create_time', 'update_time'];

    protected array $withJoinTable = ['project'];

    protected string|array $quickSearchField = ['id'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new \app\admin\model\Equipment();
    }

    /**
     * 查看
     * @throws Throwable
     */
    public function index(): void
    {
        // 如果是 select 则转发到 select 方法，若未重写该方法，其实还是继续执行 index
        if ($this->request->param('select')) {
            $this->select();
        }

        /**
         * 1. withJoin 不可使用 alias 方法设置表别名，别名将自动使用关联模型名称（小写下划线命名规则）
         * 2. 以下的别名设置了主表别名，同时便于拼接查询参数等
         * 3. paginate 数据集可使用链式操作 each(function($item, $key) {}) 遍历处理
         */
        list($where, $alias, $limit, $order) = $this->queryBuilder();
        $where['domain'] = 3;
        $res = $this->model
            ->withJoin($this->withJoinTable, $this->withJoinType)
            ->visible(['project' => ['name']])
            ->alias($alias)
            ->where($where)
            ->order($order)
            ->paginate($limit);
        $list = $res->items();
        foreach ($list as $key => $value) {
            $list[$key]['children'] = $this->model->where('parent_id',$value['id'])->where('domain',0)->select();
        }
        $this->success('', [
            'list'   => $list,
            'total'  => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 若需重写查看、编辑、删除等方法，请复制 @see \app\admin\library\traits\Backend 中对应的方法至此进行重写
     */

    /**
     * 添加
     */
    public function add(): void
    {
        if ($this->request->isPost()) {
            $data = $this->request->post();
            if (!$data) {
                $this->error(__('Parameter %s can not be empty', ['']));
            }

            $data = $this->excludeFields($data);
            if ($this->dataLimit && $this->dataLimitFieldAutoFill) {
                $data[$this->dataLimitField] = $this->auth->id;
            }

            $result = false;
            $this->model->startTrans();
            try {
                // 模型验证
                if ($this->modelValidate) {
                    $validate = str_replace("\\model\\", "\\validate\\", get_class($this->model));
                    if (class_exists($validate)) {
                        $validate = new $validate();
                        if ($this->modelSceneValidate) $validate->scene('add');
                        $validate->check($data);
                    }
                }
                $data['domain'] = 3;
                $data['create_time'] = time();
                $data['update_time'] = time();
                
                // 入库设备（仅机场本身）
                $result = $this->model->insertGetId($data);
                
                // 发布MQTT订阅消息（仅订阅机场）
                // 后续通过OSD消息自动发现并订阅子设备
                if ($result) {
                    $this->notifyMqttSubscribe($data['sn']);
                }
                
                $this->model->commit();
            } catch (Throwable $e) {
                $this->model->rollback();
                $this->error($e->getMessage());
            }
            if ($result !== false) {
                $this->success(__('Added successfully'));
            } else {
                $this->error(__('No rows were added'));
            }
        }

        $this->error(__('Parameter error'));
    }
    
    /**
     * 通知MQTT订阅新设备
     * 
     * @param string $sn 设备序列号
     * @return void
     */
    protected function notifyMqttSubscribe($sn)
    {
        $message = [
            'topic' => 'system/equipment/set/equipment_add',
            'new_sn' => $sn
        ];
        
        publish($message);
    }
    
    /**
     * 获取飞行器型号列表(按系列分组)
     * 用于航线创建时选择适用机型
     * 
     * @return void
     */
    public function getAircraftList(): void
    {
        try {
            // 加载产品配置
            $productConfig = config('dji_products');
            
            if (!isset($productConfig['aircraft']) || !is_array($productConfig['aircraft'])) {
                $this->error('产品配置文件异常');
            }
            
            $aircraftList = $productConfig['aircraft'];
            
            // 系列定义及优先级
            $seriesDefinition = [
                'M4' => [
                    'name' => 'Matrice 4 系列',
                    'name_en' => 'Matrice 4 Series',
                    'types' => [99, 100, 103],  // M4E/M4T, M4D/M4TD, M400
                    'order' => 1
                ],
                'M3' => [
                    'name' => 'Matrice 3 系列',
                    'name_en' => 'Matrice 3 Series',
                    'types' => [91],  // M3D/M3TD
                    'order' => 2
                ],
                'M350' => [
                    'name' => 'Matrice 350 系列',
                    'name_en' => 'Matrice 350 Series',
                    'types' => [89],
                    'order' => 3
                ],
                'M30' => [
                    'name' => 'Matrice 30 系列',
                    'name_en' => 'Matrice 30 Series',
                    'types' => [67],
                    'order' => 4
                ],
                'M300' => [
                    'name' => 'Matrice 300 系列',
                    'name_en' => 'Matrice 300 Series',
                    'types' => [60],
                    'order' => 5
                ],
                'Mavic' => [
                    'name' => 'Mavic 3 系列',
                    'name_en' => 'Mavic 3 Series',
                    'types' => [77],
                    'order' => 6
                ],
            ];
            
            // 按系列分组
            $groupedAircraft = [];
            foreach ($aircraftList as $key => $aircraft) {
                $type = $aircraft['type'];
                
                // 查找所属系列
                $seriesKey = null;
                foreach ($seriesDefinition as $sKey => $series) {
                    if (in_array($type, $series['types'])) {
                        $seriesKey = $sKey;
                        break;
                    }
                }
                
                // 未知系列归为其他
                if (!$seriesKey) {
                    $seriesKey = 'Other';
                }
                
                // 初始化系列分组
                if (!isset($groupedAircraft[$seriesKey])) {
                    $groupedAircraft[$seriesKey] = [
                        'series' => $seriesKey,
                        'series_name' => $seriesDefinition[$seriesKey]['name'] ?? '其他型号',
                        'series_name_en' => $seriesDefinition[$seriesKey]['name_en'] ?? 'Other',
                        'order' => $seriesDefinition[$seriesKey]['order'] ?? 99,
                        'aircraft' => []
                    ];
                }
                
                // 添加到对应系列
                $groupedAircraft[$seriesKey]['aircraft'][] = [
                    'value' => $key,
                    'label' => $aircraft['name'],
                    'label_en' => $aircraft['name_en'] ?? $aircraft['name'],
                    'domain' => $aircraft['domain'],
                    'type' => $aircraft['type'],
                    'sub_type' => $aircraft['sub_type'],
                ];
            }
            
            // 按order排序系列
            usort($groupedAircraft, function($a, $b) {
                return $a['order'] <=> $b['order'];
            });
            
            // 每个系列内部按sub_type排序
            foreach ($groupedAircraft as &$series) {
                usort($series['aircraft'], function($a, $b) {
                    return $a['sub_type'] <=> $b['sub_type'];
                });
            }
            
            
            
        } catch (Throwable $e) {
            $this->error('获取飞行器列表失败: ' . $e->getMessage());
        }
        $this->success('', [
                'series' => $groupedAircraft,  // 分组数据
                'total' => count($aircraftList)
            ]);
    }
    
    /**
     * 获取设备RTMP直播配置
     * 
     * @return void
     */
    public function getRtmpConfig(): void
    {
        $sn = $this->request->get('sn', '');
        
        if (empty($sn)) {
            $this->error('设备SN不能为空');
        }
        
        // 查找设备（优先查机场，如果是飞行器则查其父设备）
        $device = $this->model->where('sn', $sn)->find();
        
        if (!$device) {
            $this->error('设备不存在');
        }
        
        // 如果是飞行器(domain=0)，获取其父设备（机场）的配置
        if ($device['domain'] == 0 && $device['parent_id']) {
            $device = $this->model->find($device['parent_id']);
            if (!$device) {
                $this->error('未找到关联的机场设备');
            }
        }
        
        // 只有机场设备才有RTMP配置
        if ($device['domain'] != 3) {
            $this->error('该设备类型不支持RTMP配置');
        }
        
        // 获取SRS服务器配置
        $srsConfig = [
            'rtmp_server' => env('SRS.RTMP_URL', 'rtmp://103.205.254.30:1935/live'),
            'http_server' => env('SRS.FLV_URL', 'http://103.205.254.30:8088/live'),
        ];
        
        // 获取关联的飞行器SN
        $droneSn = '';
        $drone = $this->model->where('parent_id', $device['id'])->where('domain', 0)->find();
        if ($drone) {
            $droneSn = $drone['sn'];
        }
        
        $this->success('', [
            'gateway_sn' => $device['sn'],
            'drone_sn' => $droneSn,
            'cabin' => [
                'stream_key' => $device['rtmp_cabin_stream_key'] ?? '',
                'secret' => $device['rtmp_cabin_secret'] ?? '',
                'push_url' => $this->buildRtmpPushUrl($srsConfig['rtmp_server'], $device['rtmp_cabin_stream_key'] ?? '', $device['rtmp_cabin_secret'] ?? ''),
                'play_url' => $this->buildPlayUrl($srsConfig['http_server'], $device['rtmp_cabin_stream_key'] ?? ''),
            ],
            'drone' => [
                'stream_key' => $device['rtmp_drone_stream_key'] ?? '',
                'secret' => $device['rtmp_drone_secret'] ?? '',
                'push_url' => $this->buildRtmpPushUrl($srsConfig['rtmp_server'], $device['rtmp_drone_stream_key'] ?? '', $device['rtmp_drone_secret'] ?? ''),
                'play_url' => $this->buildPlayUrl($srsConfig['http_server'], $device['rtmp_drone_stream_key'] ?? ''),
            ],
            'srs_config' => $srsConfig,
        ]);
    }
    
    /**
     * 构建RTMP推流地址
     */
    private function buildRtmpPushUrl(string $server, string $streamKey, string $secret): string
    {
        if (empty($streamKey)) {
            return '';
        }
        $url = rtrim($server, '/') . '/' . $streamKey;
        if (!empty($secret)) {
            $url .= '?secret=' . $secret;
        }
        return $url;
    }
    
    /**
     * 构建播放地址
     */
    private function buildPlayUrl(string $server, string $streamKey): array
    {
        if (empty($streamKey)) {
            return ['flv' => '', 'hls' => ''];
        }
        $base = rtrim($server, '/');
        return [
            'flv' => $base . '/' . $streamKey . '.live.flv',
            'hls' => $base . '/' . $streamKey . '/hls.m3u8',
        ];
    }
    
    /**
     * 更新设备RTMP配置
     * 
     * @return void
     */
    public function updateRtmpConfig(): void
    {
        $sn = $this->request->post('sn', '');
        $cabinStreamKey = $this->request->post('cabin_stream_key', '');
        $cabinSecret = $this->request->post('cabin_secret', '');
        $droneStreamKey = $this->request->post('drone_stream_key', '');
        $droneSecret = $this->request->post('drone_secret', '');
        
        if (empty($sn)) {
            $this->error('设备SN不能为空');
        }
        
        // 查找机场设备
        $device = $this->model->where('sn', $sn)->where('domain', 3)->find();
        
        if (!$device) {
            $this->error('机场设备不存在');
        }
        
        $updateData = [
            'rtmp_cabin_stream_key' => $cabinStreamKey,
            'rtmp_cabin_secret' => $cabinSecret,
            'rtmp_drone_stream_key' => $droneStreamKey,
            'rtmp_drone_secret' => $droneSecret,
            'update_time' => time(),
        ];
        
        $result = $this->model->where('id', $device['id'])->update($updateData);
        
        if ($result !== false) {
            $this->success('RTMP配置更新成功');
        } else {
            $this->error('更新失败');
        }
    }
}
