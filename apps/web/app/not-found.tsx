import Link from "next/link";

export default function NotFound() {
  return (
    <main className="form-page">
      <div className="form-card empty-state">
        <span className="empty-orbit" aria-hidden="true" />
        <h1>页面不存在</h1>
        <p>这个地址没有对应的页面，或事件链接已经失效。</p>
        <div className="form-actions"><Link className="primary-button" href="/">返回状态页</Link></div>
      </div>
    </main>
  );
}
