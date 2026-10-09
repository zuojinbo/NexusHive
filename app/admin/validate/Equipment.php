<?php

namespace app\admin\validate;

use think\Validate;

class Equipment extends Validate
{
    protected $failException = true;

    /**
     * 验证规则
     */
    protected $rule = [
        'manufacturer' => 'require|in:0,1',
        'project_id'   => 'require|number',
        'nickname'     => 'require|max:255',
        'model'        => 'require|regex:^\\d+\\-\\d+\\-\\d+$',
        'sn'           => 'require|max:255',
    ];

    /**
     * 提示消息
     */
    protected $message = [
        'manufacturer.require' => '请选择设备厂商',
        'manufacturer.in'      => '设备厂商参数错误',
        'project_id.require'   => '请选择所属项目',
        'project_id.number'    => '所属项目参数错误',
        'nickname.require'     => '请输入设备名称',
        'nickname.max'         => '设备名称长度不能超过255字符',
        'model.require'        => '请选择设备型号',
        'model.regex'          => '设备型号格式错误',
        'sn.require'           => '请输入设备SN',
        'sn.max'               => '设备SN长度不能超过255字符',
    ];

    /**
     * 验证场景
     */
    protected $scene = [
        'add'  => ['manufacturer', 'project_id', 'nickname', 'model', 'sn'],
        'edit' => ['manufacturer', 'project_id', 'nickname', 'sn'],
    ];
}
