# Server Status Page 生产部署手册

本文说明如何把 Server Status Page 部署到一台独立的公网 VPS。示例域名为 `status.example.com`，请全部替换成你的真实域名。

## 1. 部署前准备

推荐配置：

- Ubuntu 24.04 LTS，至少 2 核 CPU、4 GB 内存、30 GB SSD。
- 一个已解析到 VPS 公网 IP 的域名，例如 `status.example.com`。
- 可用的 SMTP 账号，用于管理员告警邮件。
- 控制面尽量不要和被监控业务部署在同一台主机或同一故障域。

在 DNS 服务商处添加记录：

```text
类型  主机名  值
A     status  <VPS IPv4>
AAAA  status  <VPS IPv6，可选>
```

如果添加了 `AAAA`，必须确认该 IPv6 地址确实可以访问 VPS，否则 HTTPS 申请和部分访客访问可能失败。

服务器需要放行：

- `22/tcp`：SSH，建议限制来源 IP。
- `80/tcp`：Caddy 申请证书和 HTTP 跳转。
- `443/tcp`：HTTPS 与 WSS。
- `443/udp`：HTTP/3，可选但推荐。

使用 UFW 时，先确保 SSH 已放行，再启用防火墙：

```bash
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow 443/udp
sudo ufw enable
sudo ufw status
```

## 2. 安装 Docker

按 Docker 官方 Ubuntu 安装说明安装 Docker Engine 与 Compose plugin：

- <https://docs.docker.com/engine/install/ubuntu/>

安装后验证：

```bash
docker --version
docker compose version
sudo docker run --rm hello-world
```

以下命令默认当前用户可以执行 `docker`。如果没有权限，可使用 `sudo docker ...`，或按 Docker 官方文档配置 `docker` 用户组。

## 3. 上传项目

在服务器创建目录：

```bash
sudo mkdir -p /opt/server-status-page
sudo chown "$USER":"$USER" /opt/server-status-page
cd /opt/server-status-page
```

把源码压缩包上传并解压到该目录，最终应能看到 `docker-compose.yml`、`Makefile`、`apps/`、`agent/` 等文件。例如从本机执行：

```bash
scp server-status-page-source.zip root@<VPS-IP>:/opt/server-status-page/
```

然后在 VPS 执行：

```bash
cd /opt/server-status-page
unzip server-status-page-source.zip
```

如果压缩包解压后多了一层 `server-status-page-source/`，进入该目录再执行后续命令。

## 4. 生成并配置生产环境变量

先生成随机的应用、PostgreSQL、Redis 和 Reverb 密钥：

```bash
cd /opt/server-status-page
make init
```

`make init` 只会在 `.env` 不存在时创建它，不会覆盖已有配置。编辑 `.env`：

```bash
nano .env
```

至少确认下面这些值。`<REVERB_APP_KEY>` 必须替换为同一份 `.env` 中实际生成的 `REVERB_APP_KEY`：

```dotenv
STATUS_SITE_ADDRESS=status.example.com
APP_NAME="System Status"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://status.example.com

SESSION_DOMAIN=status.example.com
SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=status.example.com
CORS_ALLOWED_ORIGINS=https://status.example.com

REVERB_ALLOWED_ORIGINS=status.example.com
NEXT_PUBLIC_REVERB_WS_URL=wss://status.example.com/app/<REVERB_APP_KEY>?protocol=7&client=js&version=8.4.0&flash=false

STATUS_SEED_DEMO=false
```

不要修改 `make init` 已生成的 `APP_KEY`、`POSTGRES_PASSWORD`、`REDIS_PASSWORD`、`REVERB_APP_KEY` 和 `REVERB_APP_SECRET`，除非你明确要轮换它们。`.env` 包含凭据，不要提交到 Git 或发送给他人。

### SMTP 配置

587 端口、STARTTLS 常见配置：

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=status@example.com
MAIL_PASSWORD=<SMTP密码或应用专用密码>
MAIL_SCHEME=smtp
MAIL_FROM_ADDRESS=status@example.com
MAIL_FROM_NAME="System Status"
```

465 端口通常改为：

```dotenv
MAIL_PORT=465
MAIL_SCHEME=smtps
```

SMTP 提供商如果有不同要求，以其文档为准。未配置 SMTP 时可暂时保留 `MAIL_MAILER=log`，但真实邮件告警不会送达。

## 5. 启动服务

先检查 Compose 配置，再构建和启动：

```bash
docker compose config
make up
docker compose ps
```

首次构建需要下载镜像和依赖，耗时取决于服务器网络。查看启动日志：

```bash
make logs
```

Caddy 会根据 `STATUS_SITE_ADDRESS` 自动申请和续期 TLS 证书。DNS 必须已经生效，且 80/443 端口可以从公网访问。不要在 VPS 前面放一个会阻断 ACME 验证的防火墙规则。

## 6. 创建首位 Owner

API 容器健康后执行：

```bash
make owner OWNER_EMAIL=owner@example.com
```

命令会交互式要求设置至少 12 位密码，并输出一个有效期 60 分钟、只能使用一次的 Agent enrollment token。妥善保存该 token，不要写入日志或聊天记录。

浏览器访问：

```text
https://status.example.com
https://status.example.com/admin/login
```

系统没有公开注册入口。后续用户由 Owner 在后台管理。

## 7. 启动 central-agent

把上一步的一次性 token 临时写入服务器 `.env`：

```dotenv
STATUS_AGENT_ENROLLMENT_TOKEN=<one-time-token>
```

启动 Agent：

```bash
make agent-up
docker compose --profile agent logs -f central-agent
```

看到 enrollment 成功后，Agent 凭据已经保存在命名卷 `agent_state`。立即把 `.env` 改回：

```dotenv
STATUS_AGENT_ENROLLMENT_TOKEN=
```

然后重建 Agent 容器，清除容器环境中的一次性 token：

```bash
docker compose --profile agent up -d --force-recreate central-agent
```

不要删除 `agent_state` 卷，否则 Agent 需要重新 enrollment。

## 8. 部署验收

检查容器：

```bash
docker compose ps
docker compose logs --tail=100 api web caddy reverb worker scheduler outbox
```

检查公开状态 API 与 HTTPS：

```bash
curl -fsS https://status.example.com/api/public/v1/status
curl -I https://status.example.com
```

验收项目：

- `docker compose ps` 中长期服务均为 running/healthy，没有反复重启。
- 公开页与 `/admin/login` 可以通过 HTTPS 打开。
- 管理后台能正常登录，浏览器没有跨域或 WebSocket 持续报错。
- 后台 Agent 页面显示 `central-agent` 在线。
- 创建一个 HTTP 监控后能产生真实检查结果。
- SMTP 测试邮件和测试 Webhook 可以送达。

## 9. 添加远程 Agent

需要检查内网 MySQL、PostgreSQL、Redis、Squid 或业务端口时，应在目标网络部署 remote-agent。先在后台 Agent 页面签发 token，或执行：

```bash
docker compose exec api php artisan status:agent-token edge-cn
```

远端主机只需能通过出站 HTTPS 访问状态页：

```bash
docker run --read-only --restart unless-stopped \
  -e STATUS_AGENT_SERVER_URL=https://status.example.com \
  -e STATUS_AGENT_ENROLLMENT_TOKEN='<one-time-token>' \
  -e STATUS_AGENT_NAME=edge-cn \
  -v status-agent-data:/var/lib/status-agent \
  server-status-page-agent:local
```

上述 `server-status-page-agent:local` 镜像必须先在远端构建或通过你的私有镜像仓库分发。完整的独立 Agent 配置见 [`agent/README.md`](../agent/README.md)。数据库等凭据优先通过 Agent 本地环境变量或 Docker secrets 提供，不要保存到管理后台的可见监控配置中。

如果某个非 Laravel 服务只需要上报本机 systemd 状态，以及可选的 UDP listener 或周期更新文件新鲜度，可部署不接受远程命令的签名 Heartbeat reporter。脚本、systemd service/timer 模板、root-only 配置及权限要求见[本机 systemd + UDP/freshness Heartbeat](LOCAL_HEARTBEAT.md)。

## 10. 备份与恢复

每天至少备份 PostgreSQL 和 `.env`。创建数据库备份：

```bash
cd /opt/server-status-page
mkdir -p backups
docker compose exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "backups/status-$(date -u +%Y%m%dT%H%M%SZ).dump"
cp .env "backups/env-$(date -u +%Y%m%dT%H%M%SZ)"
chmod 600 backups/env-*
```

备份文件应复制到另一台主机或对象存储，不能只留在当前 VPS。恢复会覆盖目标库，执行前先停止写入并确认备份文件：

```bash
docker compose stop api worker outbox scheduler reverb central-agent
docker compose exec -T postgres sh -c 'dropdb -U "$POSTGRES_USER" --if-exists "$POSTGRES_DB" && createdb -U "$POSTGRES_USER" "$POSTGRES_DB"'
docker compose exec -T postgres sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --clean --if-exists' < backups/status-YYYYMMDDTHHMMSSZ.dump
docker compose up -d
```

恢复命令是破坏性操作，只应在确认目标数据库和备份后使用。

## 11. 升级

先备份数据库和 `.env`，再上传或拉取新代码。在项目目录执行：

```bash
docker compose build
docker compose run --rm api-init
docker compose up -d
docker compose ps
```

升级后检查公开页、后台、Agent 与最近 100 行日志。不要使用 `docker compose down -v`，它会删除 PostgreSQL、Redis、证书和 Agent 凭据等命名卷。

## 12. 常见问题

### HTTPS 证书申请失败

确认 DNS 已指向本机、没有错误的 `AAAA`、80/443 已放行，并查看：

```bash
docker compose logs --tail=200 caddy
```

### 页面打开但后台无法登录

确认以下值使用完全相同的生产域名：

```dotenv
APP_URL=https://status.example.com
SESSION_DOMAIN=status.example.com
SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=status.example.com
CORS_ALLOWED_ORIGINS=https://status.example.com
```

修改后重建相关服务：

```bash
docker compose up -d --build --force-recreate api web caddy
```

### 实时状态不更新

页面会每 30 秒轮询降级，因此状态仍应更新。检查 `NEXT_PUBLIC_REVERB_WS_URL` 中的 key 是否与 `REVERB_APP_KEY` 一致，然后重建前端，因为该值会写入前端构建产物：

```bash
docker compose up -d --build --force-recreate web reverb caddy
```

### 服务反复重启

先查看状态和对应日志：

```bash
docker compose ps
docker compose logs --tail=200 postgres redis api worker scheduler outbox reverb web caddy
```

不要把 `.env`、完整日志中的凭据、Agent token 或数据库 DSN 发到公开渠道。
