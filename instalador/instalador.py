#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
"""
Instalador gráfico de iProy para Linux: un servidor web local (solo 127.0.0.1, con token) que muestra la interfaz
en el navegador y hace la instalación de fondo, con barra de avance, pedido de permisos de administrador solo cuando
hace falta (pkexec, o el comando para pegar en una terminal) e informe final.

Aprendido de las instalaciones reales (L-395 a L-397): no necesita Git ni PHP del sistema (baja el código como ZIP y usa
el PHP que trae FrankenPHP), verifica cada descarga con SHA-256, instala en la carpeta del usuario, nunca escribe la
contraseña en disco y deja un informe con lo que conviene instalar en esa PC.

Uso:  python3 instalador.py [--puerto N] [--no-abrir]
Variables para pruebas: IPROY_INST_CODIGO (ZIP o carpeta local del repositorio), IPROY_INST_CACHE (carpeta de descargas).
"""
import hashlib, http.server, json, os, platform, pwd, re, secrets, shutil, socket, socketserver, subprocess, sys, tarfile
import threading, time, urllib.request, webbrowser, zipfile
from email.utils import parsedate_to_datetime
from pathlib import Path

VERSION = '1.5.0'
TAG = 'v' + VERSION
URL_CODIGO = f'https://github.com/iproy-ar/iproy/archive/refs/tags/{TAG}.zip'
FRANKEN = {'url': 'https://github.com/php/frankenphp/releases/download/v1.13.3/frankenphp-linux-x86_64',
           'sha': 'f88db9972ddac5e4c097c8b703e585255e181411687e5a1f79ca4b6328526e0b', 'bytes': 175484880, 'version': '1.13.3'}
MARIADB = {'url': 'https://archive.mariadb.org/mariadb-11.4.13/bintar-linux-systemd-x86_64/mariadb-11.4.13-linux-systemd-x86_64.tar.gz',
           'archivo': 'mariadb-11.4.13-linux-systemd-x86_64.tar.gz',
           'sha': '3cebf63914df3154b7cb6d548337eb2be42c773aeb641110446b72e2746cfed7', 'bytes': 358652272, 'version': '11.4.13'}
REF_HORA = 'https://iproy.com.ar/'
CACHE = Path(os.environ.get('IPROY_INST_CACHE') or Path.home() / '.cache' / 'iproy-instalador')
AQUI = Path(__file__).resolve().parent
UI_EMBEBIDA = '__UI_HTML_B64__'   # armar.sh pone acá la interfaz (base64) para distribuir un solo archivo

# Librerías que MariaDB necesita y el paquete que las trae en cada familia de distribuciones (L-395)
LIBS = {'libcrypt.so.1': {'arch': 'libxcrypt-compat', 'fedora': 'libxcrypt-compat', 'suse': 'libcrypt1', 'debian': 'libcrypt1'},
        'libaio.so.1': {'arch': 'libaio', 'fedora': 'libaio', 'suse': 'libaio1', 'debian': 'libaio1t64'},
        'libncurses.so.6': {'arch': 'ncurses', 'fedora': 'ncurses-libs', 'suse': 'libncurses6', 'debian': 'libncurses6'},
        'libpmem.so.1': {'arch': 'pmdk', 'fedora': 'libpmem', 'suse': 'libpmem1', 'debian': 'libpmem1'}}
GESTOR = {'arch': 'pacman -S --needed --noconfirm', 'fedora': 'dnf install -y', 'suse': 'zypper install -y', 'debian': 'apt-get install -y'}

# ------------------------------------------------------------------ estado compartido con la interfaz
L = threading.Lock()
E = {'fase': 'espera', 'pasos': [], 'progreso': 0, 'sub': '', 'log': [], 'pregunta': None, 'respuesta': None,
     'sistema': None, 'instalacion': {}, 'version_instalador': VERSION}
PASOS = [('carpeta', 'Preparar la carpeta'), ('runtime', 'Bajar el servidor web (FrankenPHP)'), ('codigo', 'Bajar el código de iProy'),
         ('producto', 'Armar el producto'), ('mariadb', 'Bajar e instalar la base de datos (MariaDB)'), ('librerias', 'Revisar librerías del sistema'),
         ('init', 'Crear el nodo'), ('red', 'Abrir el acceso desde la red'), ('start', 'Arrancar el sistema'), ('usuario', 'Crear el usuario administrador'),
         ('hora', 'Revisar la hora de la PC'), ('verificar', 'Comprobar que responde')]
PESO = {'carpeta': 2, 'runtime': 22, 'codigo': 6, 'producto': 6, 'mariadb': 36, 'librerias': 2, 'init': 8, 'red': 2, 'start': 8, 'usuario': 3, 'hora': 2, 'verificar': 3}


def log(t):
    with L:
        for l in str(t).splitlines() or ['']:
            E['log'].append(time.strftime('%H:%M:%S ') + l)
        del E['log'][:-400]


def paso(pid, estado=None, detalle=None):
    with L:
        for p in E['pasos']:
            if p['id'] == pid:
                if estado == 'en_curso': p['_t0'] = time.time()
                if estado and estado != 'en_curso' and '_t0' in p: p['segundos'] = round(time.time() - p['_t0'])
                if estado: p['estado'] = estado
                if detalle is not None: p['detalle'] = detalle
        hecho = sum(PESO[p['id']] for p in E['pasos'] if p['estado'] in ('ok', 'aviso', 'saltado', 'error'))
        E['progreso'] = round(100 * hecho / sum(PESO.values()), 1)


def sub(texto, frac=None, pid=None):
    with L:
        E['sub'] = texto
        if frac is not None and pid:
            hecho = sum(PESO[p['id']] for p in E['pasos'] if p['estado'] in ('ok', 'aviso', 'saltado', 'error'))
            E['progreso'] = round(100 * (hecho + PESO[pid] * max(0, min(1, frac))) / sum(PESO.values()), 1)


def preguntar(titulo, texto, comando='', manual=False, puede_elevar=True):
    """Muestra el pedido de permiso en la interfaz y espera la respuesta: autorizar | saltar | hecho."""
    pid = secrets.token_hex(4)
    with L:
        E['pregunta'] = {'id': pid, 'titulo': titulo, 'texto': texto, 'comando': comando, 'manual': manual, 'puede_elevar': puede_elevar}
        E['respuesta'] = None
    while True:
        time.sleep(0.3)
        with L:
            r = E['respuesta']
            if r and r.get('id') == pid:
                E['pregunta'] = None
                return r.get('respuesta')


# ------------------------------------------------------------------ sistema
def os_release():
    d = {}
    try:
        for l in Path('/etc/os-release').read_text().splitlines():
            if '=' in l: k, v = l.split('=', 1); d[k] = v.strip('"')
    except OSError: pass
    return d


def familia(osr):
    ids = (osr.get('ID', '') + ' ' + osr.get('ID_LIKE', '')).lower()
    for f, claves in (('arch', ('arch', 'endeavouros', 'manjaro')), ('fedora', ('fedora', 'rhel', 'centos')), ('suse', ('suse',)), ('debian', ('debian', 'ubuntu', 'mint'))):
        if any(k in ids for k in claves): return f
    return ''


def ver_cmd(cmd):
    try: return subprocess.run(cmd, capture_output=True, text=True, timeout=10).stdout.strip().splitlines()[0]
    except Exception: return ''


def puerto_libre(n):
    for h in ('127.0.0.1', '0.0.0.0'):
        s = socket.socket()
        try:
            s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1); s.bind((h, n))
        except OSError: return False
        finally: s.close()
    return True


def desfase_hora():
    try:
        r = urllib.request.urlopen(urllib.request.Request(REF_HORA, method='HEAD', headers={'User-Agent': 'iproy-instalador'}), timeout=6)
        return round(time.time() - parsedate_to_datetime(r.headers['Date']).timestamp())
    except Exception: return None


def internet():
    for u in ('https://github.com', 'https://archive.mariadb.org', REF_HORA):
        try: urllib.request.urlopen(urllib.request.Request(u, method='HEAD', headers={'User-Agent': 'iproy-instalador'}), timeout=6); return True
        except Exception: continue
    return False


def sistema():
    osr = os_release(); fam = familia(osr)
    ram = 0
    try: ram = round(int(re.search(r'MemTotal:\s+(\d+)', Path('/proc/meminfo').read_text()).group(1)) / 1048576, 1)
    except Exception: pass
    libre = round(shutil.disk_usage(Path.home()).free / 2**30, 1)
    arq = platform.machine()
    s = {'so': 'linux', 'so_nombre': osr.get('PRETTY_NAME') or platform.platform(), 'familia': fam, 'arq': arq, 'arq_ok': arq in ('x86_64', 'AMD64'),
         'usuario': pwd.getpwuid(os.getuid()).pw_name, 'es_admin': os.geteuid() == 0, 'ram_gb': ram, 'disco_libre_gb': libre,
         'motor': 'Python ' + platform.python_version(), 'git': ver_cmd(['git', '--version']).replace('git version ', ''),
         'php': ver_cmd(['php', '-r', 'echo PHP_VERSION;']), 'pkexec': bool(shutil.which('pkexec')) and bool(os.environ.get('DISPLAY') or os.environ.get('WAYLAND_DISPLAY')),
         'internet': internet(), 'hora_desfase': desfase_hora(), 'glibc': platform.libc_ver()[1],
         'destino_sugerido': str(Path.home() / 'Proyectos' / 'pim'), 'puertos_ocupados': [p for p in (8095, 3407) if not puerto_libre(p)], 'extras': []}
    if not s['arq_ok']: s['bloqueante'] = f'El nodo necesita una PC de 64 bits x86_64; esta es {arq}.'
    elif s['es_admin'] and not os.environ.get('IPROY_INST_PERMITIR_ROOT'): s['bloqueante'] = 'No ejecutes el instalador como root: usá tu usuario normal. Cuando haga falta, el instalador pide permiso.'
    elif libre < 3: s['bloqueante'] = f'Quedan {libre} GB libres en tu carpeta personal; hacen falta al menos 3 GB.'
    if s['es_admin'] is False and not fam: s['extras'].append({'titulo': 'Distribución no reconocida', 'texto': 'Si falta alguna librería, el informe dice cuál; buscá el paquete con ese nombre en tu distribución.'})
    return s


# ------------------------------------------------------------------ ayudas
def sha256(p):
    h = hashlib.sha256()
    with open(p, 'rb') as f:
        for b in iter(lambda: f.read(1 << 20), b''): h.update(b)
    return h.hexdigest()


def bajar(url, destino, sha, pid, nombre, total=0):
    """Descarga con barra de avance; reutiliza la caché si el SHA-256 coincide; nunca deja un archivo sin verificar."""
    destino = Path(destino); CACHE.mkdir(parents=True, exist_ok=True)
    cache = CACHE / Path(url).name
    if cache.exists() and sha and sha256(cache) == sha:
        log(f'{nombre}: ya estaba bajado y verificado ({sha[:16]})')
    else:
        tmp = cache.with_suffix(cache.suffix + '.part')
        req = urllib.request.Request(url, headers={'User-Agent': 'iproy-instalador/' + VERSION})
        for intento in range(1, 4):
            try:
                with urllib.request.urlopen(req, timeout=60) as r, open(tmp, 'wb') as f:
                    total = int(r.headers.get('Content-Length') or total or 0); n = 0; t0 = time.time()
                    while True:
                        b = r.read(1 << 18)
                        if not b: break
                        f.write(b); n += len(b)
                        v = n / max(0.1, time.time() - t0) / 1048576
                        sub(f'{nombre}: {n / 1048576:.0f} de {total / 1048576:.0f} MB · {v:.1f} MB/s' if total else f'{nombre}: {n / 1048576:.0f} MB', n / total if total else None, pid)
                break
            except Exception as e:
                log(f'{nombre}: intento {intento} falló ({e})')
                if intento == 3: raise RuntimeError(f'No se pudo bajar {nombre}: {e}')
                time.sleep(3 * intento)
        if sha:
            h = sha256(tmp)
            if h != sha: tmp.unlink(missing_ok=True); raise RuntimeError(f'{nombre}: el SHA-256 no coincide ({h[:16]}); no se instala')
            log(f'{nombre}: SHA-256 verificado ({sha[:16]})')
        tmp.replace(cache)
    destino.parent.mkdir(parents=True, exist_ok=True)
    if cache.resolve() != destino.resolve(): shutil.copyfile(cache, destino)
    return destino


def correr(cmd, cwd=None, env_extra=None, pid=None, secreto=None, entrada=None):
    """Ejecuta un comando mostrando su salida en el detalle técnico. Nunca muestra el secreto."""
    env = dict(os.environ); env.update(env_extra or {})
    log('$ ' + ' '.join(str(c) for c in cmd))
    p = subprocess.Popen([str(c) for c in cmd], cwd=cwd, env=env, stdin=subprocess.PIPE if entrada else subprocess.DEVNULL,
                         stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, bufsize=1)
    if entrada: p.stdin.write(entrada); p.stdin.close()
    salida = []
    for l in p.stdout:
        l = l.rstrip('\n')
        if secreto: l = l.replace(secreto, '•••')
        salida.append(l); log('  ' + l)
        if pid: sub(l[:140])
    p.wait()
    return p.returncode, '\n'.join(salida)


def elevar(motivo, comando, pid):
    """Permiso de administrador: con pkexec (ventana del sistema) o pidiendo que el usuario lo pegue en una terminal."""
    con_pk = E['sistema'].get('pkexec')
    r = preguntar('Hace falta permiso de administrador', motivo + (' Se abrirá la ventana del sistema para que escribas tu contraseña.' if con_pk else ' Pegá el comando en una terminal y después tocá «Ya lo hice».'),
                  'sudo ' + comando, manual=not con_pk, puede_elevar=con_pk)
    if r == 'saltar': return 'saltado'
    if r == 'hecho': return 'hecho'
    rc, _ = correr(['pkexec', 'sh', '-c', comando], pid=pid)
    return 'ok' if rc == 0 else 'error'


# ------------------------------------------------------------------ instalación
def instalar(o):
    s = E['sistema']; inst = E['instalacion']
    prod = o['nombre'] if o['tipo'] == 'propio' else 'iProy'
    base = Path(os.path.expanduser(o['destino'])).resolve()
    raiz = base / prod / f'{prod}-Portable'
    lanz = raiz / f'{prod}-Control.sh'
    inst.update({'producto': prod, 'version': VERSION, 'carpeta': str(raiz), 'lanzador': str(lanz), 'vista': o['vista'], 'modo': o['modo'],
                 'usuario': o['usuario'], 'url': f"http://localhost:{o['puerto']}/", 'componentes': [],
                 'cmd_start': f'bash "{lanz}" start', 'cmd_stop': f'bash "{lanz}" stop', 'cmd_status': f'bash "{lanz}" status'})
    trabajo = CACHE / 'trabajo'; fp_cache = CACHE / 'frankenphp'

    def etapa(pid, fn):
        paso(pid, 'en_curso'); sub('')
        try:
            r = fn()
            estado, det = (r if isinstance(r, tuple) else ('ok', r))
            paso(pid, estado, det or '')
            return estado != 'error'
        except Exception as e:
            log(f'✘ {e}'); paso(pid, 'error', str(e)); return False

    def p_carpeta():
        if raiz.exists() and (raiz / 'data').exists(): raise RuntimeError(f'Ya hay un nodo instalado en {raiz}. Elegí otra carpeta u otro nombre (no se sobrescribe nada).')
        if raiz.exists(): raise RuntimeError(f'La carpeta {raiz} ya existe. Elegí otra carpeta u otro nombre.')
        base.mkdir(parents=True, exist_ok=True)
        t = base / ('.prueba_' + secrets.token_hex(3)); t.write_text('x'); t.unlink()
        return str(base)

    def p_runtime():
        bajar(FRANKEN['url'], fp_cache, FRANKEN['sha'], 'runtime', 'FrankenPHP', FRANKEN['bytes'])
        fp_cache.chmod(0o755)
        inst['componentes'].append({'nombre': 'FrankenPHP (servidor web + PHP)', 'version': FRANKEN['version'], 'sha256': FRANKEN['sha']})
        return f"FrankenPHP {FRANKEN['version']} verificado"

    def p_codigo():
        if trabajo.exists(): shutil.rmtree(trabajo)
        trabajo.mkdir(parents=True)
        fuente = os.environ.get('IPROY_INST_CODIGO', '')
        if fuente and Path(fuente).is_dir():
            shutil.copytree(fuente, trabajo / 'iproy', ignore=shutil.ignore_patterns('.git', 'data', 'runtime', 'descargas'))
            origen = 'carpeta local ' + fuente
        else:
            z = Path(fuente) if fuente else bajar(URL_CODIGO, trabajo / 'iproy.zip', None, 'codigo', 'Código iProy ' + TAG)
            with zipfile.ZipFile(z) as zf:
                for m in zf.namelist():
                    if m.startswith('/') or '..' in m: raise RuntimeError('ZIP con rutas inválidas')
                zf.extractall(trabajo)
            hijos = [d for d in trabajo.iterdir() if d.is_dir()]
            hijos[0].rename(trabajo / 'iproy'); origen = 'ZIP ' + (fuente or URL_CODIGO)
            inst['componentes'].append({'nombre': 'Código iProy', 'version': TAG, 'sha256': sha256(z)})
        if not (trabajo / 'iproy' / 'nodo' / 'iProy-Control.sh').exists(): raise RuntimeError('El código bajado no tiene nodo/iProy-Control.sh')
        return 'desde ' + origen

    def p_producto():
        repo = trabajo / 'iproy'
        if o['tipo'] == 'propio':
            rc, out = correr([fp_cache, 'php-cli', repo / 'incubadora' / 'incubar.php', 'nuevo', '--nombre', o['nombre'], '--sigla', o['sigla'],
                              '--color', o['color'], '--plantilla', o['plantilla'], '--destino', base / prod], cwd=repo, pid='producto')
            if rc != 0 or 'sin problemas' not in out: raise RuntimeError('La incubadora no terminó bien (ver el detalle técnico)')
            if not raiz.exists():
                c = [d for d in (base / prod).iterdir() if d.is_dir() and d.name.endswith('-Portable')]
                if c: c[0].rename(raiz)
            det = f"{o['nombre']} ({o['sigla']}) incubado con la plantilla {o['plantilla']}"
        else:
            shutil.copytree(repo / 'nodo', raiz); det = 'iProy base'
        if not lanz.exists(): raise RuntimeError(f'No se encontró el lanzador {lanz.name}')
        lanz.chmod(0o755)
        (raiz / 'runtime' / 'linux-x64').mkdir(parents=True, exist_ok=True)
        shutil.copyfile(fp_cache, raiz / 'runtime' / 'linux-x64' / 'frankenphp'); (raiz / 'runtime' / 'linux-x64' / 'frankenphp').chmod(0o755)
        return det

    def p_mariadb():
        desc = raiz / 'descargas'; desc.mkdir(exist_ok=True)
        bajar(MARIADB['url'], desc / MARIADB['archivo'], MARIADB['sha'], 'mariadb', 'MariaDB', MARIADB['bytes'])
        (desc / 'sha256sums.txt').write_text(f"{MARIADB['sha']}  {MARIADB['archivo']}\n")   # el lanzador vuelve a verificar contra el oficial
        sub('Instalando MariaDB (se recorta a lo necesario, unos 110 MB)…', 0.9, 'mariadb')
        rc, out = correr(['bash', lanz, 'instalar-mariadb'], cwd=raiz, pid='mariadb')
        inst['componentes'].append({'nombre': 'MariaDB', 'version': MARIADB['version'], 'sha256': MARIADB['sha']})
        if rc != 0:
            faltan = re.findall(r'(lib[\w.+-]+\.so[\w.]*)[^\n]*(?:not found|No such file|cannot open)', out)
            if faltan: return ('aviso', 'Faltan librerías: ' + ', '.join(sorted(set(faltan))) + ' (se resuelven en el paso siguiente)')
            raise RuntimeError('instalar-mariadb falló (ver el detalle técnico)')
        try: (desc / MARIADB['archivo']).unlink()   # ya está instalado: libera 340 MB (queda en la caché del instalador)
        except OSError: pass
        return 'MariaDB ' + MARIADB['version'] + ' instalado y recortado'

    def p_librerias():
        mdb = raiz / 'runtime' / 'linux-x64' / 'mariadb' / 'bin' / 'mariadbd'
        if not mdb.exists():
            tar_ok = (CACHE / MARIADB['archivo']).exists()
            if not tar_ok: return ('error', 'MariaDB no quedó instalado')
        faltan = []
        if mdb.exists():
            _, out = correr(['ldd', mdb])
            faltan = sorted(set(re.findall(r'(\S+) => not found', out)))
        if not faltan: return 'Todas las librerías están'
        fam = s.get('familia') or ''
        paquetes = sorted({LIBS.get(l, {}).get(fam, '') for l in faltan} - {''})
        if not paquetes or fam not in GESTOR:
            return ('aviso', 'Faltan ' + ', '.join(faltan) + '. Instalalas con el gestor de paquetes de tu distribución y volvé a ejecutar el instalador.')
        cmd = f"{GESTOR[fam]} {' '.join(paquetes)}"
        r = elevar(f"MariaDB necesita {', '.join(faltan)}, que se instala con el paquete «{' '.join(paquetes)}».", cmd, 'librerias')
        if r in ('ok', 'hecho'):
            if not mdb.exists():
                rc, _ = correr(['bash', lanz, 'instalar-mariadb'], cwd=raiz, pid='librerias')
                if rc != 0: return ('error', 'MariaDB sigue sin instalarse después de agregar las librerías')
            _, out = correr(['ldd', mdb]); quedan = re.findall(r'(\S+) => not found', out)
            return ('ok', 'Instalado: ' + ' '.join(paquetes)) if not quedan else ('error', 'Siguen faltando: ' + ', '.join(quedan))
        return ('aviso' if r == 'saltado' else 'error', f'Sin instalar: {cmd}')

    def p_init():
        rc, _ = correr(['bash', lanz, 'init', '--nombre', o['institucion'], '--modo', o['modo'], '--puerto', str(o['puerto']), '--vista', o['vista']], cwd=raiz, pid='init')
        if rc != 0: raise RuntimeError('init falló (ver el detalle técnico)')
        return f"Nodo creado · vista {o['vista']} · icono en el escritorio"

    def p_red():
        if o['modo'] != 'lan': return ('saltado', 'Solo esta PC: no hace falta abrir puertos')
        tls = o['puerto'] + 363
        if shutil.which('ufw'):
            _, out = correr(['sh', '-c', 'ufw status 2>/dev/null || true'])
            if 'inactive' in out or not out.strip(): return 'El firewall (ufw) está inactivo: no hace falta abrir puertos'
            cmd = f'ufw allow {tls}/tcp && ufw allow 2575/tcp'
        elif shutil.which('firewall-cmd'):
            cmd = f'firewall-cmd --permanent --add-port={tls}/tcp && firewall-cmd --reload'
        else: return 'No se encontró un firewall activo conocido (ufw o firewalld)'
        r = elevar(f'Para que los celulares y otras PC de la red entren por https://<IP>:{tls}/ hay que abrir ese puerto en el firewall.', cmd, 'red')
        return ('ok', f'Puerto {tls} abierto') if r in ('ok', 'hecho') else ('aviso', f'Sin abrir el puerto {tls}: {cmd}')

    def p_start():
        rc, out = correr(['bash', lanz, 'start', '--no-abrir'], cwd=raiz, pid='start')
        if rc != 0: raise RuntimeError('start falló (ver el detalle técnico)')
        return 'MariaDB, servidor web, worker y residentes en marcha'

    def p_usuario():
        env = {'IPROY_CLAVE_NUEVA': o['clave'], prod.upper() + '_CLAVE_NUEVA': o['clave']}
        rc, out = correr(['bash', lanz, 'usuario', 'alta', o['usuario'], 'AD'], cwd=raiz, env_extra=env, pid='usuario', secreto=o['clave'])
        o['clave'] = ''   # no queda en memoria más de lo necesario
        if rc != 0 or not re.search(r'rol"?\s*:\s*"?AD', out): raise RuntimeError('No se pudo crear el usuario (ver el detalle técnico)')
        return f"Usuario «{o['usuario']}» con rol de administrador"

    def p_hora():
        d = desfase_hora(); s['hora_desfase'] = d
        if d is None: return ('aviso', 'Sin referencia para comparar (sin Internet)')
        if abs(d) <= 60: return f'Correcta (desfase {d} s)'
        r = elevar(f'La hora de la PC está desfasada {d} s. Se activa la sincronización automática (NTP).', 'timedatectl set-ntp true', 'hora')
        return ('ok', 'Sincronización activada') if r in ('ok', 'hecho') else ('aviso', f'Desfase {d} s sin corregir')

    def p_verificar():
        for i in range(20):
            try:
                urllib.request.urlopen(inst['url'] + 'app/', timeout=5); inst['url_ok'] = True
                return 'Responde en ' + inst['url']
            except Exception: time.sleep(2)
        return ('error', 'No responde en ' + inst['url'])

    fn = {'carpeta': p_carpeta, 'runtime': p_runtime, 'codigo': p_codigo, 'producto': p_producto, 'mariadb': p_mariadb, 'librerias': p_librerias,
          'init': p_init, 'red': p_red, 'start': p_start, 'usuario': p_usuario, 'hora': p_hora, 'verificar': p_verificar}
    criticos = {'carpeta', 'runtime', 'codigo', 'producto', 'init', 'start'}
    for pid, _ in PASOS:
        ok = etapa(pid, fn[pid])
        if not ok and (pid in criticos or (pid == 'librerias' and not (raiz / 'runtime/linux-x64/mariadb/bin/mariadbd').exists())):
            for p in E['pasos']:
                if p['estado'] == 'pendiente': p['estado'] = 'saltado'; p['detalle'] = 'No se hizo porque falló un paso anterior'
            break
    guardar_informe_json()
    with L: E['fase'] = 'terminado'; E['progreso'] = 100; E['sub'] = ''


def guardar_informe_json(html=None):
    i = E['instalacion']; d = Path(i.get('carpeta') or Path.home())
    if not d.exists(): d = Path.home()
    marca = time.strftime('%Y-%m-%d_%H%M')
    datos = {k: E[k] for k in ('sistema', 'instalacion', 'version_instalador')}
    datos['pasos'] = [{k: v for k, v in p.items() if not k.startswith('_')} for p in E['pasos']]
    try:
        (d / f'informe_instalacion_{marca}.json').write_text(json.dumps(datos, ensure_ascii=False, indent=2))
        if html: (d / f'informe_instalacion_{marca}.html').write_text(html)
        (d / f'informe_instalacion_{marca}.log').write_text('\n'.join(E['log']))
        i['carpeta_informe'] = str(d)
    except OSError as e: log(f'No se pudo guardar el informe: {e}')


# ------------------------------------------------------------------ servidor web local
TOKEN = secrets.token_urlsafe(24)


def ui():
    f = AQUI / 'ui.html'
    if not UI_EMBEBIDA.startswith('__'):
        import base64; return base64.b64decode(UI_EMBEBIDA).decode('utf-8')
    return f.read_text(encoding='utf-8')


class H(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a): pass

    def _env(self, code, obj, tipo='application/json; charset=utf-8'):
        b = obj.encode() if isinstance(obj, str) else json.dumps(obj, ensure_ascii=False).encode()
        self.send_response(code); self.send_header('Content-Type', tipo); self.send_header('Cache-Control', 'no-store')
        self.send_header('Content-Security-Policy', "default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data:")
        self.send_header('Content-Length', str(len(b))); self.end_headers(); self.wfile.write(b)

    def _ok(self):
        if self.headers.get('X-Token') != TOKEN: self._env(403, {'error': 'token'}); return False
        return True

    def _cuerpo(self):
        n = int(self.headers.get('Content-Length') or 0)
        return json.loads(self.rfile.read(min(n, 5_000_000)) or b'{}')

    def do_GET(self):
        u = self.path.split('?')[0]
        if u == '/':
            if f't={TOKEN}' not in self.path: return self._env(403, 'Abrí el enlace que mostró el instalador.', 'text/plain; charset=utf-8')
            return self._env(200, ui(), 'text/html; charset=utf-8')
        if not self._ok(): return
        if u == '/api/sistema':
            with L:
                if E['sistema'] is None: pass
            if E['sistema'] is None: E['sistema'] = sistema()
            return self._env(200, E['sistema'])
        if u == '/api/estado':
            with L: return self._env(200, {k: v for k, v in E.items() if k != 'respuesta'} | {'pasos': [{k: v for k, v in p.items() if not k.startswith('_')} for p in E['pasos']]})
        if u == '/api/puerto':
            n = int(re.search(r'n=(\d+)', self.path).group(1)) if 'n=' in self.path else 0
            return self._env(200, {'libre': 1024 <= n <= 65000 and puerto_libre(n)})
        self._env(404, {'error': 'no existe'})

    def do_POST(self):
        u = self.path.split('?')[0]
        if not self._ok(): return
        try: c = self._cuerpo()
        except Exception: return self._env(400, {'error': 'JSON inválido'})
        if u == '/api/iniciar':
            with L:
                if E['fase'] != 'espera': return self._env(409, {'error': 'La instalación ya empezó'})
            o = {k: c.get(k) for k in ('tipo', 'vista', 'modo', 'nombre', 'sigla', 'color', 'plantilla', 'institucion', 'destino', 'puerto', 'usuario', 'clave')}
            err = validar(o)
            if err: return self._env(422, {'error': err})
            if not puerto_libre(int(o['puerto'])): return self._env(422, {'error': f"El puerto {o['puerto']} está ocupado: elegí otro."})
            if E['sistema'] is None: E['sistema'] = sistema()
            with L:
                E['fase'] = 'instalando'; E['pasos'] = [{'id': i, 'titulo': t, 'estado': 'pendiente', 'detalle': ''} for i, t in PASOS]
            threading.Thread(target=lambda: protegido(o), daemon=True).start()
            return self._env(200, {'ok': True})
        if u == '/api/responder':
            with L: E['respuesta'] = {'id': c.get('id'), 'respuesta': c.get('respuesta')}
            return self._env(200, {'ok': True})
        if u == '/api/informe':
            guardar_informe_json(str(c.get('html', ''))[:3_000_000]); return self._env(200, {'ok': True})
        self._env(404, {'error': 'no existe'})


def validar(o):
    if o['tipo'] not in ('base', 'propio'): return 'tipo inválido'
    if o['tipo'] == 'propio':
        if not re.fullmatch(r'[A-Za-z][A-Za-z0-9]{1,19}', o['nombre'] or ''): return 'Nombre de producto inválido'
        if (o['nombre'] or '').lower() == 'iproy': return 'Para iProy elegí la opción iProy'
        if not re.fullmatch(r'[A-Z]{3}', o['sigla'] or ''): return 'Sigla inválida'
        if not re.fullmatch(r'#[0-9a-fA-F]{6}', o['color'] or ''): return 'Color inválido'
        if o['plantilla'] not in ('gestion-mantenimiento', 'mantenimiento-basico', 'monitoreo-industrial'): return 'Plantilla inválida'
    if o['vista'] not in ('widgets', 'menu') or o['modo'] not in ('local', 'lan'): return 'Vista o modo inválidos'
    if not (o['institucion'] or '').strip() or len(o['institucion']) > 60 or re.search(r'["`$\\]', o['institucion']): return 'Nombre de institución inválido (sin comillas ni símbolos $ ` \\)'
    if not (o['destino'] or '').strip(): return 'Falta la carpeta'
    try: o['puerto'] = int(o['puerto'])
    except Exception: return 'Puerto inválido'
    if not 1024 <= o['puerto'] <= 65000: return 'Puerto fuera de rango'
    if not re.fullmatch(r'[A-Za-z0-9._-]{3,30}', o['usuario'] or ''): return 'Usuario inválido'
    if len(o['clave'] or '') < 10: return 'La contraseña tiene que tener al menos 10 caracteres'
    return ''


def protegido(o):
    try: instalar(o)
    except Exception as e:
        log(f'✘ error inesperado: {e}')
        with L: E['fase'] = 'error'


class Srv(socketserver.ThreadingMixIn, http.server.HTTPServer):
    daemon_threads = True; allow_reuse_address = True


def main():
    port = 0; abrir = '--no-abrir' not in sys.argv
    if '--puerto' in sys.argv: port = int(sys.argv[sys.argv.index('--puerto') + 1])
    srv = Srv(('127.0.0.1', port), H)
    url = f'http://127.0.0.1:{srv.server_address[1]}/?t={TOKEN}'
    print(f'Instalador de iProy {VERSION}\nAbrí esta dirección en el navegador (si no se abrió sola):\n  {url}\nDejá esta ventana abierta hasta que termine. Ctrl+C para salir.', flush=True)
    if abrir:
        threading.Timer(0.8, lambda: (subprocess.Popen(['xdg-open', url], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL) if shutil.which('xdg-open') else webbrowser.open(url))).start()
    threading.Thread(target=lambda: E.__setitem__('sistema', sistema()), daemon=True).start()
    try: srv.serve_forever()
    except KeyboardInterrupt: print('\nInstalador cerrado.')


if __name__ == '__main__':
    main()
