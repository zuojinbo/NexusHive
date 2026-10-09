# NexusHive Java 后端

ThinkPHP / BuildAdmin 接口的 Java 实现。PHP 代码保留在仓库根目录，本目录是可独立运行的 Spring Boot 服务。前端仍请求原来的 `/admin/*`、`/api/*` 地址，JSON 信封保持 `{code, msg, time, data}`。

- OpenJDK 17
- Spring Boot 3.5.7
- MySQL 5.7，表前缀 `nz_`
- 动态查询使用 `JdbcTemplate`（列名来自 `information_schema`），管理员登录使用 MyBatis-Plus

## 演示账号

导入用户提供的业务库之后，把 `nz_admin` 中 `admin` 的密码改成下面的哈希，即可用演示口令登录。空库可以直接执行 `src/main/resources/db/schema.sql` 和 `seed.sql`。

- 用户名：`admin`
- 密码：`NexusHive@123`

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
# dump 若缺少 Gitee 新增的 6 张表，只补建缺失表，不要整份执行 schema.sql
bash scripts/smoke.sh
```

健康检查：`GET /actuator/health`。

## 同域切换

`deploy/docker/nginx/java-site.conf`：

- `/` 提供 `public/` 里的已编译前端
- `/admin/`、`/api/`、`/index.php` 反代到 Java `8080`
- `/mqtt/` 继续反代到 EMQX `8083`，浏览器 MQTT 不经过 Java

Java 监听 8080，迁移期间可以和 PHP 并存。
