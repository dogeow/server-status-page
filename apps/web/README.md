# Server Status Page Web

公开状态页与私有管理后台，使用 Next.js 兼容 API、React 和 Vinext 构建，并以普通 Node.js 服务运行。它不依赖 Cloudflare Worker、D1 或 R2。

## 本地开发

```bash
npm ci
npm run dev
```

默认从 `http://localhost:8000` 读取 Laravel 控制面。可通过 `.env.local` 覆盖：

```dotenv
API_INTERNAL_URL=http://localhost:8000
NEXT_PUBLIC_SITE_URL=http://localhost:3000
NEXT_PUBLIC_REVERB_WS_URL=ws://localhost/app/<key>?protocol=7&client=js&version=8.4.0&flash=false
```

公开页面在控制面不可用时明确显示未知状态，不生成模拟组件或可用率。管理请求通过同源代理转发到 Laravel，并保留 Sanctum session、CSRF cookie 与 XSRF header。

## 验证与生产运行

```bash
npm run lint
npm run typecheck
npm test
npm run build
npm run start
```

`/api/readiness?nonce=<value>` 是动态、`no-store` 且回显 nonce 的 Next.js readiness 路由。生产部署通常由根目录 Docker Compose 和 Caddy 负责。
