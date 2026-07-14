# Server Status Page API

Laravel 控制面负责监控计划、Agent HMAC 接口、状态机、事件、维护、管理员通知 outbox、历史聚合以及 Reverb 广播。PostgreSQL 是唯一事实源；Redis/Reverb 故障不会阻断状态评估与 Email/Webhook。

## 本地验证

```bash
composer install
php artisan migrate
php artisan test
vendor/bin/pint --test
```

默认本地 `.env` 使用 SQLite 方便测试；Docker Compose 会覆盖为 PostgreSQL、database cache/session/queue 和 Reverb。

## 首位管理员

```bash
php artisan status:bootstrap-owner owner@example.com
```

命令要求至少 12 位密码，并生成有效期 60 分钟的一次性 Agent enrollment token。生产环境没有公开注册入口。

## 后台进程

```bash
php artisan queue:work database --queue=default,status-probe
php artisan status:outbox-work
php artisan schedule:work
php artisan reverb:start
```

完整部署、环境变量与 Agent/Laravel Probe 接入说明见仓库根目录 `README.md`。
