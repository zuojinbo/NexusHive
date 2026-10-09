-- 把 2025-12 的 flysee dump 补到 Gitee 结构。不要执行 schema.sql，那份文件会 DROP 表。
-- 只含建表语句，不含任何业务数据和密钥。

CREATE TABLE IF NOT EXISTS `nz_command_execution`  (
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

CREATE TABLE IF NOT EXISTS `nz_firmware`  (
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

CREATE TABLE IF NOT EXISTS `nz_firmware_upgrade_task`  (
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

CREATE TABLE IF NOT EXISTS `nz_flightrecord_backup_20251126`  (
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

CREATE TABLE IF NOT EXISTS `nz_system_activation`  (
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

CREATE TABLE IF NOT EXISTS `nz_task_timeline`  (
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


SET @exist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nz_equipment' AND COLUMN_NAME = 'rtmp_cabin_stream_key'
);
SET @ddl := IF(@exist = 0,
  'ALTER TABLE `nz_equipment` ADD COLUMN `rtmp_cabin_stream_key` varchar(100) NULL, ADD COLUMN `rtmp_cabin_secret` varchar(100) NULL, ADD COLUMN `rtmp_drone_stream_key` varchar(100) NULL, ADD COLUMN `rtmp_drone_secret` varchar(100) NULL',
  'SELECT 1');
PREPARE nh_rtmp FROM @ddl;
EXECUTE nh_rtmp;
DEALLOCATE PREPARE nh_rtmp;
