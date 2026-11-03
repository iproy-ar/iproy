<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Tutorial: instalar un sistema PIM en una PC (Linux y Windows)

> 🎓 **Versión guiada, con gráficos y cuestionario:** https://iproy.com.ar/pim/instalar · Dudas: https://iproy.com.ar/pim/foro

Este tutorial sale de una instalación real hecha el 03-11-2026 en una PC con **EndeavourOS** (Arch Linux): se incubó un producto propio llamado *MantSur*, se instaló el runtime, se creó el nodo y se corrieron las pruebas del estándar (51/51 y 19/19). Los errores que aparecieron en esa práctica quedaron corregidos en la versión 1.1.1 y están anotados al final, por si usás una copia anterior.

El mismo camino sirve para instalar **iProy tal cual** (saltá el paso 3) o un **producto incubado** con tu nombre (hacé el paso 3).

```
 ┌──────────────┐   ┌──────────────┐   ┌──────────────┐   ┌──────────────┐   ┌──────────────┐   ┌──────────────┐
 │ 1 Requisitos │ → │ 2 Repositorio│ → │ 3 Incubar    │ → │ 4 Runtime    │ → │ 5 init+start │ → │ 6 Comprobar  │
 │ php, git…    │   │ git clone    │   │ (opcional)   │   │ FrankenPHP + │   │ usuario alta │   │ navegador +  │
 │              │   │              │   │ tu producto  │   │ MariaDB      │   │              │   │ pim.sh mem.sh│
 └──────────────┘   └──────────────┘   └──────────────┘   └──────────────┘   └──────────────┘   └──────────────┘
```

**Regla de oro:** un comando por vez. Esperá a que vuelva el símbolo del sistema antes de escribir el siguiente; si pegás varios juntos, el que pide la contraseña la toma vacía.

---

## A. Linux

Probado en EndeavourOS. Los paquetes cambian de nombre según la distribución; el resto es idéntico.

### A1. Requisitos (una sola vez)

| Distribución | Comando |
|---|---|
| Arch, EndeavourOS, Manjaro | `sudo pacman -S --needed git curl php php-gd libxcrypt-compat` |
| Debian, Ubuntu, Mint | `sudo apt install git curl php-cli php-gd` |
| Fedora | `sudo dnf install git curl php-cli php-gd` |

- **`libxcrypt-compat` es obligatorio en Arch:** sin él MariaDB no arranca (le falta `libcrypt.so.1`). \[verificado\]
- `php-gd` es opcional: lo usa la incubadora para dibujar el logo con tu sigla. En Arch además hay que quitar el `;` de `;extension=gd` en `/etc/php/php.ini`. Sin GD el producto funciona igual, con el logo de iProy.
- Usá una carpeta de tu disco Linux (por ejemplo `~/Proyectos`), **no una partición NTFS de Windows**: MariaDB puede fallar por permisos y bloqueos de archivos.

### A2. Bajar el repositorio

```bash
mkdir -p ~/Proyectos/pim && cd ~/Proyectos/pim
git clone https://github.com/iproy-ar/iproy.git
cd iproy
```

### A3. Incubar tu producto (opcional)

```bash
php incubadora/incubar.php nuevo --nombre MantSur --sigla MSR --color "#0277bd" --plantilla gestion-mantenimiento
cd ../MantSur/MantSur-Portable
```

- Tiene que terminar con `✔ verificación: sin problemas`.
- El producto se crea **al lado** del repositorio (`~/Proyectos/pim/MantSur/`), no adentro.
- Si PHP no tiene GD, la incubadora lo avisa al final y dice cómo activarlo.
- Para instalar iProy sin incubar: `cd nodo` y, en todos los comandos que siguen, `iProy-Control.sh` en lugar de `MantSur-Control.sh`.

### A4. Runtime: servidor web y base de datos

```bash
bash tools/bajar-runtime.sh                 # FrankenPHP 1.13.3 (167 MB), verificado por sha256
```

Debe terminar con `✔ frankenphp-linux-x86_64 verificado (f88db9972ddac5e4)`.

Para MariaDB copiá a `descargas/` **una** de estas dos cosas y corré el instalador:

| Opción | Qué copiar a `descargas/` | Cuándo conviene |
|---|---|---|
| **a) Recortada** (18 MB) | `runtime-mariadb-11.4.13-linux-x64-recortado.tar.xz` **y** `sha256sums.txt`, de otro nodo iProy o Colmen (están en su carpeta `descargas/`) | Ya tenés un nodo; es lo más rápido y queda verificado archivo por archivo |
| **b) Oficial** (unos 340 MB) | `mariadb-11.4.13-linux-systemd-x86_64.tar.gz` de https://mariadb.org/download/ | Primera instalación sin otro nodo a mano |

```bash
bash MantSur-Control.sh instalar-mariadb
runtime/linux-x64/mariadb/bin/mariadbd --version      # → mariadbd  Ver 11.4.13-MariaDB …
```

Con la opción b) aparece `⚠ sin sha256sums.txt: sha …`: es normal (no hay lista para comparar) y la instalación sigue; el recorte deja unos 108 MB.

### A5. Crear el nodo y arrancarlo

```bash
bash MantSur-Control.sh init --nombre "Pañol MantSur" --modo local --puerto 8095 --puerto-db 3415
bash MantSur-Control.sh start
bash MantSur-Control.sh usuario alta admin AD        # pide la contraseña dos veces (mínimo 10 caracteres)
```

- `init` tarda entre 10 y 40 segundos en «creando la base de datos»; no escribas nada mientras tanto. Termina con `✔ nodo XXX listo` (XXX es el ID de tu nodo).
- `start` tiene que listar el worker, los residentes MQTT y MLLP y los módulos como `cargado`, y terminar con `Listo · nodo XXX · http://localhost:8095/`.
- **Icono en el escritorio:** `init` deja un acceso directo con el logo del producto en el escritorio y en el menú de aplicaciones (categoría Oficina). Doble clic: inicia el sistema y abre el navegador; si ya está andando, solo abre el navegador. Clic derecho → «Detener». La primera vez, el escritorio puede pedir «Confiar y ejecutar» / «Permitir lanzar». Para nodos creados antes de la 1.1.2: `bash MantSur-Control.sh acceso-directo`.
- `--modo local` atiende solo en esa PC; `--modo lan` la hace visible en la red del hospital (`https://<IP>:8443/`).
- Los puertos 8095 y 3415 evitan chocar con otro nodo en la misma PC; los de fábrica son 8080 y 3407.

### A6. Comprobar que funciona

1. Abrí `http://localhost:8095/` y entrá con `admin`. El tablero muestra el nombre del producto, el ID del nodo, los módulos cargados y los asientos del diario firmado.
2. Servidor PIM: `http://localhost:8095/pim/r4/metadata` y la Declaración de Integración en `/pim/declaracion`.
3. Pruebas del estándar, desde la carpeta del repositorio:

```bash
cd ~/Proyectos/pim/iproy
bash pruebas/pim.sh ../MantSur/MantSur-Portable     # → ✔ 51 comprobaciones correctas
bash pruebas/mem.sh ../MantSur/MantSur-Portable     # → ✔ 19 comprobaciones correctas
```

Las pruebas crean datos de ejemplo con prefijo `EJ-` (establecimiento, edificios, equipos, órdenes).

### A7. Primer uso real

1. **Inventario → Ubicaciones:** creá el árbol del hospital (edificio › piso › sala). El establecimiento ya existe.
2. **Inventario → Nuevo activo:** código, nombre y ubicación. Empezá por los activos de alcance A (gases medicinales, energía crítica, cadena de frío).
3. **Nueva solicitud:** escribí parte del nombre del activo y elegilo de la lista; el sistema genera la orden.
4. **Órdenes:** asignar → aceptar → en ejecución → finalizada → conformada. «En espera», «rechazada» y «anulada» piden motivo.
5. **Identidad de tu hospital** (desde la 1.2.0): menú del usuario → **Configuración** (pide tu contraseña otra vez; dura 2 horas) → subí el logo, escribí el nombre y elegí el fondo del ingreso y del tablero. Cada usuario elige su propio fondo en **Mi perfil**.
6. Para apagar: `bash MantSur-Control.sh stop` (hace una copia de seguridad antes).

### A8. Módulo propio (opcional)

```bash
cd ~/Proyectos/pim/iproy
php incubadora/incubar.php modulo calibraciones --nombre "Calibraciones" \
  --campos "instrumento:texto!,fecha:fecha!,resultado:opcion(apto|no-apto|ajustado)!,error_pct:numero" \
  --destino ../MantSur/MantSur-Portable/modulos
cd ../MantSur/MantSur-Portable && bash MantSur-Control.sh stop && bash MantSur-Control.sh start
```

Después del reinicio, `calibraciones` aparece como `cargado`.

---

## B. Windows 10 / 11

Mismo recorrido; cambian las herramientas. Todo se hace desde **PowerShell** (botón derecho sobre Inicio → «Terminal» o «Windows PowerShell»), sin permisos de administrador salvo para instalar PHP y Git.

### B1. Requisitos (una sola vez)

1. **Git:** https://git-scm.com/download/win (o `winget install Git.Git`).
2. **PHP 8.1 o posterior** (solo para la incubadora; el nodo trae el suyo): https://windows.php.net/download/ → «Thread Safe» ZIP → descomprimir en `C:\php` y agregar esa carpeta al PATH (Configuración → Sistema → Acerca de → Configuración avanzada → Variables de entorno). O `winget install PHP.PHP.8.3`.
   - Para el logo propio: en `C:\php\php.ini` (copiá `php.ini-development` si no existe) quitá el `;` de `;extension=gd`.
3. Comprobá en una PowerShell nueva: `git --version` y `php -v`.

### B2. Bajar el repositorio

```powershell
mkdir $HOME\Proyectos\pim; cd $HOME\Proyectos\pim
git clone https://github.com/iproy-ar/iproy.git
cd iproy
```

### B3. Incubar tu producto (opcional)

```powershell
php incubadora\incubar.php nuevo --nombre MantSur --sigla MSR --color "#0277bd" --plantilla gestion-mantenimiento
cd ..\MantSur\MantSur-Portable
```

### B4. Runtime

1. **FrankenPHP:** botón derecho sobre `tools\bajar-runtime.ps1` → «Ejecutar con PowerShell». Si Windows bloquea los scripts, abrí PowerShell y corré una vez `Set-ExecutionPolicy -Scope CurrentUser RemoteSigned`. Termina con `OK FrankenPHP verificado (89b99aed53fbf8bf)`.
2. **MariaDB:** copiá a `descargas\` una de estas dos cosas:
   - a) `runtime-mariadb-11.4.13-win-x64-recortado.zip` + `sha256sums.txt` de otro nodo (19 MB), o
   - b) el ZIP oficial `mariadb-11.4.x-winx64.zip` de https://mariadb.org/download/.
3. `.\MantSur-Control.bat instalar-mariadb` y después `.\MantSur-Control.bat diagnostico` (comprueba FrankenPHP, MariaDB y puertos).

### B5. Crear el nodo y arrancarlo

```powershell
.\MantSur-Control.bat init -Nombre "Pañol MantSur" -Modo local -Puerto 8095
.\MantSur-Control.bat start
.\MantSur-Control.bat usuario alta admin AD
```

En Windows la base usa siempre el puerto 3407 (no hay `-PuertoDb`); si ya tenés otro MySQL o XAMPP, `diagnostico` lo avisa. Windows puede preguntar si permitís que FrankenPHP y MariaDB usen la red: aceptá solo redes privadas. El antivirus a veces demora el primer arranque de `mariadbd.exe`.

### B6. Comprobar y usar

Igual que en Linux (A6 y A7): `http://localhost:8095/`, `/pim/r4/metadata`, `/pim/declaracion`. Las pruebas `pim.sh` y `mem.sh` son scripts de bash: en Windows se corren desde **Git Bash** (viene con Git) con `bash pruebas/pim.sh ../MantSur/MantSur-Portable`.

Para apagar: `.\MantSur-Control.bat stop`, o el acceso «Detener MantSur» del menú Inicio. `init` también deja el icono **MantSur** en el escritorio (doble clic para iniciar); si no aparece, `.\MantSur-Control.bat acceso-directo`.

---

## C. Problemas que aparecieron en la práctica y cómo se resolvieron

| Síntoma | Causa | Solución (1.1.1) |
|---|---|---|
| `bajar-runtime.sh`: `curl: (23) … descargas/frankenphp-linux-x86_64.part: No existe el fichero` | En un producto recién incubado no existen `descargas/` ni `runtime/linux-x64/` | El script las crea. En versiones anteriores: `mkdir -p descargas runtime/linux-x64` |
| `instalar-mariadb` termina sin decir nada y no instala | Con `set -e`, la búsqueda de la versión recortada fallaba cuando no había ninguna y cortaba el script | Corregido; ahora explica qué copiar a `descargas/` |
| El lanzador muestra la ayuda en vez de ejecutar | Carácter de más al final del comando (`instalar-mariadb}`) | Escribir el comando solo |
| `✘ La contraseña debe tener al menos 10 caracteres` sin haber escrito nada | Se pegaron `init`, `start` y `usuario alta` juntos; el último tomó Enter como contraseña | Un comando por vez; repetir `usuario alta admin AD` |
| El producto incubado tiene el logo de iProy | PHP sin la extensión GD | La incubadora lo avisa al final y dice cómo activarla |
| «No existe el activo 123223» al pedir un trabajo | El nodo nace vacío: primero hay que dar de alta el activo | Inventario → Nuevo activo; la solicitud ahora ofrece la lista de activos por nombre |
| MariaDB no arranca en Arch | Falta `libcrypt.so.1` | `sudo pacman -S libxcrypt-compat` |

> 📚 Más: [nodo/INSTALAR.md](../nodo/INSTALAR.md) (todos los comandos), [incubadora/LEEME.md](../incubadora/LEEME.md), [pruebas](../pruebas/) · Capacitación por roles: https://iproy.com.ar/pim/aprende
