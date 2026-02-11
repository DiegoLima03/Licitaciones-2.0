/**
 * Cliente HTTP para la API (FastAPI).
 * baseURL desde NEXT_PUBLIC_API_URL o http://localhost:8000/api por defecto.
 * Interceptors: inyectar token en requests; en 401 redirigir a /login y borrar token.
 */

import axios, { type AxiosError } from "axios";

const STORAGE_TOKEN_KEY = "token";

const baseURL =
  typeof process !== "undefined" && process.env.NEXT_PUBLIC_API_URL
    ? process.env.NEXT_PUBLIC_API_URL
    : "http://localhost:8000/api";

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

// Response: en 401 redirigir a /login y borrar token
apiClient.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    if (error.response?.status === 401 && typeof window !== "undefined") {
      localStorage.removeItem(STORAGE_TOKEN_KEY);
      window.location.href = "/login";
    }
    return Promise.reject(error);
  }
);

export { STORAGE_TOKEN_KEY };
