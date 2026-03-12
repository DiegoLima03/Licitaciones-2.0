"use client";

import * as React from "react";
import Link from "next/link";
import { Shield, HelpCircle } from "lucide-react";

import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { AuthService, AnalyticsService } from "@/services/api";
import type { RolePermissionsMatrix } from "@/types/api";

type FeatureKey =
  | "dashboard"
  | "licitaciones"
  | "buscador"
  | "lineas"
  | "analytics"
  | "usuarios";

type RoleKey =
  | "admin"
  | "admin_licitaciones"
  | "member_licitaciones"
  | "admin_planta"
  | "member_planta";

const FEATURES: { key: FeatureKey; label: string }[] = [
  { key: "dashboard", label: "Dashboard" },
  { key: "licitaciones", label: "Mis licitaciones" },
  { key: "buscador", label: "Buscador histórico" },
  { key: "lineas", label: "Añadir líneas" },
  { key: "analytics", label: "Analítica" },
  { key: "usuarios", label: "Gestión de usuarios" },
];

const STATIC_ROLES: {
  key: RoleKey;
  label: string;
  description: string;
  permissions: Record<FeatureKey, boolean>;
}[] = [
  {
    key: "admin",
    label: "Administrador",
    description: "Acceso completo a toda la aplicación y gestión de usuarios.",
    permissions: {
      dashboard: true,
      licitaciones: true,
      buscador: true,
      lineas: true,
      analytics: true,
      usuarios: true,
    },
  },
  {
    key: "admin_licitaciones",
    label: "Administrador licitaciones",
    description:
      "Responsable de licitaciones: puede ver el dashboard, gestionar licitaciones y usar analítica.",
    permissions: {
      dashboard: true,
      licitaciones: true,
      buscador: true,
      lineas: true,
      analytics: true,
      usuarios: false,
    },
  },
  {
    key: "member_licitaciones",
    label: "Miembro licitaciones",
    description:
      "Usuario operativo de licitaciones: trabaja el día a día pero sin acceso a configuración ni usuarios.",
    permissions: {
      dashboard: true,
      licitaciones: true,
      buscador: true,
      lineas: true,
      analytics: false,
      usuarios: false,
    },
  },
  {
    key: "admin_planta",
    label: "Administrador planta",
    description:
      "Responsable de planta: centrado en CRM Presupuestos, sin acceso a analítica general ni usuarios.",
    permissions: {
      dashboard: false,
      licitaciones: true,
      buscador: true,
      lineas: true,
      analytics: false,
      usuarios: false,
    },
  },
  {
    key: "member_planta",
    label: "Miembro planta",
    description:
      "Usuario de planta: acceso operativo al CRM Presupuestos y buscador, sin administración.",
    permissions: {
      dashboard: false,
      licitaciones: true,
      buscador: true,
      lineas: false,
      analytics: false,
      usuarios: false,
    },
  },
];

function PermissionCell({ allowed, editable, onToggle }: { allowed: boolean; editable: boolean; onToggle?: () => void }) {
  const common =
    "inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium transition-colors";
  if (!editable) {
    if (allowed) {
      return (
        <span className={`${common} bg-emerald-50 text-emerald-700`}>
          Sí
        </span>
      );
    }
    return (
      <span className={`${common} bg-slate-100 text-slate-500`}>
        No
      </span>
    );
  }
  return (
    <button
      type="button"
      onClick={onToggle}
      className={`${common} ${
        allowed
          ? "bg-emerald-50 text-emerald-700 hover:bg-emerald-100"
          : "bg-slate-100 text-slate-500 hover:bg-slate-200"
      }`}
    >
      {allowed ? "Sí" : "No"}
    </button>
  );
}

export default function RolesPage() {
  const [matrix, setMatrix] = React.useState<RolePermissionsMatrix["matrix"]>({});
  const [loading, setLoading] = React.useState(true);
  const [saving, setSaving] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);
  const [isAdmin, setIsAdmin] = React.useState(false);

  React.useEffect(() => {
    let cancelled = false;
    async function load() {
      try {
        setLoading(true);
        setError(null);
        const [me, perms] = await Promise.all([
          AuthService.getMe().catch(() => null),
          AnalyticsService.getRolePermissions(),
        ]);
        if (cancelled) return;
        if (me && typeof me.role === "string") {
          setIsAdmin(me.role.toLowerCase() === "admin");
        }
        setMatrix(perms.matrix || {});
      } catch (e) {
        if (!cancelled) {
          setError(e instanceof Error ? e.message : "Error cargando permisos");
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => {
      cancelled = true;
    };
  }, []);

  const roles = React.useMemo(() => STATIC_ROLES, []);

  const handleToggle = (roleKey: RoleKey, feature: FeatureKey) => {
    setMatrix(prev => {
      const currentRole = prev[roleKey] ?? {};
      const current = Boolean(currentRole[feature]);
      return {
        ...prev,
        [roleKey]: { ...currentRole, [feature]: !current },
      };
    });
  };

  const handleSave = async () => {
    try {
      setSaving(true);
      setError(null);
      const updated = await AnalyticsService.updateRolePermissions({ matrix });
      setMatrix(updated.matrix || {});
    } catch (e) {
      setError(e instanceof Error ? e.message : "Error guardando permisos");
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="flex flex-1 flex-col gap-6">
      <header className="flex items-start justify-between gap-4">
        <div className="flex items-start gap-3">
          <div className="mt-1 flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700">
            <Shield className="h-5 w-5" />
          </div>
          <div>
            <h1 className="text-2xl font-semibold tracking-tight text-slate-900">
              Roles y permisos
            </h1>
            <p className="mt-1 text-sm text-slate-500">
              Resumen de qué puede hacer cada rol en Veraleza Licitaciones.
              La asignación de roles se gestiona desde{" "}
              <Link
                href="/usuarios"
                className="font-medium text-emerald-700 underline-offset-2 hover:underline"
              >
                Usuarios
              </Link>
              .
            </p>
          </div>
        </div>
      </header>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
          <CardTitle className="text-sm font-semibold text-slate-700">
            Matriz de permisos
          </CardTitle>
          <div className="flex items-center gap-3 text-xs text-slate-500">
            {loading && <span>Cargando…</span>}
            {!loading && (
              <span className="inline-flex items-center gap-1">
                <HelpCircle className="h-3.5 w-3.5" />
                {isAdmin
                  ? "Haz clic en Sí/No para cambiar permisos. Solo Administrador."
                  : "Solo lectura. Contacta con un Administrador para cambios."}
              </span>
            )}
          </div>
        </CardHeader>
        <CardContent>
          {error && (
            <p className="mb-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">
              {error}
            </p>
          )}
          <div className="overflow-x-auto">
            <table className="min-w-full border-collapse text-sm">
              <thead>
                <tr>
                  <th className="w-56 border-b border-slate-200 bg-slate-50 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Rol
                  </th>
                  <th className="border-b border-slate-200 bg-slate-50 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Descripción
                  </th>
                  {FEATURES.map((f) => (
                    <th
                      key={f.key}
                      className="border-b border-slate-200 bg-slate-50 px-3 py-2 text-center text-xs font-semibold uppercase tracking-wide text-slate-500"
                    >
                      {f.label}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {roles.map((role) => {
                  const rolePerms = matrix[role.key] ?? {};
                  return (
                    <tr
                      key={role.key}
                      className="border-b border-slate-100 last:border-0"
                    >
                      <td className="whitespace-nowrap px-3 py-2 align-top text-sm font-medium text-slate-900">
                        <div className="flex items-center gap-2">
                          <Badge variant="outline" className="font-mono text-[11px]">
                            {role.key}
                          </Badge>
                          <span>{role.label}</span>
                        </div>
                      </td>
                      <td className="px-3 py-2 align-top text-xs text-slate-600">
                        {role.description}
                      </td>
                      {FEATURES.map((f) => {
                        const allowed = Boolean(rolePerms[f.key]);
                        return (
                          <td
                            key={f.key}
                            className="px-3 py-2 text-center align-middle"
                          >
                            <PermissionCell
                              allowed={allowed}
                              editable={isAdmin}
                              onToggle={isAdmin ? () => handleToggle(role.key, f.key) : undefined}
                            />
                          </td>
                        );
                      })}
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          {isAdmin && (
            <div className="mt-4 flex justify-end">
              <button
                type="button"
                onClick={handleSave}
                disabled={saving}
                className="inline-flex items-center rounded-md bg-emerald-600 px-4 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-emerald-700 disabled:opacity-60"
              >
                {saving ? "Guardando…" : "Guardar cambios"}
              </button>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

