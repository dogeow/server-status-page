"use client";

import { FormEvent, useState } from "react";

export function SubscribeForm({ components }: { components: Array<{ id: string; name: string }> }) {
  const [state, setState] = useState<"idle" | "sending" | "success" | "error">("idle");
  const [message, setMessage] = useState("");

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setState("sending");
    setMessage("");
    const form = new FormData(event.currentTarget);
    const componentIds = form.getAll("component_ids").map(String);
    try {
      const response = await fetch("/api/public/v1/subscriptions", {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ email: form.get("email"), component_ids: componentIds }),
      });
      const payload = (await response.json().catch(() => ({}))) as { message?: string };
      if (!response.ok) throw new Error(payload.message || "订阅请求失败");
      setState("success");
      setMessage(payload.message || "确认邮件已发送，请检查收件箱。只在确认后开始推送。 ");
      event.currentTarget.reset();
    } catch (error) {
      setState("error");
      setMessage(error instanceof Error ? error.message : "暂时无法提交，请稍后重试。");
    }
  }

  return (
    <form className="form-card" onSubmit={submit}>
      <div className="form-grid">
        <div className="field field-full">
          <label htmlFor="subscriber-email">Email</label>
          <input id="subscriber-email" name="email" type="email" autoComplete="email" required placeholder="you@example.com" />
          <small>我们会先发送确认链接；退订令牌不会在公开页面显示。</small>
        </div>
        <fieldset className="field field-full">
          <legend>订阅组件</legend>
          <small>不选择任何组件代表订阅全部公开组件。</small>
          <div className="checkbox-row">
            {components.map((component) => (
              <label className="checkbox-item" key={component.id}>
                <input type="checkbox" name="component_ids" value={component.id} />
                <span>{component.name}</span>
              </label>
            ))}
          </div>
        </fieldset>
      </div>
      <div className="form-actions">
        <button className="primary-button" type="submit" disabled={state === "sending"}>
          {state === "sending" ? "正在提交…" : "发送确认邮件"}
        </button>
        {message ? <span className={`form-message ${state}`}>{message}</span> : null}
      </div>
    </form>
  );
}
