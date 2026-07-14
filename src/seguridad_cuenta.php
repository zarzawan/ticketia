<?php
// Primitivas de seguridad de cuenta: cifrado de secretos, TOTP y codigos
// de recuperacion. No depende de la base de datos para facilitar sus pruebas.

const CUENTA_TOTP_PERIODO = 30;
const CUENTA_TOTP_DIGITOS = 6;
const CUENTA_PASSWORD_MIN = 10;

function cuenta_app_key_disponible(): bool {
    $clave = trim((string)entorno_valor('APP_KEY', ''));
    return strlen($clave) >= 32 && !str_starts_with(strtolower($clave), 'cambia');
}

function cuenta_clave_cifrado(): ?string {
    if (!cuenta_app_key_disponible()) {
        return null;
    }
    return hash('sha256', (string)entorno_valor('APP_KEY'), true);
}

/** Cifra un secreto con AES-256-GCM. Devuelve null si falta APP_KEY. */
function cuenta_secreto_cifrar(string $secreto): ?string {
    $clave = cuenta_clave_cifrado();
    if ($clave === null || !function_exists('openssl_encrypt')) {
        return null;
    }
    $iv = random_bytes(12);
    $tag = '';
    $cifrado = openssl_encrypt($secreto, 'aes-256-gcm', $clave, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cifrado === false) {
        return null;
    }
    return base64_encode($iv . $tag . $cifrado);
}

/** Descifra un secreto; nunca propaga errores criptograficos. */
function cuenta_secreto_descifrar(string $contenido): ?string {
    $clave = cuenta_clave_cifrado();
    $binario = base64_decode($contenido, true);
    if ($clave === null || $binario === false || strlen($binario) < 29 || !function_exists('openssl_decrypt')) {
        return null;
    }
    $iv = substr($binario, 0, 12);
    $tag = substr($binario, 12, 16);
    $cifrado = substr($binario, 28);
    $secreto = openssl_decrypt($cifrado, 'aes-256-gcm', $clave, OPENSSL_RAW_DATA, $iv, $tag);
    return $secreto === false ? null : $secreto;
}

function cuenta_base32_codificar(string $binario): string {
    $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($binario) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }
    $salida = '';
    foreach (str_split($bits, 5) as $grupo) {
        $salida .= $alfabeto[bindec(str_pad($grupo, 5, '0'))];
    }
    return $salida;
}

function cuenta_base32_decodificar(string $texto): ?string {
    $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $limpio = strtoupper(preg_replace('/[\s\-=]+/', '', $texto) ?? '');
    if ($limpio === '' || preg_match('/[^A-Z2-7]/', $limpio)) {
        return null;
    }
    $bits = '';
    foreach (str_split($limpio) as $caracter) {
        $posicion = strpos($alfabeto, $caracter);
        if ($posicion === false) {
            return null;
        }
        $bits .= str_pad(decbin($posicion), 5, '0', STR_PAD_LEFT);
    }
    $salida = '';
    foreach (str_split($bits, 8) as $grupo) {
        if (strlen($grupo) === 8) {
            $salida .= chr(bindec($grupo));
        }
    }
    return $salida;
}

function cuenta_totp_nuevo_secreto(): string {
    return cuenta_base32_codificar(random_bytes(20));
}

function cuenta_totp_codigo(string $secreto, int $periodo): ?string {
    $clave = cuenta_base32_decodificar($secreto);
    if ($clave === null || $periodo < 0) {
        return null;
    }
    $contador = pack('N2', ($periodo >> 32) & 0xffffffff, $periodo & 0xffffffff);
    $hash = hash_hmac('sha1', $contador, $clave, true);
    $desplazamiento = ord($hash[19]) & 0x0f;
    $numero = ((ord($hash[$desplazamiento]) & 0x7f) << 24)
        | ((ord($hash[$desplazamiento + 1]) & 0xff) << 16)
        | ((ord($hash[$desplazamiento + 2]) & 0xff) << 8)
        | (ord($hash[$desplazamiento + 3]) & 0xff);
    return str_pad((string)($numero % (10 ** CUENTA_TOTP_DIGITOS)), CUENTA_TOTP_DIGITOS, '0', STR_PAD_LEFT);
}

/** Devuelve el periodo coincidente o null. Acepta un desfase de un periodo. */
function cuenta_totp_verificar(string $secreto, string $codigo, ?int $instante = null, int $ventana = 1): ?int {
    $codigo = preg_replace('/\D/', '', $codigo) ?? '';
    if (strlen($codigo) !== CUENTA_TOTP_DIGITOS) {
        return null;
    }
    $periodoActual = intdiv($instante ?? time(), CUENTA_TOTP_PERIODO);
    for ($desfase = -$ventana; $desfase <= $ventana; $desfase++) {
        $periodo = $periodoActual + $desfase;
        $esperado = cuenta_totp_codigo($secreto, $periodo);
        if ($esperado !== null && hash_equals($esperado, $codigo)) {
            return $periodo;
        }
    }
    return null;
}

function cuenta_codigo_recuperacion_generar(): string {
    $alfabeto = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $codigo = '';
    for ($i = 0; $i < 10; $i++) {
        $codigo .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
    }
    return substr($codigo, 0, 5) . '-' . substr($codigo, 5);
}

function cuenta_codigo_recuperacion_normalizar(string $codigo): string {
    return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $codigo) ?? '');
}

function cuenta_codigo_recuperacion_hash(string $codigo): string {
    return hash('sha256', cuenta_codigo_recuperacion_normalizar($codigo));
}

function cuenta_password_error(string $password): ?string {
    if (strlen($password) < CUENTA_PASSWORD_MIN) {
        return 'La contrasena debe tener al menos ' . CUENTA_PASSWORD_MIN . ' caracteres.';
    }
    if (strlen($password) > 4096) {
        return 'La contrasena es demasiado larga.';
    }
    return null;
}
