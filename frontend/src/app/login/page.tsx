"use client";

import * as React from "react";
import { useRouter } from "next/navigation";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";

// Usar /api (proxy de Next.js) para evitar Network Error
const API_AUTH = "/api";

type UserResponse = {
  id: number | string | null;
  email: string;
  rol: string | null;
  role?: string | null;
  nombre: string | null;
  access_token?: string | null;
};

const STORAGE_KEY = "veraleza_user";
const TOKEN_KEY = "token";

export default function LoginPage() {
  const router = useRouter();
  const [email, setEmail] = React.useState("");
  const [password, setPassword] = React.useState("");
  const [error, setError] = React.useState<string | null>(null);
  const [loading, setLoading] = React.useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setLoading(true);

    try {
      const res = await fetch(`${API_AUTH}/auth/login`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email, password }),
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok) {
        const detail = data.detail;
        const message =
          typeof detail === "string"
            ? detail
            : Array.isArray(detail)
              ? detail.map((d: { msg?: string }) => d.msg ?? JSON.stringify(d)).join(". ")
              : "Credenciales inválidas. Inténtalo de nuevo.";
        setError(message);
        setLoading(false);
        return;
      }

      const user: UserResponse = {
        id: data.id ?? null,
        email: data.email ?? "",
        rol: data.rol ?? data.role ?? null,
        role: data.role ?? data.rol ?? null,
        nombre: data.nombre ?? null,
        access_token: data.access_token ?? null,
      };

      if (typeof window !== "undefined") {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(user));
        if (data.access_token) {
          window.localStorage.setItem(TOKEN_KEY, data.access_token);
        } else {
          window.localStorage.removeItem(TOKEN_KEY);
        }
      }

      router.push("/");
    } catch (err) {
      setError(
        "No se pudo conectar con el backend. Comprueba que el backend esté en marcha (arrancar-backend.bat o uvicorn backend.main:app --reload)."
      );
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="mx-auto flex max-w-md flex-1 flex-col justify-center py-12">
      <Card>
        <CardHeader>
          <CardTitle>Iniciar sesión</CardTitle>
          <CardDescription>
            Introduce tu email y contraseña para acceder.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleSubmit} className="space-y-4">
            {error && (
              <div
                className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"
                role="alert"
              >
                {error}
              </div>
            )}

            <div>
              <label
                htmlFor="email"
                className="mb-1 block text-sm font-medium text-slate-700"
              >
                Email
              </label>
              <Input
                id="email"
                type="email"
                autoComplete="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="tu@email.com"
                required
                className="w-full"
              />
            </div>

            <div>
              <label
                htmlFor="password"
                className="mb-1 block text-sm font-medium text-slate-700"
              >
                Contraseña
              </label>
              <Input
                id="password"
                type="password"
                autoComplete="current-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="••••••••"
                required
                className="w-full"
              />
            </div>

            <div className="flex flex-col gap-2 pt-2">
              <Button type="submit" disabled={loading} className="w-full">
                {loading ? "Entrando…" : "Entrar"}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}
