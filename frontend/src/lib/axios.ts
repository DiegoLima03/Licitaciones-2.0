/**
 * Cliente HTTP para la API (FastAPI).
 * baseURL = /api (Next.js hace proxy al backend en puerto 8000).
 * Interceptors: inyectar token en requests; en 401 redirigir a /login y borrar token.
 */

import axios, { type AxiosError } from "axios";

const STORAGE_TOKEN_KEY = "token";

// Siempre usar /api: Next.js hace proxy al backend. Evita Network Error.
const baseURL = "/api";

export const apiClient = axios.create({
  baseURL,
  headers: {
    "Content-Type": "application/json",
  },
});

// Request: inyectar JWT desde localStorage (solo en cliente)
apiClient.interceptors.request.use((config) => {
  if (typeof window === "undefined") return config;
  const token = localStorage.getItem(STORAGE_TOKEN_KEY);
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Response: en 401 redirigir a /login (solo si SKIP_LOGIN=false)
const skipLogin =
  typeof process !== "undefined" &&
  process.env.NEXT_PUBLIC_SKIP_LOGIN === "true";

apiClient.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    if (
      !skipLogin &&
      error.response?.status === 401 &&
      typeof window !== "undefined"
    ) {
      localStorage.removeItem(STORAGE_TOKEN_KEY);
      localStorage.removeItem("veraleza_user");
      window.location.href = "/login";
    }
    return Promise.reject(error);
  }
);

export { STORAGE_TOKEN_KEY };
