---
name: verify
description: Receta de verificación end-to-end de TicketIA en este entorno (XAMPP Windows) — cómo autenticarse con curl, probar flujos por rol, capturar correos SMTP y hacer capturas de pantalla.
---

# Verificar TicketIA (entorno de desarrollo local)

Superficie: HTTP en `http://localhost/ticketia/public` (Apache de XAMPP ya en marcha).

## Binarios

- PHP: `C:/xampp/php/php.exe` (en Git Bash: `/c/xampp/php/php.exe`)
- MySQL: `/c/xampp/mysql/bin/mysql.exe -u <usuario> -p <base_datos>`
- Lint: `php -l fichero.php` sobre todo lo tocado.

## Cuentas de desarrollo

Las credenciales locales no se documentan en git. Consultar `AGENTS.local.md` o
el gestor de secretos del entorno.

## Login con curl (patrón)

El campo de contraseña se llama `password` (no `pass`). CSRF va en el campo `csrf`.

```bash
BASE=http://localhost/ticketia/public; JAR=/tmp/cj.txt; rm -f $JAR
T=$(curl -s -c $JAR $BASE/login.php | sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' | head -1)
curl -s -b $JAR -c $JAR -d "email=...&password=...&csrf=$T" $BASE/login.php
# login correcto = 302; para POST posteriores, re-extraer csrf de cualquier pagina autenticada
```

Ojo con `grep -P` en Git Bash (falla por locale); usar `sed -n 's/.../p'`.

## Flujos que conviene batir

- Guard por rol: cliente → `index.php` redirige a `portal.php`; admin → `portal.php` redirige a `index.php`; cliente en página admin → 403.
- Portal: `portal_ver.php?id=X` de otro cliente redirige a `portal.php`; las notas internas (`mensajes.interno=1`) no deben aparecer nunca en el HTML del portal.
- Adjuntos: subir con `-F adjunto=@fichero`, descargar y comparar con `cmp` (byte a byte); descarga de adjunto ajeno como cliente = 403.
- Tickets cerrados: el cliente no puede responder (redirige sin insertar).

## Correos (SMTP de captura)

No hay servidor SMTP real. Usar el script `smtp_captura.py` (servidor socket mínimo en
127.0.0.1:1025 que guarda cada DATA en `mail_NN.eml`); añadir temporalmente al `.env`:

```
SMTP_HOST=127.0.0.1
SMTP_PORT=1025
SMTP_SECURE=none
SMTP_FROM=soporte@ticketia.local
```

**Revertir el `.env` al terminar** (con SMTP_HOST apuntando a un servidor caído cada
envío espera el timeout de 10 s). Comprobar destinatarios y `Subject` en los .eml.

## Capturas de pantalla autenticadas

Edge headless no comparte cookies: bajar el HTML con curl autenticado, inyectar
`<base href="$BASE/">` tras `<head>` y renderizar el fichero local:

```bash
curl -s -b $JAR "$BASE/pagina.php" | sed "s|<head>|<head><base href=\"$BASE/\">|" > /tmp/cap.html
"/c/Program Files (x86)/Microsoft/Edge/Application/msedge.exe" --headless=new --disable-gpu \
  --window-size=1280,1400 --screenshot=/tmp/cap.png "file:///tmp/cap.html"
```

## Cola de trabajos IA

Para probar el ciclo completo: apuntar `LLM_LOCAL_ENDPOINT` a un puerto muerto
(`http://127.0.0.1:9/...`) en `.env`, crear un ticket (el fallo encola en
`trabajos_ia`), correr `php bin/worker.php` (reintento + backoff), restaurar el
endpoint, `UPDATE trabajos_ia SET programado_para = NOW()` y correr el worker
otra vez (completa y clasifica). **Restaurar `.env` siempre al terminar.**

## Gotchas

- No re-sembrar la BD de desarrollo (borra datos del usuario); para probar seeds usar una BD temporal.
- La IA local (LM Studio del usuario) devuelve formato harmony: cualquier texto generado se limpia con `llm_strip_razonamiento()`.
- La clasificación IA corre en segundo plano tras crear ticket; esperar 1-2 s antes de comprobar la BD.
