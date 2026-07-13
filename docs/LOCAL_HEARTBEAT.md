# 本机 systemd + UDP Heartbeat

`deploy/local-heartbeat/local_heartbeat.py` 用于从 Linux 主机向一个 Push Heartbeat 监控项上报固定的本机健康检查。每次执行始终检查第一项，并可选检查后两项：

1. 通过 `systemctl is-active` 确认指定 systemd unit 为 active；
2. 从 `/proc/net/udp` 与 `/proc/net/udp6` 确认指定 UDP 端口存在未连接的本机监听 socket；
3. 只通过 `O_NOFOLLOW` + `fstat` 检查指定普通文件的 mtime 是否在允许时限内。

所有已配置检查都成功才上报 `ok`。新鲜度检查不会读取文件内容，也不会跟随最终路径的符号链接。脚本不接受命令、命令模板或远程 Shell 配置，也不会把 unit 名、文件路径、端口、地址、精确 mtime、命令输出或底层异常写入上报 metrics、error code 或日志。

## 1. 创建 Heartbeat 监控项

在管理后台创建 `heartbeat` 类型监控项，并按实际执行频率设置超时。例如模板每 60 秒运行一次时，可保留默认的 150 秒降级、210 秒中断。随后轮换该监控项的 Heartbeat secret；控制面只在轮换响应中显示一次 endpoint 和 secret。

不要把 endpoint、monitor ID 或 secret 提交到 Git。公开组件名称与描述应使用面向访客的业务名称，不要直接展示不必要的底层实现信息。

## 2. 安装脚本与 systemd 模板

在目标 Linux 主机的仓库根目录执行：

```bash
sudo install -d -o root -g root -m 0755 /usr/local/lib/server-status-page
sudo install -d -o root -g root -m 0700 /etc/server-status-page/local-heartbeat
sudo install -d -o root -g root -m 0700 /etc/server-status-page/secrets
sudo install -o root -g root -m 0755 deploy/local-heartbeat/local_heartbeat.py /usr/local/lib/server-status-page/local_heartbeat.py
sudo install -o root -g root -m 0644 deploy/local-heartbeat/server-status-local-heartbeat@.service /etc/systemd/system/
sudo install -o root -g root -m 0644 deploy/local-heartbeat/server-status-local-heartbeat@.timer /etc/systemd/system/
```

模板以 root 执行，因为它要读取 root-only 配置和 secret。service 使用只读文件系统、空 capability 集合、禁止提权及其他 systemd 沙箱限制；它仍保留出站 HTTPS、访问 `/proc/net/udp*` 和查询 systemd 所需的最小边界。

## 3. 写入 root-only 配置

先创建空文件，再用 `sudoedit` 填写，避免 secret 出现在 shell history、进程参数或命令输出中：

```bash
sudo install -o root -g root -m 0600 /dev/null /etc/server-status-page/secrets/local-heartbeat-example.secret
sudoedit /etc/server-status-page/secrets/local-heartbeat-example.secret

sudo install -o root -g root -m 0600 deploy/local-heartbeat/local-heartbeat.example.json /etc/server-status-page/local-heartbeat/example.json
sudoedit /etc/server-status-page/local-heartbeat/example.json
```

配置格式：

```json
{
  "endpoint": "https://status.example.com/api/probe/v1/heartbeat/123",
  "secret_file": "/etc/server-status-page/secrets/local-heartbeat-example.secret",
  "service": "example.service",
  "udp_port": 12345,
  "freshness_file": "/var/lib/example/last-update.db",
  "freshness_max_age_seconds": 300,
  "timeout": 5
}
```

约束如下：

- 配置文件和 secret 文件必须是 root 拥有的普通文件，权限必须正好为 `0600`，且不能是符号链接；
- endpoint 必须是 HTTPS，路径必须精确匹配 `/api/probe/v1/heartbeat/<数字 monitor ID>`，不能包含用户名、密码、query 或 fragment；
- HTTPS 上报禁止跟随重定向，避免签名请求头离开配置的 endpoint；
- `service` 只能是固定的 systemd unit 名，不能包含空格或命令字符；
- `service` 始终必填；`udp_port` 可省略，配置时只能是 `1`–`65535`；
- `freshness_file` 与 `freshness_max_age_seconds` 可省略，但配置时必须成对出现；文件路径必须为绝对路径，最大时间可设为 1 秒至 365 天；
- freshness 文件只需为可访问的普通文件，不要求 root 所有或 `0600`，因为脚本只读取 mtime 元数据、不读取内容；
- `timeout` 只能是 `0.1`–`30` 秒，JSON 不能包含未定义字段。

原来的 systemd + UDP 配置无需修改即可继续使用。若只检查 systemd，可同时省略 UDP 与 freshness 字段；若检查一个周期更新的本地数据库或统计文件，可省略 `udp_port`，只配置 freshness 字段。例如：

```json
{
  "endpoint": "https://status.example.com/api/probe/v1/heartbeat/123",
  "secret_file": "/etc/server-status-page/secrets/local-heartbeat-example.secret",
  "service": "example.service",
  "freshness_file": "/var/lib/example/last-update.db",
  "freshness_max_age_seconds": 300,
  "timeout": 5
}
```

## 4. 启用定时器

实例名 `example` 对应 `/etc/server-status-page/local-heartbeat/example.json`：

```bash
sudo systemd-analyze verify /etc/systemd/system/server-status-local-heartbeat@.service /etc/systemd/system/server-status-local-heartbeat@.timer
sudo systemctl daemon-reload
sudo systemctl enable --now server-status-local-heartbeat@example.timer
sudo systemctl start server-status-local-heartbeat@example.service
sudo systemctl status server-status-local-heartbeat@example.timer
```

timer 在开机 30 秒后首次运行，之后约每 60 秒运行一次，并加入最多 6 秒随机延迟。脚本退出码：

- `0`：本机检查健康且控制面接受上报；
- `1`：控制面已接受上报，但至少一项本机检查失败；
- `2`：配置/权限不安全，或上报未送达。

systemd 模板把退出码 `1` 视为一次成功执行，因为故障已经可靠上报给控制面；只有退出码 `2` 会把 reporter unit 标记为 failed。这样被检查服务的故障不会额外污染 `systemctl --failed`。

日志只使用 `configuration rejected`、`delivery failed`、`local health check failed` 等固定短句，不包含 endpoint、monitor ID、secret、unit、端口或服务器响应正文。排障时可查看：

```bash
sudo systemctl status server-status-local-heartbeat@example.service
sudo journalctl -u server-status-local-heartbeat@example.service --since '15 minutes ago'
```

不要使用 `curl -v`、shell tracing (`set -x`) 或把 secret 直接放进命令行来调试签名。

## 5. 运行测试

脚本只依赖 Python 3 标准库。仓库内测试会 mock `subprocess.run` 与 `urllib.request.urlopen`，不会访问本机 systemd、UDP 服务或网络：

```bash
python3 -m unittest discover -s deploy/local-heartbeat/tests -v
```
