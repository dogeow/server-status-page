"use client";

import { FormEvent, useState } from "react";

function cookie(name: string) {
  return document.cookie
    .split("; ")
    .find((row) => row.startsWith(`${name}=`))
    ?.split("=")
    .slice(1)
    .join("=");
}

export function AdminLoginForm() {
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setLoading(true);
    setError("");
    const data = new FormData(event.currentTarget);
    try {
      await fetch("/sanctum/csrf-cookie", { credentials: "include" });
      const xsrf = cookie("XSRF-TOKEN");
      const response = await fetch("/api/admin/v1/login", {
        method: "POST",
        credentials: "include",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          ...(xsrf ? { "X-XSRF-TOKEN": decodeURIComponent(xsrf) } : {}),
        },
        body: JSON.stringify({ email: data.get("email"), password: data.get("password") }),
      });
      if (!response.ok) {
        const payload = (await response.json().catch(() => ({}))) as { message?: string };
        throw new Error(payload.message || "邮箱或密码错误");
      }
      window.location.assign("/admin");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "登录失败");
      setLoading(false);
    }
  }

  return (
    <form className="form-card" onSubmit={submit}>
      <h1>进入管理后台</h1>
      <p>只有管理员可以配置探针、事件和通知。</p>
      <div className="form-grid">
        <div className="field field-full">
          <label htmlFor="admin-email">Email</label>
          <input id="admin-email" name="email" type="email" autoComplete="username" required />
        </div>
        <div className="field field-full">
          <label htmlFor="admin-password">密码</label>
          <input id="admin-password" name="password" type="password" autoComplete="current-password" required />
        </div>
      </div>
      <div className="form-actions">
        <button className="primary-button" type="submit" disabled={loading}>{loading ? "登录中…" : "登录"}</button>
        {error ? <span className="form-message error">{error}</span> : null}
      </div>
    </form>
  );
}
