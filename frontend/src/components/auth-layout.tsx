"use client";

import * as React from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { BarChart3, FolderKanban, KeyRound, LineChart, ListPlus, LogOut, Search, Users } from "lucide-react";

import { AuthService, AnalyticsService } from "@/services/api";
import type { RolePermissionsMatrix } from "@/types/api";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";

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
  const [accountPopoverOpen, setAccountPopoverOpen] = React.useState(false);
  const [passwordDialogOpen, setPasswordDialogOpen] = React.useState(false);
  const [me, setMe] = React.useState<{ email: string } | null>(null);
  const [passwordNew, setPasswordNew] = React.useState("");
  const [passwordConfirm, setPasswordConfirm] = React.useState("");
  const [passwordChanging, setPasswordChanging] = React.useState(false);
  const [passwordError, setPasswordError] = React.useState<string | null>(null);
  const [role, setRole] = React.useState<string | null>(null);
  const [roleMatrix, setRoleMatrix] = React.useState<RolePermissionsMatrix["matrix"] | null>(null);

  const isAdmin = React.useMemo(() => {
    if (SKIP_LOGIN) return true; // En modo desarrollo, mostrar siempre el menú Usuarios
    return role === "admin";
  }, [role]);

  const permissions = React.useMemo(() => {
    // Modo desarrollo: todo visible
    if (SKIP_LOGIN) {
      return {
        dashboard: true,
        licitaciones: true,
        buscador: true,
        lineas: true,
        analytics: true,
        usuarios: true,
      };
    }

    // Si tenemos matriz y rol, respetamos 100% lo que diga la matriz
    if (role && roleMatrix && roleMatrix[role]) {
      const p = roleMatrix[role]!;
      return {
        dashboard: !!p.dashboard,
        licitaciones: !!p.licitaciones,
        buscador: !!p.buscador,
        lineas: !!p.lineas,
        analytics: !!p.analytics,
        usuarios: !!p.usuarios,
      };
    }

    // Fallback: comportamiento antiguo si aún no se ha cargado la matriz
    const isPlantaRole = role?.includes("planta") ?? false;
    return {
      dashboard: !isPlantaRole,
      licitaciones: true,
      buscador: true,
      lineas: !isPlantaRole,
      analytics: !isPlantaRole,
      usuarios: isAdmin,
    };
  }, [role, roleMatrix, isAdmin]);
  const [mounted, setMounted] = React.useState(false);

  React.useEffect(() => {
    setMounted(true);
  }, []);

  React.useEffect(() => {
    if (!mounted) return;
    const raw = window.localStorage.getItem(STORAGE_KEY);
    setUser(raw);
    if (raw) {
      try {
        const parsed = JSON.parse(raw);
        const r = String(parsed?.role ?? parsed?.rol ?? "").toLowerCase();
        setRole(r || null);
      } catch {
        setRole(null);
      }
    } else {
      setRole(null);
    }
  }, [mounted, pathname]);

  // Cargar matriz de permisos una vez montado, solo si hay sesión (token)
  React.useEffect(() => {
    if (!mounted) return;
    if (SKIP_LOGIN) return;
    if (typeof window === "undefined") return;
    const token = window.localStorage.getItem(TOKEN_KEY);
    if (!token) return;
    AnalyticsService.getRolePermissions()
      .then((data) => setRoleMatrix(data.matrix || {}))
      .catch(() => {
        // En caso de error dejamos los permisos por defecto
      });
  }, [mounted]);

  React.useEffect(() => {
    if (!accountPopoverOpen || SKIP_LOGIN) return;
    AuthService.getMe()
      .then((data) => setMe({ email: data.email }))
      .catch(() => setMe(null));
  }, [accountPopoverOpen]);

  const displayEmail = React.useMemo(() => {
    if (me?.email) return me.email;
    if (!user) return null;
    try {
      const parsed = JSON.parse(user);
      return parsed?.email ?? null;
    } catch {
      return null;
    }
  }, [me, user]);

  function openPasswordDialog() {
    setAccountPopoverOpen(false);
    setPasswordDialogOpen(true);
    setPasswordNew("");
    setPasswordConfirm("");
    setPasswordError(null);
  }

  async function handleChangePassword(e: React.FormEvent) {
    e.preventDefault();
    setPasswordError(null);
    if (passwordNew.length < 6) {
      setPasswordError("La contraseña debe tener al menos 6 caracteres.");
      return;
    }
    if (passwordNew !== passwordConfirm) {
      setPasswordError("Las contraseñas no coinciden.");
      return;
    }
    setPasswordChanging(true);
    try {
      await AuthService.updateMyPassword(passwordNew);
      setPasswordDialogOpen(false);
    } catch (err) {
      setPasswordError(err instanceof Error ? err.message : "Error al cambiar la contraseña.");
    } finally {
      setPasswordChanging(false);
    }
  }

  // Redirecciones en useEffect para no actualizar Router durante el render
  React.useEffect(() => {
    if (!mounted) return;
    const raw = window.localStorage.getItem(STORAGE_KEY);
    const isLoggedIn = SKIP_LOGIN || !!raw;

    // Si no hay sesión y no estamos en /login → ir al login
    if (!SKIP_LOGIN && !isLoggedIn && pathname !== "/login") {
      router.replace("/login");
      return;
    }

    // Si ya hay sesión y estamos en /login → ir al Dashboard por defecto
    if (isLoggedIn && pathname === "/login") {
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
        <Popover open={accountPopoverOpen} onOpenChange={setAccountPopoverOpen}>
          <PopoverTrigger asChild>
            <button
              type="button"
              className="flex h-16 w-full cursor-pointer items-center gap-2 border-b border-slate-800 px-6 text-left transition-colors hover:bg-slate-800/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-inset"
              aria-label="Mi cuenta"
            >
              <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-500 text-lg font-semibold text-slate-900">
                V
              </div>
              <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold">Veraleza</p>
                <p className="text-xs text-slate-400">Licitaciones</p>
              </div>
            </button>
          </PopoverTrigger>
          <PopoverContent
            align="start"
            className="left-6 right-auto w-56 border-slate-200 bg-white p-0 text-slate-900 shadow-lg"
          >
            <div className="border-b border-slate-100 px-4 py-3">
              <p className="text-xs font-medium text-slate-500">Mi cuenta</p>
              <p className="mt-0.5 truncate text-sm font-medium text-slate-900" title={displayEmail ?? undefined}>
                {displayEmail ?? (SKIP_LOGIN ? "Modo desarrollo" : "—")}
              </p>
            </div>
            <div className="py-1">
              {!SKIP_LOGIN && (
                <>
                  <button
                    type="button"
                    onClick={openPasswordDialog}
                    className="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-slate-700 hover:bg-slate-100"
                  >
                    <KeyRound className="h-4 w-4" />
                    Cambiar contraseña
                  </button>
                  <button
                    type="button"
                    onClick={() => {
                      setAccountPopoverOpen(false);
                      handleLogout();
                    }}
                    className="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-slate-700 hover:bg-slate-100"
                  >
                    <LogOut className="h-4 w-4" />
                    Cerrar sesión
                  </button>
                </>
              )}
              {SKIP_LOGIN && (
                <p className="px-4 py-2 text-xs text-slate-500">Inicia sesión para gestionar tu cuenta.</p>
              )}
            </div>
          </PopoverContent>
        </Popover>

        <nav className="mt-4 space-y-1 px-3 text-sm">
          {permissions.dashboard && (
            <Link
              href="/"
              className="flex items-center gap-2 rounded-lg px-3 py-2 text-slate-100 hover:bg-slate-800 hover:text-white"
            >
              <BarChart3 className="h-4 w-4" />
              <span>Dashboard</span>
            </Link>
          )}
          {permissions.licitaciones && (
            <Link
              href="/licitaciones"
              className={`flex items-center gap-2 rounded-lg px-3 py-2 text-slate-100 hover:bg-slate-800 hover:text-white ${pathname.startsWith("/licitaciones") ? "bg-slate-800" : ""}`}
            >
              <FolderKanban className="h-4 w-4" />
              <span>{role && role.includes("planta") ? "CRM Presupuestos" : "Mis Licitaciones"}</span>
            </Link>
          )}
          {permissions.buscador && (
            <Link
              href="/buscador"
              className={`flex items-center gap-2 rounded-lg px-3 py-2 text-slate-100 hover:bg-slate-800 hover:text-white ${pathname === "/buscador" ? "bg-slate-800" : ""}`}
            >
              <Search className="h-4 w-4" />
              <span>Buscador Histórico</span>
            </Link>
          )}
          {permissions.lineas && (
            <>
              <Link
                href="/lineas-referencia"
                className="flex items-center gap-2 rounded-lg px-3 py-2 text-slate-100 hover:bg-slate-800 hover:text-white"
              >
                <ListPlus className="h-4 w-4" />
                <span>Añadir líneas</span>
              </Link>
            </>
          )}
          {permissions.analytics && (
            <Link
              href="/dashboard/analytics"
              className="flex items-center gap-2 rounded-lg px-3 py-2 text-slate-100 hover:bg-slate-800 hover:text-white"
            >
              <LineChart className="h-4 w-4" />
              <span>Analítica</span>
            </Link>
          )}
          {(SKIP_LOGIN || permissions.usuarios) && (
            <Link
              href="/usuarios"
              className={`flex items-center gap-2 rounded-lg px-3 py-2 text-slate-100 hover:bg-slate-800 hover:text-white ${pathname === "/usuarios" ? "bg-slate-800" : ""}`}
            >
              <Users className="h-4 w-4" />
              <span>Usuarios</span>
            </Link>
          )}
        </nav>
      </aside>

      <Dialog open={passwordDialogOpen} onOpenChange={setPasswordDialogOpen}>
        <DialogContent className="sm:max-w-sm">
          <DialogHeader>
            <DialogTitle>Cambiar contraseña</DialogTitle>
          </DialogHeader>
          <form onSubmit={handleChangePassword} className="space-y-4">
            {passwordError && (
              <p className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{passwordError}</p>
            )}
            <div>
              <label htmlFor="account-new-password" className="mb-1 block text-sm font-medium text-slate-700">
                Nueva contraseña
              </label>
              <Input
                id="account-new-password"
                type="password"
                autoComplete="new-password"
                value={passwordNew}
                onChange={(e) => setPasswordNew(e.target.value)}
                className="w-full"
                minLength={6}
              />
            </div>
            <div>
              <label htmlFor="account-confirm-password" className="mb-1 block text-sm font-medium text-slate-700">
                Repetir contraseña
              </label>
              <Input
                id="account-confirm-password"
                type="password"
                autoComplete="new-password"
                value={passwordConfirm}
                onChange={(e) => setPasswordConfirm(e.target.value)}
                className="w-full"
              />
            </div>
            <div className="flex justify-end gap-2">
              <Button
                type="button"
                variant="outline"
                onClick={() => setPasswordDialogOpen(false)}
                disabled={passwordChanging}
              >
                Cancelar
              </Button>
              <Button type="submit" disabled={passwordChanging}>
                {passwordChanging ? "Guardando…" : "Guardar"}
              </Button>
            </div>
          </form>
        </DialogContent>
      </Dialog>

      <main className="ml-64 flex min-h-screen flex-1 flex-col bg-slate-50">
        <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col px-6 py-6 lg:px-10 lg:py-8">
          {children}
        </div>
      </main>
    </div>
  );
}
