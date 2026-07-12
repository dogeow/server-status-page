import Link from "next/link";

export function SiteHeader({ siteName }: { siteName: string }) {
  return (
    <header className="site-header">
      <Link className="brand" href="/" aria-label={`${siteName} 首页`}>
        <span className="brand-mark" aria-hidden="true">
          <span />
          <span />
          <span />
        </span>
        <span>{siteName}</span>
      </Link>
      <nav className="site-nav" aria-label="状态页导航">
        <Link href="/">当前状态</Link>
        <Link href="/history">历史记录</Link>
        <Link className="nav-subscribe" href="/subscribe">
          订阅更新
        </Link>
      </nav>
    </header>
  );
}
