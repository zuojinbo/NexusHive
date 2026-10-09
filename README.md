# NexusHive超级机场-低空智能飞行应用平台
[![License](https://img.shields.io/badge/license-Apache%202.0-blue.svg)](LICENSE)
[![BuildAdmin](https://img.shields.io/badge/Backend-BuildAdmin-brightgreen.svg)](https://buildadmin.com/)
[![Mars3D](https://img.shields.io/badge/Visualization-Mars3D-orange.svg)](https://mars3d.cn/)
[![DJI SDK](https://img.shields.io/badge/DJI-Cloud%20API-v2--v3-success.svg)](https://developer.dji.com/)

## 概述

一款 **工业级低空无人机智能调度与管理平台** ，项目凝结了团队在众多大型实战项目中的实用经验，形成 **生产级、好用、智能的无人机系统管理体系** ，项目最大程度的开放开源版功能（基本满足生产环境所需），支持大疆上云、PX4系列、Mavlink系列的多种无人机适配接入，核心功能涵盖了：设备管理、航线管理、任务管理、媒体管理等，项目前端三维可视化基于Cesium引擎开发， **实现了对无人机机队的集中化、自动化、可视化管控** ，项目已在科研、教学、电网、铁路、交通、建筑、城市安防等场景大规模应用。

```
> 系统演示地址：https://hezi.chuangxing.ren/index.html#/
             账号：admin
             密码：a123456
```


![输入图片说明](https://cyun-1300660186.file.myqcloud.com/image/1/2026/05/c2161ed44d6f3ac6932c39412b53ce88.jpg)
![输入图片说明](https://cyun-1300660186.file.myqcloud.com/image/1/2026/05/2adb7bcb5679f5a171b5b83b9c21767b.jpg)
![输入图片说明](https://cyun-1300660186.file.myqcloud.com/image/1/2026/05/344cd8af9558434e942d23283dbb6174.jpg)
![输入图片说明](https://cyun-1300660186.file.myqcloud.com/image/1/2026/05/d1b150c0923dd075686e76001005349d.jpg)
![输入图片说明](https://cyun-1300660186.file.myqcloud.com/image/1/2026/05/c9d5f6ccefc9794766babb069707d82e.jpg)
![输入图片说明](https://cyun-1300660186.file.myqcloud.com/image/1/2026/05/c20ed4c231507f211e82e5fc56fa5337.jpg)

## 技术栈

- **后端技术栈**: 基于hyperf或Go的微服务框架（后续也将支持Java）
- **后端技术栈**: VUE/webGL/three.js等
- **三维引擎**:Cesium(强大的全球三维地球平台)
- **主要功能**: 项目分区管理、设备监控、远程控制、可视化航线编辑、任务调度、数据导出

## 核心功能

### 🗂 多维管理
- **项目管理**：按项目维度集中管理所有无人机资源与任务。
- **分区管理**：灵活划分和管理作业区域，实现精细化调度。
- **设备管理**：全面接入并管理大疆机场(Dock)及飞行器，实时查看设备状态、告警信息、机场及飞行器详请数据。

### 🛩 远程调度与控制
- **多机型支持**：目前已支持大疆机场2 (Dock 2)、大疆机场3 (Dock 3) 、PX4云控盒子（内测中）的接入与管理，后续将扩展更多机型。
- **远程运维**：可对远程机场进行重启、升级、参数配置等运维操作。
- **一键指令**：支持任务一键下发、一键返航、紧急中止等快捷操作。

### ✈️ Cesium可视化任务编辑
- **三维飞行空间**：基于Cesium引擎提供强大的三维可视化航线编辑器，在地球上直接绘制、拖拽修改航线。
- **丰富动作指令**：航线支持添加多种动作，包括：
    - 拍照、录像
    - 悬停等待
    - 飞行器偏航角调整
    - 云台俯仰角控制
    - 相机变焦 (Zoom In/Out)
- **三维实时监控**：在三维场景中实时监控任务执行全过程，动态更新飞机位置、姿态与状态。

### 📊 数据与报告
- **飞行记录导出**：一键导出完整的飞行任务记录与报告，便于后续分析与审计。
- **设备告警历史**：查看所有设备的历史告警信息，助力设备健康管理。
## 关于部署
- **环境准备清单**： 

 ***后端***： 

        PHP > 8.0
        Mysql  5.7
        Redis  6.2
        Workerman 3.5.34
        EMQX 4.4
 ***前端***： 
        Node
            node.js > 20.19.0
            node.js: 22.x (推荐)
        核心框架
            Vue: 3.5.13 (Vue 3 组合式API)
            TypeScript: 5.7.2
            Vite: 6.3.5 (构建工具)
            Vue Router: 4.5.0 (路由管理)
            Pinia: 2.3.0 (状态管理)
        UI组件库
            Element Plus: 2.9.1 (主要UI组件库)
            Element Plus Icons: 2.3.1 (图标库)
            Font Awesome: 4.7.0 (图标字体)
        地图与3D渲染
            Mars3D: 3.10.0 (三维地球平台)
            Mars3D Cesium: 1.131.1 (Cesium引擎)
            Mars3D Space: 3.10.0 (空间分析)
            @turf/turf: 7.2.0 (地理空间分析)
        通信与实时功能
            MQTT: 5.13.3 (消息队列)
            Agora RTC SDK: 4.23.4 (实时音视频)
            Axios: 1.9.0 (HTTP客户端)
 ***宝塔部署***： 
        
        宝塔新建站点，设置站点目录为public

        伪静态：
        location ~* (runtime|application)/{
        	return 403;
        }
        location / {
        	if (!-e $request_filename){
        		rewrite  ^(.*)$  /index.php?s=$1  last;   break;
        	}
        }

        需要守护进程在根目录启动 Workerman：

        php think worker:server 

 ***注意事项***： 

        部署前请先保证emqx访问正常，并将机场部署至对应的第三方云，同时mqttx能够订阅thing/product/{机场SN}/osd并收到消息推送
        目前媒体支持只支持OSS，请开通bu# Nexus Hive for Web低空智能飞行调度平台
        目前直播用的声网Agora的极速直播，请在前端配置文件中配置声网token授权的访问域名，如果需要rtmp，可以查看前端部分代码，改动直播类型即可

[![License](https://img.shields.io/badge/license-Apache%202.0-blue.svg)](LICENSE)
[![BuildAdmin](https://img.shields.io/badge/Backend-BuildAdmin-brightgreen.svg)](https://buildadmin.com/)
[![Mars3D](https://img.shields.io/badge/Visualization-Mars3D-orange.svg)](https://mars3d.cn/)
[![DJI SDK](https://img.shields.io/badge/DJI-Cloud%20API-v2--v3-success.svg)](https://developer.dji.com/)
        
## 开源协议

本项目采用 Apache License 2.0 开源协议，详情请参阅 [LICENSE](LICENSE) 文件。

## 交流与贡献

欢迎提交 Issue 和 Pull Request！

-   **社区交流群**：微信扫码，进入社群获取技术支持、前端代码、数据库文件及详细开发文档（注明来意）。
-
     ![输入图片说明](https://cyun-1300660186.file.myqcloud.com/image/1/2026/06/cb64531eda69b326355c9cab43c7d371.jpg)

-   **邮箱联系**：871164797@qq.com
### 特别鸣谢
- [Thinkphp](http://www.thinkphp.cn/)
- [FastAdmin](https://gitee.com/karson/fastadmin)
- [Vue](https://github.com/vuejs/core)
- [vue-next-admin](https://gitee.com/lyt-top/vue-next-admin)
- [Element Plus](https://github.com/element-plus/element-plus)
- [TypeScript](https://github.com/microsoft/TypeScript)
- [vue-router](https://github.com/vuejs/vue-router-next)
- [vite](https://github.com/vitejs/vite)
- [Pinia](https://github.com/vuejs/pinia)
- [Axios](https://github.com/axios/axios)
- [nprogress](https://github.com/rstacruz/nprogress)
- [screenfull](https://github.com/sindresorhus/screenfull.js)
- [mitt](https://github.com/developit/mitt)
- [sass](https://github.com/sass/sass)
- [echarts](https://github.com/apache/echarts)
- [vueuse](https://github.com/vueuse/vueuse)
- [lodash](https://github.com/lodash/lodash)
- [eslint](https://github.com/eslint/eslint)
- [prettier](https://github.com/prettier/prettier)
- [Sortable](https://github.com/SortableJS/Sortable)
- [v-code-diff](https://github.com/Shimada666/v-code-diff)
- [clicaptcha](https://github.com/hooray/clicaptcha)
- [phinx](https://github.com/cakephp/phinx)
- [buildAdmin](https://www.buildadmin.com/)
- [mars3d](http://mars3d.cn/)
- [DJI SDK](https://developer.dji.com/)
- [jetbrains](https://www.jetbrains.com/)
## 免责声明

使用时请遵守相关法律法规。开发者不对因使用本项目而产生的任何直接或间接损失负责。