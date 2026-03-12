"use client";

import * as React from "react";
import type { UseQueryResult } from "@tanstack/react-query";
import type { PriceDeviationResult } from "@/types/api";

export interface PriceDeviationAlertProps {
  query: UseQueryResult<PriceDeviationResult, Error>;
  className?: string;
}

const DEVIATION_ALERT_THRESHOLD = 10;

export function PriceDeviationAlert({ query, className }: PriceDeviationAlertProps) {
  const { data, isLoading, error, isFetching } = query;

  if (isLoading || isFetching || !data) {
    if (query.isEnabled === false) return null;
    return (
      <div
        className={
          "rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500 " +
          (className ?? "")
        }
      >
        Comprobando desviación de precio…
      </div>
    );
  }

  if (error) {
    return (
      <div
        className={
          "rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 " +
          (className ?? "")
        }
      >
        {error.message}
      </div>
    );
  }

  if (!data.is_deviated || Math.abs(data.deviation_percentage) <= DEVIATION_ALERT_THRESHOLD) {
    return (
      <div
        className={
          "rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200 " +
          (className ?? "")
        }
      >
        {data.recommendation}
      </div>
    );
  }

  const isHigh = data.deviation_percentage > DEVIATION_ALERT_THRESHOLD;
  return (
    <div
      className={
        "rounded-lg border px-3 py-2 text-sm " +
        (isHigh
          ? "border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200"
          : "border-red-200 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950/30 dark:text-red-200") +
        " " +
        (className ?? "")
      }
      role="alert"
    >
      <p className="font-medium">
        {isHigh ? "Precio por encima de la media" : "Precio por debajo de la media"}
      </p>
      <p className="mt-1 opacity-90">{data.recommendation}</p>
      <p className="mt-1 text-xs opacity-75">
        Desviación: {data.deviation_percentage > 0 ? "+" : ""}
        {data.deviation_percentage.toFixed(1)}% — Media último año: €{data.historical_avg.toFixed(2)}
      </p>
    </div>
  );
}
