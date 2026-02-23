"use client";

import * as React from "react";
import type { TenderPartida } from "@/types/api";

type FixMissingProductsModalProps = {
  isOpen: boolean;
  onClose: () => void;
  partidasInvalidas: TenderPartida[];
  tenderId: number;
  onSuccess: () => void;
};

export function FixMissingProductsModal({
  isOpen,
  onClose,
  partidasInvalidas,
  tenderId,
  onSuccess,
}: FixMissingProductsModalProps) {
  React.useEffect(() => {
    if (isOpen && partidasInvalidas.length === 0) {
      onClose();
    }
  }, [isOpen, partidasInvalidas, onClose]);

  return null;
}

"use client";

import * as React from "react";
import type { TenderPartida } from "@/types/api";

type FixMissingProductsModalProps = {
  isOpen: boolean;
  onClose: () => void;
  partidasInvalidas: TenderPartida[];
  tenderId: number;
  onSuccess: () => void;
};

export function FixMissingProductsModal({
  isOpen,
  onClose,
  partidasInvalidas,
  tenderId,
  onSuccess,
}: FixMissingProductsModalProps) {
  // Implementación mínima temporal para evitar errores de build.
  // Más adelante se puede volver a añadir la UI completa del modal.
  React.useEffect(() => {
    if (isOpen && partidasInvalidas.length === 0) {
      onClose();
    }
  }, [isOpen, partidasInvalidas, onClose]);

  return null;
}

"use client";

import * as React from "react";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import type { TenderPartida, ProductoSearchResult } from "@/types/api";
import { ProductosService, TendersService } from "@/services/api";

const DEBOUNCE_MS = 280;

type FixMissingProductsModalProps = {
  isOpen: boolean;
  onClose: () => void;
  partidasInvalidas: TenderPartida[];
  tenderId: number;
  onSuccess: () => void;
};

type LocalRow = {
  partida: TenderPartida;
  query: string;
  selectedProduct: { id: number; nombre: string } | null;
  options: ProductoSearchResult[];
  loading: boolean;
};

export function FixMissingProductsModal({
  isOpen,
  onClose,
  partidasInvalidas,
  tenderId,
  onSuccess,
}: FixMissingProductsModalProps) {
  const [rows, setRows] = React.useState<LocalRow[]>([]);
  const [saving, setSaving] = React.useState(false);
  const debounceRef = React.useRef<ReturnType<typeof setTimeout> | null>(null);

  React.useEffect(() => {
    if (!isOpen) return;
    const initial: LocalRow[] = (partidasInvalidas ?? []).map((p) => ({
      partida: p,
      query: (p.nombre_producto_libre ?? "").trim(),
      selectedProduct:
        p.id_producto != null && p.product_nombre
          ? { id: p.id_producto, nombre: p.product_nombre }
          : null,
      options: [],
      loading: false,
    }));
    setRows(initial);

    // Lanzar búsqueda inicial para cada partida con texto libre
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => {
      initial.forEach((row, idx) => {
        const q = row.query.trim();
        if (!q) return;
        setRows((prev) =>
          prev.map((r, i) => (i === idx ? { ...r, loading: true } : r))
        );
        ProductosService.search(q)
          .then((data) => {
            setRows((prev) =>
              prev.map((r, i) =>
                i === idx ? { ...r, options: data, loading: false } : r
              )
            );
          })
          .catch(() => {
            setRows((prev) =>
              prev.map((r, i) =>
                i === idx ? { ...r, options: [], loading: false } : r
              )
            );
          });
      });
      debounceRef.current = null;
    }, DEBOUNCE_MS);

    return () => {
      if (debounceRef.current) clearTimeout(debounceRef.current);
    };
  }, [isOpen, partidasInvalidas]);

  const handleChangeQuery = (index: number, value: string) => {
    setRows((prev) =>
      prev.map((r, i) =>
        i === index ? { ...r, query: value, selectedProduct: null } : r
      )
    );
    if (debounceRef.current) clearTimeout(debounceRef.current);
    const q = value.trim();
    if (!q) {
      setRows((prev) =>
        prev.map((r, i) => (i === index ? { ...r, options: [] } : r))
      );
      return;
    }
    debounceRef.current = setTimeout(() => {
      setRows((prev) =>
        prev.map((r, i) => (i === index ? { ...r, loading: true } : r))
      );
      ProductosService.search(q)
        .then((data) => {
          setRows((prev) =>
            prev.map((r, i) =>
              i === index ? { ...r, options: data, loading: false } : r
            )
          );
        })
        .catch(() => {
          setRows((prev) =>
            prev.map((r, i) =>
              i === index ? { ...r, options: [], loading: false } : r
            )
          );
        })
        .finally(() => {
          debounceRef.current = null;
        });
    }, DEBOUNCE_MS);
  };

  const handleSelect = (index: number, opt: ProductoSearchResult) => {
    setRows((prev) =>
      prev.map((r, i) =>
        i === index
          ? {
              ...r,
              selectedProduct: { id: opt.id, nombre: opt.nombre },
              query: opt.nombre,
            }
          : r
      )
    );
  };

  const handleSave = async () => {
    const toUpdate = rows.filter((r) => r.selectedProduct);
    if (toUpdate.length === 0) {
      onClose();
      return;
    }
    setSaving(true);
    try {
      for (const r of toUpdate) {
        await TendersService.updatePartida(tenderId, r.partida.id_detalle, {
          id_producto: r.selectedProduct!.id,
          nombre_producto_libre: undefined,
        });
      }
      onSuccess();
      onClose();
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-3xl">
        <DialogHeader>
          <DialogTitle>Vincular productos del ERP</DialogTitle>
          <DialogDescription>
            Algunas partidas no tienen producto de catálogo asignado. Usa el
            buscador para vincular cada una antes de adjudicar la licitación.
          </DialogDescription>
        </DialogHeader>
        <div className="max-h-[420px] space-y-3 overflow-auto pt-2">
          {rows.map((row, index) => (
            <div
              key={row.partida.id_detalle}
              className="rounded-md border border-slate-200 bg-slate-50 p-3"
            >
              <div className="mb-1 flex items-center justify-between text-xs text-slate-600">
                <span>
                  <span className="font-semibold">Lote:</span>{" "}
                  {row.partida.lote ?? "General"}
                </span>
                <span className="font-semibold text-slate-500">
                  Partida #{row.partida.id_detalle}
                </span>
              </div>
              <p className="mb-2 text-sm">
                <span className="font-semibold text-slate-700">
                  Texto libre:
                </span>{" "}
                {row.partida.nombre_producto_libre || "(sin texto)"}
              </p>
              <div className="space-y-2">
                <Input
                  type="text"
                  autoComplete="off"
                  placeholder="Buscar producto en ERP..."
                  className="w-full text-sm"
                  value={row.query}
                  onChange={(e) => handleChangeQuery(index, e.target.value)}
                />
                {row.loading && (
                  <p className="text-xs text-slate-500">Buscando productos…</p>
                )}
                {!row.loading && row.options.length > 0 && (
                  <ul className="max-h-40 space-y-1 overflow-auto rounded border border-slate-200 bg-white p-2 text-sm">
                    {row.options.map((opt) => (
                      <li
                        key={opt.id}
                        className={`flex cursor-pointer items-center justify-between rounded px-2 py-1 hover:bg-slate-100 ${
                          row.selectedProduct?.id === opt.id
                            ? "bg-emerald-50"
                            : ""
                        }`}
                        onClick={() => handleSelect(index, opt)}
                      >
                        <span>{opt.nombre}</span>
                        {opt.nombre_proveedor && (
                          <span className="ml-2 text-xs text-slate-500">
                            {opt.nombre_proveedor}
                          </span>
                        )}
                      </li>
                    ))}
                  </ul>
                )}
                {!row.loading && row.options.length === 0 && row.query.trim() && (
                  <p className="text-xs text-amber-700">
                    No se han encontrado productos para &quot;{row.query}&quot;.
                  </p>
                )}
              </div>
            </div>
          ))}
          {rows.length === 0 && (
            <p className="text-sm text-slate-600">
              No hay partidas pendientes de vincular.
            </p>
          )}
        </div>
        <DialogFooter className="mt-4 flex justify-end gap-2">
          <Button
            type="button"
            variant="outline"
            onClick={onClose}
            disabled={saving}
          >
            Cancelar
          </Button>
          <Button type="button" onClick={handleSave} disabled={saving}>
            {saving ? "Guardando…" : "Guardar y continuar"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

"use client";

import * as React from "react";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import type { TenderPartida, ProductoSearchResult } from "@/types/api";
import { ProductosService, TendersService } from "@/services/api";

const DEBOUNCE_MS = 280;

type FixMissingProductsModalProps = {
  isOpen: boolean;
  onClose: () => void;
  partidasInvalidas: TenderPartida[];
  tenderId: number;
  onSuccess: () => void;
};

type LocalRow = {
  partida: TenderPartida;
  query: string;
  selectedProduct: { id: number; nombre: string } | null;
  options: ProductoSearchResult[];
  loading: boolean;
};

export function FixMissingProductsModal({
  isOpen,
  onClose,
  partidasInvalidas,
  tenderId,
  onSuccess,
}: FixMissingProductsModalProps) {
  const [rows, setRows] = React.useState<LocalRow[]>([]);
  const [saving, setSaving] = React.useState(false);
  const debounceRef = React.useRef<ReturnType<typeof setTimeout> | null>(null);

  React.useEffect(() => {
    if (!isOpen) return;
    const initial: LocalRow[] = (partidasInvalidas ?? []).map((p) => ({
      partida: p,
      query: (p.nombre_producto_libre ?? "").trim(),
      selectedProduct:
        p.id_producto != null && p.product_nombre
          ? { id: p.id_producto, nombre: p.product_nombre }
          : null,
      options: [],
      loading: false,
    }));
    setRows(initial);

    // Lanzar búsqueda inicial para cada partida con texto libre
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => {
      initial.forEach((row, idx) => {
        const q = row.query.trim();
        if (!q) return;
        setRows((prev) =>
          prev.map((r, i) => (i === idx ? { ...r, loading: true } : r))
        );
        ProductosService.search(q)
          .then((data) => {
            setRows((prev) =>
              prev.map((r, i) =>
                i === idx ? { ...r, options: data, loading: false } : r
              )
            );
          })
          .catch(() => {
            setRows((prev) =>
              prev.map((r, i) =>
                i === idx ? { ...r, options: [], loading: false } : r
              )
            );
          });
      });
      debounceRef.current = null;
    }, DEBOUNCE_MS);

    return () => {
      if (debounceRef.current) clearTimeout(debounceRef.current);
    };
  }, [isOpen, partidasInvalidas]);

  const handleChangeQuery = (index: number, value: string) => {
    setRows((prev) =>
      prev.map((r, i) =>
        i === index ? { ...r, query: value, selectedProduct: null } : r
      )
    );
    if (debounceRef.current) clearTimeout(debounceRef.current);
    const q = value.trim();
    if (!q) {
      setRows((prev) =>
        prev.map((r, i) => (i === index ? { ...r, options: [] } : r))
      );
      return;
    }
    debounceRef.current = setTimeout(() => {
      setRows((prev) =>
        prev.map((r, i) => (i === index ? { ...r, loading: true } : r))
      );
      ProductosService.search(q)
        .then((data) => {
          setRows((prev) =>
            prev.map((r, i) =>
              i === index ? { ...r, options: data, loading: false } : r
            )
          );
        })
        .catch(() => {
          setRows((prev) =>
            prev.map((r, i) =>
              i === index ? { ...r, options: [], loading: false } : r
            )
          );
        })
        .finally(() => {
          debounceRef.current = null;
        });
    }, DEBOUNCE_MS);
  };

  const handleSelect = (index: number, opt: ProductoSearchResult) => {
    setRows((prev) =>
      prev.map((r, i) =>
        i === index
          ? {
              ...r,
              selectedProduct: { id: opt.id, nombre: opt.nombre },
              query: opt.nombre,
            }
          : r
      )
    );
  };

  const handleSave = async () => {
    const toUpdate = rows.filter((r) => r.selectedProduct);
    if (toUpdate.length === 0) {
      onClose();
      return;
    }
    setSaving(true);
    try {
      for (const r of toUpdate) {
        await TendersService.updatePartida(tenderId, r.partida.id_detalle, {
          id_producto: r.selectedProduct!.id,
          nombre_producto_libre: undefined,
        });
      }
      onSuccess();
      onClose();
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-3xl">
        <DialogHeader>
          <DialogTitle>Vincular productos del ERP</DialogTitle>
          <DialogDescription>
            Algunas partidas no tienen producto de catálogo asignado. Usa el
            buscador para vincular cada una antes de adjudicar la licitación.
          </DialogDescription>
        </DialogHeader>
        <div className="max-h-[420px] space-y-3 overflow-auto pt-2">
          {rows.map((row, index) => (
            <div
              key={row.partida.id_detalle}
              className="rounded-md border border-slate-200 bg-slate-50 p-3"
            >
              <div className="mb-1 flex items-center justify-between text-xs text-slate-600">
                <span>
                  <span className="font-semibold">Lote:</span>{" "}
                  {row.partida.lote ?? "General"}
                </span>
                <span className="font-semibold text-slate-500">
                  Partida #{row.partida.id_detalle}
                </span>
              </div>
              <p className="mb-2 text-sm">
                <span className="font-semibold text-slate-700">
                  Texto libre:
                </span>{" "}
                {row.partida.nombre_producto_libre || "(sin texto)"}
              </p>
              <div className="space-y-2">
                <Input
                  type="text"
                  autoComplete="off"
                  placeholder="Buscar producto en ERP..."
                  className="w-full text-sm"
                  value={row.query}
                  onChange={(e) => handleChangeQuery(index, e.target.value)}
                />
                {row.loading && (
                  <p className="text-xs text-slate-500">Buscando productos…</p>
                )}
                {!row.loading && row.options.length > 0 && (
                  <ul className="max-h-40 space-y-1 overflow-auto rounded border border-slate-200 bg-white p-2 text-sm">
                    {row.options.map((opt) => (
                      <li
                        key={opt.id}
                        className={`flex cursor-pointer items-center justify-between rounded px-2 py-1 hover:bg-slate-100 ${
                          row.selectedProduct?.id === opt.id
                            ? "bg-emerald-50"
                            : ""
                        }`}
                        onClick={() => handleSelect(index, opt)}
                      >
                        <span>{opt.nombre}</span>
                        {opt.nombre_proveedor && (
                          <span className="ml-2 text-xs text-slate-500">
                            {opt.nombre_proveedor}
                          </span>
                        )}
                      </li>
                    ))}
                  </ul>
                )}
                {!row.loading && row.options.length === 0 && row.query.trim() && (
                  <p className="text-xs text-amber-700">
                    No se han encontrado productos para &quot;{row.query}&quot;.
                  </p>
                )}
              </div>
            </div>
          ))}
          {rows.length === 0 && (
            <p className="text-sm text-slate-600">
              No hay partidas pendientes de vincular.
            </p>
          )}
        </div>
        <DialogFooter className="mt-4 flex justify-end gap-2">
          <Button
            type="button"
            variant="outline"
            onClick={onClose}
            disabled={saving}
          >
            Cancelar
          </Button>
          <Button type="button" onClick={handleSave} disabled={saving}>
            {saving ? "Guardando…" : "Guardar y continuar"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

\"use client\";

import * as React from \"react\";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from \"@/components/ui/dialog\";
import { Button } from \"@/components/ui/button\";
import { Input } from \"@/components/ui/input\";
import type { TenderPartida, ProductoSearchResult } from \"@/types/api\";
import { ProductosService, TendersService } from \"@/services/api\";

const DEBOUNCE_MS = 280;

type FixMissingProductsModalProps = {
  isOpen: boolean;
  onClose: () => void;
  partidasInvalidas: TenderPartida[];
  tenderId: number;
  onSuccess: () => void;
};

type LocalRow = {
  partida: TenderPartida;
  query: string;
  selectedProduct: { id: number; nombre: string } | null;
  options: ProductoSearchResult[];
  loading: boolean;
};

export function FixMissingProductsModal({
  isOpen,
  onClose,
  partidasInvalidas,
  tenderId,
  onSuccess,
}: FixMissingProductsModalProps) {
  const [rows, setRows] = React.useState<LocalRow[]>([]);
  const [saving, setSaving] = React.useState(false);
  const debounceRef = React.useRef<ReturnType<typeof setTimeout> | null>(null);

  React.useEffect(() => {
    if (!isOpen) return;
    // Inicializar filas con la query igual a nombre_producto_libre para disparar sugerencias
    const initial: LocalRow[] = (partidasInvalidas ?? []).map((p) => ({
      partida: p,
      query: (p.nombre_producto_libre ?? \"\").trim(),
      selectedProduct: p.id_producto && p.product_nombre ? { id: p.id_producto, nombre: p.product_nombre } : null,
      options: [],
      loading: false,
    }));
    setRows(initial);

    // Lanzar búsqueda inicial para cada partida con texto libre
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => {
      initial.forEach((row, idx) => {
        const q = row.query.trim();
        if (!q) return;
        setRows(prev =>
          prev.map((r, i) => (i === idx ? { ...r, loading: true } : r))
        );
        ProductosService.search(q)
          .then((data) => {
            setRows(prev =>
              prev.map((r, i) =>
                i === idx ? { ...r, options: data, loading: false } : r
              )
            );
          })
          .catch(() => {
            setRows(prev =>
              prev.map((r, i) =>
                i === idx ? { ...r, options: [], loading: false } : r
              )
            );
          });
      });
      debounceRef.current = null;
    }, DEBOUNCE_MS);

    return () => {
      if (debounceRef.current) clearTimeout(debounceRef.current);
    };
  }, [isOpen, partidasInvalidas]);

  const handleChangeQuery = (index: number, value: string) => {
    setRows(prev =>
      prev.map((r, i) =>
        i === index ? { ...r, query: value, selectedProduct: null } : r
      )
    );
    if (debounceRef.current) clearTimeout(debounceRef.current);
    const q = value.trim();
    if (!q) {
      setRows(prev =>
        prev.map((r, i) => (i === index ? { ...r, options: [] } : r))
      );
      return;
    }
    debounceRef.current = setTimeout(() => {
      setRows(prev =>
        prev.map((r, i) => (i === index ? { ...r, loading: true } : r))
      );
      ProductosService.search(q)
        .then((data) => {
          setRows(prev =>
            prev.map((r, i) =>
              i === index ? { ...r, options: data, loading: false } : r
            )
          );
        })
        .catch(() => {
          setRows(prev =>
            prev.map((r, i) =>
              i === index ? { ...r, options: [], loading: false } : r
            )
          );
        })
        .finally(() => {
          debounceRef.current = null;
        });
    }, DEBOUNCE_MS);
  };

  const handleSelect = (index: number, opt: ProductoSearchResult) => {
    setRows(prev =>
      prev.map((r, i) =>
        i === index
          ? {
              ...r,
              selectedProduct: { id: opt.id, nombre: opt.nombre },
              query: opt.nombre,
            }
          : r
      )
    );
  };

  const handleSave = async () => {
    const toUpdate = rows.filter((r) => r.selectedProduct);
    if (toUpdate.length === 0) {
      onClose();
      return;
    }
    setSaving(true);
    try {
      for (const r of toUpdate) {
        await TendersService.updatePartida(tenderId, r.partida.id_detalle, {
          id_producto: r.selectedProduct!.id,
          nombre_producto_libre: undefined,
        });
      }
      onSuccess();
      onClose();
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className=\"max-w-3xl\">
        <DialogHeader>
          <DialogTitle>Vincular productos del ERP</DialogTitle>
          <DialogDescription>
            Algunas partidas no tienen producto de catálogo asignado. Usa el buscador para vincular cada una antes de adjudicar la licitación.
          </DialogDescription>
        </DialogHeader>
        <div className=\"max-h-[420px] space-y-3 overflow-auto pt-2\">
          {rows.map((row, index) => (
            <div
              key={row.partida.id_detalle}
              className=\"rounded-md border border-slate-200 bg-slate-50 p-3\"
            >
              <div className=\"mb-1 flex items-center justify-between text-xs text-slate-600\">
                <span>
                  <span className=\"font-semibold\">Lote:</span> {row.partida.lote ?? \"General\"}
                </span>
                <span className=\"font-semibold text-slate-500\">
                  Partida #{row.partida.id_detalle}
                </span>
              </div>
              <p className=\"mb-2 text-sm\">
                <span className=\"font-semibold text-slate-700\">Texto libre:</span>{\" "}
                {row.partida.nombre_producto_libre || \"(sin texto)\"}
              </p>
              <div className=\"space-y-2\">
                <Input
                  type=\"text\"
                  autoComplete=\"off\"
                  placeholder=\"Buscar producto en ERP...\"
                  className=\"w-full text-sm\"
                  value={row.query}
                  onChange={(e) => handleChangeQuery(index, e.target.value)}
                />
                {row.loading && (
                  <p className=\"text-xs text-slate-500\">Buscando productos…</p>
                )}
                {!row.loading && row.options.length > 0 && (
                  <ul className=\"max-h-40 space-y-1 overflow-auto rounded border border-slate-200 bg-white p-2 text-sm\">
                    {row.options.map((opt) => (
                      <li
                        key={opt.id}
                        className={`flex cursor-pointer items-center justify-between rounded px-2 py-1 hover:bg-slate-100 ${
                          row.selectedProduct?.id === opt.id ? \"bg-emerald-50\" : \"\"
                        }`}
                        onClick={() => handleSelect(index, opt)}
                      >
                        <span>{opt.nombre}</span>
                        {opt.nombre_proveedor && (
                          <span className=\"ml-2 text-xs text-slate-500\">
                            {opt.nombre_proveedor}
                          </span>
                        )}
                      </li>
                    ))}
                  </ul>
                )}
                {!row.loading && row.options.length === 0 && row.query.trim() && (
                  <p className=\"text-xs text-amber-700\">
                    No se han encontrado productos para &quot;{row.query}&quot;.
                  </p>
                )}
              </div>
            </div>
          ))}
          {rows.length === 0 && (
            <p className=\"text-sm text-slate-600\">
              No hay partidas pendientes de vincular.
            </p>
          )}
        </div>
        <DialogFooter className=\"mt-4 flex justify-end gap-2\">
          <Button
            type=\"button\"
            variant=\"outline\"
            onClick={onClose}
            disabled={saving}
          >
            Cancelar
          </Button>
          <Button type=\"button\" onClick={handleSave} disabled={saving}>
            {saving ? \"Guardando…\" : \"Guardar y continuar\"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

