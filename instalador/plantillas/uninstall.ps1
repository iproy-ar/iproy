# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
#
# Desinstalador de @@PROD@@ · generado por el instalador de iProy @@VERSION@@ el @@FECHA@@
# Instalado en: @@RAIZ@@
#
# Uso: doble clic en @@NOMBRE_BAT@@ (con tu usuario de siempre). Es interactivo: muestra qué va a hacer, pregunta si guardás
# una copia de los datos y pide confirmación. Opciones avanzadas (desde PowerShell: powershell -ExecutionPolicy Bypass -File @@NOMBRE@@ ...):
#   -Si                   no pide confirmación
#   -SinDatos             no guarda la copia de data\ (se pierden los datos)
#   -DestinoRespaldo D    carpeta donde dejar la copia de los datos (por defecto, Documentos)
#   -Cache                borra también la caché del instalador (@@CACHE@@)
#   -ConservarFirewall    no toca la regla del firewall aunque el instalador la haya creado
# Qué hace: detiene el nodo · guarda data\ en un ZIP verificado con SHA-256 · quita los accesos directos · quita la regla del
# firewall (pide permiso de Windows) · borra la carpeta del producto · deja un registro en Documentos.
# Qué NO deshace: Visual C++ y la sincronización de hora, porque los usan otros programas.
param([switch]$Si, [switch]$SinDatos, [string]$DestinoRespaldo = '', [switch]$Cache, [switch]$ConservarFirewall)
$ErrorActionPreference = 'Continue'
$PROD = '@@PROD@@'; $RAIZ = '@@RAIZ@@'; $LANZ = '@@LANZ@@'; $BASE_PROD = '@@BASE_PROD@@'; $CARPETA_CACHE = '@@CACHE@@'
$FW_NOMBRE = '@@FW_NOMBRE@@'; $INSTALADO = '@@FECHA@@'; $VERSION = '@@VERSION@@'
$ARCHIVOS_BASE = @(@@ARCHIVOS_BASE@@)
$DOCS = [Environment]::GetFolderPath('MyDocuments'); if (-not $DOCS) { $DOCS = $HOME }
if (-not $DestinoRespaldo) { $DestinoRespaldo = $DOCS }
$REG = New-Object System.Collections.ArrayList
function Ok([string]$t) { Write-Host "  OK  $t" -ForegroundColor Green; [void]$REG.Add("OK  $t") }
function Av([string]$t) { Write-Host "  !!  $t" -ForegroundColor Yellow; [void]$REG.Add("AV  $t") }
function Er([string]$t) { Write-Host "  XX  $t" -ForegroundColor Red; [void]$REG.Add("ER  $t") }
function Preguntar([string]$t, [bool]$porDefectoSi = $false) {
  if ($Si) { return $true }
  $r = Read-Host ($t + $(if ($porDefectoSi) { ' [S/n]' } else { ' [s/N]' }))
  if ($porDefectoSi) { return ($r -notmatch '^[nN]$') } else { return ($r -match '^[sS]$') }
}
function Lanzador([string[]]$argumentos) {
  if (-not (Test-Path $LANZ)) { return 1 }
  Push-Location $RAIZ; try { & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $LANZ @argumentos *> $null; return $LASTEXITCODE } catch { return 1 } finally { Pop-Location }
}

Write-Host ''
Write-Host "Desinstalador de $PROD (instalado el $INSTALADO con iProy $VERSION)" -ForegroundColor Cyan
Write-Host "  Carpeta:   $RAIZ"
if (Test-Path (Join-Path $RAIZ 'data')) { try { $mb = [math]::Round((Get-ChildItem (Join-Path $RAIZ 'data') -Recurse -File | Measure-Object Length -Sum).Sum / 1MB, 1); Write-Host "  Datos:     $mb MB en data\ (base de datos, claves, configuración)" } catch { } }
Write-Host "  Accesos:   escritorio y menú Inicio ($PROD)"
if ($FW_NOMBRE) { Write-Host '  Firewall:  el instalador creó una regla; se ofrece quitarla' }
Write-Host "  Caché:     $CARPETA_CACHE $(if ($Cache) { '(se borra)' } else { '(se conserva: sirve para reinstalar sin volver a bajar; -Cache para borrarla)' })"
Write-Host ''
if (-not (Test-Path $RAIZ)) { Er "La carpeta $RAIZ ya no existe: se quitan solo los accesos directos." }
if (-not (Preguntar "¿Desinstalar ${PROD}? Se va a borrar la carpeta de arriba")) { Write-Host 'Cancelado. No se tocó nada.'; exit 0 }

Write-Host "`n1) Detener"
if (Test-Path $LANZ) { if ((Lanzador @('stop')) -eq 0) { Ok 'Nodo detenido' } else { Av 'El lanzador no pudo detener el nodo (quizá ya estaba parado)' } }
foreach ($p in (Get-ChildItem (Join-Path $RAIZ 'data\run') -Filter '*.pid' -ErrorAction SilentlyContinue)) {
  try { $pid2 = [int](Get-Content $p.FullName -ErrorAction Stop); $pr = Get-Process -Id $pid2 -ErrorAction Stop
    if ($pr.Path -and $pr.Path.StartsWith($RAIZ, [StringComparison]::OrdinalIgnoreCase)) { Stop-Process -Id $pid2 -Force; Av "Proceso $($p.BaseName) ($pid2) terminado a la fuerza" } } catch { }
}
Start-Sleep -Seconds 1

Write-Host '2) Datos'
$datos = Join-Path $RAIZ 'data'
if (-not (Test-Path $datos)) { Av 'No hay carpeta data\ para guardar' }
elseif ($SinDatos) { Av 'Los datos NO se guardan (-SinDatos)' }
elseif (-not (Preguntar '¿Guardo una copia de los datos antes de borrar? (recomendado)' $true)) { Av 'Los datos NO se guardan por tu elección' }
else {
  try {
    New-Item -ItemType Directory -Force -Path $DestinoRespaldo | Out-Null
    $zip = Join-Path $DestinoRespaldo ("{0}_datos_{1}.zip" -f $PROD, (Get-Date -Format 'yyyy-MM-dd_HHmm'))
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [IO.Compression.ZipFile]::CreateFromDirectory($datos, $zip, [IO.Compression.CompressionLevel]::Optimal, $true)
    $z = [IO.Compression.ZipFile]::OpenRead($zip); $n = $z.Entries.Count; $z.Dispose()
    $h = (Get-FileHash $zip -Algorithm SHA256).Hash.ToLower(); [IO.File]::WriteAllText("$zip.sha256", "$h  $(Split-Path -Leaf $zip)`r`n")
    Ok "Datos guardados en $zip ($n archivos, $([math]::Round((Get-Item $zip).Length / 1MB, 1)) MB) · SHA-256 en $zip.sha256"
    Write-Host "     Para volver a usarlos: instalá $PROD de nuevo, detenelo, reemplazá su carpeta data\ por la de esta copia e inicialo."
  } catch { Er "No se pudo guardar la copia de los datos: no sigo (nada se borró). $($_.Exception.Message)"; Read-Host 'Enter para cerrar' | Out-Null; exit 1 }
}

Write-Host '3) Accesos directos'
Lanzador @('acceso-directo', '--quitar') | Out-Null
$esc = [Environment]::GetFolderPath('Desktop'); if (-not $esc) { $esc = Join-Path $HOME 'Desktop' }
$progs = [Environment]::GetFolderPath('Programs'); if (-not $progs) { $progs = Join-Path $HOME 'Programs' }; $menu = Join-Path $progs $PROD
Remove-Item (Join-Path $esc "$PROD.lnk") -ErrorAction SilentlyContinue; if (Test-Path $menu) { Remove-Item $menu -Recurse -Force -ErrorAction SilentlyContinue }
Ok 'Accesos directos quitados (escritorio y menú Inicio)'

Write-Host '4) Firewall'
if (-not $FW_NOMBRE) { Ok 'Firewall: el instalador no creó reglas' }
elseif ($ConservarFirewall) { Av 'Firewall: la regla queda por tu elección (-ConservarFirewall)' }
else {
  $cmd = "Remove-NetFirewallRule -DisplayName '$FW_NOMBRE' -ErrorAction SilentlyContinue; exit 0"
  Write-Host "  El instalador creó la regla «$FW_NOMBRE». Quitarla necesita permiso de administrador (ventana de Windows)."
  if (Preguntar '¿Quito la regla ahora?' $true) {
    try { $p = Start-Process powershell.exe -Verb RunAs -Wait -PassThru -WindowStyle Hidden -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-Command', $cmd)
      if ($p.ExitCode -eq 0) { Ok 'Regla del firewall quitada' } else { Av "No se quitó la regla: en PowerShell como administrador: $cmd" } }
    catch { Av "Permiso cancelado. Para quitarla después, en PowerShell como administrador: $cmd" }
  } else { Av "Firewall: la regla queda; para quitarla: $cmd" }
}

Write-Host '5) Borrar'
$borrado = $false
if (Test-Path $RAIZ) {
  if ($RAIZ -in @($HOME, 'C:\', $DOCS) -or $RAIZ.Length -lt 8) { Er "Ruta sospechosa, no se borra: $RAIZ" }
  else {
    Set-Location $HOME
    for ($k = 1; $k -le 3; $k++) { Remove-Item $RAIZ -Recurse -Force -ErrorAction SilentlyContinue; if (-not (Test-Path $RAIZ)) { break }; Start-Sleep -Seconds 2 }
    if (Test-Path $RAIZ) { Er "No se pudo borrar $RAIZ por completo (algún archivo en uso: cerrá los programas que lo usen y borrala a mano)" } else { Ok "Carpeta borrada: $RAIZ"; $borrado = $true }
  }
}
if ($borrado -and $BASE_PROD -and (Test-Path $BASE_PROD)) {
  $resto = @(Get-ChildItem $BASE_PROD -Force | Where-Object { $n = $_.Name; -not (($ARCHIVOS_BASE -contains $n) -or $n -like 'informe_instalacion_*' -or $n -like 'uninstall-*') })
  if ($resto.Count -eq 0) { Remove-Item $BASE_PROD -Recurse -Force -ErrorAction SilentlyContinue; if (-not (Test-Path $BASE_PROD)) { Ok "Carpeta borrada: $BASE_PROD" } } else { Av "Se conserva $BASE_PROD porque tiene otros archivos tuyos" }
}
if ($Cache) { if (Test-Path $CARPETA_CACHE) { Remove-Item $CARPETA_CACHE -Recurse -Force -ErrorAction SilentlyContinue; Ok "Caché del instalador borrada ($CARPETA_CACHE)" } else { Av 'No había caché para borrar' } }

$archivoReg = Join-Path $DOCS ("desinstalacion_{0}_{1}.log" -f $PROD, (Get-Date -Format 'yyyy-MM-dd_HHmm'))
$txt = @("Desinstalación de $PROD · $(Get-Date -Format 'yyyy-MM-dd HH:mm') · instalado el $INSTALADO (iProy $VERSION) en $RAIZ") + @($REG) + @('No se deshacen: Visual C++ 2015-2022 ni la sincronización de hora, porque los usan otros programas.')
[IO.File]::WriteAllLines($archivoReg, $txt, (New-Object Text.UTF8Encoding $false))
Write-Host "`nListo. Registro: $archivoReg"
Write-Host 'Para instalar de nuevo: https://iproy.com.ar/pim/instalar'
if (-not $Si) { Read-Host 'Enter para cerrar' | Out-Null }
