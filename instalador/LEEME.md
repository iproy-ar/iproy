<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Instalador gráfico de iProy (Linux y Windows)

<sub>🎓 [Tutorial de instalación en la web de capacitación →](https://iproy.com.ar/pim/instalar)</sub>

El instalador se abre en el navegador y deja un nodo funcionando, con su usuario administrador. El recorrido tiene 4 pantallas:

1. **Tu PC.** Revisa el sistema, la arquitectura, la memoria, el disco, Internet, la hora, los puertos, si hay Git o PHP y si la sesión es de administrador. No cambia nada.
2. **Opciones.** Elegís:
   - **iProy** o **tu propio producto** (nombre, sigla, color y plantilla, armado con la incubadora);
   - la carpeta, la vista (widgets o menú), el alcance (solo esta PC o la red), el puerto, el usuario y la contraseña.
3. **Instalación.** Barra de avance con los pasos, las descargas en MB y MB/s, y el detalle técnico desplegable. Si hace falta permiso de administrador, aparece un aviso que dice para qué, con los botones *Autorizar* y *Saltar*.
4. **Informe.** Resume la PC, lo instalado (con SHA-256), cada paso con su resultado y su tiempo, las **herramientas extra recomendadas para esa PC** y los comandos del día a día. Se guarda en la carpeta del producto como HTML y JSON, junto con el registro técnico. No contiene contraseñas.

## Desinstalar

El instalador deja en la carpeta del producto un **desinstalador** armado con lo que registró durante la instalación: `uninstall-<Producto>.sh` (Linux, `bash uninstall-iProy.sh`) o `uninstall-<Producto>.bat` (Windows, doble clic), más `uninstall-<Producto>.json` con el detalle. Es interactivo: muestra qué va a hacer, pregunta si guardás una copia de los datos (recomendado: `data/` comprimida en tu carpeta personal o Documentos, con SHA-256) y pide confirmación. Después detiene el nodo, quita los accesos directos, ofrece cerrar el puerto del firewall (pide permiso de administrador), borra la carpeta y deja un registro. Opciones para automatizar (`--help`): `--si`, `--sin-datos`, `--destino-respaldo DIR`, `--cache` (borra también las descargas que sirven para reinstalar) y `--conservar-firewall`; en Windows, las mismas con `-Si`, `-SinDatos`, `-DestinoRespaldo`, `-Cache`, `-ConservarFirewall`. No deshace lo que usan otros programas: librerías del sistema, Visual C++ y la sincronización de hora.

## Descarga

| Sistema | Archivo | Cómo se usa |
|---|---|---|
| Windows 10/11 | [`Instalador-iProy-Windows.zip`](https://github.com/iproy-ar/iproy/releases/latest/download/Instalador-iProy-Windows.zip) | Descomprimir y hacer doble clic en `Instalar-iProy.bat` **con tu usuario de siempre** |
| Linux x86_64 | [`instalar-iproy-linux.sh`](https://github.com/iproy-ar/iproy/releases/latest/download/instalar-iproy-linux.sh) | `bash instalar-iproy-linux.sh` **sin sudo** (hace falta Python 3, que viene en las distribuciones de escritorio) |

Cada versión publica `SHA256SUMS.txt` para comprobar la descarga.

## Qué resuelve, según lo aprendido en las instalaciones reales

| Problema de la primera instalación en Windows | Qué hace el instalador |
|---|---|
| Git o PHP no instalados; winget con el error `0x8a15000f` | Desde la 1.6.2 los instala siempre con winget (`Git.Git` y `PHP.PHP.8.3`), sin suponer que ya están. La instalación no depende de ellos (baja el código como ZIP y corre la incubadora con el PHP de FrankenPHP): si winget falta o falla, avisa cómo resolverlo y sigue |
| A PHP le faltaba `mbstring` | FrankenPHP trae `mbstring` y `gd` |
| Instalar como Administrador deja el icono en otro escritorio | Instala con tu usuario y avisa si la sesión es de administrador. Pide permiso solo para lo que lo necesita |
| PowerShell en otra carpeta; `bash` que abre WSL | No hay que escribir comandos |
| Contraseña que no se ve mientras se escribe | Se escribe dos veces en la pantalla, con un indicador de fortaleza y la confirmación de que coinciden |

**Pasos que piden permiso, y solo si hacen falta:**
- firewall, en modo red;
- sincronizar la hora, si se desfasó más de 60 s;
- librerías que faltan para MariaDB en Linux (`libxcrypt-compat`, `libaio`, etc., según la distribución);
- Visual C++ 2015-2022 en Windows, que se baja del sitio de Microsoft y se instala solo si su firma digital es válida.

En Linux se usa `pkexec` (la ventana de contraseña del sistema). Sin entorno gráfico, el instalador muestra el comando para pegar en una terminal.

## Para desarrollar

| Archivo | Qué es |
|---|---|
| `ui.html` | La interfaz (única para los dos sistemas) |
| `instalador.py` | Motor para Linux (Python 3, sin dependencias externas) |
| `instalador.ps1` | Motor para Windows (PowerShell 5.1 o superior; servidor `TcpListener`, que no requiere permisos) |
| `plantillas/uninstall.sh`, `plantillas/uninstall.ps1` | Plantillas del desinstalador (el instalador las completa con lo registrado) |
| `armar.sh` | Arma `dist/` con la interfaz y las plantillas embebidas, y `SHA256SUMS.txt` |

**Variables de entorno para pruebas:**
- `IPROY_INST_CODIGO`: carpeta o ZIP local con el repositorio.
- `IPROY_INST_CACHE`: carpeta de descargas.
- `IPROY_INST_SIMULAR=1` (solo Windows): recorre todo sin instalar nada.
- `IPROY_INST_PERMITIR_ROOT=1`: para contenedores de prueba.

**Prueba de punta a punta (Linux):**
- el instalador, manejado desde la interfaz con Chromium, armó un producto incubado («SaludNorte»);
- se ingresó con la contraseña elegida;
- `pruebas/pim.sh` y `pruebas/mem.sh` dieron 70/70 y 28/28 sobre ese nodo;
- (1.6.0) el instalador de un solo archivo armó un producto incubado («PruebaDes») y su `uninstall-PruebaDes.sh` lo desinstaló: nodo detenido, `data/` guardada y verificada, accesos quitados, carpetas borradas.

En Windows (1.6.0) se probó el instalador de un solo archivo con PowerShell 7 en modo simulado: los 13 pasos, los dos pedidos de permiso (Visual C++ y firewall), el informe y la generación de `uninstall-PruebaWin.bat/.ps1/.json`; y ese desinstalador se ejecutó de verdad sobre lo simulado (cancelar, guardar `data\` en ZIP verificado, quitar accesos, dejar la regla del firewall a elección, borrar carpetas, registro). **No cubre** UAC, firewall real, `.lnk` ni los binarios: eso solo se prueba en un Windows real.

En Windows (1.6.1) se hizo la primera instalación **real** de punta a punta, dentro de Wine 11 con OviWine Soft 0.2.1 y PowerShell 7.4: bajada y verificación de FrankenPHP y MariaDB, `init`, `start`, usuario administrador, comprobación y desinstalador, en 94 s con la caché de descargas llena; sobre ese nodo `pruebas/pim.sh` 70/70 y `pruebas/mem.sh` 28/28. Wine no cubre UAC, el firewall ni los accesos directos `.lnk` (el paso «Crear el nodo» queda con aviso), así que eso sigue pendiente de un Windows real.
