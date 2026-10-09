-- NexusHive schema only. Generated from Gitee deploy/docker/db-init/fly.sql.
-- No rows. Do not commit production dumps (passwords, tokens, business data).
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
-- ----------------------------
DROP TABLE IF EXISTS `nz_admin`;
CREATE TABLE `nz_admin`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `username` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '用户名',
  `nickname` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '昵称',
  `avatar` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '头像',
  `email` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '邮箱',
  `mobile` varchar(11) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '手机',
  `login_failure` tinyint(4) UNSIGNED NOT NULL DEFAULT 0 COMMENT '登录失败次数',
  `last_login_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '上次登录时间',
  `last_login_ip` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '上次登录IP',
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '密码',
  `salt` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '密码盐（废弃待删）',
  `motto` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '签名',
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '状态:enable=启用,disable=禁用',
  `update_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '更新时间',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  `hospital` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT '医院',
  `keshi` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT '科室',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `username`(`username`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '管理员表' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_admin_group`;
CREATE TABLE `nz_admin_group`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `pid` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '上级分组',
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '组名',
  `rules` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT '权限规则ID',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态:0=禁用,1=启用',
  `update_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '更新时间',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 5 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '管理分组表' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_admin_group_access`;
CREATE TABLE `nz_admin_group_access`  (
  `uid` int(11) UNSIGNED NOT NULL COMMENT '管理员ID',
  `group_id` int(11) UNSIGNED NOT NULL COMMENT '分组ID',
  INDEX `uid`(`uid`) USING BTREE,
  INDEX `group_id`(`group_id`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '管理分组映射表' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_admin_log`;
CREATE TABLE `nz_admin_log`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '管理员ID',
  `username` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '管理员用户名',
  `url` varchar(1500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '操作Url',
  `title` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '日志标题',
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT '请求数据',
  `ip` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'IP',
  `useragent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'User-Agent',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1285 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '管理员日志表' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_admin_rule`;
CREATE TABLE `nz_admin_rule`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `pid` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '上级菜单',
  `type` enum('menu_dir','menu','button') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'menu' COMMENT '类型:menu_dir=菜单目录,menu=菜单项,button=页面按钮',
  `title` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '标题',
  `name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '规则名称',
  `path` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '路由路径',
  `icon` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '图标',
  `menu_type` enum('tab','link','iframe') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT '菜单类型:tab=选项卡,link=链接,iframe=Iframe',
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Url',
  `component` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '组件路径',
  `keepalive` tinyint(4) UNSIGNED NOT NULL DEFAULT 0 COMMENT '缓存:0=关闭,1=开启',
  `extend` enum('none','add_rules_only','add_menu_only') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none' COMMENT '扩展属性:none=无,add_rules_only=只添加为路由,add_menu_only=只添加为菜单',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '备注',
  `weigh` int(11) NOT NULL DEFAULT 0 COMMENT '权重',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态:0=禁用,1=启用',
  `update_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '更新时间',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `pid`(`pid`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 232 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '菜单和权限规则表' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_airline`;
CREATE TABLE `nz_airline`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '航线名称',
  `project_id` int(10) UNSIGNED NULL DEFAULT NULL COMMENT '所属项目',
  `airline_floder_id` int(10) UNSIGNED NULL DEFAULT NULL COMMENT '所属文件夹',
  `type` enum('0','1') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '0' COMMENT '航线类型:0=航点航线,1=面状航线',
  `drone_model_key` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT '适用飞行器型号 (格式: 0-91-0, NULL表示通用航线)',
  `drone_model_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT '飞行器型号名称 (如: Matrice 3D, 用于前端显示)',
  `template` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '模板文件',
  `wayline` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '航线文件',
  `kmz` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '航线打包',
  `point_num` int(10) NULL DEFAULT NULL COMMENT '航点数量',
  `mileage` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '预计里程',
  `execution_time` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '预计时间',
  `kmz_md5` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'kmzMD5',
  `kmz_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '航线回显',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '修改时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_drone_model_key`(`drone_model_key`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 66 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '航线管理' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_airline_floder`;
CREATE TABLE `nz_airline_floder`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '文件夹名称',
  `project_id` int(10) UNSIGNED NULL DEFAULT NULL COMMENT '所属项目',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '修改时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 3 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '航线文件夹' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_algorithmbox`;
CREATE TABLE `nz_algorithmbox`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `appcenter_id` int(10) UNSIGNED NULL DEFAULT NULL COMMENT '所属应用',
  `avatar` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '封面图',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '算法名称',
  `pic` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '封面图',
  `introduction` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '简介',
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT '适用场景',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '修改时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '算法盒子' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_appcenter`;
CREATE TABLE `nz_appcenter`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '应用名称',
  `pic` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '应用图片',
  `introduction` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '应用简介',
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT '应用详情',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态:0=未开通,1=已开通',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '修改时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '应用中心' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_area`;
CREATE TABLE `nz_area`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `pid` int(11) UNSIGNED NULL DEFAULT NULL COMMENT '父id',
  `shortname` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT '简称',
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT '名称',
  `mergename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT '全称',
  `level` tinyint(4) UNSIGNED NULL DEFAULT NULL COMMENT '层级:1=省,2=市,3=区/县',
  `pinyin` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT '拼音',
  `code` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT '长途区号',
  `zip` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT '邮编',
  `first` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT '首字母',
  `lng` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT '经度',
  `lat` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT '纬度',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `pid`(`pid`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '省份地区表' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_attachment`;
CREATE TABLE `nz_attachment`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `topic` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '细目',
  `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '上传管理员ID',
  `user_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '上传用户ID',
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '物理路径',
  `width` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '宽度',
  `height` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '高度',
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '原始名称',
  `size` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '大小',
  `mimetype` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'mime类型',
  `quote` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '上传(引用)次数',
  `storage` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '存储方式',
  `sha1` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'sha1编码',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  `last_upload_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '最后上传时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 181 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '附件表' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_breakpoint_reason_dict`;
CREATE TABLE `nz_breakpoint_reason_dict`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `reason_code` int(10) NOT NULL COMMENT '中断原因代码',
  `reason_desc` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '原因描述',
  `category` enum('user','system','environment','error','unknown') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT 'unknown' COMMENT '分类',
  `severity` enum('low','medium','high','critical') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT 'medium' COMMENT '严重程度',
  `solution` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL COMMENT '建议解决方案',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `idx_reason_code`(`reason_code`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '断点原因代码字典表' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_captcha`;
CREATE TABLE `nz_captcha`  (
  `key` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '验证码Key',
  `code` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '验证码(加密后)',
  `captcha` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT '验证码数据',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  `expire_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '过期时间',
  PRIMARY KEY (`key`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '验证码表' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_command_execution`;
CREATE TABLE `nz_command_execution`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `command_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `flighttask_id` int(11) UNSIGNED NULL DEFAULT NULL,
  `bid` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '',
  `tid` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '',
  `target_sn` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '',
  `topic` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '',
  `method` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '',
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT 'queued',
  `source` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT 'backend',
  `operator_id` int(11) UNSIGNED NULL DEFAULT NULL,
  `command_payload` json NULL,
  `ack_payload` json NULL,
  `progress_payload` json NULL,
  `result_payload` json NULL,
  `error_code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '',
  `error_msg` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '',
  `ack_received_at` bigint(20) UNSIGNED NULL DEFAULT NULL,
  `finished_at` bigint(20) UNSIGNED NULL DEFAULT NULL,
  `create_time` bigint(20) UNSIGNED NULL DEFAULT NULL,
  `update_time` bigint(20) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `idx_command_id_unique`(`command_id`) USING BTREE,
  INDEX `idx_flighttask_id`(`flighttask_id`) USING BTREE,
  INDEX `idx_command_bid`(`bid`) USING BTREE,
  INDEX `idx_command_target_sn`(`target_sn`) USING BTREE,
  INDEX `idx_command_method_status`(`method`, `status`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_config`;
CREATE TABLE `nz_config`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `name` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '变量名',
  `group` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '分组',
  `title` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '变量标题',
  `tip` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '变量描述',
  `type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '变量输入组件类型',
  `value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT '变量值',
  `content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT '字典数据',
  `rule` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '验证规则',
  `extend` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '扩展属性',
  `allow_del` tinyint(4) UNSIGNED NOT NULL DEFAULT 0 COMMENT '允许删除:0=否,1=是',
  `weigh` int(11) NOT NULL DEFAULT 0 COMMENT '权重',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `name`(`name`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 27 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '系统配置' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_crud_log`;
CREATE TABLE `nz_crud_log`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `table_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '数据表名',
  `comment` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '注释',
  `table` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT '数据表数据',
  `fields` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT '字段数据',
  `sync` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '同步记录',
  `status` enum('delete','success','error','start') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'start' COMMENT '状态:delete=已删除,success=成功,error=失败,start=生成中',
  `connection` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '数据库连接配置标识',
  `create_time` bigint(20) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 70 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'CRUD记录表' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_djilog`;
CREATE TABLE `nz_djilog`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `sn` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '设备SN',
  `module` enum('0','3') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '0' COMMENT '日志模块:0=飞行器,3=机场',
  `boot_index` int(10) NULL DEFAULT NULL COMMENT '文件引索',
  `size` bigint(16) NULL DEFAULT NULL COMMENT '日志大小',
  `start_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '日志开始时间',
  `end_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '日志结束时间',
  `status` enum('0','1') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '0' COMMENT '已下载:0=未下载,1=已下载',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 992 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '日志管理' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_equipment`;
CREATE TABLE `nz_equipment`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `parent_id` int(10) NULL DEFAULT NULL COMMENT '主网关',
  `manufacturer` enum('0','1') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '0' COMMENT '设备厂商:0=大疆,1=道通',
  `domain` tinyint(1) UNSIGNED NULL DEFAULT 0 COMMENT '领域:0=飞机类,1=负载类,2=遥控器类,3=机场类',
  `type_code` smallint(5) UNSIGNED NULL DEFAULT NULL COMMENT '主类型(67=M30,91=M3D,100=M4D,1=机场1代,2=机场2代,3=机场3代)',
  `sub_type` tinyint(3) UNSIGNED NULL DEFAULT 0 COMMENT '子类型(0=基础版,1=T版,3=TA版等)',
  `model` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT '产品名称(用于显示,如:Matrice 3D,大疆机场 2)',
  `device_category` enum('0','1','2','3') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '3' COMMENT '设备分类:0=飞机,1=遥控器,2=负载,3=机场',
  `nickname` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '设备名称',
  `sn` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '设备sn',
  `device_binding_code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT '设备绑定码（大疆提供）',
  `device_callsign` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT '设备在组织中的名称(组织别名)',
  `is_bind_organization` enum('0','1') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '0' COMMENT '是否绑定组织:0=未绑定,1=已绑定',
  `bind_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '绑定时间戳(Unix时间戳)',
  `project_id` int(10) UNSIGNED NULL DEFAULT NULL COMMENT '所属项目',
  `firmware_version` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '固件版本',
  `mode_code` enum('0','1','2','3','4','5') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '0' COMMENT '工作状态:0=空闲中,1=现场调试,2=远程调试,3=固件升级中,4=作业中,5=待标定',
  `is_initialization` enum('0','1') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '0' COMMENT '是否初始化:0=未初始化,1=已初始化',
  `rtmp_cabin_stream_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT '机舱推流密钥(流名称，如: cabin_7CTXN3S00B08GE)',
  `rtmp_cabin_secret` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT '机舱推流鉴权密钥(SRS直播间的secret)',
  `rtmp_drone_stream_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT '飞行器推流密钥(流名称，如: drone_1581F6QAD247P00)',
  `rtmp_drone_secret` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT '飞行器推流鉴权密钥(SRS直播间的secret)',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '修改时间',
  `device_model_key` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (concat(`domain`,'-',`type_code`,'-',`sub_type`)) STORED COMMENT '产品枚举值(自动生成:domain-type-sub_type,如:3-2-0,0-91-0)' NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_domain_type`(`domain`, `type_code`) USING BTREE COMMENT '设备类型联合索引',
  INDEX `idx_device_model_key`(`device_model_key`) USING BTREE COMMENT 'device_model_key索引',
  INDEX `idx_sn`(`sn`) USING BTREE COMMENT 'SN查询索引',
  INDEX `idx_bind_status`(`is_bind_organization`) USING BTREE COMMENT '绑定状态索引'
) ENGINE = InnoDB AUTO_INCREMENT = 13 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '设备厂商管理' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_equipment_aircraft`;
CREATE TABLE `nz_equipment_aircraft`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '飞行器名称',
  `pic` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '封面图',
  `firmware_version` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '固件版本',
  `scenarios` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '应用场景',
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT '详情',
  `update_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '修改时间',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '飞行器市场' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_equipment_alarm`;
CREATE TABLE `nz_equipment_alarm`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `equipment_id` int(10) UNSIGNED NULL DEFAULT NULL COMMENT '所属设备',
  `level` enum('0','1','2') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '0' COMMENT '告警等级:0=通知,1=提醒,2=警告',
  `module` enum('0','1','2','3') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '0' COMMENT '事件模块:0=飞行任务,1=设备管理,2=媒体,3=hms',
  `in_the_sky` enum('0','1') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '0' COMMENT '是否飞行:0=在地上,1=在天上',
  `code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '告警码',
  `device_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '设备类型',
  `imminent` enum('0','1') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '0' COMMENT '是否及时性:0=否,1=是',
  `content` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '告警内容',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '修改时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '设备警报' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_equipment_load`;
CREATE TABLE `nz_equipment_load`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '负载名称',
  `pic` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '封面图',
  `firmware_version` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '固件版本',
  `scenarios` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '应用场景',
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT '详情',
  `update_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '修改时间',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '负载市场' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_errormsg`;
CREATE TABLE `nz_errormsg`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '错误码',
  `msg` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '错误内容',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '报错翻译' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_firmware`;
CREATE TABLE `nz_firmware`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_type` enum('dock','drone') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '设备类型: dock机场, drone无人机',
  `device_model` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '设备型号(如 Dock2, Dock3, M3TD, M30T)',
  `version` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '固件版本号',
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '文件名',
  `file_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'OSS下载地址',
  `file_size` bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT '文件大小(字节)',
  `md5` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '文件MD5',
  `release_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL COMMENT '更新说明',
  `status` tinyint(4) NOT NULL DEFAULT 1 COMMENT '状态: 0禁用 1启用',
  `create_time` datetime NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uk_type_model_version`(`device_type`, `device_model`, `version`) USING BTREE,
  INDEX `idx_device_type`(`device_type`) USING BTREE,
  INDEX `idx_status`(`status`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '固件版本表' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_firmware_upgrade_task`;
CREATE TABLE `nz_firmware_upgrade_task`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '任务ID(对应MQTT的bid)',
  `gateway_sn` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '机场SN',
  `device_sn` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '无人机SN(可选)',
  `upgrade_type` tinyint(4) NOT NULL DEFAULT 3 COMMENT '升级类型: 2一致性升级 3普通升级',
  `dock_firmware_id` int(10) UNSIGNED NULL DEFAULT NULL COMMENT '机场固件ID',
  `drone_firmware_id` int(10) UNSIGNED NULL DEFAULT NULL COMMENT '无人机固件ID',
  `dock_version` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '机场目标版本',
  `drone_version` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '无人机目标版本',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'sent' COMMENT '状态: sent已下发, in_progress执行中, ok成功, failed失败, canceled取消, timeout超时',
  `progress` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT '进度百分比(0-100)',
  `current_step` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '当前步骤: download_firmware下载固件, upgrade_firmware更新固件',
  `result_code` int(11) NULL DEFAULT NULL COMMENT '返回码(非0代表错误)',
  `error_msg` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '错误信息',
  `create_time` datetime NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `finish_time` datetime NULL DEFAULT NULL COMMENT '完成时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uk_task_id`(`task_id`) USING BTREE,
  INDEX `idx_gateway_sn`(`gateway_sn`) USING BTREE,
  INDEX `idx_status`(`status`) USING BTREE,
  INDEX `idx_create_time`(`create_time`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '固件升级任务表' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_flight_breakpoint`;
CREATE TABLE `nz_flight_breakpoint`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `flight_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '任务ID(bid)',
  `track_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '轨迹ID',
  `break_index` int(10) NULL DEFAULT NULL COMMENT '断点航点索引',
  `break_state` tinyint(1) NULL DEFAULT NULL COMMENT '断点状态(0:航段上/1:航点上)',
  `break_progress` decimal(5, 4) NULL DEFAULT NULL COMMENT '航段进度(0-1)',
  `break_reason` int(10) NULL DEFAULT NULL COMMENT '中断原因代码(参考官方文档)',
  `break_reason_desc` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '中断原因描述(中文)',
  `break_reason_category` enum('user','system','environment','error','unknown') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT 'unknown' COMMENT '中断原因分类',
  `latitude` decimal(10, 6) NULL DEFAULT NULL COMMENT '断点纬度',
  `longitude` decimal(10, 6) NULL DEFAULT NULL COMMENT '断点经度',
  `height` decimal(10, 2) NULL DEFAULT NULL COMMENT '断点椭球高度(米)',
  `relative_height` decimal(10, 2) NULL DEFAULT NULL COMMENT '断点相对高度(米)',
  `attitude_head` decimal(6, 2) NULL DEFAULT NULL COMMENT '断点偏航角(度)',
  `wayline_id` int(10) NULL DEFAULT NULL COMMENT '航线ID',
  `waypoint_index` int(10) NULL DEFAULT NULL COMMENT '当前航点索引',
  `battery_percent` int(3) NULL DEFAULT NULL COMMENT '断点时电量(%)',
  `gps_signal_level` int(2) NULL DEFAULT NULL COMMENT 'GPS信号质量',
  `wind_speed` decimal(5, 2) NULL DEFAULT NULL COMMENT '风速(m/s)',
  `temperature` decimal(5, 1) NULL DEFAULT NULL COMMENT '温度(℃)',
  `is_resumed` tinyint(1) NULL DEFAULT 0 COMMENT '是否已续飞',
  `resume_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '续飞时间(毫秒)',
  `resume_track_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '续飞后的新轨迹ID',
  `breakpoint_data` json NULL COMMENT '断点完整数据(JSON)',
  `break_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '断点发生时间(毫秒)',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间(毫秒)',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_flight_id`(`flight_id`) USING BTREE,
  INDEX `idx_track_id`(`track_id`) USING BTREE,
  INDEX `idx_break_reason`(`break_reason`) USING BTREE,
  INDEX `idx_break_time`(`break_time`) USING BTREE,
  INDEX `idx_is_resumed`(`is_resumed`) USING BTREE,
  INDEX `idx_create_time`(`create_time`) USING BTREE,
  INDEX `idx_reason_time`(`break_reason`, `break_time`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '航线断点事件表-支持多次断点记录和续飞追踪' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_flight_return_track`;
CREATE TABLE `nz_flight_return_track`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `flight_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '任务ID(bid)',
  `track_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '关联轨迹ID',
  `return_type` enum('low_battery','manual','lost_signal','emergency','planned','obstacle','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT 'manual' COMMENT '返航类型',
  `last_point_type` tinyint(1) NULL DEFAULT NULL COMMENT '最后点类型(0:在返航点上空/1:不在)',
  `home_latitude` decimal(10, 6) NULL DEFAULT NULL COMMENT '返航点纬度',
  `home_longitude` decimal(10, 6) NULL DEFAULT NULL COMMENT '返航点经度',
  `home_height` decimal(10, 2) NULL DEFAULT NULL COMMENT '返航点高度(米)',
  `home_dock_sn` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT 'Home点机场SN',
  `trigger_latitude` decimal(10, 6) NULL DEFAULT NULL COMMENT '触发返航时纬度',
  `trigger_longitude` decimal(10, 6) NULL DEFAULT NULL COMMENT '触发返航时经度',
  `trigger_height` decimal(10, 2) NULL DEFAULT NULL COMMENT '触发返航时高度(米)',
  `planned_points` json NULL COMMENT '规划轨迹点数组(JSON)',
  `planned_points_count` int(10) NULL DEFAULT 0 COMMENT '规划点数量',
  `planned_distance` decimal(10, 2) NULL DEFAULT NULL COMMENT '规划距离(米)',
  `estimated_battery_consumption` int(3) NULL DEFAULT NULL COMMENT '预估电量消耗(%)',
  `is_multi_dock` tinyint(1) NULL DEFAULT 0 COMMENT '是否蛙跳任务',
  `multi_dock_info` json NULL COMMENT '多机场返航信息(JSON)',
  `return_status` enum('planning','in_progress','completed','failed','canceled') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT 'planning' COMMENT '返航状态',
  `actual_points_count` int(10) NULL DEFAULT 0 COMMENT '实际飞行点数',
  `actual_distance` decimal(10, 2) NULL DEFAULT NULL COMMENT '实际飞行距离(米)',
  `actual_battery_consumption` int(3) NULL DEFAULT NULL COMMENT '实际电量消耗(%)',
  `trigger_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '触发返航时间(毫秒)',
  `complete_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '返航完成时间(毫秒)',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间(毫秒)',
  `update_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '更新时间(毫秒)',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_flight_id`(`flight_id`) USING BTREE,
  INDEX `idx_track_id`(`track_id`) USING BTREE,
  INDEX `idx_return_type`(`return_type`) USING BTREE,
  INDEX `idx_return_status`(`return_status`) USING BTREE,
  INDEX `idx_trigger_time`(`trigger_time`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '返航轨迹表-记录返航规划路径和实际飞行数据' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_flight_track`;
CREATE TABLE `nz_flight_track`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `flight_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '任务ID(bid)',
  `track_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '轨迹ID(唯一标识)',
  `task_id` int(10) UNSIGNED NULL DEFAULT NULL COMMENT '关联nz_flighttask表ID',
  `equipment_id` int(10) UNSIGNED NULL DEFAULT NULL COMMENT '设备ID',
  `drone_sn` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '飞行器SN',
  `dock_sn` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '机场SN',
  `track_type` enum('wayline','return_home','manual','emergency') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT 'wayline' COMMENT '轨迹类型:航线/返航/手动/紧急',
  `track_status` enum('recording','paused','completed','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT 'recording' COMMENT '记录状态',
  `wayline_id` int(10) NULL DEFAULT NULL COMMENT '航线ID',
  `total_waypoints` int(10) NULL DEFAULT 0 COMMENT '航线总航点数',
  `completed_waypoints` int(10) NULL DEFAULT 0 COMMENT '已完成航点数',
  `total_points` int(10) NULL DEFAULT 0 COMMENT '总轨迹点数(OSD记录数)',
  `total_distance` decimal(10, 2) NULL DEFAULT 0.00 COMMENT '总飞行距离(米)',
  `total_duration` int(10) NULL DEFAULT 0 COMMENT '总飞行时长(秒)',
  `max_altitude` decimal(10, 2) NULL DEFAULT NULL COMMENT '最大飞行高度(米)',
  `min_altitude` decimal(10, 2) NULL DEFAULT NULL COMMENT '最小飞行高度(米)',
  `avg_speed` decimal(6, 2) NULL DEFAULT NULL COMMENT '平均速度(m/s)',
  `max_speed` decimal(6, 2) NULL DEFAULT NULL COMMENT '最大速度(m/s)',
  `start_battery_percent` int(3) NULL DEFAULT NULL COMMENT '起飞时电量(%)',
  `end_battery_percent` int(3) NULL DEFAULT NULL COMMENT '降落时电量(%)',
  `battery_consumption` int(3) NULL DEFAULT NULL COMMENT '电量消耗(%)',
  `avg_wind_speed` decimal(5, 2) NULL DEFAULT NULL COMMENT '平均风速(m/s)',
  `max_wind_speed` decimal(5, 2) NULL DEFAULT NULL COMMENT '最大风速(m/s)',
  `media_count` int(10) NULL DEFAULT 0 COMMENT '产生的媒体文件数量',
  `has_breakpoint` tinyint(1) NULL DEFAULT 0 COMMENT '是否有断点事件',
  `breakpoint_count` int(10) NULL DEFAULT 0 COMMENT '断点次数',
  `has_return_home` tinyint(1) NULL DEFAULT 0 COMMENT '是否触发返航',
  `start_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '轨迹开始时间(毫秒)',
  `end_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '轨迹结束时间(毫秒)',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间(毫秒)',
  `update_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '更新时间(毫秒)',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `idx_track_id`(`track_id`) USING BTREE,
  INDEX `idx_flight_id`(`flight_id`) USING BTREE,
  INDEX `idx_task_id`(`task_id`) USING BTREE,
  INDEX `idx_drone_sn`(`drone_sn`) USING BTREE,
  INDEX `idx_dock_sn`(`dock_sn`) USING BTREE,
  INDEX `idx_track_status`(`track_status`) USING BTREE,
  INDEX `idx_start_time`(`start_time`) USING BTREE,
  INDEX `idx_create_time`(`create_time`) USING BTREE,
  INDEX `idx_time_drone`(`start_time`, `drone_sn`) USING BTREE,
  INDEX `idx_status_time`(`track_status`, `create_time`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '航线轨迹主表-管理轨迹生命周期和统计数据' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_flight_track_point`;
CREATE TABLE `nz_flight_track_point`  (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `track_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '轨迹ID',
  `flight_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '任务ID',
  `point_index` int(10) NULL DEFAULT NULL COMMENT '轨迹点序号(自增)',
  `waypoint_index` int(10) NULL DEFAULT NULL COMMENT '当前航点索引',
  `latitude` decimal(10, 6) NOT NULL COMMENT '纬度(精度到小数点后6位)',
  `longitude` decimal(10, 6) NOT NULL COMMENT '经度(精度到小数点后6位)',
  `height` decimal(10, 2) NULL DEFAULT NULL COMMENT '椭球高度(米)',
  `relative_height` decimal(10, 2) NULL DEFAULT NULL COMMENT '相对起飞点高度(米)',
  `heading` decimal(6, 2) NULL DEFAULT NULL COMMENT '航向角/偏航角(度)',
  `pitch` decimal(6, 2) NULL DEFAULT NULL COMMENT '俯仰角(度)',
  `roll` decimal(6, 2) NULL DEFAULT NULL COMMENT '横滚角(度)',
  `horizontal_speed` decimal(6, 2) NULL DEFAULT NULL COMMENT '水平速度(m/s)',
  `vertical_speed` decimal(6, 2) NULL DEFAULT NULL COMMENT '垂直速度(m/s)',
  `total_speed` decimal(6, 2) NULL DEFAULT NULL COMMENT '总速度(m/s)',
  `battery_percent` int(3) NULL DEFAULT NULL COMMENT '电池电量(%)',
  `gps_signal_level` int(2) NULL DEFAULT NULL COMMENT 'GPS信号质量(0-5)',
  `rtk_signal_level` int(2) NULL DEFAULT NULL COMMENT 'RTK信号质量(0-5)',
  `satellite_count` int(3) NULL DEFAULT NULL COMMENT '卫星数量',
  `wayline_state` tinyint(2) NULL DEFAULT NULL COMMENT '航线执行状态(0-9)',
  `flight_mode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '飞行模式',
  `wind_speed` decimal(5, 2) NULL DEFAULT NULL COMMENT '风速(m/s)',
  `wind_direction` int(3) NULL DEFAULT NULL COMMENT '风向(度)',
  `temperature` decimal(5, 1) NULL DEFAULT NULL COMMENT '温度(℃)',
  `humidity` int(3) NULL DEFAULT NULL COMMENT '湿度(%)',
  `sdr_signal_quality` int(2) NULL DEFAULT NULL COMMENT 'SDR信号质量(0-5)',
  `4g_signal_quality` int(2) NULL DEFAULT NULL COMMENT '4G信号质量(0-5)',
  `osd_data` json NULL COMMENT 'OSD完整数据(JSON格式)',
  `timestamp` bigint(16) UNSIGNED NOT NULL COMMENT '数据时间戳(毫秒)',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '入库时间(毫秒)',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_track_id`(`track_id`) USING BTREE,
  INDEX `idx_flight_id`(`flight_id`) USING BTREE,
  INDEX `idx_timestamp`(`timestamp`) USING BTREE,
  INDEX `idx_point_index`(`point_index`) USING BTREE,
  INDEX `idx_create_time`(`create_time`) USING BTREE,
  INDEX `idx_track_timestamp`(`track_id`, `timestamp`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '航线轨迹点详情表-存储OSD高频数据(0.5Hz)' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_flightosd`;
CREATE TABLE `nz_flightosd`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `sn` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '飞行器SN',
  `dock_sn` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '机场SN',
  `latitude` double NULL DEFAULT NULL COMMENT '实时纬度',
  `longitude` double NULL DEFAULT NULL COMMENT '实时经度',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 27263 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '任务轨迹' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_flightrecord`;
CREATE TABLE `nz_flightrecord`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `flight_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '任务ID(bid)',
  `track_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '航迹ID',
  `sn` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '设备SN(机场/飞行器)',
  `gateway_sn` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '网关SN(通常是机场SN)',
  `current_waypoint_index` int(10) NULL DEFAULT NULL COMMENT '当前执行的航点序号',
  `wayline_id` int(10) NULL DEFAULT NULL COMMENT '当前航线ID',
  `wayline_mission_state` tinyint(2) NULL DEFAULT NULL COMMENT '航线任务状态(0-9)',
  `media_count` int(10) NULL DEFAULT 0 COMMENT '本次航线产生的媒体文件数量',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '任务状态:ok/in_progress/paused/failed/canceled等',
  `break_reason` int(10) NULL DEFAULT NULL COMMENT '中断原因代码',
  `break_point_latitude` decimal(10, 6) NULL DEFAULT NULL COMMENT '断点纬度',
  `break_point_longitude` decimal(10, 6) NULL DEFAULT NULL COMMENT '断点经度',
  `break_point_height` decimal(10, 2) NULL DEFAULT NULL COMMENT '断点高度(米)',
  `break_point_attitude_head` decimal(6, 2) NULL DEFAULT NULL COMMENT '断点偏航角',
  `timestamp` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '消息时间戳(毫秒)',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '记录创建时间(毫秒)',
  `update_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '记录更新时间(毫秒)',
  `current_step` int(10) NULL DEFAULT NULL COMMENT '当前执行步骤(0-65535)',
  `percent` int(3) NULL DEFAULT 0 COMMENT '进度百分比(0-100)',
  `has_breakpoint` tinyint(1) NULL DEFAULT 0 COMMENT '本次进度是否包含断点信息',
  `break_point_index` int(10) NULL DEFAULT NULL COMMENT '断点航点索引',
  `break_point_state` tinyint(1) NULL DEFAULT NULL COMMENT '断点状态(0:航段上/1:航点上)',
  `break_point_progress` decimal(5, 4) NULL DEFAULT NULL COMMENT '航段进度(0-1)',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_flight_id`(`flight_id`) USING BTREE,
  INDEX `idx_track_id`(`track_id`) USING BTREE,
  INDEX `idx_sn`(`sn`) USING BTREE,
  INDEX `idx_wayline_state`(`wayline_mission_state`) USING BTREE,
  INDEX `idx_timestamp`(`timestamp`) USING BTREE,
  INDEX `idx_has_breakpoint`(`has_breakpoint`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '航线任务进度快照表(重构版)-记录每次进度变化' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_flightrecord_backup_20251126`;
CREATE TABLE `nz_flightrecord_backup_20251126`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `flight_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '任务ID(bid)',
  `track_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '航迹ID',
  `sn` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '设备SN(机场/飞行器)',
  `gateway_sn` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '网关SN(通常是机场SN)',
  `current_waypoint_index` int(10) NULL DEFAULT NULL COMMENT '当前执行的航点序号',
  `wayline_id` int(10) NULL DEFAULT NULL COMMENT '当前航线ID',
  `wayline_mission_state` tinyint(2) NULL DEFAULT NULL COMMENT '航线任务状态(0-9)',
  `media_count` int(10) NULL DEFAULT 0 COMMENT '本次航线产生的媒体文件数量',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '任务状态:ok/in_progress/paused/failed/canceled等',
  `current_step` int(10) NULL DEFAULT NULL COMMENT '当前执行步骤(0-65535)',
  `percent` int(3) NULL DEFAULT 0 COMMENT '进度百分比(0-100)',
  `has_breakpoint` tinyint(1) NULL DEFAULT 0 COMMENT '本次进度是否包含断点信息',
  `break_point_index` int(10) NULL DEFAULT NULL COMMENT '断点航点索引',
  `break_point_state` tinyint(1) NULL DEFAULT NULL COMMENT '断点状态(0:航段上/1:航点上)',
  `break_point_progress` decimal(5, 4) NULL DEFAULT NULL COMMENT '航段进度(0-1)',
  `break_reason` int(10) NULL DEFAULT NULL COMMENT '中断原因代码',
  `break_point_latitude` decimal(10, 6) NULL DEFAULT NULL COMMENT '断点纬度',
  `break_point_longitude` decimal(10, 6) NULL DEFAULT NULL COMMENT '断点经度',
  `break_point_height` decimal(10, 2) NULL DEFAULT NULL COMMENT '断点高度(米)',
  `break_point_attitude_head` decimal(6, 2) NULL DEFAULT NULL COMMENT '断点偏航角',
  `timestamp` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '消息时间戳(毫秒)',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '记录创建时间(毫秒)',
  `update_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '记录更新时间(毫秒)',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_flight_id`(`flight_id`) USING BTREE,
  INDEX `idx_track_id`(`track_id`) USING BTREE,
  INDEX `idx_sn`(`sn`) USING BTREE,
  INDEX `idx_wayline_state`(`wayline_mission_state`) USING BTREE,
  INDEX `idx_timestamp`(`timestamp`) USING BTREE,
  INDEX `idx_has_breakpoint`(`has_breakpoint`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '航线任务进度快照表(重构版)-记录每次进度变化' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_flighttask`;
CREATE TABLE `nz_flighttask`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `parent_task_id` int(10) UNSIGNED NULL DEFAULT NULL COMMENT '父任务ID(循环任务生成的子任务关联到主任务)',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `bid` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '业务ID',
  `tid` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '事务ID',
  `airline_id` int(10) UNSIGNED NULL DEFAULT NULL COMMENT '航线ID(手动飞行可为NULL)',
  `equipment_id` int(10) UNSIGNED NULL DEFAULT NULL COMMENT '任务执行设备',
  `execute_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '开始执行时间',
  `execute_time_ms` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '执行时间(毫秒级)',
  `end_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '任务结束时间',
  `task_type` enum('0','1','2','3') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '0' COMMENT '任务类型:0=立即执行,1=定时执行,2=循环执行,3=手动飞行',
  `flight_mode` enum('wayline','manual') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT 'wayline' COMMENT '飞行方式:wayline=航线飞行,manual=手动飞行',
  `repeat_type` enum('daily','weekly','monthly','custom') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT '循环类型:daily=每天,weekly=每周,monthly=每月,custom=自定义',
  `repeat_config` json NULL COMMENT '循环配置(JSON格式,存储执行时间点等信息)',
  `repeat_start_date` date NULL DEFAULT NULL COMMENT '循环开始日期',
  `repeat_end_date` date NULL DEFAULT NULL COMMENT '循环结束日期(NULL表示永久循环)',
  `repeat_count` int(11) NULL DEFAULT 0 COMMENT '已执行次数(循环任务自动累加)',
  `repeat_max_count` int(11) NULL DEFAULT NULL COMMENT '最大执行次数(NULL表示无限制)',
  `is_repeat_enabled` enum('0','1') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '1' COMMENT '是否启用循环:0=禁用(暂停或已结束),1=启用',
  `file_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT '航线文件URL(手动飞行可为NULL)',
  `file_fingerprint` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT '文件签名(手动飞行可为NULL)',
  `rth_altitude` int(10) NULL DEFAULT NULL COMMENT '返航高度',
  `rth_mode` enum('0','1') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '0' COMMENT '返航高度模式:0=智能高度,1=设定高度',
  `out_of_control_action` enum('0','1','2') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '0' COMMENT '遥控器失控动作:0=返航,1=悬停,2=降落',
  `exit_wayline_when_rc_lost` enum('0','1','2') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '0' COMMENT '航线失控动作:0=继续执行航线任务,1=退出航线任务,2=执行遥控器失控动作',
  `wayline_precision_type` enum('0','1') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '0' COMMENT '航线精度类型:0=GPS 任务,1=高精度 RTK 任务',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '修改时间',
  `status` enum('canceled','failed','in_progress','ok','partially_done','paused','rejected','sent','timeout') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT 'canceled' COMMENT '执行状态:canceled=取消或终止,failed=失败,in_progress=执行中,ok=执行成功,partially_done=部分完成,paused=暂停,rejected=拒绝,sent=已下发,timeout=超时',
  `admin_id` int(10) UNSIGNED NULL DEFAULT NULL COMMENT '创建人',
  `error_code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '错误码',
  `error_msg` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '错误原因',
  `break_reason` int(11) NULL DEFAULT NULL COMMENT '航线中断原因代码(break_reason，DJI官方定义)',
  `break_latitude` decimal(10, 6) NULL DEFAULT NULL COMMENT '断点纬度（航线中断位置）',
  `break_longitude` decimal(10, 6) NULL DEFAULT NULL COMMENT '断点经度（航线中断位置）',
  `wayline_mission_state` tinyint(4) NULL DEFAULT NULL COMMENT '航线任务状态(0-9, DJI官方定义)',
  `failed_step` tinyint(4) NULL DEFAULT NULL COMMENT '失败时的执行步骤（progress.current_step）',
  `failed_time` bigint(20) UNSIGNED NULL DEFAULT NULL COMMENT '失败时间戳',
  `total_point` int(10) NULL DEFAULT NULL COMMENT '总航点',
  `now_point` int(10) NULL DEFAULT NULL COMMENT '执行航点',
  `media_total` int(10) NULL DEFAULT 0 COMMENT '媒体数量',
  `media_now` int(10) NULL DEFAULT 0 COMMENT '上传数量',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_task_type`(`task_type`) USING BTREE COMMENT '任务类型索引',
  INDEX `idx_parent_task`(`parent_task_id`) USING BTREE COMMENT '父任务索引',
  INDEX `idx_repeat_enabled`(`is_repeat_enabled`, `repeat_type`) USING BTREE COMMENT '循环任务查询索引',
  INDEX `idx_flight_mode`(`flight_mode`) USING BTREE COMMENT '飞行方式索引',
  INDEX `idx_break_reason`(`break_reason`) USING BTREE COMMENT '中断原因索引',
  INDEX `idx_wayline_state`(`wayline_mission_state`) USING BTREE COMMENT '航线状态索引',
  INDEX `idx_failed_time`(`failed_time`) USING BTREE COMMENT '失败时间索引'
) ENGINE = InnoDB AUTO_INCREMENT = 396 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '计划任务' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_hms`;
CREATE TABLE `nz_hms`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '告警码',
  `en` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '中文文案',
  `zh` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '英文文案',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 4103 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'HMS管理' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_hmscenter`;
CREATE TABLE `nz_hmscenter`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `sn` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'SN',
  `level` enum('0','1','2') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '0' COMMENT '告警等级:0=通知,1=提醒,2=警告',
  `module` enum('0','1','2','3') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '0' COMMENT '事件模块:0=飞行任务,1=设备管理,2=媒体,3=hms',
  `in_the_sky` enum('0','1') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '0' COMMENT '是否飞行:0=在地上,1=在天上',
  `code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '告警码',
  `device_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '设备类型',
  `imminent` enum('0','1') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '0' COMMENT '是否及时:0=否,1=是',
  `component_index` int(10) NULL DEFAULT NULL COMMENT '文案变量',
  `sensor_index` int(10) NULL DEFAULT NULL COMMENT '文案变量',
  `message` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '转换消息',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '修改时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 18380 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '健康告警' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_media`;
CREATE TABLE `nz_media`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `type` enum('0','1','2') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '0' COMMENT '下拉框:0=图片,1=视频,2=其它',
  `sn` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'SN',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '文件名称',
  `object_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '存储桶路径',
  `path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '业务路径',
  `flight_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '任务ID',
  `drone_model_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '无人机型号',
  `payload_model_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '负载型号',
  `is_original` enum('0','1') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '0' COMMENT '是否原图:0=否,1=是',
  `gimbal_yaw_degree` decimal(6, 3) NULL DEFAULT NULL COMMENT '云台偏航角度(度)',
  `absolute_altitude` decimal(8, 3) NULL DEFAULT NULL COMMENT '绝对高度(米)',
  `relative_altitude` decimal(8, 3) NULL DEFAULT NULL COMMENT '相对高度(米)',
  `c_time` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT '拍摄时间',
  `lat` double NULL DEFAULT NULL COMMENT '拍摄位置纬度',
  `lng` double NULL DEFAULT NULL COMMENT '拍摄位置经度',
  `size` bigint(20) UNSIGNED NULL DEFAULT 0 COMMENT '文件大小(字节)',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 416 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '媒体管理' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_migrations`;
CREATE TABLE `nz_migrations`  (
  `version` bigint(20) NOT NULL,
  `migration_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `start_time` timestamp NULL DEFAULT NULL,
  `end_time` timestamp NULL DEFAULT NULL,
  `breakpoint` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`version`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_modemanage`;
CREATE TABLE `nz_modemanage`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '模型标识',
  `project_id` int(10) UNSIGNED NULL DEFAULT NULL COMMENT '所属项目',
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '模型地址',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '是否启用:0=关,1=开',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '修改时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 7 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '模型管理' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_project`;
CREATE TABLE `nz_project`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '项目名称',
  `introduction` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '简介',
  `longitude` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '经度',
  `latitude` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '纬度',
  `is_stop` tinyint(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '云端阻飞:0=关,1=开',
  `wind_speed` int(10) NULL DEFAULT NULL COMMENT '云端阻飞风速',
  `rainfall` enum('0','1','2','3') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '0' COMMENT '云端阻飞雨量:0=无雨,1=小雨,2=中雨,3=大雨',
  `admin_id` int(10) UNSIGNED NULL DEFAULT NULL COMMENT '创建人',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '修改时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 3 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '项目管理' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_project_admin`;
CREATE TABLE `nz_project_admin`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `project_id` int(10) UNSIGNED NULL DEFAULT NULL COMMENT '项目名称',
  `admin_id` int(10) UNSIGNED NULL DEFAULT NULL COMMENT '管理员',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态:0=关,1=开',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '修改时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '项目成员' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_project_user`;
CREATE TABLE `nz_project_user`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `project_id` int(10) UNSIGNED NULL DEFAULT NULL COMMENT '项目名称',
  `admin_id` int(10) UNSIGNED NULL DEFAULT NULL COMMENT '管理员',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态:0=关,1=开',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '修改时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '项目成员' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_security_data_recycle`;
CREATE TABLE `nz_security_data_recycle`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '规则名称',
  `controller` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '控制器',
  `controller_as` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '控制器别名',
  `data_table` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '对应数据表',
  `connection` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '数据库连接配置标识',
  `primary_key` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '数据表主键',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态:0=禁用,1=启用',
  `update_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '更新时间',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 7 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '回收规则表' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_security_data_recycle_log`;
CREATE TABLE `nz_security_data_recycle_log`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作管理员',
  `recycle_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '回收规则ID',
  `data` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT '回收的数据',
  `data_table` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '数据表',
  `connection` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '数据库连接配置标识',
  `primary_key` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '数据表主键',
  `is_restore` tinyint(4) UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否已还原:0=否,1=是',
  `ip` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '操作者IP',
  `useragent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'User-Agent',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '数据回收记录表' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_security_sensitive_data`;
CREATE TABLE `nz_security_sensitive_data`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '规则名称',
  `controller` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '控制器',
  `controller_as` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '控制器别名',
  `data_table` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '对应数据表',
  `connection` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '数据库连接配置标识',
  `primary_key` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '数据表主键',
  `data_fields` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT '敏感数据字段',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态:0=禁用,1=启用',
  `update_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '更新时间',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 4 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '敏感数据规则表' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_security_sensitive_data_log`;
CREATE TABLE `nz_security_sensitive_data_log`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作管理员',
  `sensitive_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '敏感数据规则ID',
  `data_table` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '数据表',
  `connection` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '数据库连接配置标识',
  `primary_key` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '数据表主键',
  `data_field` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '被修改字段',
  `data_comment` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '被修改项',
  `id_value` int(11) NOT NULL DEFAULT 0 COMMENT '被修改项主键值',
  `before` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT '修改前',
  `after` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT '修改后',
  `ip` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '操作者IP',
  `useragent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'User-Agent',
  `is_rollback` tinyint(4) UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否已回滚:0=否,1=是',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '敏感数据修改记录' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_system_activation`;
CREATE TABLE `nz_system_activation`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `activation_state` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT 'pending',
  `company_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '',
  `contact_phone` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '',
  `activation_payload` json NULL,
  `company_verified` tinyint(1) NULL DEFAULT 0,
  `sms_verified` tinyint(1) NULL DEFAULT 0,
  `customer_reported` tinyint(1) NULL DEFAULT 0,
  `activation_verified_at` bigint(20) UNSIGNED NULL DEFAULT NULL,
  `last_error` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '',
  `create_time` bigint(20) UNSIGNED NULL DEFAULT NULL,
  `update_time` bigint(20) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_task_timeline`;
CREATE TABLE `nz_task_timeline`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `flighttask_id` int(11) UNSIGNED NULL DEFAULT NULL,
  `command_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '',
  `bid` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '',
  `event_type` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '',
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT '',
  `payload` json NULL,
  `create_time` bigint(20) UNSIGNED NULL DEFAULT NULL,
  `update_time` bigint(20) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_timeline_command_id`(`command_id`) USING BTREE,
  INDEX `idx_timeline_flighttask_id`(`flighttask_id`) USING BTREE,
  INDEX `idx_timeline_bid`(`bid`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_test_build`;
CREATE TABLE `nz_test_build`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `title` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '标题',
  `keyword_rows` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '关键词',
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT '内容',
  `views` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '浏览量',
  `likes` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '有帮助数',
  `dislikes` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '无帮助数',
  `note_textarea` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '备注',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态:0=禁用,1=启用',
  `weigh` int(11) NOT NULL DEFAULT 0 COMMENT '权重',
  `update_time` bigint(20) UNSIGNED NULL DEFAULT NULL COMMENT '更新时间',
  `create_time` bigint(20) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '知识库表' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_token`;
CREATE TABLE `nz_token`  (
  `token` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Token',
  `type` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '类型',
  `user_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '用户ID',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  `expire_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '过期时间',
  PRIMARY KEY (`token`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '用户Token表' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_user`;
CREATE TABLE `nz_user`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `group_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '分组ID',
  `username` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '用户名',
  `nickname` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '昵称',
  `email` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '邮箱',
  `mobile` varchar(11) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '手机',
  `avatar` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '头像',
  `gender` tinyint(4) UNSIGNED NOT NULL DEFAULT 0 COMMENT '性别:0=未知,1=男,2=女',
  `birthday` date NULL DEFAULT NULL COMMENT '生日',
  `money` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '余额',
  `score` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '积分',
  `last_login_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '上次登录时间',
  `last_login_ip` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '上次登录IP',
  `login_failure` tinyint(4) UNSIGNED NOT NULL DEFAULT 0 COMMENT '登录失败次数',
  `join_ip` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '加入IP',
  `join_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '加入时间',
  `motto` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '签名',
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '密码',
  `salt` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '密码盐（废弃待删）',
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '状态:enable=启用,disable=禁用',
  `update_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '更新时间',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `username`(`username`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '会员表' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_user_group`;
CREATE TABLE `nz_user_group`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '组名',
  `rules` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT '权限节点',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态:0=禁用,1=启用',
  `update_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '更新时间',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '会员组表' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_user_money_log`;
CREATE TABLE `nz_user_money_log`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `user_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '会员ID',
  `money` int(11) NOT NULL DEFAULT 0 COMMENT '变更余额',
  `before` int(11) NOT NULL DEFAULT 0 COMMENT '变更前余额',
  `after` int(11) NOT NULL DEFAULT 0 COMMENT '变更后余额',
  `memo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '会员余额变动表' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_user_rule`;
CREATE TABLE `nz_user_rule`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `pid` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '上级菜单',
  `type` enum('route','menu_dir','menu','nav_user_menu','nav','button') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'menu' COMMENT '类型:route=路由,menu_dir=菜单目录,menu=菜单项,nav_user_menu=顶栏会员菜单下拉项,nav=顶栏菜单项,button=页面按钮',
  `title` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '标题',
  `name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '规则名称',
  `path` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '路由路径',
  `icon` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '图标',
  `menu_type` enum('tab','link','iframe') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tab' COMMENT '菜单类型:tab=选项卡,link=链接,iframe=Iframe',
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Url',
  `component` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '组件路径',
  `no_login_valid` tinyint(4) UNSIGNED NOT NULL DEFAULT 0 COMMENT '未登录有效:0=否,1=是',
  `extend` enum('none','add_rules_only','add_menu_only') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none' COMMENT '扩展属性:none=无,add_rules_only=只添加为路由,add_menu_only=只添加为菜单',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '备注',
  `weigh` int(11) NOT NULL DEFAULT 0 COMMENT '权重',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态:0=禁用,1=启用',
  `update_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '更新时间',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `pid`(`pid`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 7 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '会员菜单权限规则表' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_user_score_log`;
CREATE TABLE `nz_user_score_log`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `user_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '会员ID',
  `score` int(11) NOT NULL DEFAULT 0 COMMENT '变更积分',
  `before` int(11) NOT NULL DEFAULT 0 COMMENT '变更前积分',
  `after` int(11) NOT NULL DEFAULT 0 COMMENT '变更后积分',
  `memo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '会员积分变动表' ROW_FORMAT = DYNAMIC;

-- ----------------------------

-- ----------------------------
DROP TABLE IF EXISTS `nz_valgorithmbox`;
CREATE TABLE `nz_valgorithmbox`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '算法名称',
  `avatar` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '封面图',
  `introduction` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '算法简介',
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL COMMENT '算法详情',
  `create_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` bigint(16) UNSIGNED NULL DEFAULT NULL COMMENT '修改时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = '视觉算法中心' ROW_FORMAT = DYNAMIC;

-- ----------------------------

SET FOREIGN_KEY_CHECKS = 1;
