# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
#
# Instalador gráfico de iProy para Windows 10/11 (PowerShell 5.1 o superior).
# Un servidor web local (solo 127.0.0.1, con token) muestra la interfaz en el navegador y la instalación corre de fondo,
# con barra de avance, pedido de permiso de administrador (UAC) solo cuando hace falta e informe final.
#
# Aprendido de la primera instalación real en Windows (Paso 114, L-395 a L-397):
#  - no hacen falta Git, PHP ni winget: el código se baja como ZIP y la incubadora corre con el PHP de FrankenPHP;
#  - se instala con TU usuario (el icono queda en tu escritorio); Administrador solo para firewall, hora o Visual C++;
#  - cada descarga se verifica con SHA-256; la contraseña nunca se escribe en disco.
#
# Uso: doble clic en Instalar-iProy.bat (o: powershell -ExecutionPolicy Bypass -File instalador.ps1 [-Puerto N] [-NoAbrir])
# Pruebas: $env:IPROY_INST_CODIGO (ZIP o carpeta local), $env:IPROY_INST_CACHE, $env:IPROY_INST_SIMULAR=1 (sin instalar nada)
param([int]$Puerto = 0, [switch]$NoAbrir)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
try { [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 } catch { }

$VERSION = '1.5.1'
$UI_B64 = '__UI_HTML_B64__'   # armar.sh pone acá la interfaz (base64) para distribuir un solo archivo
$AQUI = Split-Path -Parent $MyInvocation.MyCommand.Path
$TOKEN = -join ((1..32) | ForEach-Object { '{0:x}' -f (Get-Random -Maximum 16) })

$S = [hashtable]::Synchronized(@{
    fase = 'espera'; pasos = [System.Collections.ArrayList]::Synchronized((New-Object System.Collections.ArrayList)); progreso = 0; sub = ''
    log = [System.Collections.ArrayList]::Synchronized((New-Object System.Collections.ArrayList)); pregunta = $null; respuesta = $null
    sistema = $null; instalacion = @{}; version_instalador = $VERSION
  })

function EsWindows { return ($env:OS -eq 'Windows_NT') }
function PuertoLibre([int]$n) {
  foreach ($ip in @([Net.IPAddress]::Loopback, [Net.IPAddress]::Any)) {
    $l = New-Object Net.Sockets.TcpListener($ip, $n)
    try { $l.Start(); $l.Stop() } catch { return $false }
  }
  return $true
}
function DesfaseHora {
  try {
    $r = Invoke-WebRequest -UseBasicParsing -Method Head -TimeoutSec 6 'https://iproy.com.ar/'
    $d = [DateTime]::Parse(($r.Headers['Date'] | Select-Object -First 1), [Globalization.CultureInfo]::InvariantCulture).ToUniversalTime()
    return [int][math]::Round(([DateTime]::UtcNow - $d).TotalSeconds)
  } catch { return $null }
}
function HayInternet {
  foreach ($u in 'https://github.com', 'https://archive.mariadb.org', 'https://iproy.com.ar/') {
    try { $null = Invoke-WebRequest -UseBasicParsing -Method Head -TimeoutSec 6 $u; return $true } catch { }
  }
  return $false
}
function VerCmd([string]$exe, [string[]]$arg) {
  try { $c = Get-Command $exe -ErrorAction Stop; return ((& $c.Source @arg 2>$null) | Select-Object -First 1) } catch { return '' }
}
function Sistema {
  $si = [ordered]@{ so = 'windows'; extras = @(); puertos_ocupados = @() }
  $win = EsWindows
  try { $os = Get-CimInstance Win32_OperatingSystem; $si.so_nombre = "$($os.Caption) (compilación $($os.BuildNumber))"; $si.build = [int]$os.BuildNumber
        $si.ram_gb = [math]::Round($os.TotalVisibleMemorySize / 1MB, 1) } catch { $si.so_nombre = [Environment]::OSVersion.VersionString; $si.build = 0; $si.ram_gb = 0 }
  $si.arq = $(if ($env:PROCESSOR_ARCHITECTURE) { $env:PROCESSOR_ARCHITECTURE } else { [Runtime.InteropServices.RuntimeInformation]::OSArchitecture.ToString() })
  $si.arq_ok = ($si.arq -in @('AMD64', 'X64'))
  $si.usuario = [Environment]::UserName
  $si.es_admin = $false
  if ($win) { try { $si.es_admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator) } catch { } }
  try { $d = New-Object IO.DriveInfo ([IO.Path]::GetPathRoot($HOME)); $si.disco_libre_gb = [math]::Round($d.AvailableFreeSpace / 1GB, 1) } catch { $si.disco_libre_gb = 0 }
  $si.motor = "PowerShell $($PSVersionTable.PSVersion)"
  $si.git = (VerCmd 'git' @('--version')) -replace 'git version ', ''
  $si.php = VerCmd 'php' @('-r', 'echo PHP_VERSION;')
  $si.internet = HayInternet
  $si.hora_desfase = DesfaseHora
  $si.vcredist = $(if ($win) { Test-Path "$env:WINDIR\System32\vcruntime140.dll" } else { $true })
  $si.destino_sugerido = Join-Path $HOME 'Proyectos\pim'
  foreach ($p in 8095, 3407) { if (-not (PuertoLibre $p)) { $si.puertos_ocupados += $p } }
  if ($win) { try { $pol = Get-ExecutionPolicy -Scope MachinePolicy; if ($pol -in @('Restricted', 'AllSigned')) { $si.extras += @{ titulo = 'Política de scripts del equipo'; texto = "La política de grupo ($pol) puede bloquear el sistema. Si algo no arranca, pedile a TI que permita scripts firmados localmente." } } } catch { } }
  if (-not $si.vcredist) { $si.extras += @{ titulo = 'Visual C++ 2015-2022'; texto = 'No está instalado en Windows. El sistema usa una copia propia; si hiciera falta, el instalador ofrece instalarlo (pide permiso de administrador).' } }
  if (-not $si.arq_ok) { $si.bloqueante = "El nodo necesita Windows de 64 bits (x64); este equipo es $($si.arq)." }
  elseif ($win -and $si.build -gt 0 -and $si.build -lt 10240) { $si.bloqueante = 'Hace falta Windows 10 u 11.' }
  elseif ($PSVersionTable.PSVersion.Major -lt 5) { $si.bloqueante = 'Hace falta PowerShell 5.1 o superior (viene con Windows 10).' }
  elseif ($si.disco_libre_gb -lt 3) { $si.bloqueante = "Quedan $($si.disco_libre_gb) GB libres; hacen falta al menos 3 GB." }
  return $si
}

# ------------------------------------------------------------------ trabajo de fondo (runspace aparte)
$TRABAJO = {
  param($S, $O)
  $ErrorActionPreference = 'Stop'; $ProgressPreference = 'SilentlyContinue'
  try { [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 } catch { }
  $FRANKEN = @{ url = 'https://github.com/php/frankenphp/releases/download/v1.13.3/frankenphp-windows-x86_64.zip'; sha = '89b99aed53fbf8bfe718a816a987001d499bf92e2e1288e882285a8d940ba00e'; bytes = 60415937; version = '1.13.3' }
  $MARIADB = @{ url = 'https://archive.mariadb.org/mariadb-11.4.13/winx64-packages/mariadb-11.4.13-winx64.zip'; archivo = 'mariadb-11.4.13-winx64.zip'
                sha = 'd62986d433eeebfde218560b276103831604a61e929e87f1a17f5aebd80257e2'; bytes = 0; version = '11.4.13' }
  $TAG = 'v' + $S.version_instalador
  $URL_CODIGO = "https://github.com/iproy-ar/iproy/archive/refs/tags/$TAG.zip"
  $CACHE = $(if ($env:IPROY_INST_CACHE) { $env:IPROY_INST_CACHE } else { Join-Path $env:LOCALAPPDATA 'iproy-instalador' })
  $SIMULAR = [bool]$env:IPROY_INST_SIMULAR
  $PESO = @{ carpeta = 2; runtime = 14; codigo = 6; producto = 6; mariadb = 30; librerias = 4; init = 10; red = 3; start = 12; usuario = 4; hora = 3; verificar = 6 }
  $TOTAL = 0; foreach ($v in $PESO.Values) { $TOTAL += $v }
  Add-Type -AssemblyName System.IO.Compression.FileSystem

  function Log([string]$t) { foreach ($l in ($t -split "`r?`n")) { [void]$S.log.Add((Get-Date -Format 'HH:mm:ss ') + $l) }; while ($S.log.Count -gt 400) { $S.log.RemoveAt(0) } }
  function Hecho { $h = 0; foreach ($p in $S.pasos) { if ($p.estado -in @('ok', 'aviso', 'saltado', 'error')) { $h += $PESO[$p.id] } }; return $h }
  function Paso([string]$id, [string]$estado, [string]$detalle) {
    foreach ($p in $S.pasos) { if ($p.id -eq $id) {
        if ($estado -eq 'en_curso') { $p._t0 = Get-Date }
        elseif ($estado -and $p._t0) { $p.segundos = [int]((Get-Date) - $p._t0).TotalSeconds }
        if ($estado) { $p.estado = $estado }; if ($null -ne $detalle) { $p.detalle = $detalle } } }
    $S.progreso = [math]::Round(100 * (Hecho) / $TOTAL, 1)
  }
  function Sub([string]$t, $frac, [string]$id) {
    $S.sub = $t
    if ($null -ne $frac -and $id) { $f = [math]::Max(0, [math]::Min(1, $frac)); $S.progreso = [math]::Round(100 * ((Hecho) + $PESO[$id] * $f) / $TOTAL, 1) }
  }
  function Preguntar([string]$titulo, [string]$texto, [string]$comando) {
    $id = [guid]::NewGuid().ToString('N').Substring(0, 8)
    $S.respuesta = $null
    $S.pregunta = @{ id = $id; titulo = $titulo; texto = $texto; comando = $comando; manual = $false; puede_elevar = $true }
    while ($true) { Start-Sleep -Milliseconds 300; $r = $S.respuesta; if ($r -and $r.id -eq $id) { $S.pregunta = $null; return $r.respuesta } }
  }
  function Elevar([string]$motivo, [string]$comando) {
    # Ventana de Control de cuentas de usuario (UAC): Windows pide confirmar o la contraseña de un administrador
    $r = Preguntar 'Hace falta permiso de administrador' ($motivo + ' Al tocar «Autorizar», Windows muestra su ventana de permiso.') $comando
    if ($r -ne 'autorizar') { return 'saltado' }
    if ($SIMULAR) { Log "(simulado) elevado: $comando"; return 'ok' }
    try {
      $p = Start-Process powershell.exe -Verb RunAs -Wait -PassThru -WindowStyle Hidden -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-Command', $comando)
      Log "permiso de administrador: código $($p.ExitCode)"
      if ($p.ExitCode -eq 0) { return 'ok' } else { return 'error' }
    } catch { Log "permiso de administrador cancelado: $($_.Exception.Message)"; return 'saltado' }
  }
  function Sha([string]$f) { return (Get-FileHash $f -Algorithm SHA256).Hash.ToLower() }
  function Bajar([string]$url, [string]$destino, [string]$sha, [string]$id, [string]$nombre, [long]$total) {
    New-Item -ItemType Directory -Force -Path $CACHE | Out-Null
    $cache = Join-Path $CACHE ([IO.Path]::GetFileName(([Uri]$url).AbsolutePath))
    if ((Test-Path $cache) -and $sha -and ((Sha $cache) -eq $sha)) { Log "${nombre}: ya estaba bajado y verificado ($($sha.Substring(0,16)))" }
    else {
      $tmp = "$cache.part"
      for ($i = 1; $i -le 3; $i++) {
        try {
          $req = [Net.HttpWebRequest]::Create($url); $req.UserAgent = "iproy-instalador/$($S.version_instalador)"; $req.Timeout = 60000
          $px = [Net.WebRequest]::GetSystemWebProxy(); $px.Credentials = [Net.CredentialCache]::DefaultCredentials; $req.Proxy = $px
          $res = $req.GetResponse(); if ($res.ContentLength -gt 0) { $total = $res.ContentLength }
          $in = $res.GetResponseStream(); $out = [IO.File]::Create($tmp); $buf = New-Object byte[] 262144; $n = 0; $t0 = Get-Date; $ult = Get-Date
          try {
            while (($k = $in.Read($buf, 0, $buf.Length)) -gt 0) {
              $out.Write($buf, 0, $k); $n += $k
              if (((Get-Date) - $ult).TotalMilliseconds -gt 400) {
                $ult = Get-Date; $v = $n / [math]::Max(0.1, ((Get-Date) - $t0).TotalSeconds) / 1MB
                if ($total -gt 0) { Sub ("{0}: {1:N0} de {2:N0} MB · {3:N1} MB/s" -f $nombre, ($n / 1MB), ($total / 1MB), $v) ($n / $total) $id } else { Sub ("{0}: {1:N0} MB" -f $nombre, ($n / 1MB)) $null $id }
              }
            }
          } finally { $out.Close(); $in.Close(); $res.Close() }
          break
        } catch { Log "${nombre}: intento $i falló ($($_.Exception.Message))"; if ($i -eq 3) { throw "No se pudo bajar ${nombre}: $($_.Exception.Message)" }; Start-Sleep -Seconds (3 * $i) }
      }
      if ($sha) { $h = Sha $tmp; if ($h -ne $sha) { Remove-Item $tmp -Force; throw "${nombre}: el SHA-256 no coincide ($($h.Substring(0,16))); no se instala" }; Log "${nombre}: SHA-256 verificado ($($sha.Substring(0,16)))" }
      Move-Item -Force $tmp $cache
    }
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $destino) | Out-Null
    if ($cache -ne $destino) { Copy-Item -Force $cache $destino }
    return $destino
  }
  function Correr([string]$ps1, [string[]]$argumentos, [string]$cwd, [hashtable]$envExtra, [string]$secreto) {
    # Ejecuta el lanzador del producto y muestra su salida en el detalle técnico (sin la contraseña)
    Log ("$ " + (Split-Path -Leaf $ps1) + ' ' + ($argumentos -join ' '))
    if ($SIMULAR) { Start-Sleep -Milliseconds 700; Log '  (simulado)'; return @{ codigo = 0; salida = 'rol: AD sin problemas' } }
    $antes = @{}; if ($envExtra) { foreach ($k in $envExtra.Keys) { $antes[$k] = [Environment]::GetEnvironmentVariable($k); [Environment]::SetEnvironmentVariable($k, $envExtra[$k]) } }
    $lineas = New-Object System.Collections.ArrayList
    try {
      Push-Location $cwd
      & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $ps1 @argumentos 2>&1 | ForEach-Object {
        $l = "$_"; if ($secreto) { $l = $l.Replace($secreto, '•••') }; [void]$lineas.Add($l); Log ('  ' + $l); if ($l.Length -gt 140) { $S.sub = $l.Substring(0, 140) } else { $S.sub = $l } }
      $rc = $LASTEXITCODE
    } finally { Pop-Location; if ($envExtra) { foreach ($k in $envExtra.Keys) { [Environment]::SetEnvironmentVariable($k, $antes[$k]) } } }
    return @{ codigo = $rc; salida = ($lineas -join "`n") }
  }
  function Extraer([string]$zip, [string]$dst) { New-Item -ItemType Directory -Force -Path $dst | Out-Null; [IO.Compression.ZipFile]::ExtractToDirectory($zip, $dst) }

  $SIS = $S.sistema; $i = $S.instalacion
  $prod = $(if ($O.tipo -eq 'propio') { $O.nombre } else { 'iProy' })
  $base = [IO.Path]::GetFullPath([Environment]::ExpandEnvironmentVariables($O.destino))
  $raiz = Join-Path (Join-Path $base $prod) "$prod-Portable"
  $lanz = Join-Path $raiz "$prod-Control.ps1"
  $i.producto = $prod; $i.version = $S.version_instalador; $i.carpeta = $raiz; $i.lanzador = $lanz; $i.vista = $O.vista; $i.modo = $O.modo; $i.usuario = $O.usuario
  $i.url = "http://localhost:$($O.puerto)/"; $i.componentes = New-Object System.Collections.ArrayList
  $i.cmd_start = ".\$prod-Control.bat start   (en $raiz)"; $i.cmd_stop = ".\$prod-Control.bat stop"; $i.cmd_status = ".\$prod-Control.bat status"
  $trab = Join-Path $CACHE 'trabajo'; $fpdir = Join-Path $CACHE 'frankenphp'

  $pasosFn = [ordered]@{
    carpeta = {
      if (Test-Path $raiz) { throw "La carpeta $raiz ya existe. Elegí otra carpeta u otro nombre (no se sobrescribe nada)." }
      if ($raiz.Length -gt 120) { throw "La ruta es muy larga ($($raiz.Length) caracteres). Elegí una carpeta más corta, por ejemplo C:\Users\<vos>\pim." }
      New-Item -ItemType Directory -Force -Path $base | Out-Null
      $t = Join-Path $base ('_prueba_' + [guid]::NewGuid().ToString('N').Substring(0, 6)); Set-Content $t 'x'; Remove-Item -Force $t
      return $base }
    runtime = {
      $zip = Join-Path $CACHE 'frankenphp-windows-x86_64.zip'
      if ($SIMULAR) { Start-Sleep 1; Sub 'FrankenPHP: 60 de 60 MB' 1 'runtime' } else {
        Bajar $FRANKEN.url $zip $FRANKEN.sha 'runtime' 'FrankenPHP' $FRANKEN.bytes | Out-Null
        if (Test-Path $fpdir) { Remove-Item -Recurse -Force $fpdir }; Extraer $zip $fpdir }
      [void]$i.componentes.Add(@{ nombre = 'FrankenPHP (servidor web + PHP)'; version = $FRANKEN.version; sha256 = $FRANKEN.sha })
      return "FrankenPHP $($FRANKEN.version) verificado" }
    codigo = {
      if (Test-Path $trab) { Remove-Item -Recurse -Force $trab }; New-Item -ItemType Directory -Force -Path $trab | Out-Null
      $f = $env:IPROY_INST_CODIGO
      if ($f -and (Test-Path $f -PathType Container)) { Copy-Item -Recurse $f (Join-Path $trab 'iproy'); $origen = "carpeta local $f" }
      elseif ($SIMULAR) { New-Item -ItemType Directory -Force -Path (Join-Path $trab 'iproy\nodo') | Out-Null; $origen = '(simulado)' }
      else {
        $z = $(if ($f) { $f } else { Bajar $URL_CODIGO (Join-Path $trab 'iproy.zip') '' 'codigo' "Código iProy $TAG" 0 })
        Extraer $z (Join-Path $trab 'x'); $d = Get-ChildItem (Join-Path $trab 'x') -Directory | Select-Object -First 1
        Move-Item $d.FullName (Join-Path $trab 'iproy'); $origen = "ZIP $(if ($f) { $f } else { $URL_CODIGO })"
        [void]$i.componentes.Add(@{ nombre = 'Código iProy'; version = $TAG; sha256 = (Sha $z) }) }
      if (-not $SIMULAR -and -not (Test-Path (Join-Path $trab 'iproy\nodo\iProy-Control.ps1'))) { throw 'El código bajado no tiene nodo\iProy-Control.ps1' }
      return "desde $origen" }
    producto = {
      $repo = Join-Path $trab 'iproy'
      if ($SIMULAR) { New-Item -ItemType Directory -Force -Path $raiz | Out-Null; Set-Content $lanz '# simulado'; return "$prod (simulado)" }
      if ($O.tipo -eq 'propio') {
        # La incubadora corre con el PHP de FrankenPHP: no hace falta instalar PHP ni activar extensiones (L-395)
        $ini = Join-Path $CACHE 'php-incubar'; New-Item -ItemType Directory -Force -Path $ini | Out-Null
        $ext = (Join-Path $fpdir 'ext') -replace '\\', '/'
        Set-Content -Encoding ASCII (Join-Path $ini 'php.ini') "extension_dir = `"$ext`"`nextension=mbstring`nextension=gd`nextension=openssl`nextension=fileinfo`nmemory_limit=512M`n"
        $env:PHPRC = $ini
        $lin = New-Object System.Collections.ArrayList
        Push-Location $repo
        try { & (Join-Path $fpdir 'frankenphp.exe') php-cli 'incubadora\incubar.php' nuevo --nombre $O.nombre --sigla $O.sigla --color $O.color --plantilla $O.plantilla --destino (Join-Path $base $prod) 2>&1 | ForEach-Object { [void]$lin.Add("$_"); Log "  $_" } }
        finally { Pop-Location; Remove-Item Env:PHPRC -ErrorAction SilentlyContinue }
        if (($lin -join "`n") -notmatch 'sin problemas') { throw 'La incubadora no terminó bien (ver el detalle técnico)' }
        $det = "$($O.nombre) ($($O.sigla)) incubado con la plantilla $($O.plantilla)"
      } else { Copy-Item -Recurse (Join-Path $repo 'nodo') $raiz; $det = 'iProy base' }
      if (-not (Test-Path $lanz)) { throw "No se encontró el lanzador $(Split-Path -Leaf $lanz)" }
      $rt = Join-Path $raiz 'runtime\win-x64\frankenphp'; New-Item -ItemType Directory -Force -Path $rt | Out-Null
      Copy-Item -Recurse -Force (Join-Path $fpdir '*') $rt
      Get-ChildItem -Path $raiz -Recurse -File | Unblock-File -ErrorAction SilentlyContinue   # sin la marca «bajado de Internet»
      return $det }
    mariadb = {
      $desc = Join-Path $raiz 'descargas'; New-Item -ItemType Directory -Force -Path $desc | Out-Null
      if ($SIMULAR) { for ($k = 1; $k -le 10; $k++) { Sub "MariaDB: $($k * 9) de 90 MB (simulado)" ($k / 10) 'mariadb'; Start-Sleep -Milliseconds 300 } }
      else {
        Bajar $MARIADB.url (Join-Path $desc $MARIADB.archivo) $MARIADB.sha 'mariadb' 'MariaDB' $MARIADB.bytes | Out-Null
        Set-Content -Encoding ASCII (Join-Path $desc 'sha256sums.txt') "$($MARIADB.sha)  $($MARIADB.archivo)" }
      Sub 'Instalando MariaDB (se recorta a lo necesario)…' 0.9 'mariadb'
      $r = Correr $lanz @('instalar-mariadb') $raiz $null ''
      [void]$i.componentes.Add(@{ nombre = 'MariaDB'; version = $MARIADB.version; sha256 = $MARIADB.sha })
      if ($r.codigo -ne 0) { throw 'instalar-mariadb falló (ver el detalle técnico)' }
      if (-not $SIMULAR) { Remove-Item -Force (Join-Path $desc $MARIADB.archivo) -ErrorAction SilentlyContinue }   # queda en la caché del instalador
      return "MariaDB $($MARIADB.version) instalado y recortado" }
    librerias = {
      $r = Correr $lanz @('diagnostico') $raiz $null ''
      $faltaVc = ($r.salida -match 'X\s+DLL de Visual C')
      if ($SIMULAR) { $faltaVc = -not $SIS.vcredist }
      if (-not $faltaVc) { if ($r.salida -match '(\d+) para resolver' -and $matches[1] -ne '0') { return @('aviso', "diagnostico: $($matches[0]) (ver el detalle técnico)") }; return 'Diagnóstico sin problemas' }
      $cmd = "`$f=Join-Path `$env:TEMP 'vc_redist.x64.exe'; Invoke-WebRequest -UseBasicParsing https://aka.ms/vs/17/release/vc_redist.x64.exe -OutFile `$f; if ((Get-AuthenticodeSignature `$f).Status -ne 'Valid') { exit 3 }; Start-Process `$f -ArgumentList '/install','/quiet','/norestart' -Wait; exit 0"
      $e = Elevar 'Faltan las bibliotecas de Visual C++ 2015-2022 de Microsoft, que usan el servidor y la base de datos. Se bajan del sitio de Microsoft y se instalan sin reiniciar.' $cmd
      if ($e -eq 'ok') { return 'Visual C++ 2015-2022 instalado' } else { return @('aviso', 'Visual C++ sin instalar: https://aka.ms/vs/17/release/vc_redist.x64.exe') } }
    init = {
      $r = Correr $lanz @('init', '-Nombre', $O.institucion, '-Modo', $O.modo, '-Puerto', "$($O.puerto)", '-Vista', $O.vista) $raiz $null ''
      if ($r.codigo -ne 0) { throw 'init falló (ver el detalle técnico)' }
      return "Nodo creado · vista $($O.vista) · icono en tu escritorio" }
    red = {
      if ($O.modo -ne 'lan') { return @('saltado', 'Solo esta PC: no hace falta abrir puertos') }
      $tls = [int]$O.puerto + 363
      $cmd = "New-NetFirewallRule -DisplayName '$prod (HTTPS $tls)' -Direction Inbound -Protocol TCP -LocalPort $tls -Action Allow -Profile Private | Out-Null"
      $e = Elevar "Para que los celulares y otras PC de la red entren por https://<IP>:$tls/ hay que permitir ese puerto en el firewall de Windows (solo redes privadas)." $cmd
      if ($e -eq 'ok') { return "Puerto $tls permitido en redes privadas" } else { return @('aviso', "Sin permitir el puerto ${tls}: $cmd") } }
    start = {
      $r = Correr $lanz @('start', '-NoAbrir') $raiz $null ''
      if ($r.codigo -ne 0) { throw 'start falló (ver el detalle técnico)' }
      return 'MariaDB, servidor web, worker y residentes en marcha' }
    usuario = {
      $envx = @{ IPROY_CLAVE_NUEVA = $O.clave }; $envx[$prod.ToUpper() + '_CLAVE_NUEVA'] = $O.clave
      $r = Correr $lanz @('usuario', 'alta', $O.usuario, 'AD') $raiz $envx $O.clave
      $O.clave = ''
      if ($r.codigo -ne 0 -or $r.salida -notmatch 'rol"?\s*:\s*"?AD') { throw 'No se pudo crear el usuario (ver el detalle técnico)' }
      return "Usuario «$($O.usuario)» con rol de administrador" }
    hora = {
      $d = $null
      try { $rr = Invoke-WebRequest -UseBasicParsing -Method Head -TimeoutSec 6 'https://iproy.com.ar/'; $d = [int][math]::Round(([DateTime]::UtcNow - [DateTime]::Parse(($rr.Headers['Date'] | Select-Object -First 1), [Globalization.CultureInfo]::InvariantCulture).ToUniversalTime()).TotalSeconds) } catch { }
      $SIS.hora_desfase = $d
      if ($null -eq $d) { return @('aviso', 'Sin referencia para comparar (sin Internet)') }
      if ([math]::Abs($d) -le 60) { return "Correcta (desfase $d s)" }
      $e = Elevar "La hora de la PC está desfasada $d s. Se sincroniza con el servidor de hora de Windows." 'Start-Service w32time -ErrorAction SilentlyContinue; w32tm /resync /force | Out-Null; exit 0'
      if ($e -eq 'ok') { return 'Hora sincronizada' } else { return @('aviso', "Desfase de $d s sin corregir: Configuración → Hora e idioma → «Sincronizar ahora»") } }
    verificar = {
      if ($SIMULAR) { return 'Responde (simulado)' }
      for ($k = 0; $k -lt 20; $k++) { try { $null = Invoke-WebRequest -UseBasicParsing -TimeoutSec 5 ($i.url + 'app/'); $i.url_ok = $true; return "Responde en $($i.url)" } catch { Start-Sleep 2 } }
      return @('error', "No responde en $($i.url)") }
  }
  $criticos = @('carpeta', 'runtime', 'codigo', 'producto', 'mariadb', 'init', 'start')
  foreach ($id in $pasosFn.Keys) {
    Paso $id 'en_curso' $null; $S.sub = ''
    try { $r = & $pasosFn[$id]; if ($r -is [array]) { Paso $id $r[0] $r[1] } else { Paso $id 'ok' "$r" } }
    catch { Log "✘ $($_.Exception.Message)"; Paso $id 'error' $_.Exception.Message
      if ($criticos -contains $id) { foreach ($p in $S.pasos) { if ($p.estado -eq 'pendiente') { $p.estado = 'saltado'; $p.detalle = 'No se hizo porque falló un paso anterior' } }; break } }
  }
  # informe JSON y registro técnico en la carpeta del producto (sin contraseñas)
  try {
    $dir = $(if (Test-Path $raiz) { $raiz } else { $HOME }); $marca = Get-Date -Format 'yyyy-MM-dd_HHmm'
    $pasos = @($S.pasos | ForEach-Object { $h = @{}; foreach ($k in $_.Keys) { if (-not "$k".StartsWith('_')) { $h[$k] = $_[$k] } }; $h })
    $datos = @{ sistema = $S.sistema; instalacion = $S.instalacion; version_instalador = $S.version_instalador; pasos = $pasos }
    [IO.File]::WriteAllText((Join-Path $dir "informe_instalacion_$marca.json"), ($datos | ConvertTo-Json -Depth 6), (New-Object Text.UTF8Encoding $false))
    [IO.File]::WriteAllText((Join-Path $dir "informe_instalacion_$marca.log"), ($S.log -join "`r`n"), (New-Object Text.UTF8Encoding $false))
    $i.carpeta_informe = $dir
  } catch { Log "No se pudo guardar el informe: $($_.Exception.Message)" }
  $S.progreso = 100; $S.sub = ''; $S.fase = 'terminado'
}

# ------------------------------------------------------------------ servidor web local (TcpListener: no necesita permisos)
function Ui {
  if (-not $UI_B64.StartsWith('__')) { return [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($UI_B64)) }
  return [IO.File]::ReadAllText((Join-Path $AQUI 'ui.html'), [Text.Encoding]::UTF8)
}
function Responder($stream, [int]$codigo, [string]$cuerpo, [string]$tipo = 'application/json; charset=utf-8') {
  $b = [Text.Encoding]::UTF8.GetBytes($cuerpo)
  $txt = @{ 200 = 'OK'; 400 = 'Bad Request'; 403 = 'Forbidden'; 404 = 'Not Found'; 409 = 'Conflict'; 422 = 'Unprocessable Entity' }[$codigo]
  $cab = "HTTP/1.1 $codigo $txt`r`nContent-Type: $tipo`r`nContent-Length: $($b.Length)`r`nCache-Control: no-store`r`nConnection: close`r`n" +
         "Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data:`r`n`r`n"
  $h = [Text.Encoding]::ASCII.GetBytes($cab); $stream.Write($h, 0, $h.Length); $stream.Write($b, 0, $b.Length); $stream.Flush()
}
function Json($o) { return ($o | ConvertTo-Json -Depth 6 -Compress) }
function Validar($o) {
  if ($o.tipo -notin @('base', 'propio')) { return 'tipo inválido' }
  if ($o.tipo -eq 'propio') {
    if ($o.nombre -notmatch '^[A-Za-z][A-Za-z0-9]{1,19}$') { return 'Nombre de producto inválido' }
    if ($o.nombre -eq 'iproy') { return 'Para iProy elegí la opción iProy' }
    if ($o.sigla -cnotmatch '^[A-Z]{3}$') { return 'Sigla inválida' }
    if ($o.color -notmatch '^#[0-9a-fA-F]{6}$') { return 'Color inválido' }
    if ($o.plantilla -notin @('gestion-mantenimiento', 'mantenimiento-basico', 'monitoreo-industrial')) { return 'Plantilla inválida' } }
  if ($o.vista -notin @('widgets', 'menu') -or $o.modo -notin @('local', 'lan')) { return 'Vista o modo inválidos' }
  if (-not "$($o.institucion)".Trim() -or "$($o.institucion)".Length -gt 60 -or "$($o.institucion)" -match '["`$\\]') { return 'Nombre de institución inválido (sin comillas ni símbolos $ ` \)' }
  if (-not "$($o.destino)".Trim()) { return 'Falta la carpeta' }
  if (-not ([int]$o.puerto -ge 1024 -and [int]$o.puerto -le 65000)) { return 'Puerto fuera de rango' }
  if ($o.usuario -notmatch '^[A-Za-z0-9._-]{3,30}$') { return 'Usuario inválido' }
  if ("$($o.clave)".Length -lt 10) { return 'La contraseña tiene que tener al menos 10 caracteres' }
  return ''
}
function LeerPedido($cli) {
  $st = $cli.GetStream(); $st.ReadTimeout = 5000
  $ms = New-Object IO.MemoryStream; $buf = New-Object byte[] 65536; $fin = -1
  while ($fin -lt 0) {
    $k = $st.Read($buf, 0, $buf.Length); if ($k -le 0) { break }; $ms.Write($buf, 0, $k)
    $txt = [Text.Encoding]::ASCII.GetString($ms.ToArray()); $fin = $txt.IndexOf("`r`n`r`n")
    if ($ms.Length -gt 6MB) { break } }
  if ($fin -lt 0) { return $null }
  $cab = [Text.Encoding]::ASCII.GetString($ms.ToArray(), 0, $fin) -split "`r`n"
  $partes = $cab[0] -split ' '; $h = @{}
  foreach ($l in $cab[1..($cab.Length - 1)]) { $j = $l.IndexOf(':'); if ($j -gt 0) { $h[$l.Substring(0, $j).Trim().ToLower()] = $l.Substring($j + 1).Trim() } }
  $largo = 0; if ($h['content-length']) { $largo = [int]$h['content-length'] }
  while ($ms.Length - ($fin + 4) -lt $largo) { $k = $st.Read($buf, 0, $buf.Length); if ($k -le 0) { break }; $ms.Write($buf, 0, $k) }
  $cuerpo = ''; if ($largo -gt 0) { $cuerpo = [Text.Encoding]::UTF8.GetString($ms.ToArray(), $fin + 4, $largo) }
  return @{ metodo = $partes[0]; ruta = $partes[1]; cab = $h; cuerpo = $cuerpo; stream = $st }
}
function Estado {
  $pasos = @($S.pasos | ForEach-Object { $h = [ordered]@{}; foreach ($k in $_.Keys) { if (-not "$k".StartsWith('_')) { $h[$k] = $_[$k] } }; $h })
  return [ordered]@{ fase = $S.fase; pasos = $pasos; progreso = $S.progreso; sub = $S.sub; log = @($S.log); pregunta = $S.pregunta; sistema = $S.sistema; instalacion = $S.instalacion; version_instalador = $S.version_instalador }
}
function Atender($p) {
  $st = $p.stream; $ruta = ($p.ruta -split '\?')[0]
  if ($p.metodo -eq 'GET' -and $ruta -eq '/') {
    if ($p.ruta -notmatch "t=$TOKEN") { return Responder $st 403 'Abrí el enlace que mostró el instalador.' 'text/plain; charset=utf-8' }
    return Responder $st 200 (Ui) 'text/html; charset=utf-8' }
  if ($p.cab['x-token'] -ne $TOKEN) { return Responder $st 403 '{"error":"token"}' }
  if ($p.metodo -eq 'GET') {
    switch ($ruta) {
      '/api/sistema' { if (-not $S.sistema) { $S.sistema = Sistema }; return Responder $st 200 (Json $S.sistema) }
      '/api/estado' { return Responder $st 200 (Json (Estado)) }
      '/api/puerto' { $n = 0; if ($p.ruta -match 'n=(\d+)') { $n = [int]$matches[1] }; return Responder $st 200 (Json @{ libre = ($n -ge 1024 -and $n -le 65000 -and (PuertoLibre $n)) }) }
    }
    return Responder $st 404 '{"error":"no existe"}' }
  try { $c = $p.cuerpo | ConvertFrom-Json } catch { return Responder $st 400 '{"error":"JSON inválido"}' }
  switch ($ruta) {
    '/api/iniciar' {
      if ($S.fase -ne 'espera') { return Responder $st 409 '{"error":"La instalación ya empezó"}' }
      $o = @{}; foreach ($k in 'tipo', 'vista', 'modo', 'nombre', 'sigla', 'color', 'plantilla', 'institucion', 'destino', 'puerto', 'usuario', 'clave') { $o[$k] = $c.$k }
      $err = Validar $o; if ($err) { return Responder $st 422 (Json @{ error = $err }) }
      if (-not (PuertoLibre ([int]$o.puerto))) { return Responder $st 422 (Json @{ error = "El puerto $($o.puerto) está ocupado: elegí otro." }) }
      if (-not $S.sistema) { $S.sistema = Sistema }
      $S.fase = 'instalando'
      foreach ($par in @(@('carpeta', 'Preparar la carpeta'), @('runtime', 'Bajar el servidor web (FrankenPHP)'), @('codigo', 'Bajar el código de iProy'), @('producto', 'Armar el producto'),
                         @('mariadb', 'Bajar e instalar la base de datos (MariaDB)'), @('librerias', 'Revisar Windows y Visual C++'), @('init', 'Crear el nodo'), @('red', 'Abrir el acceso desde la red'),
                         @('start', 'Arrancar el sistema'), @('usuario', 'Crear el usuario administrador'), @('hora', 'Revisar la hora de la PC'), @('verificar', 'Comprobar que responde'))) {
        [void]$S.pasos.Add([hashtable]::Synchronized(@{ id = $par[0]; titulo = $par[1]; estado = 'pendiente'; detalle = '' })) }
      $rs = [runspacefactory]::CreateRunspace(); $rs.ApartmentState = 'STA'; $rs.Open()
      $ps = [powershell]::Create(); $ps.Runspace = $rs
      [void]$ps.AddScript($TRABAJO).AddArgument($S).AddArgument($o)
      $script:tarea = @{ ps = $ps; h = $ps.BeginInvoke() }
      return Responder $st 200 '{"ok":true}' }
    '/api/responder' { $S.respuesta = @{ id = $c.id; respuesta = $c.respuesta }; return Responder $st 200 '{"ok":true}' }
    '/api/informe' {
      try { $d = $S.instalacion.carpeta_informe; if ($d) { [IO.File]::WriteAllText((Join-Path $d ("informe_instalacion_{0}.html" -f (Get-Date -Format 'yyyy-MM-dd_HHmm'))), "$($c.html)", (New-Object Text.UTF8Encoding $false)) } } catch { }
      return Responder $st 200 '{"ok":true}' }
  }
  return Responder $st 404 '{"error":"no existe"}'
}

# ------------------------------------------------------------------ arranque
$l = New-Object Net.Sockets.TcpListener([Net.IPAddress]::Loopback, $Puerto); $l.Start()
$url = "http://127.0.0.1:$(($l.LocalEndpoint).Port)/?t=$TOKEN"
$Host.UI.RawUI.WindowTitle = "Instalador de iProy $VERSION"
Write-Host "Instalador de iProy $VERSION" -ForegroundColor Cyan
Write-Host "Se abre en el navegador. Si no se abrió, copiá esta dirección:`n  $url"
Write-Host 'Dejá esta ventana abierta hasta que termine. Para salir, cerrala o Ctrl+C.'
if (-not $NoAbrir) { try { Start-Process $url } catch { } }
$S.sistema = Sistema
$script:tarea = $null
while ($true) {
  if ($l.Pending()) {
    $cli = $l.AcceptTcpClient()
    try { $p = LeerPedido $cli; if ($p) { Atender $p } } catch { try { Write-Host "pedido con error: $($_.Exception.Message)" } catch { } } finally { $cli.Close() }
  } else {
    Start-Sleep -Milliseconds 40
    if ($script:tarea -and $script:tarea.h.IsCompleted -and -not $script:tarea.fin) {
      $script:tarea.fin = $true
      try { $script:tarea.ps.EndInvoke($script:tarea.h) } catch { [void]$S.log.Add("✘ error inesperado: $($_.Exception.Message)"); $S.fase = 'error' }
      foreach ($e in $script:tarea.ps.Streams.Error) { [void]$S.log.Add("✘ $e") }
      $inf = $script:tarea.ps.InvocationStateInfo; if ($inf.Reason) { [void]$S.log.Add("✘ $($inf.State): $($inf.Reason.Message)") }
      if ($S.fase -eq 'instalando') { $S.fase = 'error' }
    }
  }
}
