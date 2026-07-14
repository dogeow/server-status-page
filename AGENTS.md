# AGENTS.md

本文档为在本仓库中工作的 AI 编码助手提供项目边界、验证方式和生产安全约定。默认使用中文沟通。

## 项目概述

Server Status Page 是单组织、自托管的全栈状态监控服务：

- Laravel 控制面负责 API、状态评估、事件、通知、调度和 Reverb 广播。
- Next.js/Vinext 提供公开状态页和私有管理后台。
- Go Agent 执行固定类型探针并批量上报结果。
- Laravel Probe 包提供 Queue、Scheduler、Reverb 和 Heartbeat 集成。
- PostgreSQL 是唯一事实源，并承载核心调度锁与数据库队列；Redis 只用于可丢失缓存和实时广播。

## 仓库结构

```text
apps/api/                 Laravel 控制面
apps/web/                 Next.js/Vinext 前端
agent/                    Go 探针 Agent
packages/laravel-probe/   Laravel 集成包
config/                   Agent 与部署配置示例
deploy/                   部署辅助文件
docs/                     部署和运维文档
scripts/                  初始化脚本
docker-compose.yml        本地及生产 Compose 基础配置
```

## 当前产品约定

- 公开页面为单页状态页，最近 90 天历史和周期切换直接显示在首页。
- 不提供 `/history` 和 `/subscribe` 页面，也不提供公众订阅 API。
- 顶部只显示品牌，不增加“当前状态”“历史记录”或“订阅更新”导航。
- Hero 保持紧凑：桌面约 113px，手机约 95px；桌面内容栏最大宽度为 718px。
- 移动端必须完整容纳 90 个状态条，不允许卡片或页面产生横向滚动。
- 公开页面不得暴露主机地址、端口、凭据、内部错误、VPN 或 Hysteria2 等敏感字样；仅 VPN/连接相关组件在公开页面使用“连接桥梁”等中性名称，其他服务保留真实技术名称。
- “连接桥梁入口”和“连接桥梁”属于“服务器 B · 基础服务”分组，不建立独立连接分组。
- 管理后台使用 Sanctum 同源 Session，无公开注册；权限为 Owner、Admin、Viewer。

## 实现约束

- PostgreSQL 承载监控状态、结果、事件、通知 Outbox 和审计数据；不得把 Redis 变成事实源。
- 关键评估与通知必须在 Redis/Reverb 不可用时继续工作。
- Agent 只实现白名单探针类型，不增加远程 Shell、任意命令执行或动态脚本下载能力。
- 凭据优先使用 Agent 本地 `secretRef`；服务端密文不可通过 API、日志或错误响应回显。
- 检查结果幂等键保持为 `monitor_id + agent_id + scheduled_at + config_version`。
- Agent 离线时目标状态为 `unknown`，不得批量误报为中断。
- 维护期间继续采样、暂停告警，并默认从 uptime 计算中排除。
- 修改已在生产执行过的迁移时应新增迁移，不直接重写历史迁移；不得未经明确授权删除生产数据表。

## 常用验证命令

按修改范围运行最小相关集合；跨层改动运行全部命令。

```bash
# Web
cd apps/web
npm test
npm run lint
npm run typecheck

# API
cd apps/api
php artisan test

# Go Agent
cd agent
go test -race ./...

# Laravel Probe
cd packages/laravel-probe
composer install --no-interaction
vendor/bin/phpunit

# 全仓库入口
make test
```

本地 API 测试若未配置 `.env`，可仅为测试进程传入一次性 `APP_KEY`；不要写入或提交真实生产密钥。

## 变更规则

- 先从用户指出的页面、探针、路由或监控项开始，避免无关重构。
- UI 修改后至少检查 1920px 桌面和 390px 手机视口，并确认无横向溢出。
- 状态机、uptime、维护或通知逻辑修改必须补充对应单元/特性测试。
- API 契约变化时同步更新 Web 调用、测试、README 和 `docs/` 中的相关说明。
- 保留工作区中与当前任务无关的用户改动；提交前运行 `git diff --check` 和 `git status --short`。
- 不提交 `.env`、密码、SSH 私钥、Agent Secret、数据库转储、生产备份或 Playwright 临时产物。

## 生产部署安全

- 推送 GitHub 不等于生产部署；只有用户明确要求部署时才操作生产服务器。
- 部署前阅读 `docs/DEPLOYMENT.md`，备份将被覆盖的文件或数据，并确认回滚路径。
- 不覆盖生产 `.env`、`docker-compose.override.yml`、证书、Agent 数据卷或服务器本地 Secret 文件。
- 仅重建受影响的 Compose 服务；不得重启或修改同机的 Nginx、Squid、sing-box 及其他无关服务。
- Web-only 改动只重建 `web`。API 改动按需重建 `api`，并同步重建使用同一镜像的 `worker`、`outbox`、`scheduler`、`reverb`。
- 部署后检查容器健康状态、公开 HTTP 状态、相关 API 契约，以及 Nginx、Squid、sing-box、Docker 的运行状态。
- 直接调整生产组件分组或监控数据前必须保存快照并写入审计记录；保持组件 ID 和监控关联不变。

## 文档入口

- `README.md`：架构、功能与快速启动。
- `docs/DEPLOYMENT.md`：生产部署与升级流程。
- `docs/LOCAL_HEARTBEAT.md`：本机 systemd/UDP/freshness Heartbeat。
- `agent/README.md`：Agent 配置和注册。
- `packages/laravel-probe/README.md`：Laravel 集成。
- `SECURITY.md`：安全问题报告方式。
