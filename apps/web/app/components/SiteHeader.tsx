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
    </header>
  );
}
