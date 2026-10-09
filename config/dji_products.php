<?php
/**
 * 大疆产品枚举值映射配置
 * 参考文档: 项目资料/产品支持.md
 * 更新时间: 2025-11-21
 */

return [
    /**
     * 机场类型映射 (domain = 3)
     */
    'dock' => [
        '3-1-0' => [
            'name' => '大疆机场',
            'name_en' => 'DJI Dock',
            'domain' => 3,
            'type' => 1,
            'sub_type' => 0,
            'device_category' => 3, // nz_equipment.device_category
        ],
        '3-2-0' => [
            'name' => '大疆机场 2',
            'name_en' => 'DJI Dock 2',
            'domain' => 3,
            'type' => 2,
            'sub_type' => 0,
            'device_category' => 3,
        ],
        '3-3-0' => [
            'name' => '大疆机场 3',
            'name_en' => 'DJI Dock 3',
            'domain' => 3,
            'type' => 3,
            'sub_type' => 0,
            'device_category' => 3,
        ],
    ],

    /**
     * 飞机类型映射 (domain = 0)
     */
    'aircraft' => [
        '0-60-0' => [
            'name' => 'Matrice 300 RTK',
            'name_en' => 'Matrice 300 RTK',
            'domain' => 0,
            'type' => 60,
            'sub_type' => 0,
            'device_category' => 0,
        ],
        '0-67-0' => [
            'name' => 'Matrice 30',
            'name_en' => 'Matrice 30',
            'domain' => 0,
            'type' => 67,
            'sub_type' => 0,
            'device_category' => 0,
        ],
        '0-67-1' => [
            'name' => 'Matrice 30T',
            'name_en' => 'Matrice 30T',
            'domain' => 0,
            'type' => 67,
            'sub_type' => 1,
            'device_category' => 0,
        ],
        '0-77-0' => [
            'name' => 'Mavic 3E',
            'name_en' => 'Mavic 3E',
            'domain' => 0,
            'type' => 77,
            'sub_type' => 0,
            'device_category' => 0,
        ],
        '0-77-1' => [
            'name' => 'Mavic 3T',
            'name_en' => 'Mavic 3T',
            'domain' => 0,
            'type' => 77,
            'sub_type' => 1,
            'device_category' => 0,
        ],
        '0-77-3' => [
            'name' => 'Mavic 3TA',
            'name_en' => 'Mavic 3TA',
            'domain' => 0,
            'type' => 77,
            'sub_type' => 3,
            'device_category' => 0,
        ],
        '0-89-0' => [
            'name' => 'Matrice 350 RTK',
            'name_en' => 'Matrice 350 RTK',
            'domain' => 0,
            'type' => 89,
            'sub_type' => 0,
            'device_category' => 0,
        ],
        '0-91-0' => [
            'name' => 'Matrice 3D',
            'name_en' => 'Matrice 3D',
            'domain' => 0,
            'type' => 91,
            'sub_type' => 0,
            'device_category' => 0,
        ],
        '0-91-1' => [
            'name' => 'Matrice 3TD',
            'name_en' => 'Matrice 3TD',
            'domain' => 0,
            'type' => 91,
            'sub_type' => 1,
            'device_category' => 0,
        ],
        '0-99-0' => [
            'name' => 'DJI Matrice 4E',
            'name_en' => 'DJI Matrice 4E',
            'domain' => 0,
            'type' => 99,
            'sub_type' => 0,
            'device_category' => 0,
        ],
        '0-99-1' => [
            'name' => 'DJI Matrice 4T',
            'name_en' => 'DJI Matrice 4T',
            'domain' => 0,
            'type' => 99,
            'sub_type' => 1,
            'device_category' => 0,
        ],
        '0-100-0' => [
            'name' => 'Matrice 4D',
            'name_en' => 'Matrice 4D',
            'domain' => 0,
            'type' => 100,
            'sub_type' => 0,
            'device_category' => 0,
        ],
        '0-100-1' => [
            'name' => 'Matrice 4TD',
            'name_en' => 'Matrice 4TD',
            'domain' => 0,
            'type' => 100,
            'sub_type' => 1,
            'device_category' => 0,
        ],
        '0-103-0' => [
            'name' => 'Matrice 400',
            'name_en' => 'Matrice 400',
            'domain' => 0,
            'type' => 103,
            'sub_type' => 0,
            'device_category' => 0,
        ],
    ],

    /**
     * 遥控器类型映射 (domain = 2)
     */
    'controller' => [
        '2-56-0' => [
            'name' => 'DJI 带屏遥控器行业版',
            'name_en' => 'DJI Smart Controller Enterprise',
            'domain' => 2,
            'type' => 56,
            'sub_type' => 0,
            'device_category' => 1,
        ],
        '2-119-0' => [
            'name' => 'DJI RC Plus',
            'name_en' => 'DJI RC Plus',
            'domain' => 2,
            'type' => 119,
            'sub_type' => 0,
            'device_category' => 1,
        ],
        '2-144-0' => [
            'name' => 'DJI RC Pro 行业版',
            'name_en' => 'DJI RC Pro Enterprise',
            'domain' => 2,
            'type' => 144,
            'sub_type' => 0,
            'device_category' => 1,
        ],
        '2-174-0' => [
            'name' => 'DJI RC Plus 2',
            'name_en' => 'DJI RC Plus 2',
            'domain' => 2,
            'type' => 174,
            'sub_type' => 0,
            'device_category' => 1,
        ],
    ],

    /**
     * 根据 device_model_key 获取设备信息
     * 
     * @param string $device_model_key 格式: domain-type-sub_type (如: 3-2-0)
     * @return array|null
     */
    'get_product_info' => function($device_model_key) {
        $products = config('dji_products');
        
        // 在所有分类中查找
        foreach (['dock', 'aircraft', 'controller'] as $category) {
            if (isset($products[$category][$device_model_key])) {
                return $products[$category][$device_model_key];
            }
        }
        
        return null;
    },

    /**
     * 解析 device_model_key 为 domain, type, sub_type
     * 
     * @param string $device_model_key
     * @return array
     */
    'parse_model_key' => function($device_model_key) {
        $parts = explode('-', $device_model_key);
        
        return [
            'domain' => isset($parts[0]) ? (int)$parts[0] : 0,
            'type' => isset($parts[1]) ? (int)$parts[1] : 0,
            'sub_type' => isset($parts[2]) ? (int)$parts[2] : 0,
        ];
    },

    /**
     * 生成 device_model_key
     * 
     * @param int $domain
     * @param int $type
     * @param int $sub_type
     * @return string
     */
    'make_model_key' => function($domain, $type, $sub_type = 0) {
        return sprintf('%d-%d-%d', $domain, $type, $sub_type);
    },

    /**
     * 判断设备类型
     * 
     * @param string $device_model_key
     * @return string dock|aircraft|controller|payload|unknown
     */
    'get_device_type' => function($device_model_key) {
        $parts = explode('-', $device_model_key);
        $domain = isset($parts[0]) ? (int)$parts[0] : 0;
        
        $typeMap = [
            0 => 'aircraft',    // 飞机
            1 => 'payload',     // 负载
            2 => 'controller',  // 遥控器
            3 => 'dock',        // 机场
        ];
        
        return $typeMap[$domain] ?? 'unknown';
    },

    /**
     * 获取父子设备关系配置
     * 机场和对应的飞机型号
     */
    'dock_aircraft_mapping' => [
        '3-1-0' => ['0-67-0', '0-67-1'], // 大疆机场 → Matrice 30/30T
        '3-2-0' => ['0-91-0', '0-91-1'], // 大疆机场 2 → Matrice 3D/3TD
        '3-3-0' => ['0-100-0', '0-100-1'], // 大疆机场 3 → Matrice 4D/4TD
    ],
];
