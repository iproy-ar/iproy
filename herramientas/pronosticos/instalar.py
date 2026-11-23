#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
"""Pronósticos de iProy con Google TimesFM: instalación en Linux (la usa el instalador de iProy; también se corre a mano).

Deja todo dentro de una sola carpeta (por defecto <nodo>/python), sin tocar el Python del sistema ni otros entornos:
  <carpeta>/timesfm/          entorno virtual con PyTorch (CPU) y TimesFM fijados a una versión
  <carpeta>/timesfm_iproy.py  el programa que calcula y guarda los pronósticos en el nodo
  <carpeta>/modelo/           el modelo google/timesfm-2.5-200m-pytorch (925 MB, se baja una vez en partes de 300 MB verificadas)
y programa la corrida semanal (lunes 03:17) con cron, o con un temporizador de systemd del usuario si no hay cron.

Uso:  python3 instalar.py --nodo /ruta/al/nodo [--carpeta DIR] [--sin-modelo] [--sin-programar] [--sin-correr]
La última línea es «RESULTADO {json}» (la lee el instalador gráfico). No escribe contraseñas ni tokens.
"""
import argparse, hashlib, json, os, shutil, subprocess, sys, time, urllib.request
from pathlib import Path

TORCH = "torch==2.15.0"
PAQUETES = ["timesfm==3.0.2", "numpy>=1.26.4"]
INDICE_TORCH = "https://download.pytorch.org/whl/cpu"
MODELO = "google/timesfm-2.5-200m-pytorch"
MARCA = "# iproy-timesfm"
# El modelo (925 MB) se baja en partes de 300 MB fijado a una revisión: cada parte se verifica con SHA-256 y, si la red se corta,
# sigue desde donde quedó; al final se unen y el archivo entero se verifica contra el SHA-256 que publica Hugging Face.
MOD = {"base": "https://huggingface.co/google/timesfm-2.5-200m-pytorch/resolve/1d952420fba87f3c6dee4f240de0f1a0fbc790e3",
       "tam": 925181104, "sha": "2f776efe6245e42b24bc4153ffdf61810140210e4bd3b01fb21f7aa779ab6ce8",
       "config": "cd3315b760d5cc7e278d7afdf41b897031ced888fc6c115bd9b3ac0ea2c47408",
       "partes": ["e173a5f70481ab6bc4be2f3a3166e58746b4ac9de7db46b5c91ae44b8fd06a85", "3146999acf38e32dee5f894c9cd4411cce077a496b421d54cbd0574a19995bd6",
                  "0b5959788ca82e0d9217c120ab315336f7feab33f44f451861c29e0c6031ab01"]}
PARTE = 300 * 1024 * 1024


def sha(f):
    h = hashlib.sha256()
    with open(f, "rb") as x:
        for b in iter(lambda: x.read(1 << 20), b""): h.update(b)
    return h.hexdigest()


def bajar_parte(url, archivo, ini, largo):
    ya = os.path.getsize(archivo) if os.path.exists(archivo) else 0
    if ya > largo: os.remove(archivo); ya = 0
    if ya == largo: return
    if ya: print(f"   sigue desde {ya // 2**20} MB", flush=True)
    req = urllib.request.Request(url, headers={"Range": f"bytes={ini + ya}-{ini + largo - 1}", "User-Agent": "iProy-instalador"})
    with urllib.request.urlopen(req, timeout=120) as r, open(archivo, "ab") as out:
        if r.status != 206: raise RuntimeError(f"el servidor no aceptó la descarga por partes (HTTP {r.status})")
        marca = ya
        for b in iter(lambda: r.read(1 << 20), b""):
            out.write(b); ya += len(b)
            if ya - marca >= 50 * 2**20: print(f"   {ya // 2**20} de {largo // 2**20} MB", flush=True); marca = ya
    if ya != largo: raise RuntimeError(f"la parte quedó incompleta ({ya} de {largo} bytes)")


def bajar_modelo(dir_modelo, dir_partes, espera=60):
    dir_modelo.mkdir(parents=True, exist_ok=True); final = dir_modelo / "model.safetensors"; cfg = dir_modelo / "config.json"
    if not (cfg.exists() and sha(cfg) == MOD["config"]):
        urllib.request.urlretrieve(MOD["base"] + "/config.json", cfg)
        if sha(cfg) != MOD["config"]: cfg.unlink(); raise SystemExit("SHA-256 distinto en config.json del modelo")
    if final.exists() and final.stat().st_size == MOD["tam"] and sha(final) == MOD["sha"]: print("Modelo ya descargado y verificado"); return
    dir_partes.mkdir(parents=True, exist_ok=True); n = len(MOD["partes"]); arch = []
    for i, esperado in enumerate(MOD["partes"]):
        ini = i * PARTE; largo = min(PARTE, MOD["tam"] - ini); f = dir_partes / f"model.safetensors.parte{i + 1}de{n}"; arch.append(f)
        for k in range(1, 6):
            try:
                if not (f.exists() and f.stat().st_size == largo and sha(f) == esperado):
                    print(f"Parte {i + 1} de {n} ({largo // 2**20} MB)", flush=True)
                    bajar_parte(MOD["base"] + "/model.safetensors", f, ini, largo)
                    if sha(f) != esperado: f.unlink(); raise RuntimeError(f"SHA-256 distinto en la parte {i + 1}: se baja de nuevo")
                print(f"Parte {i + 1} de {n} verificada", flush=True); break
            except Exception as e:
                if k == 5: raise SystemExit(f"No se pudo bajar la parte {i + 1}: {e}")
                print(f"Parte {i + 1}: {e}. Reintento {k} de 4 en {espera} s (sigue desde donde quedó)", flush=True); time.sleep(espera)
    print("Uniendo las partes", flush=True); tmp = final.with_name(final.name + ".uniendo")
    with open(tmp, "wb") as out:
        for f in arch:
            with open(f, "rb") as x: shutil.copyfileobj(x, out, 1 << 20)
    if sha(tmp) != MOD["sha"]: tmp.unlink(); raise SystemExit("El modelo unido no coincide con el SHA-256 publicado por Hugging Face")
    tmp.replace(final); shutil.rmtree(dir_partes)   # las partes ya no hacen falta (925 MB menos)
    print(f"Modelo listo y verificado: {final}", flush=True)


def correr(cmd, env=None, intentos=3):
    """Corre un paso; si falla (por ejemplo, la red se cortó un rato) lo reintenta a los 60 s."""
    for i in range(1, intentos + 1):
        print("$", " ".join(str(c) for c in cmd), f"(intento {i} de {intentos})" if i > 1 else "", flush=True)
        r = subprocess.run([str(c) for c in cmd], env=env, text=True, capture_output=True)
        sys.stdout.write(r.stdout[-3000:]); sys.stderr.write(r.stderr[-3000:])
        if r.returncode == 0: return r.stdout
        if i < intentos: print("Falló; reintento en 60 s (si es la red, suele volver sola)", flush=True); time.sleep(60)
    raise SystemExit(f"Falló: {' '.join(str(c) for c in cmd[:3])} … (código {r.returncode}, {intentos} intentos)")


def programar(py, script, nodo, log):
    linea = f'17 3 * * 1 "{py}" "{script}" --nodo "{nodo}" >> "{log}" 2>&1 {MARCA} {nodo}'
    if shutil.which("crontab"):
        actual = subprocess.run(["crontab", "-l"], text=True, capture_output=True).stdout if subprocess.run(["crontab", "-l"], capture_output=True).returncode == 0 else ""
        nuevas = [l for l in actual.splitlines() if not l.endswith(f"{MARCA} {nodo}")] + [linea]
        subprocess.run(["crontab", "-"], input="\n".join(nuevas) + "\n", text=True, check=True)
        return "cron: los lunes a las 03:17"
    if shutil.which("systemctl"):
        d = Path.home() / ".config/systemd/user"; d.mkdir(parents=True, exist_ok=True)
        slug = "iproy-timesfm-" + hashlib.sha256(str(nodo).encode()).hexdigest()[:8]
        (d / f"{slug}.service").write_text(f"[Unit]\nDescription=iProy: pronósticos TimesFM ({nodo})\n[Service]\nType=oneshot\n"
                                          f'ExecStart="{py}" "{script}" --nodo "{nodo}"\nStandardOutput=append:{log}\nStandardError=append:{log}\n')
        (d / f"{slug}.timer").write_text(f"[Unit]\nDescription=iProy: pronósticos TimesFM semanales\n[Timer]\nOnCalendar=Mon *-*-* 03:17:00\nPersistent=true\n[Install]\nWantedBy=timers.target\n")
        r = subprocess.run(["systemctl", "--user", "enable", "--now", f"{slug}.timer"], capture_output=True, text=True)
        if r.returncode == 0: return f"systemd del usuario ({slug}.timer): los lunes a las 03:17"
    return f"sin programar: no hay cron ni systemd. Corré a mano: {py} {script} --nodo {nodo}"


def main():
    ap = argparse.ArgumentParser(description="Instala los pronósticos con Google TimesFM para un nodo de iProy (Linux)")
    ap.add_argument("--nodo", help="carpeta del nodo (la que tiene data/ e iProy-Control.sh)")
    ap.add_argument("--carpeta", help="dónde instalar (por defecto <nodo>/python)")
    ap.add_argument("--sin-modelo", action="store_true", help="no bajar el modelo ahora (se baja en la primera corrida)")
    ap.add_argument("--sin-programar", action="store_true"); ap.add_argument("--sin-correr", action="store_true")
    a = ap.parse_args()
    if not a.nodo and not a.carpeta: raise SystemExit("Indicá --nodo o --carpeta")
    nodo = Path(a.nodo).resolve() if a.nodo else None
    c = Path(a.carpeta).resolve() if a.carpeta else nodo / "python"
    t0 = time.time(); c.mkdir(parents=True, exist_ok=True)
    venv = c / "timesfm"; py = venv / "bin" / "python"
    if not py.exists():
        r = subprocess.run([sys.executable, "-m", "venv", str(venv)], capture_output=True, text=True)
        if r.returncode != 0: raise SystemExit("No se pudo crear el entorno de Python. En Debian/Ubuntu instalá «python3-venv» y repetí. " + r.stderr[-300:])
    correr([py, "-m", "pip", "install", "--disable-pip-version-check", "--no-input", "--index-url", INDICE_TORCH, TORCH])
    correr([py, "-m", "pip", "install", "--disable-pip-version-check", "--no-input", *PAQUETES])
    shutil.copy2(Path(__file__).with_name("timesfm_iproy.py"), c / "timesfm_iproy.py")
    env = dict(os.environ, HF_HOME=str(c / "hf"), HF_HUB_DISABLE_PROGRESS_BARS="1")
    if not a.sin_modelo: bajar_modelo(c / "modelo", c / "descargas" / "modelo")
    ver = correr([py, "-c", "import timesfm, torch; print(torch.__version__)"]).strip().splitlines()[-1]
    res = {"carpeta": str(c), "python": str(py), "torch": ver, "timesfm": "3.0.2", "modelo": None if a.sin_modelo else f"{MODELO} (rev. 1d95242) en {c / 'modelo'}", "programado": None, "primera_corrida": None}
    if nodo and not a.sin_programar: res["programado"] = programar(py, c / "timesfm_iproy.py", nodo, c / "timesfm.log")
    if nodo and not a.sin_correr:
        r = subprocess.run([str(py), str(c / "timesfm_iproy.py"), "--nodo", str(nodo)], env=env, capture_output=True, text=True)
        res["primera_corrida"] = (r.stdout.strip().splitlines() or ["(sin salida)"])[-2 if len(r.stdout.strip().splitlines()) > 1 else -1] if r.returncode == 0 else "falló: " + (r.stderr.strip().splitlines() or [""])[-1][:200]
    res["segundos"] = round(time.time() - t0)
    print("RESULTADO " + json.dumps(res, ensure_ascii=False))


if __name__ == "__main__":
    main()
