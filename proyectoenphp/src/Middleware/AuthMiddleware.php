<?php

declare(strict_types=1);

final class AuthMiddleware
{
    /**
     * Autentica la petición usando el header Authorization: Bearer <jwt>.
     *
     * Este método:
     *  - Lee la cabecera HTTP_AUTHORIZATION.
     *  - Extrae el token Bearer.
     *  - Decodifica el payload del JWT (segundo segmento, base64url → JSON).
     *  - Devuelve el array de payload (debe contener al menos organization_id y user_id).
     *
     * NOTA: Aquí no se verifica la firma ni la expiración; en producción,
     *       integrar una librería como firebase/php-jwt y validar correctamente.
     *
     * @return array<string, mixed>
     */
    public static function authenticate(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($header === '') {
            self::unauthorized('Missing Authorization header.');
        }

        if (stripos($header, 'Bearer ') !== 0) {
            self::unauthorized('Authorization header must use Bearer scheme.');
        }

        $token = trim(substr($header, 7));
        if ($token === '') {
            self::unauthorized('Empty Bearer token.');
        }

        $parts = explode('.', $token);
        if (count($parts) < 2) {
            self::unauthorized('Malformed JWT token.');
        }

        $payloadPart = $parts[1];
        // Convertir base64url a base64
        $payloadPart = strtr($payloadPart, '-_', '+/');
        $padding = strlen($payloadPart) % 4;
        if ($padding > 0) {
            $payloadPart .= str_repeat('=', 4 - $padding);
        }

        $json = base64_decode($payloadPart, true);
        if ($json === false) {
            self::unauthorized('Unable to decode JWT payload.');
        }

        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            self::unauthorized('Invalid JWT payload JSON.');
        }

        // En Supabase, los claims pueden incluir sub, role y custom claims.
        // Aquí asumimos que el payload contiene organization_id y user_id (o sub).
        if (!isset($payload['organization_id']) && !isset($payload['org_id'])) {
            self::unauthorized('Missing organization_id in token.');
        }

        if (!isset($payload['user_id']) && !isset($payload['sub'])) {
            self::unauthorized('Missing user_id in token.');
        }

        return $payload;
    }

    /**
     * Envía 401 Unauthorized en JSON y termina la ejecución.
     */
    private static function unauthorized(string $message): void
    {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode(
            [
                'error' => 'Unauthorized',
                'message' => $message,
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }
}

