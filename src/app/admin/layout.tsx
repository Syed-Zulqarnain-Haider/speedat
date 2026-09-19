import type { Metadata } from "next";

export const metadata: Metadata = { title: "Rates admin", robots: { index: false, follow: false } };

/** Bare wrapper: each admin page decides its own shell so /admin/login stays minimal. */
export default function AdminLayout({ children }: LayoutProps<"/admin">) {
  return <>{children}</>;
}
