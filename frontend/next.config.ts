import type { NextConfig } from "next";

const apiUrl =
  process.env.NEXT_PUBLIC_API_URL || "http://127.0.0.1:8000/api";

const nextConfig: NextConfig = {
  reactCompiler: true,
  // Proxy API: /api/* se reenvía al backend. Evita Network Error.
  async rewrites() {
    const base = apiUrl.replace(/\/$/, "");
    return [{ source: "/api/:path*", destination: `${base}/:path*` }];
  },
};

export default nextConfig;
