"use client";

import * as React from "react";
import { useRouter } from "next/navigation";
import { Users } from "lucide-react";

import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { UsersService, type OrgUser } from "@/services/api";

const STORAGE_KEY = "veraleza_user";
const SKIP_LOGIN =
  typeof process !== "undefined" &&
  process.env.NEXT_PUBLIC_SKIP_LOGIN === "true";

const ROLES = [
  { value: "member", label: "Miembro" },
  { value: "admin", label: "Administrador" },
];

function useIsAdmin(): { isAdmin: boolean; checked: boolean } {
  const [state, setState] = React.useState<{ isAdmin: boolean; checked: boolean }>({
    isAdmin: false,
    checked: false,
  });
  React.useEffect(() => {
    if (SKIP_LOGIN) {
      setState({ isAdmin: true, checked: true });
      return;
    }
    try {
      const raw = window.localStorage.getItem(STORAGE_KEY);
      const parsed = raw ? JSON.parse(raw) : null;
      const role = String(parsed?.role ?? parsed?.rol ?? "").toLowerCase();
      setState({ isAdmin: role === "admin", checked: true });
    } catch {
      setState({ isAdmin: false, checked: true });
    }
  }, []);
  return state;
}

export default function UsuariosPage() {
  const router = useRouter();
  const { isAdmin, checked } = useIsAdmin();
  const [users, setUsers] = React.useState<OrgUser[]>([]);
  const [loading, setLoading] = React.useState(true);
  const [error, setError] = React.useState<string | null>(null);
  const [updatingId, setUpdatingId] = React.useState<string | null>(null);

  React.useEffect(() => {
    if (!checked) return;
    if (!isAdmin) {
      router.replace("/");
      return;
    }
    setLoading(true);
    setError(null);
    UsersService.list()
      .then(setUsers)
      .catch((e) => {
        setError(e instanceof Error ? e.message : "Error al cargar usuarios");
        setUsers([]);
      })
      .finally(() => setLoading(false));
  }, [checked, isAdmin, router]);

  const handleRoleChange = React.useCallback(
    async (userId: string, newRole: string) => {
      setUpdatingId(userId);
      try {
        await UsersService.updateRole(userId, newRole);
        setUsers((prev) =>
          prev.map((u) => (u.id === userId ? { ...u, role: newRole } : u))
        );
      } catch (e) {
        setError(e instanceof Error ? e.message : "Error al actualizar rol");
      } finally {
        setUpdatingId(null);
      }
    },
    []
  );

  if (!checked) {
    return (
      <div className="flex min-h-[200px] items-center justify-center">
        <p className="text-sm text-slate-500">Cargando…</p>
      </div>
    );
  }
  if (!isAdmin) {
    return (
      <div className="flex min-h-[200px] items-center justify-center">
        <p className="text-sm text-slate-500">Redirigiendo…</p>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="flex items-center gap-2 text-2xl font-semibold text-slate-900">
          <Users className="h-7 w-7" />
          Gestión de usuarios
        </h1>
        <p className="mt-1 text-sm text-slate-600">
          Administra los roles de los usuarios de tu organización.
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Usuarios de la organización</CardTitle>
          <CardDescription>
            Cambia el rol (Administrador o Miembro) según los permisos que deba tener cada usuario.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {loading ? (
            <p className="py-8 text-center text-sm text-slate-500">
              Cargando usuarios…
            </p>
          ) : error ? (
            <div
              className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"
              role="alert"
            >
              {error}
            </div>
          ) : users.length === 0 ? (
            <p className="py-8 text-center text-sm text-slate-500">
              No hay usuarios en tu organización.
            </p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-200 text-left">
                    <th className="pb-2 font-medium text-slate-700">Email</th>
                    <th className="pb-2 font-medium text-slate-700">Nombre</th>
                    <th className="pb-2 font-medium text-slate-700">Rol</th>
                  </tr>
                </thead>
                <tbody>
                  {users.map((u) => (
                    <tr
                      key={u.id}
                      className="border-b border-slate-100 last:border-0"
                    >
                      <td className="py-3 text-slate-900">
                        {u.email ?? "—"}
                      </td>
                      <td className="py-3 text-slate-700">
                        {u.full_name ?? "—"}
                      </td>
                      <td className="py-3">
                        <select
                          value={u.role}
                          onChange={(e) => handleRoleChange(u.id, e.target.value)}
                          disabled={updatingId === u.id}
                          className="w-40 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-emerald-500 disabled:opacity-60"
                        >
                          {ROLES.map((r) => (
                            <option key={r.value} value={r.value}>
                              {r.label}
                            </option>
                          ))}
                        </select>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
