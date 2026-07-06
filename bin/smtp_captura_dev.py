"""Servidor SMTP minimo de captura: guarda cada mensaje en un fichero .eml."""
import socket
import sys
import os

DESTINO = sys.argv[1] if len(sys.argv) > 1 else "."
os.makedirs(DESTINO, exist_ok=True)

srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
srv.bind(("127.0.0.1", 1025))
srv.listen(5)
print("SMTP de captura en 127.0.0.1:1025", flush=True)

n = 0
while True:
    conn, _ = srv.accept()
    f = conn.makefile("rb")
    conn.sendall(b"220 captura ESMTP\r\n")
    datos = None
    while True:
        linea = f.readline()
        if not linea:
            break
        cmd = linea.decode("latin1").strip()
        mayus = cmd.upper()
        if mayus.startswith(("EHLO", "HELO")):
            conn.sendall(b"250-captura\r\n250 OK\r\n")
        elif mayus.startswith(("MAIL", "RCPT", "NOOP", "RSET")):
            conn.sendall(b"250 OK\r\n")
        elif mayus.startswith("DATA"):
            conn.sendall(b"354 adelante\r\n")
            cuerpo = []
            while True:
                l = f.readline()
                if not l or l.rstrip(b"\r\n") == b".":
                    break
                cuerpo.append(l)
            n += 1
            ruta = os.path.join(DESTINO, f"mail_{n:02d}.eml")
            with open(ruta, "wb") as out:
                out.writelines(cuerpo)
            print(f"capturado {ruta}", flush=True)
            conn.sendall(b"250 OK\r\n")
        elif mayus.startswith("QUIT"):
            conn.sendall(b"221 adios\r\n")
            break
        else:
            conn.sendall(b"250 OK\r\n")
    conn.close()
