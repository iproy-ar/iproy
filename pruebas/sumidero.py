#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
"""Sumidero de pruebas para el módulo notificaciones: recibe lo que el nodo manda y lo deja en una carpeta.
     python3 pruebas/sumidero.py <carpeta> <puerto-http> <puerto-smtp>
   HTTP: cualquier POST se guarda en http.jsonl ({ruta, cabeceras, cuerpo}); una ruta que contiene «falla» responde 500
         y «/bot…/sendMessage» responde como la API de Telegram.
   SMTP: sin cifrado ni autenticación; cada mensaje se guarda en smtp.jsonl ({de, para, datos}).
   Solo escucha en 127.0.0.1 y no reenvía nada: sirve para comprobar el contenido sin mandar correos de verdad."""
import json, os, socketserver, sys, threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

D, PH, PS = sys.argv[1], int(sys.argv[2]), int(sys.argv[3])
candado = threading.Lock()
def anotar(archivo, obj):
    with candado, open(os.path.join(D, archivo), 'a', encoding='utf-8') as f: f.write(json.dumps(obj, ensure_ascii=False) + '\n')

class Web(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def do_POST(self):
        cuerpo = self.rfile.read(int(self.headers.get('Content-Length') or 0)).decode('utf-8', 'replace')
        anotar('http.jsonl', {'ruta': self.path, 'cabeceras': {k.lower(): v for k, v in self.headers.items()}, 'cuerpo': cuerpo})
        mal = 'falla' in self.path
        r = json.dumps({'ok': not mal, 'description': 'Bad Request: chat not found'} if '/bot' in self.path else {'recibido': not mal}).encode()
        self.send_response((400 if '/bot' in self.path else 500) if mal else 200); self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(r))); self.end_headers(); self.wfile.write(r)

class Smtp(socketserver.StreamRequestHandler):
    def handle(self):
        w = lambda t: self.wfile.write((t + '\r\n').encode())
        w('220 sumidero ESMTP'); de, para = '', []
        while True:
            l = self.rfile.readline()
            if not l: return
            c = l.decode('utf-8', 'replace').strip(); u = c.upper()
            if u.startswith('EHLO') or u.startswith('HELO'): w('250 sumidero')
            elif u.startswith('MAIL FROM'): de = c[10:].strip('<> '); w('250 ok')
            elif u.startswith('RCPT TO'):
                p = c[8:].strip('<> ')
                if 'rechazado' in p: w('550 buzon inexistente')
                else: para.append(p); w('250 ok')
            elif u == 'DATA':
                w('354 fin con punto'); datos = []
                while True:
                    x = self.rfile.readline()
                    if not x or x in (b'.\r\n', b'.\n'): break
                    datos.append(x.decode('utf-8', 'replace'))
                anotar('smtp.jsonl', {'de': de, 'para': para, 'datos': ''.join(datos)}); para = []; w('250 guardado')
            elif u == 'QUIT': w('221 chau'); return
            else: w('250 ok')

class TS(socketserver.ThreadingTCPServer): allow_reuse_address = True; daemon_threads = True
threading.Thread(target=TS(('127.0.0.1', PS), Smtp).serve_forever, daemon=True).start()
ThreadingHTTPServer(('127.0.0.1', PH), Web).serve_forever()
