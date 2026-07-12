import type { Metadata } from "next";
import { AdminConsole } from "./AdminConsole";

export const metadata: Metadata = { title: "管理后台" };

export default function AdminPage() {
  return <AdminConsole />;
}
