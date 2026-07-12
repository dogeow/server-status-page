import type { Metadata } from "next";
import { AdminLoginForm } from "./form";
import Link from "next/link";

export const metadata: Metadata = { title: "管理后台登录" };

export default function AdminLoginPage() {
  return (
    <main className="admin-login-shell">
      <div className="admin-login">
        <Link className="brand" href="/">
          <span className="brand-mark" aria-hidden="true"><span /><span /><span /></span>
          <span>System Status</span>
        </Link>
        <AdminLoginForm />
      </div>
    </main>
  );
}
