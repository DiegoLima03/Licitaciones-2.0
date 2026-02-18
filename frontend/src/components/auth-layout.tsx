"use client";

import * as React from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { BarChart3, FolderKanban, LineChart, ListPlus, LogOut, Search, Users } from "lucide-react";

const STORAGE_KEY = "veraleza_user";
const TOKEN_KEY = "token";

/** Si es "true", no se pide login y siempre se muestra el menú (para desarrollo). */
const SKIP_LOGIN =
  typeof process !== "undefined" &&
  process.env.NEXT_PUBLIC_SKIP_LOGIN === "true";

export function AuthLayout({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();
  const [user, setUser] = React.useState<string | null>(null);
  const isAdmin = React.useMemo(() => {
    if (SKIP_LOGIN) return true; // En modo desarrollo, mostrar siempre el menú Usuarios
    if (!user) return false;
    try {
      const parsed = JSON.parse(user);
      const role = String(parsed?.role ?? parsed?.rol ?? "").toLowerCase();
      return role === "admin";
    } catch {
      return false;
    }
  }, [user]);
  const [mounted, setMounted] = React.useState(false);

  React.useEffect(() => {
    setMounted(true);
  }, []);

  React.useEffect(() => {
    if (!mounted) return;
    const raw = window.localStorage.getItem(STORAGE_KEY);
    setUser(raw);
  }, [mounted, pathname]);

  // Redirecciones en useEffect para no actualizar Router durante el render
  React.useEffect(() => {
    if (!mounted) return;
    const raw = window.localStorage.getItem(STORAGE_KEY);
    const isLoggedIn = SKIP_LOGIN || !!raw;

    if (!SKIP_LOGIN && !isLoggedIn && pathname !== "/login") {
      router.replace("/login");
    } else if (isLoggedIn && pathname === "/login") {
      router.replace("/");
    }
  }, [mounted, pathname, router]);

  function handleLogout() {
    window.localStorage.removeItem(STORAGE_KEY);
    window.localStorage.removeItem(TOKEN_KEY);
    setUser(null);
    router.push("/login");
  }

  if (!mounted) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-50">
        <p className="text-sm text-slate-500">Cargando…</p>
      </div>
    );
  }

  const isLoggedIn = SKIP_LOGIN || !!user;
  const shouldRedirectToLogin = !SKIP_LOGIN && !isLoggedIn && pathname !== "/login";
  const shouldRedirectToHome = isLoggedIn && pathname === "/login";

  // Mientras se redirige, mostrar mensaje (la redirección ocurre en useEffect)
  if (shouldRedirectToLogin) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-50">
        <p className="text-sm text-slate-500">Redirigiendo al login…</p>
      </div>
    );
  }

  if (shouldRedirectToHome) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-50">
        <p className="text-sm text-slate-500">Redirigiendo al menú…</p>
      </div>
    );
  }

  if (!SKIP_LOGIN && !isLoggedIn && pathname === "/login") {
    return (
      <div className="min-h-screen bg-slate-50 antialiased">
        {children}
      </div>
    );
  }

  return (
    <div className="flex min-h-screen">
      <aside className="fixed inset-y-0 left-0 z-20 w-64 bg-slate-900 text-slate-50 shadow-xl">
        <div className="flex h-16 items-center gap-2 border-b border-slate-800 px-6">
          <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-500 text-lg font-semibold text-slate-900">
            V
          </div>
          <div>
            <p className="text-sm font-semibold">Veraleza</p>
            <p className="text-xs text-slate-400">Licitaciones</p>
          </div>
        </div>

        <nav className="mt-4 space-y-1 px-3 text-sm">
          <Link
            href="/"
            className="flex items-center gap-2 rounded-lg px-3 py-2 text-slate-100 hover:bg-slate-800 hover:text-white"
          >
            <BarChart3 className="h-4 w-4" />
            <span>Dashboard</span>
          </Link>
          <Link
            href="/licitaciones"
            className="flex items-center gap-2 rounded-lg px-3 py-2 text-slate-100 hover:bg-slate-800 hover:text-white"
          >
            <FolderKanban className="h-4 w-4" />
            <span>Mis Licitaciones</span>
          </Link>
          <Link
            href="/buscador"
            className="flex items-center gap-2 rounded-lg px-3 py-2 text-slate-100 hover:bg-slate-800 hover:text-white"
          >
            <Search className="h-4 w-4" />
            <span>Buscador Histórico</span>
          </Link>
          <Link
            href="/lineas-referencia"
            className="flex items-center gap-2 rounded-lg px-3 py-2 text-slate-100 hover:bg-slate-800 hover:text-white"
          >
            <ListPlus className="h-4 w-4" />
            <span>Añadir líneas</span>
          </Link>
          <Link
            href="/dashboard/analytics"
            className="flex items-center gap-2 rounded-lg px-3 py-2 text-slate-100 hover:bg-slate-800 hover:text-white"
          >
            <LineChart className="h-4 w-4" />
            <span>Analítica</span>
          </Link>
          {(SKIP_LOGIN || isAdmin) && (
            <Link
              href="/usuarios"
              className={`flex items-center gap-2 rounded-lg px-3 py-2 text-slate-100 hover:bg-slate-800 hover:text-white ${pathname === "/usuarios" ? "bg-slate-800" : ""}`}
            >
              <Users className="h-4 w-4" />
              <span>Usuarios</span>
            </Link>
          )}
          {!SKIP_LOGIN && (
            <button
              type="button"
              onClick={handleLogout}
              className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-slate-100 hover:bg-slate-800 hover:text-white"
            >
              <LogOut className="h-4 w-4" />
              <span>Cerrar sesión</span>
            </button>
          )}
        </nav>
      </aside>

      <main className="ml-64 flex min-h-screen flex-1 flex-col bg-slate-50">
        <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col px-6 py-6 lg:px-10 lg:py-8">
          {children}
        </div>
      </main>
    </div>
  );
}
