"use client";

import * as React from "react";
import {
  FormProvider,
  useFormContext,
  Controller,
  type ControllerProps,
  type FieldPath,
  type FieldValues,
} from "react-hook-form";
import { cn } from "@/lib/utils";

export { useForm } from "react-hook-form";

export function Form({
  children,
  ...props
}: React.ComponentProps<typeof FormProvider>) {
  return <FormProvider {...props}>{children}</FormProvider>;
}

type FormFieldProps<TFieldValues extends FieldValues> = ControllerProps<TFieldValues> & {
  name: FieldPath<TFieldValues>;
};

export function FormField<TFieldValues extends FieldValues>({
  ...props
}: FormFieldProps<TFieldValues>) {
  return <Controller {...props} />;
}

export function FormItem({
  className,
  ...props
}: React.HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={cn("space-y-1", className)}
      {...props}
    />
  );
}

export function FormLabel({
  className,
  ...props
}: React.LabelHTMLAttributes<HTMLLabelElement>) {
  return (
    <label
      className={cn(
        "text-sm font-medium leading-none text-slate-800",
        className
      )}
      {...props}
    />
  );
}

export function FormControl({
  className,
  ...props
}: React.HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={cn("mt-1", className)}
      {...props}
    />
  );
}

export function FormMessage({
  className,
  ...props
}: React.HTMLAttributes<HTMLParagraphElement>) {
  const {
    formState: { errors },
  } = useFormContext();

  // No intentamos mapear un campo concreto aquí, simplemente mostramos children
  // o nada si no se provee mensaje desde arriba.

  if (!props.children) return null;

  return (
    <p
      className={cn("text-xs text-red-500", className)}
      {...props}
    />
  );
}

