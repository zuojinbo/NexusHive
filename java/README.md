# NexusHive Java 后端

ThinkPHP / BuildAdmin 接口的 Java 实现。PHP 代码保留在仓库根目录，本目录是可独立运行的 Spring Boot 服务。前端仍请求原来的 `/admin/*`、`/api/*` 地址，JSON 信封保持 `{code, msg, time, data}`。

- OpenJDK 17
- Spring Boot 3.5.7
- MySQL 5.7，表前缀 `nz_`
- 动态查询使用 `JdbcTemplate`（列名来自 `information_schema`），管理员登录使用 MyBatis-Plus

服务器从零部署（Docker Compose、HTTPS、防火墙、裸机 systemd）见 [DEPLOY.md](DEPLOY.md)。

## 演示账号

导入用户提供的业务库之后，把 `nz_admin` 中 `admin` 的密码改成下面的哈希，即可用演示口令登录。空库执行 `src/main/resources/db/schema.sql` 和 `seed.sql` 时，这条哈希已经在 `seed.sql` 里。

- 用户名：`admin`
- 密码：`NexusHive@123`
- bcrypt（cost 5）：`$2y$05$6viJkpu3gOJ3domlEDtU6.QPdYVrg35lgh80kiAmz2EcnPwCDDO62`

```sql
UPDATE nz_admin
SET password='$2y$05$6viJkpu3gOJ3domlEDtU6.QPdYVrg35lgh80kiAmz2EcnPwCDDO62',
    salt='',
    status='enable',
    login_failure=0
WHERE username='admin';
```

不要把原始 dump（含生产密码哈希、token、业务数据）提交到仓库。

## 本地启动

```bash
export JAVA_HOME=/usr/lib/jvm/java-17-openjdk-amd64
cd java
mvn -DskipTests package
mvn test
java -jar target/nexushive-backend-1.0.0.jar
```

工作目录是 `java/` 时，静态目录默认 `../public`。也可以设置 `PUBLIC_DIR`。

数据库默认：

- `DB_HOST=127.0.0.1`
- `DB_PORT=3306`
- `DB_NAME=flysee`
- `DB_USER=root`
- `DB_PASSWORD=StrongRootPwd!123`

应用启动时不会自动执行 `schema.sql`（其中包含 `DROP TABLE`）。MQTT 连不上时进程继续运行，每 5 秒重试。

## 环境变量

密钥只从环境变量读取，仓库里的默认值是空字符串。

| 变量 | 用途 |
| --- | --- |
| `TOKEN_KEY` | 与 `config/buildadmin.php` 的 token.key 一致，用于 HMAC-RIPEMD160 |
| `MQTT_URI` `MQTT_USERNAME` `MQTT_PASSWORD` `MQTT_CLIENT_ID` | DJI Cloud MQTT |
| `STORAGE_PROVIDER` `STORAGE_BUCKET` `STORAGE_ENDPOINT` `STORAGE_CDN_URL` | 对象存储，`ali` 或 `minio` |
| `STORAGE_ACCESS_KEY_ID` `STORAGE_ACCESS_KEY_SECRET` `STORAGE_ROLE_ARN` | 阿里云 STS |
| `STORAGE_ACCESS_KEY` `STORAGE_SECRET_KEY` | MinIO |
| `DJI_APP_ID` `DJI_APP_KEY` `DJI_APP_LICENSE` `DJI_NTP_SERVER_HOST` | 机场 `config` 请求 |
| `AGORA_TOKEN_URL` | 声网 token 代理，留空则接口返回未配置 |
| `ZLM_API_SERVER` `ZLM_API_SECRET` `ZLM_RTMP_URL` `ZLM_FLV_URL` `ZLM_HLS_URL` `ZLM_RTMP_SECRET` | ZLMediaKit |
| `DASHSCOPE_API_KEY` | 视频帧分析 |

PHP 源码里的 ZLM / MQTT 默认地址和口令没有复制到 Java。

## 与 MySQL 5.7 一起冒烟

```bash
# 容器示例：mysql:5.7，库名 flysee，root 密码与上面的默认值一致
mysql -uroot -pStrongRootPwd!123 flysee < /path/to/local-dump.sql
# 2025-12 dump 没有 Gitee 后来的 6 张表和设备 RTMP 字段。只补这一份，不要执行 schema.sql。
mysql -uroot -pStrongRootPwd!123 flysee < src/main/resources/db/gitee-delta.sql
# 演示登录：把 admin 密码改成 README 中的 bcrypt。原始 dump 里的哈希不要提交。
bash scripts/smoke.sh
```

健康检查：`GET /actuator/health`。

## 同域切换

`deploy/docker/nginx/java-site.conf`：

- `/` 提供 `public/` 里的已编译前端
- `/admin/`、`/api/`、`/index.php` 反代到 Java `8080`
- `/mqtt/` 继续反代到 EMQX `8083`，浏览器 MQTT 不经过 Java

Java 监听 8080，迁移期间可以和 PHP 并存。

## 已编译前端里的地址

`public/assets/index-e-dypP0o.js` 把浏览器 MQTT 写成 `ws://192.168.0.122:8083/mqtt`（用户名 `fly-`，口令 `bgwl`），并把 SRS 写成 `rtmp://192.168.0.122:1935/live/` 和 `http://192.168.0.122:8088`。`ConfigSection-B5S1p5Zr.js` 里的 RTMP 默认值也是这个地址。

这些字符串在构建时写进 JS。修改后端的 `MQTT_*`、`ZLM_*` 或 Nginx 反代，都不会改写它们。浏览器仍会去连 `192.168.0.122`，换一台服务器后 OSD 和直播默认地址不会跟着变。登录和后台 CRUD 不受影响。

处理办法是用前端源码重新构建并替换 `public/assets`。完整说明见 [DEPLOY.md](DEPLOY.md) 第 11 节。
