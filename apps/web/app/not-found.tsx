import Link from "next/link";

export default function NotFound() {
  return (
    <main className="form-page">
      <div className="form-card empty-state">
        <span className="empty-orbit" aria-hidden="true" />
        <h1>没有找到这条记录</h1>
        <p>事件可能已被合并、撤回，或链接已经失效。</p>
        <div className="form-actions"><Link className="primary-button" href="/">返回状态页</Link></div>
      </div>
    </main>
  );
}
