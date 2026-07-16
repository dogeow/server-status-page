"use client";

type Theme = "light" | "dark";

const STORAGE_KEY = "server-status-theme";

function currentTheme(): Theme {
  return document.documentElement.dataset.theme === "dark" ? "dark" : "light";
}

function applyTheme(theme: Theme) {
  document.documentElement.dataset.theme = theme;
  document.documentElement.style.colorScheme = theme;
  window.localStorage.setItem(STORAGE_KEY, theme);
}

export function ThemeToggle() {
  function toggleTheme() {
    const next = currentTheme() === "dark" ? "light" : "dark";
    applyTheme(next);
  }

  return (
    <button
      className="theme-toggle"
      type="button"
      onClick={toggleTheme}
      aria-label="切换浅色或深色模式"
      title="切换浅色或深色模式"
    >
      <span className="theme-choice theme-choice-light">
        <span className="theme-toggle-icon" aria-hidden="true">☾</span>
        <span className="theme-toggle-label">深色</span>
      </span>
      <span className="theme-choice theme-choice-dark">
        <span className="theme-toggle-icon" aria-hidden="true">☀</span>
        <span className="theme-toggle-label">浅色</span>
      </span>
    </button>
  );
}
