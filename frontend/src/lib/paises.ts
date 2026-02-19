import type { PaisLicitacion } from "@/types/api";

/** Rutas de las banderas en public/flags (servidas como /flags/...) */
export const PAIS_FLAG_SRC: Record<PaisLicitacion, string> = {
  España: "/flags/spain.png",
  Portugal: "/flags/portugal.png",
};

export const PAIS_LABEL: Record<PaisLicitacion, string> = {
  España: "España",
  Portugal: "Portugal",
};

export const PAISES_OPCIONES: PaisLicitacion[] = ["España", "Portugal"];
