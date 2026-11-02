<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->
# Publicar iProy en GitHub

> ⚠ **Para completar:** el repositorio usa dos marcadores, **`CUENTA-GITHUB`** (usuario u organización) y **`CORREO-SEGURIDAD`**. Aparecen en `nodo/producto.json` (enlace «código fuente» que exige la AGPL), `CITATION.cff`, `README.md` y `SEGURIDAD.md`. Se reemplazan todos juntos con un comando (paso 3).

## 1. Crear la cuenta (una sola vez)

Se recomienda **una cuenta personal del autor y una organización «iproy»** que sea dueña del repositorio. Así el proyecto no queda atado a un nombre de usuario y se pueden sumar mantenedores sin compartir claves.

1. En https://github.com/signup creá la cuenta personal con **admin@iproy.com.ar** (o tu correo personal y después agregás este). Elegí un usuario propio, por ejemplo `ovidio-arias`.
2. Activá la **verificación en dos pasos** (Settings → Password and authentication). GitHub la exige a quienes publican código.
3. Creá la organización: https://github.com/organizations/plan → plan **Free** → nombre **`iproy`** (si está tomado: `iproy-ar` o `iproy-pim`) → correo de contacto admin@iproy.com.ar → «My personal account».
4. **Verificá el dominio** (Organization → Settings → Verified and approved domains → Add domain → `iproy.com.ar`). GitHub te da un registro TXT con un nombre como `_gh-iproy-o.iproy.com.ar`: cargalo en el panel DNS de DonWeb, esperá la propagación y tocá «Verify». La organización queda con la insignia «Verified», que muestra que el repositorio es del dueño del dominio.
5. Opcional: creá en DonWeb el alias **seguridad@iproy.com.ar** que reenvíe a admin@iproy.com.ar, para el reporte de vulnerabilidades.

## 2. Preparar git en tu computadora

```bash
git config --global user.name  "Ovidio Francisco Arias"
git config --global user.email "admin@iproy.com.ar"     # el mismo correo de la cuenta: firma el DCO de cada commit
```

## 3. Completar los marcadores

```bash
bash herramientas/fijar-github.sh iproy seguridad@iproy.com.ar
git commit -s -am "Publicación: cuenta de GitHub y correo de seguridad"
```

## 4. Subir

Creá en la organización un repositorio **público y vacío** llamado `iproy` (sin README ni licencia: ya están en esta carpeta). Después:

```bash
git remote add origin https://github.com/iproy/iproy.git
git push -u origin main
git tag -a v1.1.0 -m "iProy 1.1.0 · PIM 0.9.2 alineado con IHE" && git push origin v1.1.0
```

## 5. Ajustes del repositorio (Settings)

- **Pages:** Source = GitHub Actions. El flujo `.github/workflows/guia.yml` compila la especificación y publica la guía FHIR.
- **Branches:** proteger `main` (Pull Request obligatorio y verificación del flujo «software»).
- **Security → Private vulnerability reporting:** activado.
- **About:** descripción, sitio `https://iproy.com.ar` y temas `fhir`, `hl7`, `ihe`, `interoperabilidad`, `salud`, `mantenimiento`, `argentina`.

## 6. Después de publicar

1. Archivá la versión en **Software Heritage**: https://archive.softwareheritage.org/save/ con la URL del repositorio.
2. Inscribí el software como obra publicada en la **Dirección Nacional del Derecho de Autor** (Trámites a Distancia).
3. Decidí dónde resuelve el canónico `https://iproy.com.ar/fhir` (por ejemplo, una redirección en el hosting hacia la guía publicada en GitHub Pages) \[pendiente de decisión del autor\].

Los pasos legales previos (pruebas de autoría, depósito, nota a la institución) están en la memoria del proyecto (L-344 y L-345).
