"use client";

import * as React from "react";
import { useRouter } from "next/navigation";
import { KeyRound, Trash2, UserPlus, Users } from "lucide-react";

import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { UsersService, type OrgUser } from "@/services/api";

const STORAGE_KEY = "veraleza_user";
const SKIP_LOGIN =
  typeof process !== "undefined" &&
  process.env.NEXT_PUBLIC_SKIP_LOGIN === "true";

// Jerarquía: Administrador > Admin planta | Admin licitaciones > Miembro planta | Miembro licitaciones
const ROLES = [
  { value: "admin", label: "Administrador" },
  { value: "admin_planta", label: "Administrador planta" },
  { value: "admin_licitaciones", label: "Administrador licitaciones" },
  { value: "member_planta", label: "Miembro planta" },
  { value: "member_licitaciones", label: "Miembro licitaciones" },
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
  const [createOpen, setCreateOpen] = React.useState(false);
  const [createEmail, setCreateEmail] = React.useState("");
  const [createPassword, setCreatePassword] = React.useState("");
  const [createPasswordConfirm, setCreatePasswordConfirm] = React.useState("");
  const [createFullName, setCreateFullName] = React.useState("");
  const [createRole, setCreateRole] = React.useState("member_licitaciones");
  const [creating, setCreating] = React.useState(false);
  const [createError, setCreateError] = React.useState<string | null>(null);
  const [passwordUser, setPasswordUser] = React.useState<OrgUser | null>(null);
  const [passwordNew, setPasswordNew] = React.useState("");
  const [passwordConfirm, setPasswordConfirm] = React.useState("");
  const [passwordChanging, setPasswordChanging] = React.useState(false);
  const [passwordError, setPasswordError] = React.useState<string | null>(null);
  const [deleteUser, setDeleteUser] = React.useState<OrgUser | null>(null);
  const [deletingId, setDeletingId] = React.useState<string | null>(null);
  const [deleteError, setDeleteError] = React.useState<string | null>(null);

  const refetchUsers = React.useCallback(() => {
    setLoading(true);
    UsersService.list()
      .then(setUsers)
      .catch((e) => {
        setError(e instanceof Error ? e.message : "Error al cargar usuarios");
        setUsers([]);
      })
      .finally(() => setLoading(false));
  }, []);

  React.useEffect(() => {
    if (!checked) return;
    if (!isAdmin) {
      router.replace("/");
      return;
    }
    setError(null);
    refetchUsers();
  }, [checked, isAdmin, router, refetchUsers]);

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

  const handleCreateUser = React.useCallback(
    async (e: React.FormEvent) => {
      e.preventDefault();
      setCreateError(null);
      if (!createEmail.trim() || !createPassword.trim()) {
        setCreateError("Email y contraseña son obligatorios.");
        return;
      }
      if (createPassword.length < 6) {
        setCreateError("La contraseña debe tener al menos 6 caracteres.");
        return;
      }
      if (createPassword !== createPasswordConfirm) {
        setCreateError("Las contraseñas no coinciden.");
        return;
      }
      setCreating(true);
      try {
        await UsersService.create({
          email: createEmail.trim(),
          password: createPassword,
          full_name: createFullName.trim() || undefined,
          role: createRole,
        });
        setCreateOpen(false);
        setCreateEmail("");
        setCreatePassword("");
        setCreatePasswordConfirm("");
        setCreateFullName("");
        setCreateRole("member_licitaciones");
        refetchUsers();
      } catch (e) {
        setCreateError(e instanceof Error ? e.message : "Error al crear el usuario");
      } finally {
        setCreating(false);
      }
    },
    [createEmail, createPassword, createPasswordConfirm, createFullName, createRole, refetchUsers]
  );

  const handleChangePasswordSubmit = React.useCallback(
    async (e: React.FormEvent) => {
      e.preventDefault();
      if (!passwordUser) return;
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
        await UsersService.updatePassword(passwordUser.id, passwordNew);
        setPasswordUser(null);
        setPasswordNew("");
        setPasswordConfirm("");
      } catch (e) {
        setPasswordError(e instanceof Error ? e.message : "Error al cambiar la contraseña");
      } finally {
        setPasswordChanging(false);
      }
    },
    [passwordUser, passwordNew, passwordConfirm]
  );

  const handleConfirmDelete = React.useCallback(async () => {
    if (!deleteUser) return;
    setDeleteError(null);
    setDeletingId(deleteUser.id);
    try {
      await UsersService.delete(deleteUser.id);
      setDeleteUser(null);
      refetchUsers();
    } catch (e) {
      setDeleteError(e instanceof Error ? e.message : "Error al eliminar el usuario");
    } finally {
      setDeletingId(null);
    }
  }, [deleteUser, refetchUsers]);

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
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-semibold text-slate-900">
            <Users className="h-7 w-7" />
            Gestión de usuarios
          </h1>
          <p className="mt-1 text-sm text-slate-600">
            Administra los roles de los usuarios de tu organización.
          </p>
        </div>
        <Dialog open={createOpen} onOpenChange={setCreateOpen}>
          <DialogTrigger asChild>
            <Button type="button" className="flex items-center gap-2">
              <UserPlus className="h-4 w-4" />
              Crear usuario
            </Button>
          </DialogTrigger>
          <DialogContent className="max-w-md">
            <DialogHeader>
              <DialogTitle>Nuevo usuario</DialogTitle>
              <DialogDescription>
                El usuario se creará en tu organización. Elige el rol que tendrá desde el inicio.
              </DialogDescription>
            </DialogHeader>
            <form onSubmit={handleCreateUser} className="mt-4 space-y-4">
              {createError && (
                <div
                  className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"
                  role="alert"
                >
                  {createError}
                </div>
              )}
              <div>
                <label htmlFor="new-email" className="mb-1 block text-sm font-medium text-slate-700">
                  Email
                </label>
                <Input
                  id="new-email"
                  type="email"
                  autoComplete="email"
                  value={createEmail}
                  onChange={(e) => setCreateEmail(e.target.value)}
                  placeholder="usuario@ejemplo.com"
                  required
                  className="w-full"
                />
              </div>
              <div>
                <label htmlFor="new-password" className="mb-1 block text-sm font-medium text-slate-700">
                  Contraseña
                </label>
                <Input
                  id="new-password"
                  type="password"
                  autoComplete="new-password"
                  value={createPassword}
                  onChange={(e) => setCreatePassword(e.target.value)}
                  placeholder="Mínimo 6 caracteres"
                  required
                  minLength={6}
                  className="w-full"
                />
              </div>
              <div>
                <label htmlFor="new-password-confirm" className="mb-1 block text-sm font-medium text-slate-700">
                  Repetir contraseña
                </label>
                <Input
                  id="new-password-confirm"
                  type="password"
                  autoComplete="new-password"
                  value={createPasswordConfirm}
                  onChange={(e) => setCreatePasswordConfirm(e.target.value)}
                  placeholder="Repite la contraseña"
                  required
                  minLength={6}
                  className="w-full"
                />
              </div>
              <div>
                <label htmlFor="new-fullname" className="mb-1 block text-sm font-medium text-slate-700">
                  Nombre (opcional)
                </label>
                <Input
                  id="new-fullname"
                  type="text"
                  value={createFullName}
                  onChange={(e) => setCreateFullName(e.target.value)}
                  placeholder="Nombre completo"
                  className="w-full"
                />
              </div>
              <div>
                <label htmlFor="new-role" className="mb-1 block text-sm font-medium text-slate-700">
                  Rol
                </label>
                <select
                  id="new-role"
                  value={createRole}
                  onChange={(e) => setCreateRole(e.target.value)}
                  className="w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                >
                  {ROLES.map((r) => (
                    <option key={r.value} value={r.value}>
                      {r.label}
                    </option>
                  ))}
                </select>
              </div>
              <div className="flex justify-end gap-2 pt-2">
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setCreateOpen(false)}
                  disabled={creating}
                >
                  Cancelar
                </Button>
                <Button type="submit" disabled={creating}>
                  {creating ? "Creando…" : "Crear usuario"}
                </Button>
              </div>
            </form>
          </DialogContent>
        </Dialog>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Usuarios de la organización</CardTitle>
          <CardDescription>
            Administrador (máximo). Debajo: Administrador planta → Miembro planta; Administrador licitaciones → Miembro licitaciones.
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
                    <th className="pb-2 font-medium text-slate-700 text-right">Acciones</th>
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
                          value={ROLES.some((r) => r.value === u.role) ? u.role : "member_licitaciones"}
                          onChange={(e) => handleRoleChange(u.id, e.target.value)}
                          disabled={updatingId === u.id}
                          className="w-48 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-emerald-500 disabled:opacity-60"
                        >
                          {ROLES.map((r) => (
                            <option key={r.value} value={r.value}>
                              {r.label}
                            </option>
                          ))}
                        </select>
                      </td>
                      <td className="py-3 text-right">
                        <div className="flex items-center justify-end gap-2">
                          <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="flex items-center gap-1.5"
                            onClick={() => {
                              setPasswordUser(u);
                              setPasswordNew("");
                              setPasswordConfirm("");
                              setPasswordError(null);
                            }}
                          >
                            <KeyRound className="h-3.5 w-3.5" />
                            Cambiar contraseña
                          </Button>
                          <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="flex items-center gap-1.5 text-red-600 hover:bg-red-50 hover:text-red-700"
                            onClick={() => {
                              setDeleteUser(u);
                              setDeleteError(null);
                            }}
                            disabled={deletingId === u.id}
                          >
                            <Trash2 className="h-3.5 w-3.5" />
                            Eliminar
                          </Button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>

      <Dialog
        open={!!passwordUser}
        onOpenChange={(open) => {
          if (!open) {
            setPasswordUser(null);
            setPasswordNew("");
            setPasswordConfirm("");
            setPasswordError(null);
          }
        }}
      >
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Cambiar contraseña</DialogTitle>
            <DialogDescription>
              {passwordUser
                ? `Nueva contraseña para ${passwordUser.email ?? passwordUser.full_name ?? "el usuario"}. El usuario podrá entrar con esta contraseña.`
                : ""}
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={handleChangePasswordSubmit} className="mt-4 space-y-4">
            {passwordError && (
              <div
                className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"
                role="alert"
              >
                {passwordError}
              </div>
            )}
            <div>
              <label htmlFor="change-password-new" className="mb-1 block text-sm font-medium text-slate-700">
                Nueva contraseña
              </label>
              <Input
                id="change-password-new"
                type="password"
                autoComplete="new-password"
                value={passwordNew}
                onChange={(e) => setPasswordNew(e.target.value)}
                placeholder="Mínimo 6 caracteres"
                required
                minLength={6}
                className="w-full"
              />
            </div>
            <div>
              <label htmlFor="change-password-confirm" className="mb-1 block text-sm font-medium text-slate-700">
                Repetir contraseña
              </label>
              <Input
                id="change-password-confirm"
                type="password"
                autoComplete="new-password"
                value={passwordConfirm}
                onChange={(e) => setPasswordConfirm(e.target.value)}
                placeholder="Repite la contraseña"
                required
                minLength={6}
                className="w-full"
              />
            </div>
            <div className="flex justify-end gap-2 pt-2">
              <Button
                type="button"
                variant="outline"
                onClick={() => {
                  setPasswordUser(null);
                  setPasswordNew("");
                  setPasswordConfirm("");
                }}
                disabled={passwordChanging}
              >
                Cancelar
              </Button>
              <Button type="submit" disabled={passwordChanging}>
                {passwordChanging ? "Guardando…" : "Guardar contraseña"}
              </Button>
            </div>
          </form>
        </DialogContent>
      </Dialog>

      <Dialog
        open={!!deleteUser}
        onOpenChange={(open) => {
          if (!open) {
            setDeleteUser(null);
            setDeleteError(null);
          }
        }}
      >
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Eliminar usuario</DialogTitle>
            <DialogDescription>
              {deleteUser ? (
                <>
                  ¿Eliminar a <strong>{deleteUser.email ?? deleteUser.full_name ?? "este usuario"}</strong>?
                  No podrá volver a iniciar sesión. Esta acción no se puede deshacer.
                </>
              ) : (
                ""
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="mt-4 flex flex-col gap-3">
            {deleteError && (
              <div
                className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"
                role="alert"
              >
                {deleteError}
              </div>
            )}
            <div className="flex justify-end gap-2">
              <Button
                type="button"
                variant="outline"
                onClick={() => {
                  setDeleteUser(null);
                  setDeleteError(null);
                }}
                disabled={!!deletingId}
              >
                Cancelar
              </Button>
              <Button
                type="button"
                variant="outline"
                className="text-red-600 hover:bg-red-50 hover:text-red-700 border-red-200"
                onClick={handleConfirmDelete}
                disabled={!!deletingId}
              >
                {deletingId ? "Eliminando…" : "Eliminar"}
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  );
}
