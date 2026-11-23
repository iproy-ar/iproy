#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
"""Pronóstico de órdenes con Google TimesFM para «Tendencias y Pronóstico» de iProy (módulo reportes 1.3.0).

Qué hace
  1. Lee del nodo las series de órdenes ingresadas (GET /api/m/reportes/series), hasta el último período COMPLETO:
     mensuales (total, servicios, empresas, tipos de tarea PIM y servicio × tarea) y semanales (total, servicios, tareas).
  2. Pronostica cada serie con TimesFM 2.5 (google/timesfm-2.5-200m-pytorch, Google Research): p10, p50 y p90
     (12 meses y 26 semanas por defecto).
  3. Guarda la corrida en el nodo (POST /api/m/reportes/pronosticos). La pantalla la muestra junto a Holt-Winters.

Por qué corre aparte: TimesFM es un modelo de 200 M de parámetros (~1 GB con PyTorch). Se corre en la PC de
mantenimiento, en el mismo servidor del nodo o en Colab; una vez por semana alcanza. Sin Internet, solo hace falta
haber bajado el modelo una vez (queda en la caché de Hugging Face).

Instalación (una vez):
    python3 -m venv ~/iproy-timesfm && . ~/iproy-timesfm/bin/activate
    pip install --index-url https://download.pytorch.org/whl/cpu torch && pip install "timesfm[torch]" numpy
Uso:
    export IPROY_URL=http://servidor:8080            # dirección del nodo
    export IPROY_TOKEN=...                            # token de un usuario Gestor (Usuarios › Token); lo escribe usted
    python3 timesfm_iproy.py                          # calcula y guarda
    python3 timesfm_iproy.py --solo-calcular --csv pronosticos.csv   # prueba sin guardar
    python3 timesfm_iproy.py --series series.json --solo-calcular    # sin nodo: series de un archivo
    python3 timesfm_iproy.py --nodo /opt/iproy/nodo                  # en el mismo equipo del nodo: usa su lanzador local
                                                                     # (lo que programa el instalador, sin token)
El token nunca se guarda en disco ni se imprime.
"""
import argparse, csv, datetime as dt, glob, json, os, sys, time, urllib.request

# Instalado por el instalador de iProy: el modelo queda en la carpeta «hf» al lado de este archivo (sin tocar la caché del usuario)
_HF = os.path.join(os.path.dirname(os.path.abspath(__file__)), "hf")
if os.path.isdir(_HF) and "HF_HOME" not in os.environ: os.environ["HF_HOME"] = _HF
MODELO_ID = "google/timesfm-2.5-200m-pytorch"
# El instalador baja el modelo por partes a la carpeta «modelo» (config.json + model.safetensors): si está, se usa sin red
_MODELO_LOCAL = os.path.join(os.path.dirname(os.path.abspath(__file__)), "modelo")
NOMBRE = "timesfm-2.5-200m-pytorch"


def lanzador_local(carpeta):
    """Dirección y cabecera del lanzador del nodo instalado en este equipo (solo responde en 127.0.0.1). No imprime ni guarda nada."""
    env = [f for f in glob.glob(os.path.join(carpeta, "data", "*.env")) if not f.endswith("secrets.env")]
    if not env: raise SystemExit(f"No encuentro el nodo en {carpeta} (falta data/*.env)")
    pref = os.path.splitext(os.path.basename(env[0]))[0].upper(); v = {}
    for f in (env[0], os.path.join(carpeta, "data", "secrets.env")):
        try:
            for l in open(f, encoding="utf-8"):
                if "=" in l and not l.lstrip().startswith("#"): k, x = l.strip().split("=", 1); v[k] = x.strip().strip('"').strip("'")
        except FileNotFoundError: pass
    if not v.get(pref + "_TICK_TOKEN"): raise SystemExit("El nodo no tiene lanzador local configurado")
    return f"http://127.0.0.1:{v.get(pref + '_PUERTO', '8080')}", {f"X-{pref}-Tick": v[pref + "_TICK_TOKEN"]}


CAB = {}


def pedir(metodo, url, token, cuerpo=None):
    datos = json.dumps(cuerpo).encode() if cuerpo is not None else None
    cab = {"Accept": "application/json", "Content-Type": "application/json", "X-Nucleo": "1", **CAB}
    if token and not CAB: cab["Authorization"] = "Bearer " + token
    r = urllib.request.Request(url, data=datos, method=metodo, headers=cab)
    try:
        with urllib.request.urlopen(r, timeout=180) as h:
            return json.loads(h.read().decode())
    except urllib.error.HTTPError as e:
        try: j = json.loads(e.read().decode())
        except Exception: j = {}
        raise SystemExit(f"El nodo respondió {e.code}: {j.get('error') or j.get('mensaje') or e.reason}")


def mes_mas(m, k):
    a, b = map(int, m.split("-")[:2]); n = a * 12 + b - 1 + k
    return f"{n // 12:04d}-{n % 12 + 1:02d}"


def periodo(s, k):
    """Período k (1, 2, …) después del último dato: el mes (AAAA-MM-01) o la semana (lunes AAAA-MM-DD)."""
    if s["freq"] == "W": return (dt.date.fromisoformat(s["ultimo"]) + dt.timedelta(days=7 * k)).isoformat()
    return mes_mas(s["ultimo"], k) + "-01"


def ultimo_dato(s):
    return s["ultimo"] if s["freq"] == "W" else s["ultimo"] + "-01"


def cargar_modelo(h):
    import numpy as np, timesfm, torch
    torch.set_float32_matmul_precision("high")
    local = os.path.isfile(os.path.join(_MODELO_LOCAL, "model.safetensors")) and os.path.isfile(os.path.join(_MODELO_LOCAL, "config.json"))
    m = timesfm.TimesFM_2p5_200M_torch.from_pretrained(_MODELO_LOCAL if local else MODELO_ID, torch_compile=False)
    m.compile(timesfm.ForecastConfig(max_context=1024, max_horizon=int(np.ceil(h / 128) * 128), normalize_inputs=True,
                                     use_continuous_quantile_head=True, force_flip_invariance=True, infer_is_positive=True, fix_quantile_crossing=True))
    return m


def pronosticar(modelo, series, h_m, h_w, lote=32):
    import numpy as np
    filas, n = [], 0
    for freq, h, minimo in (("M", h_m, 6), ("W", h_w, 16)):
        usadas = [s for s in series if s.get("freq", "M") == freq and len(s["y"]) >= minimo]; n += len(usadas)
        for i in range(0, len(usadas), lote):
            parte = usadas[i:i + lote]
            punto, cuant = modelo.forecast(horizon=h, inputs=[np.array(s["y"], dtype=np.float32) for s in parte])
            for s, p50, q in zip(parte, punto, cuant):      # q[..., 1] = cuantil 10 %, q[..., 9] = cuantil 90 %
                for k in range(h):
                    filas.append({"serie": s["serie"], "freq": freq, "periodo": periodo(s, k + 1),
                                  "p10": round(max(0.0, float(q[k, 1])), 2), "p50": round(max(0.0, float(p50[k])), 2),
                                  "p90": round(max(0.0, float(q[k, 9])), 2), "ultimo_dato": ultimo_dato(s)})
    return filas, n


def main():
    ap = argparse.ArgumentParser(description="Pronóstico TimesFM de órdenes para iProy")
    ap.add_argument("--url", default=os.environ.get("IPROY_URL", "http://127.0.0.1:8080"))
    ap.add_argument("--nodo", help="carpeta del nodo instalado en este equipo (usa su lanzador local en vez de un token)")
    ap.add_argument("--horizonte", type=int, default=12, help="meses a pronosticar (12)")
    ap.add_argument("--horizonte-semanas", type=int, default=26, help="semanas a pronosticar (26)")
    ap.add_argument("--top-servicios", type=int, default=20)
    ap.add_argument("--top-empresas", type=int, default=15)
    ap.add_argument("--top-tareas", type=int, default=20)
    ap.add_argument("--top-combos", type=int, default=40, help="servicio × tarea (solo mensual)")
    ap.add_argument("--sin-semanal", action="store_true", help="solo series mensuales")
    ap.add_argument("--series", help="leer las series de este JSON (salida de /api/m/reportes/series) en vez del nodo")
    ap.add_argument("--solo-calcular", action="store_true", help="no guarda en el nodo")
    ap.add_argument("--csv", help="guardar también los pronósticos en este CSV")
    a = ap.parse_args()
    t0 = time.time(); token = os.environ.get("IPROY_TOKEN", "")
    if a.nodo:
        url, cab = lanzador_local(a.nodo); CAB.update(cab); a.url = url; token = "local"
    base = a.url.rstrip("/") + "/api/m/reportes"
    if a.series:
        j = json.load(open(a.series, encoding="utf-8"))
    else:
        if not token: raise SystemExit("Falta la variable de entorno IPROY_TOKEN (token de un usuario Gestor).")
        j = pedir("GET", f"{base}/series?top_servicios={a.top_servicios}&top_empresas={a.top_empresas}&top_tareas={a.top_tareas}"
                         f"&top_combos={a.top_combos}&semanal={0 if a.sin_semanal else 1}", token)
    series = j["series"]
    print(f"{len(series)} series ({sum(s.get('freq', 'M') == 'M' for s in series)} mensuales hasta {j.get('ultimo_completo')}, "
          f"{sum(s.get('freq') == 'W' for s in series)} semanales hasta {j.get('ultima_semana')})")
    modelo = cargar_modelo(max(a.horizonte, a.horizonte_semanas))
    filas, n = pronosticar(modelo, series, a.horizonte, a.horizonte_semanas)
    tot = [f for f in filas if f["serie"] == "total" and f["freq"] == "M"]
    print(f"{n} series pronosticadas; total: " + ", ".join(f"{f['periodo'][:7]} {f['p50']:.0f} [{f['p10']:.0f} a {f['p90']:.0f}]" for f in tot[:6]))
    if a.csv:
        with open(a.csv, "w", newline="", encoding="utf-8") as fh:
            w = csv.DictWriter(fh, fieldnames=list(filas[0].keys())); w.writeheader(); w.writerows(filas)
        print("CSV:", a.csv)
    if not a.solo_calcular:
        if not token: raise SystemExit("Falta IPROY_TOKEN para guardar la corrida.")
        tot_g = 0; corrida = None
        for i in range(0, len(filas), 19000):   # el nodo acepta hasta 20.000 filas por corrida: si son más, van en varias
            r = pedir("POST", f"{base}/pronosticos", token, {"modelo": NOMBRE, "nota": f"{n} series, {a.horizonte} meses y {a.horizonte_semanas} semanas", "filas": filas[i:i + 19000]})
            tot_g += r["guardadas"]; corrida = r["corrida"]
        print(f"Corrida {corrida} guardada en el nodo: {tot_g} filas")
    print(f"Listo en {time.time() - t0:.0f} s")


if __name__ == "__main__":
    main()
