<?php

declare(strict_types=1);

final class RoleMiddleware
{
    /**
     * Comprueba si el rol del usuario cumple el rol requerido.
     *
     * Jerarquía (de menor a mayor):
     *  - member_planta, member_licitaciones
     *  - admin_planta, admin_licitaciones
     *  - admin
     */
    public static function checkPermission(string $requiredRole, string $userRole): bool
    {
        $requiredRole = strtolower(trim($requiredRole));
        $userRole = strtolower(trim($userRole));

        $weights = [
            'member_planta' => 1,
            'member_licitaciones' => 1,
            'admin_planta' => 2,
            'admin_licitaciones' => 2,
            'admin' => 3,
        ];

        if (!isset($weights[$requiredRole]) || !isset($weights[$userRole])) {
            // Rol desconocido: denegar acceso por defecto.
            return false;
        }

        return $weights[$userRole] >= $weights[$requiredRole];
    }
}

