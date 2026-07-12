import type { Metadata } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import "./globals.css";

const geistSans = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  metadataBase: new URL(process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000"),
  title: {
    default: "System Status",
    template: "%s · System Status",
  },
  description: "服务可用性、事件、维护与实时监控状态。",
  openGraph: {
    type: "website",
    locale: "zh_CN",
    title: "System Status",
    description: "服务可用性、事件、维护与实时监控状态。",
    images: [{ url: "/og.png", width: 1731, height: 909, alt: "System Status 监控概览" }],
  },
  twitter: {
    card: "summary_large_image",
    title: "System Status",
    description: "服务可用性、事件、维护与实时监控状态。",
    images: ["/og.png"],
  },
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="zh-CN">
      <body
        className={`${geistSans.variable} ${geistMono.variable} antialiased`}
      >
        {children}
      </body>
    </html>
  );
}
