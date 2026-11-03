<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Instalar un nodo iProy 1.0

> 🎓 **Capacitación PIM:** este documento tiene su versión guiada, con diagramas, ejemplos y foro, en [iproy.com.ar/pim](https://iproy.com.ar/pim/) (en particular [esta página](https://iproy.com.ar/pim/instalar)). El código fuente vive en [github.com/iproy-ar/iproy](https://github.com/iproy-ar/iproy).


Un nodo es una carpeta autocontenida: servidor web con PHP (FrankenPHP), base de datos (MariaDB), núcleo, Servidor PIM, módulos y aplicación. No instala nada en el sistema operativo y no necesita permisos de administrador. Todo lo que genera queda en `data/`.

## 1. Runtime (una vez por equipo)

<sub>🎓 [Tutorial: instalar un sistema PIM en Linux y Windows →](https://iproy.com.ar/pim/instalar)</sub>

1. **FrankenPHP 1.13.3** (servidor + PHP 8.5), verificado por sha256:
   - Linux: `bash tools/bajar-runtime.sh linux`
   - Windows: `tools\bajar-runtime.ps1`, con clic derecho → «Ejecutar con PowerShell».
2. **MariaDB 11.4**:
   1. Bajá el paquete oficial de https://mariadb.org/download/ («bintar» de Linux x86_64 o ZIP de Windows) a `descargas/`.
   2. Si tenés el `sha256sums.txt` de la página, ponelo en la misma carpeta.
   3. Corré `bash iProy-Control.sh instalar-mariadb` (en Windows, `iProy-Control.bat instalar-mariadb`). El comando lo verifica y deja solo lo necesario: alrededor de 108 MB en lugar de 860.
   - En Arch, EndeavourOS o Manjaro: `sudo pacman -S libxcrypt-compat`.
3. Si copiás la carpeta a un disco NTFS o exFAT en Linux, usá siempre `bash iProy-Control.sh …`: así no hace falta el permiso de ejecución.

## 2. Primer arranque

<sub>🎓 [Tutorial: instalar un sistema PIM en Linux y Windows →](https://iproy.com.ar/pim/instalar)</sub>

```bash
bash iProy-Control.sh init --nombre "Pañol central" --modo lan     # ID del nodo, claves, base de datos (vacía)
bash iProy-Control.sh start                                       # MariaDB + FrankenPHP + worker + residentes MQTT y MLLP + núcleo
bash iProy-Control.sh usuario alta admin AD                       # pide la contraseña dos veces (mínimo 10 caracteres)
```

- **Aplicación:** `http://localhost:8080/`. Desde la red local: `https://<IP>:8443/`, con la CA propia del nodo (`confiar-ca`).
- **Otros puntos de acceso:**
  - Consola PIM: `/pim/`
  - API del núcleo: `/api`
  - API PIM (FHIR R4): `/pim/r4/metadata`
- **Dos nodos en el mismo equipo (pruebas):** `init --puerto 8090 --puerto-db 3408`.

## 3. Comandos

<sub>🎓 [Rutas de aprendizaje por rol →](https://iproy.com.ar/pim/aprende)</sub>

| Comando | Para qué |
|---|---|
| `abrir` · `acceso-directo [--quitar]` | Icono en el escritorio y el menú (lo crea `init`); `abrir` inicia si hace falta y abre el navegador |
| `start` · `stop` · `status` | Iniciar, detener (con respaldo automático) y ver el estado de cada proceso |
| `usuario alta <u> <AD\|GM\|EJ\|SO\|FT\|CI> [organización]` · `usuario lista` · `usuario clave <u>` · `usuario token <u> [días]` | Usuarios. `token` sirve para integraciones (gateways, scripts) con su rol |
| `nucleo estado\|modulos\|tareas\|diario\|verificar\|bus\|tick` · `nucleo ejecutar <modulo:tarea>` | Introspección y operación del núcleo |
| `modulo activar\|desactivar <id>` · `modulo config <id> [archivo.json]` | Módulos y su configuración (por ejemplo, el broker MQTT, los equipos Modbus o el puerto MLLP de IHE PCD-15 del módulo `mem`: `{"habilitado":true,"host":"0.0.0.0","puerto":2575,"ips_permitidas":["192.168.1.0/24"]}`) |
| `colmena identidad\|lista` · `colmena vincular <url\|ID>` · `colmena aceptar <par> [rol]` · `colmena rol\|revocar\|ping <par>` | Vínculos con otros nodos |
| `colmena rpc <par> GET "<ruta FHIR>"` · `colmena hub <url> [código]` · `colmena via <par> auto\|hub` · `colmena url <url>` | Usar la API PIM de otro nodo; hub de relevo para Internet |
| `pim cliente alta <id> <jwks.json> <rol>` · `pim prueba` · `pim verificar` · `pim remoto …` | Clientes del Servidor PIM y pruebas |
| `respaldo` · `restaurar [archivo]` · `verificar` · `confiar-ca` · `red modo local\|lan` · `nombre "…"` | Mantenimiento del nodo |

En Windows los comandos son los mismos: `iProy-Control.bat <comando> …`.

## 4. Roles

<sub>🎓 [Manuales de usuario por rol →](https://iproy.com.ar/pim/manuales)</sub>

| Rol | Puede |
|---|---|
| AD | Administrar el nodo: todo, más usuarios, módulos y colmena |
| GM | Gestionar: todo el dominio, ver el sistema y usar la colmena |
| EJ | Ejecutar: operar las órdenes de su organización, reconocer y archivar alarmas |
| SO | Pedir trabajos y consultar |
| FT | Enviar telemetría (sistemas y sensores) |
| CI | Consultar |

Cada módulo suma sus capacidades. Están en Sistema y en `/api/proc/capacidades`.

## 5. Datos de la institución

<sub>🎓 [Manuales de usuario por rol →](https://iproy.com.ar/pim/manuales)</sub>

El nodo nace **vacío**. Lo propio de tu organización se carga desde la aplicación o desde la API PIM:

- organismo, empresas y áreas;
- locales y activos;
- usuarios.

La configuración de cada módulo vive en `data/nucleo/modulos/<id>.json`. Nada de eso forma parte del código, así que podés actualizar el código sin perder datos.

**Organismo.** Editá `data/pim/config.json` → `organismo` (código, nombre y REFES si corresponde) y reiniciá.


---

<sub>🎓 ¿Algo no quedó claro? [Preguntá en el foro de la capacitación PIM](https://iproy.com.ar/pim/foro) · [Dejá una propuesta de mejora](https://iproy.com.ar/pim/resenas) · Repositorio: https://github.com/iproy-ar/iproy</sub>
