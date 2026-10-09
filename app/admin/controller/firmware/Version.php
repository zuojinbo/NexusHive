<?php

namespace app\admin\controller\firmware;

use app\common\controller\Backend;
use app\admin\model\firmware\Firmware;
use think\facade\Db;

/**
 * 固件版本管理
 */
class Version extends Backend
{
    protected array|string $preExcludeFields = ['create_time', 'update_time'];

    protected string|array $quickSearchField = ['version', 'file_name', 'device_model'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new Firmware();
    }

    /**
     * 查看
     */
    public function index(): void
    {
        if ($this->request->param('select')) {
            $this->select();
        }

        list($where, $alias, $limit, $order) = $this->queryBuilder();
        
        $res = $this->model
            ->withAttr('file_size_text', function ($value, $data) {
                return $this->formatFileSize($data['file_size']);
            })
            ->withAttr('device_type_text', function ($value, $data) {
                return Firmware::$deviceTypeMap[$data['device_type']] ?? $data['device_type'];
            })
            ->alias($alias)
            ->where($where)
            ->order($order)
            ->paginate($limit);

        $this->success('', [
            'list' => $res->items(),
            'total' => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }

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

            // 验证必填字段
            if (empty($data['device_type']) || empty($data['device_model']) || empty($data['version'])) {
                $this->error('设备类型、设备型号、版本号不能为空');
            }

            // 检查版本是否已存在
            $exists = $this->model->where([
                'device_type' => $data['device_type'],
                'device_model' => $data['device_model'],
                'version' => $data['version'],
            ])->find();

            if ($exists) {
                $this->error('该版本已存在');
            }

            // 计算文件 MD5（如果有文件URL）
            if (!empty($data['file_url']) && empty($data['md5'])) {
                // MD5 需要在上传时计算，这里暂时留空
                $data['md5'] = '';
            }

            $result = false;
            $this->model->startTrans();
            try {
                $result = $this->model->save($data);
                $this->model->commit();
            } catch (\Exception $e) {
                $this->model->rollback();
                $this->error($e->getMessage());
            }

            if ($result !== false) {
                $this->success(__('Added successfully'));
            } else {
                $this->error(__('No rows were added'));
            }
        }

        // 返回设备类型和型号选项
        $this->success('', [
            'deviceTypes' => Firmware::$deviceTypeMap,
            'deviceModels' => Firmware::$deviceModelMap,
        ]);
    }

    /**
     * 编辑
     */
    public function edit(): void
    {
        $id = $this->request->param($this->model->getPk());
        $row = $this->model->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }

        if ($this->request->isPost()) {
            $data = $this->request->post();
            if (!$data) {
                $this->error(__('Parameter %s can not be empty', ['']));
            }

            $data = $this->excludeFields($data);

            // 检查版本是否已存在（排除自己）
            if (!empty($data['device_type']) && !empty($data['device_model']) && !empty($data['version'])) {
                $exists = $this->model->where([
                    'device_type' => $data['device_type'],
                    'device_model' => $data['device_model'],
                    'version' => $data['version'],
                ])->where('id', '<>', $id)->find();

                if ($exists) {
                    $this->error('该版本已存在');
                }
            }

            $result = false;
            $this->model->startTrans();
            try {
                $result = $row->save($data);
                $this->model->commit();
            } catch (\Exception $e) {
                $this->model->rollback();
                $this->error($e->getMessage());
            }

            if ($result !== false) {
                $this->success(__('Update successful'));
            } else {
                $this->error(__('No rows updated'));
            }
        }

        $this->success('', [
            'row' => $row,
            'deviceTypes' => Firmware::$deviceTypeMap,
            'deviceModels' => Firmware::$deviceModelMap,
        ]);
    }

    /**
     * 删除
     */
    public function del(): void
    {
        $ids = $this->request->param('ids', []);
        if (empty($ids)) {
            $this->error(__('Parameter %s can not be empty', ['ids']));
        }

        $dataLimitAdminIds = $this->getDataLimitAdminIds();
        if ($dataLimitAdminIds) {
            $this->model->where($this->dataLimitField, 'in', $dataLimitAdminIds);
        }

        $pk = $this->model->getPk();
        $data = $this->model->where($pk, 'in', $ids)->select();

        $count = 0;
        $this->model->startTrans();
        try {
            foreach ($data as $v) {
                $count += $v->delete();
            }
            $this->model->commit();
        } catch (\Exception $e) {
            $this->model->rollback();
            $this->error($e->getMessage());
        }

        if ($count) {
            $this->success(__('Deleted successfully'));
        } else {
            $this->error(__('No rows were deleted'));
        }
    }

    /**
     * 获取可用固件列表（用于升级选择）
     */
    public function available(): void
    {
        $deviceType = $this->request->param('device_type', '');
        $deviceModel = $this->request->param('device_model', '');

        $query = $this->model->where('status', 1);
        
        if ($deviceType) {
            $query->where('device_type', $deviceType);
        }
        if ($deviceModel) {
            $query->where('device_model', $deviceModel);
        }

        $list = $query->order('version', 'desc')->select();

        $this->success('', $list);
    }

    /**
     * 格式化文件大小
     */
    private function formatFileSize(int $size): string
    {
        if ($size < 1024) {
            return $size . ' B';
        } elseif ($size < 1024 * 1024) {
            return round($size / 1024, 2) . ' KB';
        } elseif ($size < 1024 * 1024 * 1024) {
            return round($size / (1024 * 1024), 2) . ' MB';
        } else {
            return round($size / (1024 * 1024 * 1024), 2) . ' GB';
        }
    }
}
