"use client";

import { create } from "zustand";

interface BuscadorState {
  /** ID del producto seleccionado para mostrar la ficha de analíticas (null = panel cerrado). */
  selectedProductId: number | null;
  setSelectedProductId: (id: number | null) => void;
}

export const useBuscadorStore = create<BuscadorState>((set) => ({
  selectedProductId: null,
  setSelectedProductId: (id) => set({ selectedProductId: id }),
}));
