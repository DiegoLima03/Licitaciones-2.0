import type { Metadata } from "next";

import { AuthLayout } from "@/components/auth-layout";
import { QueryProvider } from "@/components/providers/query-provider";

import "./globals.css";

export const metadata: Metadata = {
  title: "Veraleza Licitaciones",
  description: "Dashboard de licitaciones y seguimiento de proyectos.",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="es">
      <body className="min-h-screen bg-slate-50 antialiased">
        <QueryProvider>
          <AuthLayout>{children}</AuthLayout>
        </QueryProvider>
      </body>
    </html>
  );
}
