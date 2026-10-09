# NexusHive Java 后端：Linux 服务器部署

本文是空机器到可登录后台的步骤。PHP 代码留在仓库里，本部署只启动 Java、MySQL 5.7、EMQX 5.8 和 Nginx。日常命令都在 `deploy/java/` 下执行。

本地开发说明见 [README.md](README.md)。

## 1. 服务器要求

- 64 位 Linux（Ubuntu 22.04 / Debian 12 / CentOS 7+ 均可）
- 内存建议 4 GB 以上。首次构建镜像会跑 Maven，磁盘预留 8 GB
- 已安装 Git
- 开放端口见第 8 节
- 不要和 `deploy/docker/` 里的 PHP 编排同时占用 80、3306、1883、8083

## 2. 安装 Docker

Ubuntu / Debian：

```bash
sudo apt-get update
sudo apt-get install -y ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | sudo tee /etc/apt/sources.list.d/docker.list
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo docker compose version
```

Debian 把上面 URL 里的 `ubuntu` 换成 `debian`。CentOS / RHEL 使用 Docker 官方的 `centos` 源，装好后同样要有 `docker compose` 插件（V2），不要用旧的 `docker-compose` 1.x 独立二进制。

当前用户加入 `docker` 组后重新登录，可去掉 `sudo`：

```bash
sudo usermod -aG docker "$USER"
```

## 3. 拉取代码

```bash
sudo mkdir -p /opt/nexushive
sudo chown "$USER" /opt/nexushive
git clone https://github.com/zuojinbo/NexusHive.git /opt/nexushive
cd /opt/nexushive
```

已有目录时：

```bash
cd /opt/nexushive
git pull
```

## 4. 配置 `.env`

```bash
cd /opt/nexushive/deploy/java
cp .env.example .env
chmod 600 .env
```

`.env` 只放在服务器上。仓库里的 `.env.example` 全是占位符。逐项说明：

| 变量 | 怎么填 |
| --- | --- |
| `MYSQL_ROOT_PASSWORD` | MySQL root 口令。`openssl rand -base64 24` 生成一串 |
| `DB_NAME` | 库名，默认 `flysee`。要和导入的 dump 库名一致 |
| `DB_USER` | Java 连接用户，默认 `root` |
| `DB_PASSWORD` | 与 `MYSQL_ROOT_PASSWORD` 相同（默认用 root 连接） |
| `TOKEN_KEY` | 管理员 token 的 HMAC-RIPEMD160 密钥。空库执行 `openssl rand -base64 32`。种子数据没有 token 行，换新密钥不影响演示登录。沿用已有 `nz_token` 时，必须与 `config/buildadmin.php` 的 `token.key` 相同，否则旧 token 全部变成过期 |
| `EMQX_DASHBOARD_PASSWORD` | EMQX 控制台口令，用户名固定 `admin`。控制台只绑定在本机 `18083` |
| `MQTT_USERNAME` / `MQTT_PASSWORD` | Java 进程连接 EMQX 的账号。留空即可：本编排不启用 EMQX 认证器，匿名连接可以连上。给机场单独加认证后，把这里改成同一套账号 |
| `MQTT_CLIENT_ID` | 留空时进程自己生成 `nexushive-` 前缀的客户端 ID |
| `STORAGE_PROVIDER` | `ali` 或 `minio` |
| `STORAGE_BUCKET` `STORAGE_ENDPOINT` `STORAGE_REGION` `STORAGE_CDN_URL` | 对象存储位置。不上传文件可以全空 |
| `STORAGE_ACCESS_KEY_ID` `STORAGE_ACCESS_KEY_SECRET` `STORAGE_ROLE_ARN` | 阿里云 STS AssumeRole。三件套留空时前端拿不到临时凭证，后台其它接口不受影响 |
| `STORAGE_ACCESS_KEY` `STORAGE_SECRET_KEY` | MinIO 静态密钥，`STORAGE_PROVIDER=minio` 时使用 |
| `DJI_APP_ID` `DJI_APP_KEY` `DJI_APP_LICENSE` | 大疆上云 license。留空时机场 `config` 请求拿不到授权，HTTP 接口仍可启动 |
| `DJI_NTP_SERVER_HOST` `DJI_NTP_SERVER_PORT` | 下发给机场的 NTP，默认 `ntp.aliyun.com:123` |
| `AGORA_TOKEN_URL` | 声网 token 服务地址。留空时接口返回「声网服务未配置」 |
| `ZLM_API_SERVER` `ZLM_API_SECRET` `ZLM_RTMP_URL` `ZLM_FLV_URL` `ZLM_HLS_URL` `ZLM_RTMP_SECRET` | 只影响 Java 的 `/api/Rtmp/*`。改不了已编译前端里的地址，见第 11 节 |
| `DASHSCOPE_API_KEY` | 通义千问视频帧分析。留空时该接口返回未配置 |

EMQX 地址在编排里固定为 `tcp://emqx:1883`，不要把公网 MQTT 地址写进 `.env`。

## 5. 两种数据库

`schema.sql` 开头是 `DROP TABLE`。MySQL 官方镜像只在数据卷为空时执行 `/docker-entrypoint-initdb.d`。数据卷一旦有内容，再改挂载也不会重跑。

### 5.1 空库（默认，本编排已挂载）

`docker-compose.yml` 按文件名顺序挂载：

1. `java/src/main/resources/db/schema.sql` → `01-schema.sql`
2. `java/src/main/resources/db/seed.sql` → `02-seed.sql`

第一次 `docker compose up` 会建库、建表，并写入菜单、配置项和演示管理员。配置项里的邮件和存储密钥是空的。没有 token 行。

演示账号：

- 用户名 `admin`
- 密码 `NexusHive@123`
- bcrypt（cost 5）：`$2y$05$6viJkpu3gOJ3domlEDtU6.QPdYVrg35lgh80kiAmz2EcnPwCDDO62`

`seed.sql` 已经插入这枚哈希。若要手工写回：

```sql
UPDATE nz_admin
SET password='$2y$05$6viJkpu3gOJ3domlEDtU6.QPdYVrg35lgh80kiAmz2EcnPwCDDO62',
    salt='',
    status='enable',
    login_failure=0
WHERE username='admin';
```

### 5.2 已有业务库（2025-12 及更早的 dump）

不要对有数据的库执行 `schema.sql`。

1. 编辑 `deploy/java/docker-compose.yml`，删掉或注释 mysql 服务里 `01-schema.sql` 和 `02-seed.sql` 两行挂载。
2. 若之前用空库初始化过，先备份再删卷，否则初始化脚本不会执行，旧空库也不会自动换成 dump：

```bash
cd /opt/nexushive/deploy/java
docker compose down
docker volume rm nexushive-java_mysql_data
```

3. 只启动 MySQL，等健康检查通过：

```bash
docker compose up -d mysql
docker compose ps
```

4. 导入 dump（文件留在服务器上，不要提交进 Git），再补 Gitee 相对这份 dump 新增的 6 张表和设备 RTMP 字段：

```bash
docker compose exec -T mysql mysql -uroot -p"$MYSQL_ROOT_PASSWORD" flysee < /path/to/your-dump.sql
docker compose exec -T mysql mysql -uroot -p"$MYSQL_ROOT_PASSWORD" flysee \
  < /opt/nexushive/java/src/main/resources/db/gitee-delta.sql
```

`MYSQL_ROOT_PASSWORD` 从 `.env` 里取。dump 的库名不是 `flysee` 时，把命令和 `DB_NAME` 改成同一个名字。

5. 把 `admin` 改成上面的演示哈希，或另建你自己的 bcrypt。原始 dump 里的密码哈希不要写入仓库。
6. `docker compose up -d` 启动其余服务。

Gitee 新库（已含那 6 张表和 `rtmp_*` 字段）只导入 dump，不必再跑 `gitee-delta.sql`。`gitee-delta.sql` 使用 `CREATE TABLE IF NOT EXISTS` 和按列判断的 `ALTER`，重复执行是安全的。

## 6. 启动、日志、停止、升级

```bash
cd /opt/nexushive/deploy/java
docker compose up -d --build
docker compose ps
docker compose logs -f java
```

浏览器打开 `http://服务器IP/`。管理接口也可以直接打本机 `http://127.0.0.1:8080`。

冒烟：

```bash
bash /opt/nexushive/java/scripts/smoke.sh
```

脚本默认请求 `http://127.0.0.1:8080`。登录使用 `admin` / `NexusHive@123`。

停止（保留数据库卷）：

```bash
docker compose stop
```

连容器一起删掉但保留卷：

```bash
docker compose down
```

`docker compose down -v` 会删除 MySQL 数据，只在确定要清空时使用。

升级：

```bash
cd /opt/nexushive
git pull
cd deploy/java
docker compose build java
docker compose up -d
```

Java 启动时不会执行 `schema.sql`（`spring.sql.init.mode=never`）。表结构变化要单独执行 SQL。EMQX 连不上时 Java 继续运行，每 5 秒重试。

## 7. HTTPS

### 7.1 Certbot

先让第 6 节的 Nginx 在 80 端口可访问，DNS 指到这台机器，然后：

```bash
sudo apt-get install -y certbot
sudo certbot certonly --webroot -w /opt/nexushive/public -d example.com
```

本编排的 `java-site.conf` 没有证书段。证书申请成功后，在宿主机再开一层 Nginx / Caddy 反代到 `127.0.0.1:80`，或把证书挂进 `nexushive-java-nginx-1` 并增加 `listen 443 ssl`。反代必须保留 WebSocket 升级头，否则浏览器 MQTT 经 `/mqtt/` 时会失败：

```nginx
proxy_http_version 1.1;
proxy_set_header Upgrade $http_upgrade;
proxy_set_header Connection "upgrade";
proxy_set_header Host $host;
proxy_set_header X-Forwarded-Proto https;
```

续期用 `certbot renew`，并重载外层 Nginx。

### 7.2 宝塔

1. 网站目录设为仓库的 `public/`。
2. 申请站点证书。
3. 反向代理：
   - `/admin`、`/api`、`/index.php` → `http://127.0.0.1:8080`
   - `/mqtt` → `http://127.0.0.1:8083`，打开 WebSocket
4. 伪静态不要再用 PHP 的 `rewrite ^(.*)$ /index.php?s=$1`。静态站点回源 `index.html` 即可。
5. Java 用第 9 节的 systemd 跑在 `127.0.0.1:8080`，或者只把 Docker Nginx 的 80 映射改到 `127.0.0.1:8088`，宝塔再反代到 `8088`。

宝塔和 Docker Nginx 不要同时监听公网 80。

## 8. 端口和防火墙

| 端口 | 谁用 | 建议 |
| --- | --- | --- |
| 80、443 | 浏览器访问站点 | 对公网开放 |
| 1883 | 机场 MQTT | 对公网或机场出口 IP 开放 |
| 8083 | 浏览器直连 EMQX WebSocket | 仅当浏览器不走站点上的 `/mqtt/` 时开放。当前已编译前端直连 `192.168.0.122:8083`，见第 11 节 |
| 18083 | EMQX 控制台 | 只留在 `127.0.0.1`，用 SSH 隧道访问 |
| 3306 | MySQL | 只留在 `127.0.0.1` |
| 8080 | Java | 只留在 `127.0.0.1`，对外走 Nginx |

ufw：

```bash
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow 1883/tcp
sudo ufw reload
```

需要浏览器直连 EMQX 时再 `sudo ufw allow 8083/tcp`。

firewalld：

```bash
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --permanent --add-port=1883/tcp
sudo firewall-cmd --reload
```

云厂商安全组要和上面一致。3306、8080、18083 不要加入安全组。

## 9. 不用 Docker

需要 OpenJDK 17、MySQL 5.7、EMQX 5.8、Nginx。系统默认 `java` 如果是 21，不能拿来跑这个服务。

```bash
sudo apt-get install -y openjdk-17-jdk nginx
export JAVA_HOME=/usr/lib/jvm/java-17-openjdk-amd64
cd /opt/nexushive/java
mvn -DskipTests package
```

空库：

```bash
mysql -uroot -p -e "CREATE DATABASE IF NOT EXISTS flysee CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -uroot -p flysee < src/main/resources/db/schema.sql
mysql -uroot -p flysee < src/main/resources/db/seed.sql
```

已有 dump：导入 dump 后只执行 `gitee-delta.sql`，再按第 5.1 节更新 `admin` 密码。不要执行 `schema.sql`。

`/etc/nexushive.env` 权限 `600`，内容与 `.env.example` 相同，并补上宿主机地址：

```bash
DB_HOST=127.0.0.1
DB_PORT=3306
PUBLIC_DIR=/opt/nexushive/public
MQTT_URI=tcp://127.0.0.1:1883
SERVER_PORT=8080
```

`/etc/systemd/system/nexushive.service`：

```ini
[Unit]
Description=NexusHive Java backend
After=network.target mysql.service

[Service]
Type=simple
User=nexushive
WorkingDirectory=/opt/nexushive/java
Environment=JAVA_HOME=/usr/lib/jvm/java-17-openjdk-amd64
EnvironmentFile=/etc/nexushive.env
ExecStart=/usr/lib/jvm/java-17-openjdk-amd64/bin/java -jar /opt/nexushive/java/target/nexushive-backend-1.0.0.jar
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
sudo useradd --system --home /opt/nexushive --shell /usr/sbin/nologin nexushive
sudo chown -R nexushive:nexushive /opt/nexushive/public/uploads /opt/nexushive/public/kmz
sudo systemctl daemon-reload
sudo systemctl enable --now nexushive
sudo systemctl status nexushive
```

`deploy/docker/nginx/java-site.conf` 里的上游是 Docker 主机名 `java` 和 `emqx`。裸机上请改用下面这份，保存为 `/etc/nginx/conf.d/nexushive.conf`：

```nginx
server {
    listen 80;
    server_name _;
    root /opt/nexushive/public;
    index index.html;
    client_max_body_size 256m;

    location /mqtt/ {
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_pass http://127.0.0.1:8083/mqtt/;
    }

    location /admin/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 300;
    }

    location /api/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 300;
    }

    location = /index.php {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }

    location /index.php/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }

    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~* /\.(git|env|htaccess|sql|log) { deny all; }
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

## 10. 故障排查

**初始化把业务数据清掉了。** `schema.sql` 含 `DROP TABLE`，只能给空数据卷。恢复备份后改用第 5.2 节。

**改了 init 脚本但表没有变。** 数据卷非空时入口脚本不会再跑。确认要重建时才 `docker compose down -v`。

**Java 一直在等 MySQL，或日志里 SchemaMeta 报错。** 看 `docker compose ps`，MySQL 健康检查通过后再看 Java。口令不一致时把 `DB_PASSWORD` 改成和 `MYSQL_ROOT_PASSWORD` 一样。

**登录返回 token 过期（code 409）或旧 token 全部失效。** `TOKEN_KEY` 与签发时的 `token.key` 不同。空库用新密钥即可，浏览器清掉本地 token 重新登录。

**EMQX 控制台要求改密码或起不来。** 确认 `.env` 里 `EMQX_DASHBOARD_PASSWORD` 已设置，然后 `docker compose up -d emqx`。控制台地址是 `http://127.0.0.1:18083`。

**机场连不上 MQTT。** 安全组放行 1883。若后来给 EMQX 加了认证器，匿名会被拒绝，Java 和机场都要配置同一套账号。Java 日志里每 5 秒重试一次，进程本身不会退出。

**浏览器地图在线，设备状态不刷新。** 见第 11 节。后端 `MQTT_URI` 只影响 Java 进程，不影响已编译前端。

**`java` 命令是 21。** `JAVA_HOME` 指向 `/usr/lib/jvm/java-17-openjdk-amd64`。镜像内已经是 Temurin 17，宿主机 JDK 版本不影响容器。

**80 端口被占用。** PHP 编排或宝塔已监听 80。停掉其中一个，或只把本文件的 Nginx 改绑到 `127.0.0.1`。

**`sudo docker` 才可用。** 把用户加入 `docker` 组，或部署时一直加 `sudo`。

## 11. 已编译前端写死了 `192.168.0.122`

`public/assets/index-e-dypP0o.js` 和 `public/assets/ConfigSection-B5S1p5Zr.js` 是构建产物，里面写死了：

- MQTT WebSocket：`ws://192.168.0.122:8083/mqtt`，用户名 `fly-`，口令 `bgwl`
- SRS / RTMP：`rtmp://192.168.0.122:1935/live/`，HTTP `http://192.168.0.122:8088`，以及一段打包进去的 Bearer token

Java 的 `MQTT_*`、`ZLM_*` 只改变服务端连接和 `/api/Rtmp` 的返回值，不会改写这两个 JS 文件。Nginx 的 `/mqtt/` 也只转发相对路径。当前包使用绝对地址 `ws://192.168.0.122:8083/mqtt`，浏览器不会把这条流量交给本站 Nginx。

影响：换服务器之后，后台页面仍会去连那台内网主机。连不上时，OSD、远程调试和直播地址保持旧环境，登录和 CRUD 不受影响。

处理办法：

1. 用前端源码重新构建，把 MQTT 改成当前站点的 `/mqtt/`（或真实 EMQX 域名），把 SRS 改成实际流媒体地址，然后替换 `public/assets`。这是正道。
2. 暂时不能构建时，只有浏览器能访问 `192.168.0.122` 且该地址上的 EMQX 接受用户名 `fly-`、口令 `bgwl`，实时状态才会动。本编排的 EMQX 没有认证器，那组口令在「直连本机 8083」时也能通过，但地址仍然必须是 `192.168.0.122`，所以换机器后这条路不通。
3. 部分设备页会再请求后端 RTMP 配置。配好 `ZLM_*` 后，这些接口返回的地址可以覆盖页面上的空值；JS 里的默认值和 MQTT 客户端地址仍然是 `192.168.0.122`。
