# =====================================================================
#  iProy-Control · iProy Portable «Colmena» · v1.1.2 · 03-11-2026 (núcleo modular + colmena + Servidor PIM alineado con IHE)
#  Lanzador del nodo para Windows 10/11 x64. Compatible con Windows PowerShell 5.1.
#  No requiere administrador. Uso (o doble clic en iProy-Control.bat):
#    iProy-Control.bat init [-Nombre "Pañol Central"] [-Modo local|lan] [-Puerto 8080]
#    iProy-Control.bat abrir | acceso-directo [--quitar] | diagnostico | instalar-mariadb | start | stop | status | importar-app | importar-datos | respaldo | verificar
#    iProy-Control.bat fhir-cliente alta <id> <jwks.json> ["system/*.read"] | baja <id> | lista | demo | token <id> <clave.xml>
#    iProy-Control.bat pim importar | pim cliente alta <id> <jwks.json> <GM|EJ|SO|FT|CI|RE> [organización] [alcances] | pim cliente baja <id> | pim cliente lista
#    iProy-Control.bat pim token <id> <clave.xml> [alcances] | pim prueba | pim verificar | pim consola      (Consola PIM: http://localhost:8080/pim/)
#    iProy-Control.bat red [estado] | red modo local|lan · precargar · nombre "PC Taller" · clave-auth [--borrar]   (panel: http://localhost:8080/_iproy/red.php)
#    iProy-Control.bat nucleo estado|arranque|tick|tareas|diario|verificar · modulo activar|desactivar|config <id> · usuario alta <u> <rol> | lista
#    iProy-Control.bat colmena identidad|lista|vincular <url|ID>|aceptar <par> [rol]|revocar|ping|via|rpc <par> GET <ruta>|hub <url> [código]   (app: http://localhost:8080/app/)
# =====================================================================
param(
  [Parameter(Position = 0)][string]$Comando = 'ayuda',
  [Parameter(Position = 1)][string]$SoloBase = '',   # importar-datos <base>: importa solo esa base · fhir-cliente <acción>
  [Parameter(Position = 2)][string]$P2 = '',         # fhir-cliente: argumentos (nombres distintos de $args, L-305)
  [Parameter(Position = 3)][string]$P3 = '',
  [Parameter(Position = 4)][string]$P4 = '',
  [Parameter(Position = 5)][string]$P5 = '',
  [Parameter(Position = 6)][string]$P6 = '',
  [Parameter(Position = 7)][string]$P7 = '',
  [string]$Nombre = 'Nodo IPROY',
  [ValidateSet('local', 'lan')][string]$Modo = 'local',
  [int]$Puerto = 8080,
  [switch]$NoAbrir
)
$script:NombreDado = $PSBoundParameters.ContainsKey('Nombre')
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'   # en 5.1 la barra de progreso vuelve lentísimos Invoke-WebRequest y Expand-Archive
$VER = '1.1.2'
$RAIZ = Split-Path -Parent $MyInvocation.MyCommand.Path
$RT = Join-Path $RAIZ 'runtime\win-x64'
$FPDIR = Join-Path $RT 'frankenphp'
$FP = Join-Path $FPDIR 'frankenphp.exe'
$MDB = if ($env:IPROY_MARIADB_DIR) { $env:IPROY_MARIADB_DIR } else { Join-Path $RT 'mariadb' }
$DATA = Join-Path $RAIZ 'data'
$APP = Join-Path $RAIZ 'app'
$RUN = Join-Path $DATA 'run'
$ALFABETO = '23456789ABCDEFGHJKMNPQRSTUVWXYZ'
$BASES = @('legado_iproy', 'legado_requerimientos', 'legado_stock_mant', 'legado_iot', 'legado_user_gestion', 'legado_analiticas', 'legado_preventivos', 'iproy')
$UTF8 = New-Object System.Text.UTF8Encoding($false)   # sin BOM: un BOM antes de <?php rompe las cabeceras

function Log($m) { Write-Host ("[IPROY {0}] {1}" -f (Get-Date -Format 'HH:mm:ss'), $m) }
function Fallo($m) { Write-Host "[IPROY] X $m" -ForegroundColor Red; exit 1 }
function Escribir($ruta, $texto) { [IO.File]::WriteAllText($ruta, $texto, $UTF8) }
function Barra($p) { return ($p -replace '\\', '/') }
function Aleatorio([int]$n = 32) {
  $rng = [Security.Cryptography.RandomNumberGenerator]::Create(); $b = New-Object byte[] 64; $rng.GetBytes($b)
  $s = ([Convert]::ToBase64String($b) -replace '[^A-Za-z0-9]', ''); return $s.Substring(0, $n)
}
function NodoId {
  $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
  do { $b = New-Object byte[] 3; $rng.GetBytes($b); $id = -join ($b | ForEach-Object { $ALFABETO[$_ % 31] }) } while ($id -eq 'HUB' -or $id -eq 'IPROY')
  return $id
}
function ProtegerDir($ruta) {   # carpeta: lo que se cree adentro hereda el permiso exclusivo
  $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
  if ((Nativo 'icacls.exe' @($ruta, '/inheritance:r', '/grant:r', "*$($sid):(OI)(CI)(F)")) -ne 0) { Log "aviso: no se pudieron restringir los permisos de $ruta (¿pendrive FAT32/exFAT?)" }
}
function Proteger($ruta) {   # por SID: sirve con usuarios locales, de dominio o con espacios; en FAT32/exFAT avisa y sigue
  $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
  if ((Nativo 'icacls.exe' @($ruta, '/inheritance:r', '/grant:r', "*$($sid):(F)")) -ne 0) { Log "aviso: no se pudieron restringir los permisos de $ruta (¿pendrive FAT32/exFAT?)" }
}
function LeerEnv($archivo) {
  $h = @{}; foreach ($l in Get-Content -Encoding UTF8 $archivo) { if ($l -match '^\s*([A-Z_]+)=(.*)$') { $h[$Matches[1]] = $Matches[2].Trim('"') } }; return $h
}
function CargarEnv {
  if (-not (Test-Path "$DATA\iproy.env")) { Fallo 'falta data\iproy.env: ejecutá primero «init»' }
  $script:E = LeerEnv "$DATA\iproy.env"; $s = LeerEnv "$DATA\secrets.env"; foreach ($k in $s.Keys) { $script:E[$k] = $s[$k] }
  foreach ($k in $script:E.Keys) { [Environment]::SetEnvironmentVariable($k, $script:E[$k], 'Process') }
  $env:IPROY_APP = Barra $APP; $env:IPROY_DATA = Barra $DATA; $env:PHPRC = Join-Path $DATA 'php'
}
function Bin($n) { $p = Join-Path $MDB "bin\$n.exe"; if (-not (Test-Path $p)) { Fallo "no está $n.exe en $MDB\bin" }; return $p }
# Ejecutables nativos: en 5.1, con $ErrorActionPreference='Stop', cualquier línea en stderr de un .exe
# redirigido corta el script (PowerShell#3996). Se los llama siempre por acá (L-298).
function Nativo([string]$Exe, [string[]]$Arg, [string]$Log, [switch]$Append) {
  $eap = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
  try { if ($Log) { if ($Append) { & $Exe @Arg *>> $Log } else { & $Exe @Arg *> $Log } } else { & $Exe @Arg *> $null } }
  finally { $ErrorActionPreference = $eap }
  return $LASTEXITCODE
}
function Cliente([string]$base, [string]$sql, [switch]$Tolerante) {
  # -Tolerante: sigue ante errores (--force) y devuelve $true/$false en vez de cortar el script
  # El SQL va como bytes UTF-8 por la entrada estándar del proceso: «$sql | mariadb.exe» en 5.1 lo pasaría
  # por $OutputEncoding (ASCII) y cambiaría ñ, tildes y · por «?» (L-297).
  $a = @('--no-defaults', '--host=127.0.0.1', "--port=$($E.IPROY_DB_PORT)", '-uroot', "-p$($E.IPROY_DB_ROOT_PASS)", '--default-character-set=utf8mb4', '--binary-mode')
  if ($Tolerante) { $a += '--force' }
  if ($base) { $a += $base }
  $psi = New-Object Diagnostics.ProcessStartInfo((Bin 'mariadb'), ($a -join ' '))
  $psi.UseShellExecute = $false; $psi.RedirectStandardInput = $true; $psi.RedirectStandardError = $true; $psi.CreateNoWindow = $true
  $p = [Diagnostics.Process]::Start($psi); $err = $p.StandardError.ReadToEndAsync()
  $by = $UTF8.GetBytes($sql)
  try { $p.StandardInput.BaseStream.Write($by, 0, $by.Length); $p.StandardInput.Close() } catch { Log 'aviso: mariadb cortó la entrada (error de SQL; ver abajo)' }
  $p.WaitForExit()
  if ($p.ExitCode -ne 0) {
    $err.Result | Out-File -Append -Encoding utf8 "$DATA\logs\sql_error.log"
    if ($Tolerante) { return $false }
    Write-Host $err.Result; Fallo "error de SQL en $base"
  }
  if ($Tolerante) { return $true }
}
function Vivo($n) {
  # además del PID se controla el nombre del proceso: un PID viejo (o de otra PC) no se confunde con el nuestro
  $f = "$RUN\$n.pid"; if (-not (Test-Path $f)) { return $false }
  $id = 0; if (-not [int]::TryParse("$(Get-Content $f -TotalCount 1)".Trim(), [ref]$id)) { return $false }
  $p = Get-Process -Id $id -ErrorAction SilentlyContinue
  return [bool]($p -and $p.ProcessName -eq @{ mariadb = 'mariadbd'; frankenphp = 'frankenphp'; worker = 'powershell'; residente = 'frankenphp'; residente_mem = 'frankenphp' }[$n])
}
function Ping { try { (Invoke-WebRequest -UseBasicParsing -TimeoutSec 3 "http://127.0.0.1:$($E.IPROY_PUERTO)/_iproy/ping.php").StatusCode -eq 200 } catch { $false } }

# ---------------------------------------------------------------- API FHIR de interoperabilidad (v0.3)
function Hexa([int]$n = 32) {
  $rng = [Security.Cryptography.RandomNumberGenerator]::Create(); $b = New-Object byte[] $n; $rng.GetBytes($b)
  return -join ($b | ForEach-Object { $_.ToString('x2') })
}
function B64Url([byte[]]$b) { return ([Convert]::ToBase64String($b)).TrimEnd('=').Replace('+', '-').Replace('/', '_') }

# Usuario de base SOLO LECTURA sobre las vistas iproy_fhir.* (sin datos personales) + clave propia de tokens.
# Corre en cada start: actualiza solos los nodos v0.2 y recrea las vistas si se reimportaron datos.
function AsegurarFhir {
  foreach ($d in "$DATA\fhir\clientes", "$DATA\fhir\run") { New-Item -ItemType Directory -Force -Path $d | Out-Null }
  $sec = [IO.File]::ReadAllText("$DATA\secrets.env")
  if ($sec -notmatch '(?m)^IPROY_FHIR_DB_PASS=') { [IO.File]::AppendAllText("$DATA\secrets.env", "IPROY_FHIR_DB_PASS=$(Aleatorio 32)`n", $UTF8) }
  if ($sec -notmatch '(?m)^IPROY_FHIR_TOKEN_KEY=') { [IO.File]::AppendAllText("$DATA\secrets.env", "IPROY_FHIR_TOKEN_KEY=$(Hexa 32)`n", $UTF8) }
  CargarEnv
  if (-not (Test-Path "$DATA\fhir\config.json")) { Copy-Item "$RAIZ\conf\fhir-config.json" "$DATA\fhir\config.json" }
  $ok = Cliente '' ([IO.File]::ReadAllText("$RAIZ\conf\sql\02_fhir_vistas.sql", $UTF8)) -Tolerante
  if (-not $ok) { Log 'aviso: alguna vista FHIR no se creó (¿faltan bases legado_*?): ver data\logs\sql_error.log' }
  $sql = ''
  foreach ($u in "'iproy_fhir'@'127.0.0.1'", "'iproy_fhir'@'localhost'") {
    $sql += "CREATE USER IF NOT EXISTS $u IDENTIFIED BY '$($E.IPROY_FHIR_DB_PASS)'; ALTER USER $u IDENTIFIED BY '$($E.IPROY_FHIR_DB_PASS)'; GRANT SELECT ON ``iproy_fhir``.* TO $u;`n"
  }
  Cliente '' "$sql FLUSH PRIVILEGES;"
  Escribir "$DATA\php\fhir.php" ("<?php // generado por iProy-Control $VER — NO copiar a otro nodo`nreturn ['db_host' => '127.0.0.1;port=$($E.IPROY_DB_PORT)', 'db_user' => 'iproy_fhir', 'db_pass' => '$($E.IPROY_FHIR_DB_PASS)', 'token_key' => '$($E.IPROY_FHIR_TOKEN_KEY)'];`n")
  Proteger "$DATA\php\fhir.php"
  ProtegerDir "$DATA\fhir"
}

# ---------------------------------------------------------------- Servidor PIM v1.0 (sistema paralelo, v0.5)
# Base propia iproy_pim. Usuario iproy_pim: lectura/escritura SOLO en iproy_pim y lectura de las vistas iproy_fhir (puente desde iProy).
function AsegurarPim {
  foreach ($d in "$DATA\pim\clientes", "$DATA\pim\run", "$DATA\pim\consola") { New-Item -ItemType Directory -Force -Path $d | Out-Null }
  $sec = [IO.File]::ReadAllText("$DATA\secrets.env")
  if ($sec -notmatch '(?m)^IPROY_PIM_DB_PASS=') { [IO.File]::AppendAllText("$DATA\secrets.env", "IPROY_PIM_DB_PASS=$(Aleatorio 32)`n", $UTF8) }
  if ($sec -notmatch '(?m)^IPROY_PIM_TOKEN_KEY=') { [IO.File]::AppendAllText("$DATA\secrets.env", "IPROY_PIM_TOKEN_KEY=$(Hexa 32)`n", $UTF8) }
  CargarEnv
  if (-not (Test-Path "$DATA\pim\config.json")) { Copy-Item "$RAIZ\conf\pim-config.json" "$DATA\pim\config.json" }
  Remove-Item "$DATA\pim\run\consola_token.json" -ErrorAction SilentlyContinue
  $ok = Cliente '' ([IO.File]::ReadAllText("$RAIZ\conf\sql\04_pim.sql", $UTF8)) -Tolerante
  if (-not $ok) { Log 'aviso: no se creó la base del Servidor PIM: ver data\logs\sql_error.log'; return }
  $sql = ''
  foreach ($u in "'iproy_pim'@'127.0.0.1'", "'iproy_pim'@'localhost'") {
    $sql += "CREATE USER IF NOT EXISTS $u IDENTIFIED BY '$($E.IPROY_PIM_DB_PASS)'; ALTER USER $u IDENTIFIED BY '$($E.IPROY_PIM_DB_PASS)';"
    $sql += "GRANT SELECT, INSERT, UPDATE, DELETE ON ``iproy_pim``.* TO $u; GRANT SELECT ON ``iproy_fhir``.* TO $u;`n"
  }
  Cliente '' "$sql FLUSH PRIVILEGES;"
  Escribir "$DATA\php\pim.php" ("<?php // generado por iProy-Control $VER — NO copiar a otro nodo`nreturn ['db_host' => '127.0.0.1;port=$($E.IPROY_DB_PORT)', 'db_user' => 'iproy_pim', 'db_pass' => '$($E.IPROY_PIM_DB_PASS)', 'token_key' => '$($E.IPROY_PIM_TOKEN_KEY)'];`n")
  Proteger "$DATA\php\pim.php"
  ProtegerDir "$DATA\pim"
}
function PimImportar {
  return (Invoke-WebRequest -UseBasicParsing -TimeoutSec 600 -Method Post -Headers @{ 'X-IPROY-Tick' = $E.IPROY_TICK_TOKEN } "http://127.0.0.1:$($E.IPROY_PUERTO)/pim/admin/importar.php").Content
}
# Aserción RS384 firmada con .NET (clave en XML, como fhir-cliente) y canjeada por un access_token del Servidor PIM.
function PimToken([string]$id, [string]$clave, [string]$alcance) {
  if (-not $alcance) { $alcance = 'system/*.read' }
  if (-not (Test-Path "$DATA\pim\clientes\$id.json")) { Fallo "no existe el cliente PIM $id" }
  if (-not (Test-Path $clave)) { Fallo "falta la clave privada $clave" }
  $rsa = New-Object Security.Cryptography.RSACryptoServiceProvider; $rsa.FromXmlString([IO.File]::ReadAllText($clave))
  $kid = ''; if ([IO.File]::ReadAllText("$DATA\pim\clientes\$id.json") -match '"kid":\s*"([^"]+)"') { $kid = $Matches[1] }
  $aud = "http://127.0.0.1:$($E.IPROY_PUERTO)/pim/auth/token"
  $exp = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds() + 240
  $h = B64Url ($UTF8.GetBytes('{"alg":"RS384","typ":"JWT","kid":"' + $kid + '"}'))
  $p = B64Url ($UTF8.GetBytes('{"iss":"' + $id + '","sub":"' + $id + '","aud":"' + $aud + '","exp":' + $exp + ',"jti":"' + (Hexa 16) + '"}'))
  $f = B64Url ($rsa.SignData($UTF8.GetBytes("$h.$p"), [Security.Cryptography.HashAlgorithmName]::SHA384, [Security.Cryptography.RSASignaturePadding]::Pkcs1))
  $cuerpo = @{ grant_type = 'client_credentials'; client_assertion_type = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer'; client_assertion = "$h.$p.$f"; scope = $alcance }
  try { $r = Invoke-RestMethod -UseBasicParsing -Method Post -Uri $aud -Body $cuerpo } catch { Fallo "el Servidor PIM rechazó la aserción: $($_.Exception.Message)" }
  return $r.access_token
}
function CmdPimCliente([string]$acc, [string]$id, [string]$jwksF, [string]$rol, [string]$org, [string]$sc) {
  switch ($acc) {
    'alta' {
      if ($id -notmatch '^[A-Za-z0-9._-]{3,64}$') { Fallo 'client_id inválido (3-64 caracteres: letras, números, . _ -)' }
      if ($id -eq 'consola-local') { Fallo 'consola-local es el cliente interno de la Consola' }
      if ($rol -notmatch '^(GM|EJ|SO|FT|CI|RE)$') { Fallo 'rol PIM: GM (gestor) | EJ (ejecutor) | SO (solicitante) | FT (telemetría) | CI (consulta) | RE (registro estatal)' }
      if ($rol -eq 'EJ' -and -not $org) { Fallo 'un Ejecutor necesita su organización (código de la empresa o área, p. ej. "MANT")' }
      if (-not (Test-Path $jwksF) -or ([IO.File]::ReadAllText($jwksF) -notmatch '"keys"')) { Fallo 'falta el archivo JWKS con las claves públicas del sistema' }
      $jwks = [IO.File]::ReadAllText($jwksF)
      if ($jwks -match '"d"\s*:') { Fallo 'el JWKS contiene una clave PRIVADA (campo d): pedí solo la pública' }
      if (-not $sc) { $sc = @{ GM = 'system/*.cruds'; CI = 'system/*.rs'; EJ = 'system/*.rs system/Task.u'; SO = 'system/*.rs system/ServiceRequest.c'; FT = 'system/Device.rs system/Observation.crs' }[$rol] }
      $lista = (($sc -split '\s+' | Where-Object { $_ }) | ForEach-Object { '"' + $_ + '"' }) -join ','
      $o = $org -replace '"', ''
      Escribir "$DATA\pim\clientes\$id.json" ("{`n  ""client_id"": ""$id"",`n  ""activo"": true,`n  ""alta"": ""$((Get-Date).ToString('s'))"",`n  ""rol"": ""$rol"",`n  ""organizacion"": ""$o"",`n  ""scopes"": [$lista],`n  ""jwks"": $jwks`n}`n")
      Log "cliente PIM $id · rol $rol · alcances: $sc"
    }
    'baja' {
      if (-not (Test-Path "$DATA\pim\clientes\$id.json")) { Fallo "no existe el cliente $id" }
      New-Item -ItemType Directory -Force -Path "$DATA\pim\clientes\_bajas" | Out-Null
      Move-Item "$DATA\pim\clientes\$id.json" "$DATA\pim\clientes\_bajas\$id.$(Get-Date -Format yyyyMMdd_HHmmss).json"
      Log "cliente $id dado de baja (archivo en data\pim\clientes\_bajas\)"
    }
    'lista' {
      Get-ChildItem "$DATA\pim\clientes\*.json" -ErrorAction SilentlyContinue | ForEach-Object {
        $t = [IO.File]::ReadAllText($_.FullName)
        $r = if ($t -match '"rol":\s*"([A-Z]+)"') { $Matches[1] } else { '' }
        $s = if ($t -match '"scopes":\s*(\[[^\]]*\])') { $Matches[1] } else { '' }
        Write-Host ('  {0,-24} {1,-4} {2}' -f $_.BaseName, $r, $s) }
    }
    default { Fallo 'uso: pim cliente alta <id> <jwks.json> <rol> [organización] [alcances] | baja <id> | lista' }
  }
}
function CmdPim([string]$acc, [string]$a1, [string]$a2, [string]$a3, [string]$a4, [string]$a5, [string]$a6) {
  CargarEnv; New-Item -ItemType Directory -Force -Path "$DATA\pim\clientes" | Out-Null
  switch ($acc) {
    'importar' {
      Log 'reflejando iProy en el Servidor PIM (solo lectura de iProy)...'
      try { $j = PimImportar } catch { Fallo "el nodo no responde (start primero): $($_.Exception.Message)" }
      Escribir "$DATA\logs\pim_importacion.json" $j
      $r = $j | ConvertFrom-Json
      if (-not $r.ok) { Fallo "importación con error: $j" }
      Log "OK en $($r.segundos) s · detalle en data\logs\pim_importacion.json"
      foreach ($k in $r.recursos.PSObject.Properties) { Write-Host ('   {0,-14} nuevos {1,-6} cambios {2,-6} iguales {3}' -f $k.Name, $k.Value.nuevo, $k.Value.cambio, $k.Value.igual) }
    }
    'cliente' { CmdPimCliente $a1 $a2 $a3 $a4 $a5 $a6 }
    'token' { PimToken $a1 $a2 $a3 }
    'prueba' {
      $d = "$DATA\pim\demo"; New-Item -ItemType Directory -Force -Path $d | Out-Null
      if (-not (Test-Path "$d\clave_privada.xml")) {
        $rsa = New-Object Security.Cryptography.RSACryptoServiceProvider 2048
        Escribir "$d\clave_privada.xml" $rsa.ToXmlString($true); Proteger "$d\clave_privada.xml"
      }
      $rsa = New-Object Security.Cryptography.RSACryptoServiceProvider; $rsa.FromXmlString([IO.File]::ReadAllText("$d\clave_privada.xml"))
      $pp = $rsa.ExportParameters($false)
      Escribir "$d\jwks_publico.json" ('{"keys":[{"kty":"RSA","kid":"prueba-1","alg":"RS384","use":"sig","e":"' + (B64Url $pp.Exponent) + '","n":"' + (B64Url $pp.Modulus) + '"}]}')
      CmdPimCliente 'alta' 'prueba-local' "$d\jwks_publico.json" 'CI' '' 'system/*.read' | Out-Null
      $t = PimToken 'prueba-local' "$d\clave_privada.xml" 'system/*.read'
      $m = Invoke-RestMethod -UseBasicParsing "http://127.0.0.1:$($E.IPROY_PUERTO)/pim/r4/metadata"
      Write-Host ('  metadata       {0}' -f $m.title)
      foreach ($r in 'Organization', 'Location', 'Device', 'ServiceRequest', 'Task', 'Observation') {
        $b = Invoke-RestMethod -UseBasicParsing -Headers @{ Authorization = "Bearer $t" } "http://127.0.0.1:$($E.IPROY_PUERTO)/pim/r4/$($r)?_summary=count"
        Write-Host ('  {0,-14} total {1}' -f $r, $b.total)
      }
      Log "Servidor PIM operativo. Consola: http://localhost:$($E.IPROY_PUERTO)/pim/"
    }
    'verificar' {
      $j = (Invoke-WebRequest -UseBasicParsing -TimeoutSec 300 -Method Post -Headers @{ 'X-IPROY-Tick' = $E.IPROY_TICK_TOKEN } "http://127.0.0.1:$($E.IPROY_PUERTO)/pim/admin/verificar.php").Content | ConvertFrom-Json
      if ($j.ok) { Log "cadena íntegra: $($j.versiones) versiones de $($j.recursos) recursos" } else { Fallo "¡alteración detectada! $($j.alteradas -join ', ')" }
    }
    'consola' { Log "Consola PIM: http://localhost:$($E.IPROY_PUERTO)/pim/  (solo desde este equipo)"; Start-Process "http://localhost:$($E.IPROY_PUERTO)/pim/" }
    default { Fallo 'uso: pim importar | cliente alta|baja|lista | token | prueba | verificar | consola' }
  }
}

# ---------------------------------------------------------------- modo autónomo y red (v0.4)
# Raíces de confianza de Windows (equipo + usuario) en PEM para curl/openssl de PHP. Incluye la CA del proxy del hospital si la hay.
function ExportarRaices {
  try {
    $sb = New-Object Text.StringBuilder; $vistos = @{}
    foreach ($alm in 'Cert:\LocalMachine\Root', 'Cert:\CurrentUser\Root', 'Cert:\LocalMachine\CA') {
      foreach ($c in (Get-ChildItem $alm -ErrorAction SilentlyContinue)) {
        if ($vistos.ContainsKey($c.Thumbprint) -or $c.NotAfter -lt (Get-Date)) { continue }
        $vistos[$c.Thumbprint] = 1
        [void]$sb.Append("-----BEGIN CERTIFICATE-----`n" + [Convert]::ToBase64String($c.RawData, 'InsertLineBreaks') + "`n-----END CERTIFICATE-----`n")
      }
    }
    if ($vistos.Count -gt 0) { Escribir "$DATA\php\cacert.pem" $sb.ToString() }
  } catch { Log "aviso: no se pudieron exportar los certificados raíz de Windows ($($_.Exception.Message))" }
}
function EscribirIps {
  $ips = @(Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } | ForEach-Object { '"' + $_.IPAddress + '"' })
  Escribir "$RUN\ips.json" ('{"ipv4":[' + ($ips -join ',') + '],"ts":"' + (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ') + '"}')
  # Proxy del sistema (si el hospital usa uno): PHP no lo detecta solo
  try {
    $px = [Net.WebRequest]::GetSystemWebProxy().GetProxy([Uri]'https://script.google.com/')
    if ($px -and $px.Host -ne 'script.google.com') { Escribir "$RUN\proxy_sistema.txt" $px.AbsoluteUri } elseif (Test-Path "$RUN\proxy_sistema.txt") { Remove-Item "$RUN\proxy_sistema.txt" }
  } catch { }
}
function AsegurarOffline {
  New-Item -ItemType Directory -Force -Path "$DATA\cdn" | Out-Null
  $ok = Cliente 'iproy' ([IO.File]::ReadAllText("$RAIZ\conf\sql\03_offline.sql", $UTF8)) -Tolerante
  if (-not $ok) { Log 'aviso: no se crearon las tablas del modo autónomo: ver data\logs\sql_error.log' }
  [void](Cliente 'iproy' ("UPDATE sync_nodo SET nombre = '" + ($E.IPROY_NOMBRE -replace "'", "''") + "' WHERE local_actual = 1;") -Tolerante)
  EscribirIps
  # versión del paquete en uso (un nodo creado con una versión anterior queda al día al iniciar)
  Escribir "$DATA\iproy.env" (([IO.File]::ReadAllText("$DATA\iproy.env", $UTF8)) -replace '(?m)^IPROY_VERSION=.*$', "IPROY_VERSION=$VER")
  Escribir "$DATA\node.json" (([IO.File]::ReadAllText("$DATA\node.json", $UTF8)) -replace '"version":"[^"]*"', ('"version":"' + $VER + '"'))
  $script:E.IPROY_VERSION = $VER
}
function ModoRed {   # el modo elegido en el panel (data\red.json) tiene prioridad sobre iproy.env
  if (Test-Path "$DATA\red.json") {
    try { $j = [IO.File]::ReadAllText("$DATA\red.json", $UTF8) | ConvertFrom-Json; if ($j.modo -eq 'lan' -or $j.modo -eq 'local') { $script:E.IPROY_MODO = $j.modo } } catch { Log 'aviso: data\red.json no es JSON válido; se usa el modo de iproy.env' }
  }
}
function CmdPrecargar {
  CargarEnv
  Log 'descargando al nodo las librerías y archivos que usa iProy (hace falta Internet)...'
  try { $r = Invoke-WebRequest -UseBasicParsing -TimeoutSec 300 -Method Post -Headers @{ 'X-IPROY-Tick' = $E.IPROY_TICK_TOKEN } "http://127.0.0.1:$($E.IPROY_PUERTO)/_iproy/precargar.php" }
  catch { Fallo 'el nodo no responde (start primero)' }
  Escribir "$DATA\logs\precarga.json" $r.Content
  try { $j = $r.Content | ConvertFrom-Json; Log "resultado: $($j.listos) de $($j.total) guardados; $(@($j.fallidos).Count) sin conexión o inexistentes (detalle: data\logs\precarga.json)" } catch { Log $r.Content }
}
function CmdRed([string]$acc, [string]$m) {
  CargarEnv
  if (-not $acc) { $acc = 'estado' }
  switch ($acc) {
    'estado' {
      ModoRed; EscribirIps
      Write-Host ("Nodo {0} · modo {1} · HTTP {2} · HTTPS {3}" -f $E.IPROY_NODE, $E.IPROY_MODO, $E.IPROY_PUERTO, $E.IPROY_PUERTO_TLS)
      Write-Host ('  IP          ' + ((Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' }).IPAddress -join ' '))
      try { Write-Host ('  central     ' + (Invoke-WebRequest -UseBasicParsing -TimeoutSec 5 "http://127.0.0.1:$($E.IPROY_PUERTO)/_iproy/red.php?estado=1").Content) } catch { Write-Host '  central     nodo detenido' }
      Write-Host "  panel       http://localhost:$($E.IPROY_PUERTO)/_iproy/red.php"
    }
    'modo' {
      if ($m -ne 'local' -and $m -ne 'lan') { Fallo 'uso: red modo local|lan' }
      $j = if (Test-Path "$DATA\red.json") { [IO.File]::ReadAllText("$DATA\red.json", $UTF8) | ConvertFrom-Json } else { New-Object PSObject }
      $j | Add-Member -NotePropertyName modo -NotePropertyValue $m -Force
      Escribir "$DATA\red.json" ($j | ConvertTo-Json)
      Log "modo $m guardado. Reiniciá: iProy-Control.bat stop y luego iProy-Control.bat start"
      if ($m -eq 'lan') { Log "Windows puede pedir permiso en el firewall (puertos $($E.IPROY_PUERTO) y $($E.IPROY_PUERTO_TLS)). Si no aparece, un administrador puede habilitarlos." }
    }
    default { Fallo 'uso: red [estado] | red modo local|lan' }
  }
}
# Clave de autenticación (pepper) del servicio de usuarios para el PRIMER ingreso sin red. Se escribe oculta y queda
# solo en data\php\auth_pepper.txt (acceso exclusivo del usuario). Nunca se muestra ni se copia a otro lado.
function CmdClaveAuth([string]$op) {
  CargarEnv
  $f = "$DATA\php\auth_pepper.txt"
  if ($op -eq '--borrar') { if (Test-Path $f) { Escribir $f ''; Remove-Item $f -ErrorAction SilentlyContinue }; Log 'clave de autenticación quitada del nodo'; return }
  $a = Read-Host 'Clave de autenticación (no se muestra)' -AsSecureString
  $b = Read-Host 'Repetila' -AsSecureString
  $pa = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($a); $pb = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($b)
  try {
    $ta = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pa); $tb = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pb)
    if (-not $ta -or $ta -ne $tb) { Fallo 'vacía o no coinciden; no se guardó nada' }
    Escribir $f $ta; Proteger $f
  } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pa); [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pb); $ta = $null; $tb = $null }
  Log 'OK clave guardada en data\php\auth_pepper.txt (solo este nodo). Los usuarios replicados ya pueden ingresar sin red.'
}

# Aserción RS384 (SMART Backend Services) firmada con .NET y canjeada por un access_token.
# En Windows la clave privada de prueba se guarda como XML de .NET (data\fhir\demo\clave_privada.xml).
function FhirToken([string]$id, [string]$clave) {
  if (-not (Test-Path "$DATA\fhir\clientes\$id.json")) { Fallo "no existe el cliente $id" }
  if (-not (Test-Path $clave)) { Fallo "falta la clave privada $clave" }
  $rsa = New-Object Security.Cryptography.RSACryptoServiceProvider; $rsa.FromXmlString([IO.File]::ReadAllText($clave))
  $kid = ''; if ([IO.File]::ReadAllText("$DATA\fhir\clientes\$id.json") -match '"kid":\s*"([^"]+)"') { $kid = $Matches[1] }
  $aud = "http://127.0.0.1:$($E.IPROY_PUERTO)/fhir/auth/token"
  $exp = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds() + 240   # (Get-Date -UFormat %s) en 5.1 usa hora local
  $h = B64Url ($UTF8.GetBytes('{"alg":"RS384","typ":"JWT","kid":"' + $kid + '"}'))
  $p = B64Url ($UTF8.GetBytes('{"iss":"' + $id + '","sub":"' + $id + '","aud":"' + $aud + '","exp":' + $exp + ',"jti":"' + (Hexa 16) + '"}'))
  $f = B64Url ($rsa.SignData($UTF8.GetBytes("$h.$p"), [Security.Cryptography.HashAlgorithmName]::SHA384, [Security.Cryptography.RSASignaturePadding]::Pkcs1))
  $cuerpo = @{ grant_type = 'client_credentials'; client_assertion_type = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer'; client_assertion = "$h.$p.$f"; scope = 'system/*.read' }
  try { $r = Invoke-RestMethod -UseBasicParsing -Method Post -Uri $aud -Body $cuerpo } catch { Fallo "el nodo rechazó la aserción: $($_.Exception.Message)" }
  return $r.access_token
}

function CmdFhirCliente([string]$acc, [string]$a1, [string]$a2, [string]$a3) {
  CargarEnv; New-Item -ItemType Directory -Force -Path "$DATA\fhir\clientes" | Out-Null
  switch ($acc) {
    'alta' {
      if ($a1 -notmatch '^[A-Za-z0-9._-]{3,64}$') { Fallo 'client_id inválido (3-64 caracteres: letras, números, . _ -)' }
      if (-not (Test-Path $a2) -or ([IO.File]::ReadAllText($a2) -notmatch '"keys"')) { Fallo 'falta el archivo JWKS con las claves públicas del sistema externo' }
      $jwks = [IO.File]::ReadAllText($a2)
      if ($jwks -match '"d"\s*:') { Fallo 'el JWKS contiene una clave PRIVADA (campo d): pedile al organismo solo la pública' }
      $sc = if ($a3) { $a3 } else { 'system/*.read' }
      $lista = (($sc -split '\s+' | Where-Object { $_ }) | ForEach-Object { '"' + $_ + '"' }) -join ','
      Escribir "$DATA\fhir\clientes\$a1.json" ("{`n  ""client_id"": ""$a1"",`n  ""activo"": true,`n  ""alta"": ""$((Get-Date).ToString('s'))"",`n  ""scopes"": [$lista],`n  ""jwks"": $jwks`n}`n")
      Log "cliente $a1 registrado con alcances: $sc"
    }
    'baja' {
      if (-not (Test-Path "$DATA\fhir\clientes\$a1.json")) { Fallo "no existe el cliente $a1" }
      New-Item -ItemType Directory -Force -Path "$DATA\fhir\clientes\_bajas" | Out-Null
      Move-Item "$DATA\fhir\clientes\$a1.json" "$DATA\fhir\clientes\_bajas\$a1.$(Get-Date -Format yyyyMMdd_HHmmss).json"
      Log "cliente $a1 dado de baja (archivo en clientes\_bajas\)"
    }
    'lista' {
      Get-ChildItem "$DATA\fhir\clientes\*.json" -ErrorAction SilentlyContinue | ForEach-Object {
        $t = [IO.File]::ReadAllText($_.FullName); $s = if ($t -match '"scopes":\s*(\[[^\]]*\])') { $Matches[1] } else { '' }
        Write-Host ('  {0,-24} {1}' -f $_.BaseName, $s) }
    }
    'token' { FhirToken $a1 $a2 }
    'demo' {
      $d = "$DATA\fhir\demo"; New-Item -ItemType Directory -Force -Path $d | Out-Null
      if (-not (Test-Path "$d\clave_privada.xml")) {
        $rsa = New-Object Security.Cryptography.RSACryptoServiceProvider 2048
        Escribir "$d\clave_privada.xml" $rsa.ToXmlString($true); Proteger "$d\clave_privada.xml"
      }
      $rsa = New-Object Security.Cryptography.RSACryptoServiceProvider; $rsa.FromXmlString([IO.File]::ReadAllText("$d\clave_privada.xml"))
      $pp = $rsa.ExportParameters($false)
      Escribir "$d\jwks_publico.json" ('{"keys":[{"kty":"RSA","kid":"demo-1","alg":"RS384","use":"sig","e":"' + (B64Url $pp.Exponent) + '","n":"' + (B64Url $pp.Modulus) + '"}]}')
      CmdFhirCliente 'alta' 'demo-local' "$d\jwks_publico.json" 'system/*.read'
      $t = FhirToken 'demo-local' "$d\clave_privada.xml"
      foreach ($r in 'Organization', 'Location', 'Device', 'Task') {
        $b = Invoke-RestMethod -UseBasicParsing -Headers @{ Authorization = "Bearer $t" } "http://127.0.0.1:$($E.IPROY_PUERTO)/fhir/r4/$($r)?_summary=count"
        Write-Host ('  {0,-14} total {1}' -f $r, $b.total)
      }
      Log 'API FHIR operativa. Token para pruebas: iProy-Control.bat fhir-cliente token demo-local data\fhir\demo\clave_privada.xml'
    }
    default { Fallo 'uso: fhir-cliente alta|baja|lista|token|demo' }
  }
}

# ---------------------------------------------------------------- init
function NombreLimpio([string]$n) {
  $n = ($n -replace '[^\p{L}\p{Nd} ._()-]', '').Trim()
  if ($n.Length -gt 40) { $n = $n.Substring(0, 40) }
  if (-not $n) { $n = 'Nodo IPROY' }
  return $n
}
function CmdNombre([string]$n) {
  CargarEnv
  if (-not $n) { $n = Read-Host "Nombre nuevo de este equipo (ahora: $($E.IPROY_NOMBRE))" }
  $n = NombreLimpio $n
  Escribir "$DATA\iproy.env" (([IO.File]::ReadAllText("$DATA\iproy.env", $UTF8)) -replace '(?m)^IPROY_NOMBRE=.*$', ('IPROY_NOMBRE="' + $n + '"'))
  Escribir "$DATA\node.json" (([IO.File]::ReadAllText("$DATA\node.json", $UTF8)) -replace '"nombre":"[^"]*"', ('"nombre":"' + $n + '"'))
  CargarEnv; GenerarConfigPhp
  Log "OK el nodo $($E.IPROY_NODE) ahora se llama «$n». Se ve en la app al recargar; la base se actualiza en el próximo start."
}
function CmdInit {
  if (Test-Path "$DATA\iproy.env") { Fallo 'data\ ya existe (nodo inicializado). Para reinstalar, mové data\ a otro lado.' }
  if (-not (Test-Path $FP)) { Fallo "falta FrankenPHP en $FP" }
  if (-not (Test-Path "$MDB\bin\mariadbd.exe")) { Fallo "falta MariaDB en $MDB (descomprimí el ZIP oficial winx64 ahí; ver INSTALAR.md)" }
  foreach ($d in 'run', 'logs', 'backups', 'outbox', 'inbox', 'adjuntos', 'caddy', 'php\sesiones') { New-Item -ItemType Directory -Force -Path "$DATA\$d" | Out-Null }
  # v0.4.5: el nombre del equipo se pide al instalar (se ve en la app: «Nodo ABC · PC Taller»)
  if (-not $script:NombreDado) {
    $sug = if ($env:COMPUTERNAME) { $env:COMPUTERNAME } else { 'Nodo IPROY' }
    $r = Read-Host "Nombre de este equipo para la colmena (ej. PC Taller) [$sug]"
    $script:Nombre = if ($r) { $r } else { $sug }
  }
  $script:Nombre = NombreLimpio $Nombre
  $id = NodoId
  Log "nodo nuevo: $id · «$Nombre» · modo $Modo · puerto $Puerto"
  Escribir "$DATA\iproy.env" ("IPROY_NODE=$id`nIPROY_NOMBRE=""$Nombre""`nIPROY_MODO=$Modo`nIPROY_PUERTO=$Puerto`nIPROY_PUERTO_TLS=$($Puerto + 363)`nIPROY_DB_PORT=3407`nIPROY_VERSION=$VER`n")
  Escribir "$DATA\secrets.env" ("IPROY_DB_ROOT_PASS=$(Aleatorio 32)`nIPROY_DB_APP_PASS=$(Aleatorio 32)`nIPROY_TICK_TOKEN=$(Aleatorio 40)`nIPROY_SESSION_SECRET=$(Aleatorio 48)`n")
  Proteger "$DATA\secrets.env"
  Escribir "$DATA\node.json" ('{"node_id":"' + $id + '","nombre":"' + ($Nombre -replace '"', '') + '","confirmado":false,"alta_utc":"' + (Get-Date).ToUniversalTime().ToString('s') + 'Z","version":"' + $VER + '"}')
  CargarEnv

  Log 'creando la base de datos (mariadb-install-db)…'
  if ((Nativo (Bin 'mariadb-install-db') @("--datadir=$DATA\mariadb", "--password=$($E.IPROY_DB_ROOT_PASS)", "--port=$($E.IPROY_DB_PORT)", '--silent') "$DATA\logs\install-db.log") -ne 0) {
    Get-Content "$DATA\logs\install-db.log" -Tail 20
    Fallo 'mariadb-install-db falló. Antes de reintentar, renombrá data\ (init no reutiliza una data\ a medio crear).'
  }
  EscribirConfig
  IniciarMariaDB
  $sql = "CREATE USER IF NOT EXISTS 'iproy_app'@'127.0.0.1' IDENTIFIED BY '$($E.IPROY_DB_APP_PASS)';`nCREATE USER IF NOT EXISTS 'iproy_app'@'localhost' IDENTIFIED BY '$($E.IPROY_DB_APP_PASS)';`n"
  $priv = 'SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,CREATE VIEW,SHOW VIEW,CREATE TEMPORARY TABLES,LOCK TABLES,EXECUTE,REFERENCES,TRIGGER'
  foreach ($b in $BASES) {
    $sql += "CREATE DATABASE IF NOT EXISTS ``$b`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`nGRANT $priv ON ``$b``.* TO 'iproy_app'@'127.0.0.1';`nGRANT $priv ON ``$b``.* TO 'iproy_app'@'localhost';`n"
  }
  Cliente '' ($sql + 'FLUSH PRIVILEGES;')
  Cliente 'iproy' (Get-Content -Raw -Encoding UTF8 "$RAIZ\conf\sql\01_ddl_sync.sql")
  Cliente 'iproy' ("INSERT INTO sync_nodo (node_id,nombre,local_actual) VALUES ('$id','" + ($Nombre -replace "'", "''") + "',1);")
  GenerarConfigPhp
  DetenerMariaDB
  Log "OK nodo $id listo. Siguiente: iProy-Control.bat importar-app · importar-datos · start"
  try { CmdAccesoDirecto '' } catch { Log "Aviso: no se pudo crear el acceso directo ($_). Después: iProy-Control.bat acceso-directo" }
}

# my.ini y php.ini se regeneran en cada init/start con las rutas ACTUALES (pendrive con otra letra, carpeta movida)
function EscribirConfig {
  # php.ini: plantilla común + extensiones (en Windows son DLL)
  $ini = (Get-Content -Raw -Encoding UTF8 "$RAIZ\conf\php.ini.tpl") -replace '@DATA@', (Barra $DATA) -replace '@APP@', (Barra $APP)
  # PHP en Windows no usa el almacén de certificados del sistema: se exporta a data\php\cacert.pem (L-313)
  ExportarRaices
  if (Test-Path "$DATA\php\cacert.pem") { $ini += "`ncurl.cainfo = ""$(Barra "$DATA\php\cacert.pem")""`nopenssl.cafile = ""$(Barra "$DATA\php\cacert.pem")""`n" }
  $ini += "`nextension_dir = ""$(Barra (Join-Path $FPDIR 'ext'))""`n"
  foreach ($x in 'pdo_mysql', 'mysqli', 'mbstring', 'openssl', 'sodium', 'intl', 'curl', 'zip', 'gd', 'fileinfo', 'exif', 'sockets') { $ini += "extension=$x`n" }
  # OPcache ya viene incluido en PHP 8.5 (no hay php_opcache.dll en ext\)
  Escribir "$DATA\php\php.ini" $ini

  # my.ini propio (el que genera install-db queda dentro de data\mariadb y no se usa)
  Escribir "$DATA\my.ini" @"
[mariadbd]
lower_case_table_names=1
basedir=$(Barra $MDB)
datadir=$(Barra "$DATA\mariadb")
bind-address=127.0.0.1
port=$($E.IPROY_DB_PORT)
log-error=$(Barra "$DATA\logs\mariadb.err")
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
default-time-zone=-03:00
innodb_buffer_pool_size=256M
max_allowed_packet=64M
[client]
port=$($E.IPROY_DB_PORT)
default-character-set=utf8mb4
"@
}

function GenerarConfigPhp {
  $nom = $E.IPROY_NOMBRE -replace "'", "\'"
  Escribir "$DATA\php\nodo.php" @"
<?php // generado por iProy-Control $VER — NO copiar a otro nodo
return [
  'node_id' => '$($E.IPROY_NODE)', 'nombre' => '$nom', 'modo' => '$($E.IPROY_MODO)',
  'db_host' => '127.0.0.1;port=$($E.IPROY_DB_PORT)', 'db_user' => 'iproy_app', 'db_pass' => '$($E.IPROY_DB_APP_PASS)',
  'tick_token' => '$($E.IPROY_TICK_TOKEN)', 'session_secret' => '$($E.IPROY_SESSION_SECRET)',
];
"@
  Proteger "$DATA\php\nodo.php"
  InstalarLocalPhp
}

function InstalarLocalPhp {
  $api = Join-Path $APP 'iProy\api'; if (-not (Test-Path $api)) { return }
  $cab = "<?php // generado por iProy-Control (nodo portable). Sin secretos: los lee de data/php/nodo.php`n`$N = require dirname(__DIR__, 3) . '/data/php/nodo.php';`n"
  Escribir "$api\db.local.php" ($cab + "return ['db_host'=>`$N['db_host'],'db_name'=>'legado_iproy','db_user'=>`$N['db_user'],'db_pass'=>`$N['db_pass'],`n        'host'=>`$N['db_host'],'db'=>'legado_iproy','user'=>`$N['db_user'],'pass'=>`$N['db_pass'],'sync_token'=>`$N['tick_token']];`n")
  $b = ''
  foreach ($kv in 'req:legado_requerimientos', 'stock:legado_stock_mant', 'iot:legado_iot', 'user:legado_user_gestion', 'ana:legado_analiticas', 'prev:legado_preventivos') {
    $k, $v = $kv.Split(':')
    $b += "  '$k' => ['db_host'=>`$N['db_host'],'db_name'=>'$v','db_user'=>`$N['db_user'],'db_pass'=>`$N['db_pass'],'db'=>'$v','user'=>`$N['db_user'],'pass'=>`$N['db_pass']],`n"
  }
  Escribir "$api\bases.local.php" ($cab + "return [`n$b];`n")
  if (-not (Test-Path "$api\config.local.php")) { Escribir "$api\config.local.php" ($cab + "return ['modo' => 'mysql', 'sync_ahora' => false, 'nodo_portable' => `$N['node_id']];`n") }
}

# ---------------------------------------------------------------- procesos
function IniciarMariaDB {
  if (Vivo 'mariadb') { return }
  $ini = if (Test-Path "$DATA\my.ini") { "$DATA\my.ini" } else { "$DATA\mariadb\my.ini" }
  $p = Start-Process -FilePath (Bin 'mariadbd') -ArgumentList "--defaults-file=""$ini""" -WindowStyle Hidden -PassThru `
       -RedirectStandardError "$DATA\logs\mariadb.out"
  Set-Content "$RUN\mariadb.pid" $p.Id
  for ($i = 0; $i -lt 240; $i++) {   # hasta 120 s (pendrive o disco lento)
    if ((Nativo (Bin 'mariadb-admin') @('--no-defaults', '--host=127.0.0.1', "--port=$($E.IPROY_DB_PORT)", '-uroot', "-p$($E.IPROY_DB_ROOT_PASS)", 'ping')) -eq 0) { Log "MariaDB en 127.0.0.1:$($E.IPROY_DB_PORT) OK"; return }
    Start-Sleep -Milliseconds 500
  }
  Fallo 'MariaDB no respondió en 120 s (ver data\logs\mariadb.err)'
}
function DetenerMariaDB {
  if (-not (Vivo 'mariadb')) { return }
  $id = [int](Get-Content "$RUN\mariadb.pid" -TotalCount 1)
  $null = Nativo (Bin 'mariadb-admin') @('--no-defaults', '--host=127.0.0.1', "--port=$($E.IPROY_DB_PORT)", '-uroot', "-p$($E.IPROY_DB_ROOT_PASS)", 'shutdown')
  for ($i = 0; $i -lt 40 -and (Vivo 'mariadb'); $i++) { Start-Sleep -Milliseconds 500 }
  if (Vivo 'mariadb') {   # plan B: evento de apagado ordenado de mariadbd en Windows (no pide credenciales)
    try { $null = [Threading.EventWaitHandle]::OpenExisting("MySQLShutdown$id").Set(); Wait-Process -Id $id -Timeout 30 -ErrorAction SilentlyContinue } catch { Log 'aviso: no se pudo enviar el apagado ordenado' }
  }
  if (Vivo 'mariadb') { Stop-Process -Id $id -Force; Log 'aviso: MariaDB terminado a la fuerza' }
  Remove-Item "$RUN\mariadb.pid" -ErrorAction SilentlyContinue; Log 'MariaDB detenido'
}

# ---------------------------------------------------------------- Núcleo v1.0 (módulos, bus, diario, colmena)
function Api([string]$Metodo, [string]$Ruta, $Cuerpo = $null) {
  $h = @{ 'X-IPROY-Tick' = $E.IPROY_TICK_TOKEN; 'Accept' = 'application/json' }
  $u = "http://127.0.0.1:$($E.IPROY_PUERTO)/api/$Ruta"
  try {
    if ($null -ne $Cuerpo) {
      $b = if ($Cuerpo -is [string]) { $Cuerpo } else { $Cuerpo | ConvertTo-Json -Depth 10 -Compress }
      $r = Invoke-WebRequest -UseBasicParsing -TimeoutSec 180 -Method $Metodo -Headers $h -ContentType 'application/json; charset=utf-8' -Body ([Text.Encoding]::UTF8.GetBytes($b)) $u
    } else { $r = Invoke-WebRequest -UseBasicParsing -TimeoutSec 180 -Method $Metodo -Headers $h $u }
    $txt = [Text.Encoding]::UTF8.GetString($r.RawContentStream.ToArray())
  } catch {
    $resp = $_.Exception.Response
    if ($null -eq $resp) { Fallo "el nodo no responde (¿está iniciado?): $($_.Exception.Message)" }
    $txt = (New-Object IO.StreamReader($resp.GetResponseStream(), [Text.Encoding]::UTF8)).ReadToEnd()
  }
  return ($txt | ConvertFrom-Json)
}
function Ver($j) { if ($j.ok -eq $false) { Fallo $j.error }; $j | ConvertTo-Json -Depth 8 | Write-Host }
function AsegurarNucleo {
  if (-not (Test-Path "$APP\nucleo\index.php")) { return }
  $j = Api 'POST' 'nucleo/arranque'
  if ($j.ok) { Log ("núcleo OK · módulos: " + (($j.estado.PSObject.Properties | ForEach-Object { "$($_.Name):$($_.Value)" }) -join ' · ')) }
  else { Log "AVISO: el núcleo no arrancó: $($j.error)" }
  $u = Api 'GET' 'usuarios'
  if ($u.ok -and @($u.usuarios).Count -eq 0) { Log 'Todavía no hay usuarios. Creá el administrador: iProy-Control.bat usuario alta admin AD' }
}
function LeerClave {
  $a = Read-Host -AsSecureString 'Contraseña (mínimo 10 caracteres)'; $b = Read-Host -AsSecureString 'Repetila'
  $pa = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($a))
  $pb = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($b))
  if ($pa -ne $pb) { Fallo 'no coinciden' }; return $pa
}
function CmdNucleo([string]$acc, [string]$a1) {
  CargarEnv
  switch ($(if ($acc) { $acc } else { 'estado' })) {
    'estado' { Ver (Api 'GET' 'proc/sistema') }
    'arranque' { Ver (Api 'POST' 'nucleo/arranque') }
    'tick' { Ver (Api 'POST' 'nucleo/tick') }
    'tareas' { Ver (Api 'GET' 'proc/tareas') }
    'diario' { Ver (Api 'GET' "proc/diario?n=$(if ($a1) { $a1 } else { 20 })") }
    'verificar' { Ver (Api 'POST' 'proc/diario/verificar') }
    'ejecutar' { Ver (Api 'POST' "tareas/$a1/ejecutar") }
    default { Fallo 'uso: nucleo estado | arranque | tick | tareas | diario [n] | verificar | ejecutar <modulo:tarea>' }
  }
}
function CmdModulo([string]$acc, [string]$id, [string]$arch) {
  CargarEnv
  switch ($acc) {
    { $_ -in 'activar', 'desactivar' } { Ver (Api 'POST' "modulos/$id/$acc") }
    'config' { if ($arch) { Ver (Api 'PUT' "modulos/$id/config" ('{"config": ' + [IO.File]::ReadAllText($arch) + '}')) } else { Ver (Api 'GET' "modulos/$id/config") } }
    default { Fallo 'uso: modulo activar|desactivar <id> | modulo config <id> [archivo.json]' }
  }
}
function CmdUsuario([string]$acc, [string]$u, [string]$rol, [string]$org) {
  CargarEnv
  switch ($(if ($acc) { $acc } else { 'lista' })) {
    'alta' { if (-not $u -or -not $rol) { Fallo 'uso: usuario alta <usuario> <AD|GM|EJ|SO|FT|CI> [organización]' }
             $c = LeerClave; Ver (Api 'POST' 'nucleo/usuario' @{ usuario = $u; rol = $rol; organizacion = $org; nombre = $u; clave = $c }) }
    'clave' { $c = LeerClave; Ver (Api 'PUT' "usuarios/$u" @{ clave = $c }) }
    'baja' { Ver (Api 'PUT' "usuarios/$u" @{ activo = $false }) }
    'token' { Ver (Api 'POST' "usuarios/$u/token" @{ dias = $(if ($rol) { [int]$rol } else { 90 }) }) }
    'lista' { Ver (Api 'GET' 'usuarios') }
    default { Fallo 'uso: usuario alta <u> <rol> [org] | clave <u> | baja <u> | token <u> [días] | lista' }
  }
}
function CmdColmena([string]$acc, [string]$a1, [string]$a2, [string]$a3, [string]$a4) {
  CargarEnv
  switch ($(if ($acc) { $acc } else { 'lista' })) {
    'identidad' { Ver (Api 'GET' 'colmena/identidad') }
    'lista' { Ver (Api 'GET' 'colmena/vinculos') }
    'vincular' { Ver (Api 'POST' 'colmena/vincular' @{ destino = $a1; tipo = $(if ($a2) { $a2 } else { 'institucional' }) }) }
    'aceptar' { $sha = ''; if ($a4) { $sha = (Get-FileHash -Algorithm SHA256 $a4).Hash.ToLower() }
                Ver (Api 'POST' "colmena/vinculos/$a1/aceptar" @{ rol = $(if ($a2) { $a2 } else { 'CI' }); vence = $a3; acta_sha = $sha }) }
    'rol' { Ver (Api 'POST' "colmena/vinculos/$a1/rol" @{ rol = $a2 }) }
    'revocar' { Ver (Api 'POST' "colmena/vinculos/$a1/revocar" '{}') }
    'ping' { Ver (Api 'POST' "colmena/vinculos/$a1/ping" '{}') }
    'via' { Ver (Api 'POST' "colmena/vinculos/$a1/via" @{ via = $(if ($a2) { $a2 } else { 'auto' }) }) }
    'rpc' { $cu = if ($a4) { [IO.File]::ReadAllText($a4) } else { 'null' }
            Ver (Api 'POST' 'colmena/rpc' ('{"par":"' + $a1 + '","metodo":"' + $(if ($a2) { $a2 } else { 'GET' }) + '","ruta":' + ($a3 | ConvertTo-Json) + ',"cuerpo":' + $cu + ',"esperar":60}')) }
    'hub' { if ($a1 -eq 'apagar') { Ver (Api 'PUT' 'colmena/config' '{"hub":{"activo":false}}') }
            else { Ver (Api 'PUT' 'colmena/config' @{ hub = @{ url = $a1; activo = $true; codigo = $a2 } }); Ver (Api 'POST' 'colmena/hub/registrar' '{}') } }
    'url' { Ver (Api 'PUT' 'colmena/config' @{ url_publica = $a1 }) }
    default { Fallo 'uso: colmena identidad | lista | vincular <url|ID> | aceptar <par> [rol] | rol <par> <rol> | revocar <par> | ping <par> | via <par> auto|hub | rpc <par> <método> <ruta> [cuerpo.json] | hub <url> [código] | hub apagar | url <url>' }
  }
}

function CmdStart {
  CargarEnv; New-Item -ItemType Directory -Force -Path $RUN | Out-Null
  EscribirConfig
  InstalarLocalPhp
  IniciarMariaDB
  AsegurarFhir
  AsegurarPim
  AsegurarOffline
  ModoRed; Escribir "$RUN\modo_activo" $E.IPROY_MODO
  # FrankenPHP necesita las DLL de Visual C++ 2015-2022; MariaDB las trae en bin\ (si la PC no tiene el redistribuible)
  foreach ($d in 'vcruntime140.dll', 'vcruntime140_1.dll', 'msvcp140.dll') { if (-not (Test-Path "$FPDIR\$d") -and (Test-Path "$MDB\bin\$d")) { Copy-Item "$MDB\bin\$d" $FPDIR } }
  if (-not (Vivo 'frankenphp')) {
    $sitios = "https://localhost:$($E.IPROY_PUERTO_TLS), https://127.0.0.1:$($E.IPROY_PUERTO_TLS)"
    if ($E.IPROY_MODO -eq 'lan') {
      $env:IPROY_BIND = '0.0.0.0'
      Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } |
        ForEach-Object { $sitios += ", https://$($_.IPAddress):$($E.IPROY_PUERTO_TLS)" }
      $sitios += ", https://nodo-$($E.IPROY_NODE.ToLower()).local:$($E.IPROY_PUERTO_TLS)"
    } else { $env:IPROY_BIND = '127.0.0.1' }
    $env:IPROY_TLS_SITIOS = $sitios
    $p = Start-Process -FilePath $FP -WorkingDirectory $FPDIR -ArgumentList 'run', '--config', """$RAIZ\conf\Caddyfile""", '--adapter', 'caddyfile' `
         -WindowStyle Hidden -PassThru -RedirectStandardError "$DATA\logs\frankenphp.out"
    Set-Content "$RUN\frankenphp.pid" $p.Id
    for ($i = 0; $i -lt 40 -and -not (Ping); $i++) { Start-Sleep -Milliseconds 500 }
    if (-not (Ping)) { Get-Content "$DATA\logs\frankenphp.out" -Tail 20; Fallo 'FrankenPHP no respondió' }
    Log "FrankenPHP en http://localhost:$($E.IPROY_PUERTO) OK"
  }
  if (-not (Vivo 'worker')) {
    $w = Start-Process -FilePath 'powershell.exe' -WindowStyle Hidden -PassThru -ArgumentList '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', """$RAIZ\tools\worker.ps1""", '-Puerto', $E.IPROY_PUERTO
    Set-Content "$RUN\worker.pid" $w.Id; Log 'worker (tick cada 15 s) OK'
  }
  if ((Test-Path "$RAIZ\modulos\mqtt") -and -not (Vivo 'residente')) {   # conexión permanente con el broker MQTT
    $r = Start-Process -FilePath $FP -WorkingDirectory $FPDIR -ArgumentList 'php-cli', """$RAIZ\tools\residente.php""", 'mqtt' -WindowStyle Hidden -PassThru -RedirectStandardError "$DATA\logs\residente.log"
    Set-Content "$RUN\residente.pid" $r.Id; Log 'residente MQTT OK'
  }
  if ((Test-Path "$RAIZ\modulos\mem") -and -not (Vivo 'residente_mem')) {   # servidor MLLP de IHE PCD-15 (módulo mem)
    $r = Start-Process -FilePath $FP -WorkingDirectory $FPDIR -ArgumentList 'php-cli', """$RAIZ\tools\residente.php""", 'mem' -WindowStyle Hidden -PassThru -RedirectStandardError "$DATA\logs\residente_mem.log"
    Set-Content "$RUN\residente_mem.pid" $r.Id; Log 'residente MLLP (IHE PCD-15) OK'
  }
  AsegurarNucleo
  ProbariProy
  # Con red, deja en el nodo librerías, APIs y archivos estáticos que usa iProy (en segundo plano, ventana oculta)
  $cmd = "try { Invoke-WebRequest -UseBasicParsing -TimeoutSec 300 -Method Post -Headers @{ 'X-IPROY-Tick' = '$($E.IPROY_TICK_TOKEN)' } 'http://127.0.0.1:$($E.IPROY_PUERTO)/_iproy/precargar.php' -OutFile '$DATA\logs\precarga.json' } catch { }"
  Start-Process -FilePath 'powershell.exe' -WindowStyle Hidden -ArgumentList '-NoProfile', '-Command', $cmd | Out-Null
  $url = "http://localhost:$($E.IPROY_PUERTO)/"
  Log "Listo · nodo $($E.IPROY_NODE) · $url"
  if (-not $NoAbrir) { Start-Process $url }
}

function CmdStop {
  CargarEnv
  foreach ($n in 'residente', 'residente_mem', 'worker', 'frankenphp') {
    if (Vivo $n) { Stop-Process -Id (Get-Content "$RUN\$n.pid") -Force; Remove-Item "$RUN\$n.pid"; Log "$n detenido" }
  }
  try { CmdRespaldo -Silencioso } catch { }
  DetenerMariaDB
}

function CmdStatus {
  CargarEnv
  Write-Host ("Nodo {0} · «{1}» · modo {2} · v{3}" -f $E.IPROY_NODE, $E.IPROY_NOMBRE, $E.IPROY_MODO, $E.IPROY_VERSION)
  foreach ($n in 'mariadb', 'frankenphp', 'worker') { Write-Host ("  {0,-11} {1}" -f $n, $(if (Vivo $n) { "activo (pid $(Get-Content "$RUN\$n.pid"))" } else { 'detenido' })) }
  try { Write-Host ('  http        ' + (Invoke-WebRequest -UseBasicParsing -TimeoutSec 5 "http://127.0.0.1:$($E.IPROY_PUERTO)/_iproy/estado.php?fmt=txt").Content) } catch { Write-Host '  http        sin respuesta' }
}

function CmdImportarApp {
  # El nodo usa SOLO iProy (v0.4.2). Lo que la app todavía pida a /GestionMant/... lo sirve el alias del Caddyfile
  # desde app\iProy\; acá solo se informa qué referencias no tienen su archivo dentro de iProy.
  $src = Join-Path $RAIZ 'descargas\iProy'; if (-not (Test-Path $src)) { Fallo "no está $src (copiá ahí la carpeta iProy del servidor)" }
  $dst = Join-Path $APP 'iProy'
  if (Test-Path $dst) { Rename-Item $dst ("iProy.prev_" + (Get-Date -Format 'yyyyMMdd_HHmmss')); Log 'copia anterior guardada en app\iProy.prev_*' }
  $n = 0
  Get-ChildItem -Path $src -Recurse -File | Where-Object {
    $r = $_.FullName.Substring($src.Length)
    -not ($_.Name -like '*.local.php' -or $r -match '\\_estado\\' -or $_.Name -like '*_bk_*' -or
          $_.Name -match '_\d\d-\d\d-20\d\d[a-z]?\.(php|html|js)$' -or $_.Name -eq '.htaccess' -or $_.Name -like '*.log')
  } | ForEach-Object {
    $dest = Join-Path $dst $_.FullName.Substring($src.Length + 1)
    New-Item -ItemType Directory -Force -Path (Split-Path $dest) | Out-Null
    Copy-Item $_.FullName $dest; $n++
  }
  Get-ChildItem $dst -Recurse -File | ForEach-Object { "{0}  {1}" -f (Get-FileHash $_.FullName -Algorithm SHA256).Hash.ToLower(), (Barra $_.FullName.Substring($dst.Length + 1)) } |
    Set-Content "$APP\iProy.sha256"
  Log "OK iProy copiado: $n archivos (sin *.local.php, _estado\, .htaccess ni respaldos)"
  RevisarGestionMant
  if (Test-Path "$DATA\php\nodo.php") { CargarEnv; InstalarLocalPhp }
}
# Prueba real (no por el texto del archivo): ¿la portada de iProy responde sin GestionMant?
function ProbariProy {
  if (-not (Test-Path "$APP\iProy\index.php")) { return }
  try {
    $r = Invoke-WebRequest -UseBasicParsing -TimeoutSec 20 "http://127.0.0.1:$($E.IPROY_PUERTO)/iProy/"
    if ($r.StatusCode -eq 200 -and $r.Content -notmatch 'No se encontr') { Log "iProy responde en /iProy/ OK ($($r.RawContentLength) B)" }
    else { Log "aviso: /iProy/ respondió HTTP $($r.StatusCode). Ver data\logs\php_errors.log" }
  } catch {
    $c = try { [int]$_.Exception.Response.StatusCode } catch { 0 }
    Log "aviso: /iProy/ respondió HTTP $c (si dice que no encontró GestionMant/index.php, su index.php todavía depende de esa carpeta). Ver data\logs\php_errors.log"
  }
}
function RevisarGestionMant {
  $inf = "$DATA\logs\gestionmant_referencias.txt"
  New-Item -ItemType Directory -Force -Path "$DATA\logs" | Out-Null
  $refs = @(Get-ChildItem "$APP\iProy" -Recurse -File -Include *.php, *.js, *.html, *.css, *.json -ErrorAction SilentlyContinue |
    Select-String -Pattern '/GestionMant/[A-Za-z0-9_./-]+\.(js|css|php|html|png|jpg|svg|json|webmanifest|ico)' -AllMatches |
    ForEach-Object { $_.Matches } | ForEach-Object { $_.Value } | Sort-Object -Unique)
  $lineas = @(); $falta = 0
  foreach ($r in $refs) {
    $rel = $r.Substring('/GestionMant/'.Length) -replace '/', '\'
    if (Test-Path (Join-Path "$APP\iProy" $rel)) { $lineas += "ok     $r" } else { $lineas += "FALTA  $r"; $falta++ }
  }
  Escribir $inf (($lineas -join "`n") + "`n")
  if ($refs.Count -eq 0) { Log 'iProy no referencia archivos de /GestionMant/ OK' }
  elseif ($falta -eq 0) { Log "iProy nombra $($refs.Count) archivo(s) de /GestionMant/: todos existen dentro de iProy y el nodo los sirve desde ahí OK" }
  else { Log "aviso: iProy nombra $($refs.Count) archivo(s) de /GestionMant/; $falta no están dentro de iProy (lista: data\logs\gestionmant_referencias.txt). Esas partes no cargarán en el nodo." }
}

function LeerSql($f) {
  if ($f.Name -like '*.gz') {
    $in = [IO.File]::OpenRead($f.FullName); $z = New-Object IO.Compression.GZipStream($in, [IO.Compression.CompressionMode]::Decompress)
    $sr = New-Object IO.StreamReader($z, $UTF8); $t = $sr.ReadToEnd(); $sr.Close(); return $t
  }
  return (Get-Content -Raw -Encoding UTF8 $f.FullName)
}
function Vaciar([string]$b) { Cliente '' ("DROP DATABASE IF EXISTS ``$b``; CREATE DATABASE ``$b`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;") }
function CmdImportarDatos {
  CargarEnv; IniciarMariaDB
  $dir = Join-Path $RAIZ 'descargas\sql'; if (-not (Test-Path $dir)) { Fallo "no está $dir (dejá ahí los .sql exportados de phpMyAdmin)" }
  # Formatos: sql\<base>.sql[.gz] (una base por archivo) o sql\<base>\<tabla>.sql[.gz] (una tabla por archivo)
  $lista = @{}; $exp = Join-Path $dir 'EXPORTACION.sha256'
  if (Test-Path $exp) { foreach ($l in Get-Content $exp) { if ($l -match '^([0-9a-f]{16,64})\s+(\S+)$') { $lista[$Matches[2]] = $Matches[1] } } }
  $script:n = 0
  $cargar = {
    param($f, $base, $txt)
    if ($lista.ContainsKey($f.Name)) {
      $h = (Get-FileHash $f.FullName -Algorithm SHA256).Hash.ToLower()
      if (-not $h.StartsWith($lista[$f.Name])) { Fallo "$($f.Name): el sha256 no coincide con EXPORTACION.sha256" }
      Log "sha256 OK $($f.Name)"
    }
    # DEFINER de producción (legado_x@%) no existe en el nodo: se quita para que las vistas funcionen
    $txt = $txt -replace '(?m)^(CREATE DATABASE|USE) .*$', '' -replace 'DEFINER=`[^`]+`@`[^`]+`', ''
    Cliente $base ("SET FOREIGN_KEY_CHECKS=0; SET UNIQUE_CHECKS=0;`n" + $txt); $script:n++   # restauración: producción tiene filas huérfanas (L-303)
  }
  foreach ($f in Get-ChildItem $dir -File | Where-Object { $_.Name -like '*.sql' -or $_.Name -like '*.sql.gz' }) {
    $base = $f.Name -replace '\.gz$', '' -replace '\.sql$', ''
    if ($BASES -notcontains $base) { Log "aviso: $($f.Name) no es el nombre de una base → se saltea (si es una tabla, va en sql\<base>\)"; continue }
    if ($SoloBase -and $base -ne $SoloBase) { continue }
    Log "importando $($f.Name) → $base (reemplaza la base) …"; Vaciar $base; & $cargar $f $base (LeerSql $f)
  }
  foreach ($d in Get-ChildItem $dir -Directory) {
    if ($BASES -notcontains $d.Name) { Log "aviso: carpeta $($d.Name) no es el nombre de una base → se saltea"; continue }
    if ($SoloBase -and $d.Name -ne $SoloBase) { continue }
    $archivos = Get-ChildItem $d.FullName -File | Where-Object { $_.Name -like '*.sql' -or $_.Name -like '*.sql.gz' } |
      ForEach-Object { $t = LeerSql $_; [pscustomobject]@{ F = $_; T = $t; Vista = ($t -match '(?m)^CREATE (ALGORITHM|VIEW|OR REPLACE)') } }
    Log "importando $($d.Name): $(@($archivos | Where-Object { -not $_.Vista }).Count) tablas y $(@($archivos | Where-Object { $_.Vista }).Count) vistas (reemplaza la base) …"; Vaciar $d.Name
    foreach ($a in @($archivos | Where-Object { -not $_.Vista }) + @($archivos | Where-Object { $_.Vista })) { & $cargar $a.F $d.Name $a.T }
  }
  Log "OK $($script:n) archivos importados"
}

function CmdRespaldo([switch]$Silencioso) {
  CargarEnv
  if (-not (Vivo 'mariadb')) { if ($Silencioso) { return }; Fallo 'MariaDB no está corriendo (start primero)' }
  $f = "$DATA\backups\iproy_$($E.IPROY_NODE)_$(Get-Date -Format 'yyyy-MM-dd_HHmmss').sql"
  $arg = @('--no-defaults', '--host=127.0.0.1', "--port=$($E.IPROY_DB_PORT)", '-uroot', "-p$($E.IPROY_DB_ROOT_PASS)", '--single-transaction', '--routines', '--triggers', "--result-file=$f", '--databases') + $BASES
  if ((Nativo (Bin 'mariadb-dump') $arg "$DATA\logs\respaldo.log" -Append) -ne 0) {
    Remove-Item $f -ErrorAction SilentlyContinue
    if ($Silencioso) { Log 'aviso: el respaldo falló (ver data\logs\respaldo.log)'; return }   # stop sigue y apaga MariaDB
    Fallo 'mariadb-dump falló (ver data\logs\respaldo.log)'
  }
  $gz = "$f.gz"; $in = [IO.File]::OpenRead($f); $out = [IO.File]::Create($gz)
  $z = New-Object IO.Compression.GZipStream($out, [IO.Compression.CompressionMode]::Compress); $in.CopyTo($z); $z.Close(); $in.Close(); Remove-Item $f
  $h = (Get-FileHash $gz -Algorithm SHA256).Hash.ToLower(); Set-Content "$gz.sha256" "$h  $(Split-Path -Leaf $gz)"
  New-Item -ItemType Directory -Force -Path "$DATA\backups\viejos" | Out-Null
  Get-ChildItem "$DATA\backups\iproy_*.sql.gz" | Sort-Object LastWriteTime -Descending | Select-Object -Skip 7 |
    ForEach-Object { Move-Item $_.FullName, "$($_.FullName).sha256" "$DATA\backups\viejos\" -ErrorAction SilentlyContinue }
  Log "respaldo: $(Split-Path -Leaf $gz) ($((Get-Item $gz).Length) B, sha $($h.Substring(0,16)))"
}

function CmdVerificar([switch]$SoloContar) {
  $m = Join-Path $RAIZ 'MANIFEST.sha256'; if (-not (Test-Path $m)) { Fallo 'falta MANIFEST.sha256' }
  $mal = 0
  foreach ($l in Get-Content $m) {
    if ($l -notmatch '^([0-9a-f]{64})\s+\*?(.+)$') { continue }
    $p = Join-Path $RAIZ ($Matches[2] -replace '/', '\')
    if (-not (Test-Path $p) -or (Get-FileHash $p -Algorithm SHA256).Hash.ToLower() -ne $Matches[1]) { Write-Host "  distinto o falta: $($Matches[2])"; $mal++ }
  }
  if ($SoloContar) { return $mal }
  if ($mal) { Fallo "$mal archivos alterados o faltantes" } else { Log 'OK todos los archivos coinciden con el manifiesto' }
}

function CmdInstalarMariaDB {
  # 1.ª opción: runtime ya recortado (tools/recortar-mariadb.sh sobre el ZIP oficial verificado)
  $pre = Get-ChildItem (Join-Path $RAIZ 'descargas') -Filter 'runtime-mariadb-11.4.*-win-x64-recortado.zip' -ErrorAction SilentlyContinue | Sort-Object Name | Select-Object -Last 1
  $sums = Join-Path $RAIZ 'descargas\sha256sums.txt'
  if ($pre -and (Test-Path $sums)) {
    $hp = (Get-FileHash $pre.FullName -Algorithm SHA256).Hash.ToLower()
    if (-not ((Get-Content $sums) -match $hp)) { Fallo "el sha256 de $($pre.Name) NO figura en sha256sums.txt" }
    Log "runtime recortado verificado OK ($($hp.Substring(0,16)))"
    if (Test-Path "$MDB\bin") { Rename-Item $MDB ("mariadb.prev_" + (Get-Date -Format 'yyyyMMdd_HHmmss')) }
    if (Test-Path $MDB) { Remove-Item $MDB -Recurse -Force }
    Expand-Archive -Path $pre.FullName -DestinationPath $MDB
    & "$MDB\bin\mariadbd.exe" --version
    Log 'OK MariaDB instalado en runtime\win-x64\mariadb'
    return
  }
  $zip = Get-ChildItem (Join-Path $RAIZ 'descargas') -Filter 'mariadb-11.4.*-winx64.zip' -ErrorAction SilentlyContinue | Sort-Object Name | Select-Object -Last 1
  if (-not $zip) { Fallo 'no está descargas\mariadb-11.4.x-winx64.zip (bajalo de https://mariadb.org/download/)' }
  $h = (Get-FileHash $zip.FullName -Algorithm SHA256).Hash.ToLower()
  $sums = Join-Path $RAIZ 'descargas\sha256sums.txt'
  if (Test-Path $sums) {
    if ((Get-Content $sums) -match $h) { Log "sha256 verificado contra sha256sums.txt OK ($($h.Substring(0,16)))" } else { Fallo "el sha256 de $($zip.Name) NO figura en sha256sums.txt: no se instala" }
  } else { Log "aviso: sin sha256sums.txt; sha $($h.Substring(0,16)) (compará con la página de descarga)" }
  if (Test-Path "$MDB\bin") { Rename-Item $MDB ("mariadb.prev_" + (Get-Date -Format 'yyyyMMdd_HHmmss')) }
  $tmp = Join-Path $env:TEMP ("iproy_mariadb_" + [guid]::NewGuid().ToString('N'))
  Expand-Archive -Path $zip.FullName -DestinationPath $tmp
  $dir = (Get-ChildItem $tmp -Directory | Select-Object -First 1).FullName
  # recorte (igual que tools/recortar-mariadb.sh): sin pruebas, encabezados, símbolos ni motores que no se usan
  foreach ($d in 'include', 'man', 'mariadb-test', 'mysql-test', 'sql-bench', 'lib\pkgconfig') { Remove-Item (Join-Path $dir $d) -Recurse -Force -ErrorAction SilentlyContinue }
  Get-ChildItem $dir -Recurse -File -Include '*.pdb', '*.lib', '*.a' | Remove-Item -Force
  foreach ($x in 'ha_rocksdb', 'ha_mroonga', 'ha_spider', 'ha_connect', 'ha_oqgraph', 'ha_sphinx', 'ha_s3', 'ha_federatedx', 'handlersocket', 'ha_test_sql_discovery') { Remove-Item (Join-Path $dir "lib\plugin\$x.dll") -Force -ErrorAction SilentlyContinue }
  $keep = '^(mariadbd|mysqld|mariadb|mysql|mariadb-admin|mysqladmin|mariadb-dump|mysqldump|mariadb-check|mariadb-upgrade|mysql_upgrade|my_print_defaults|mariadb-tzinfo-to-sql|mariadb-install-db|mysql_install_db)\.exe$'
  Get-ChildItem (Join-Path $dir 'bin') -File | Where-Object { $_.Extension -ne '.dll' -and $_.Name -notmatch $keep } | Remove-Item -Force
  if (Test-Path $MDB) { Remove-Item $MDB -Recurse -Force }   # solo quedaba LEEME.txt
  Copy-Item $dir $MDB -Recurse; Remove-Item $tmp -Recurse -Force   # Copy (no Move): TEMP puede estar en otro disco
  Set-Content "$MDB\VERSION" "origen: $($zip.Name)`nsha256_oficial: $h`nrecortado con iProy-Control instalar-mariadb"
  & "$MDB\bin\mariadbd.exe" --version
  Log 'OK MariaDB instalado en runtime\win-x64\mariadb'
}

function CmdDiagnostico {
  # Revisa la PC ANTES de init/start y dice exactamente qué falta. No modifica nada.
  $script:ok = 0; $script:mal = 0
  function Chk([bool]$c, [string]$m, [string]$ayuda = '') { if ($c) { Write-Host "  OK  $m" -ForegroundColor Green; $script:ok++ } else { Write-Host "  X   $m" -ForegroundColor Red; if ($ayuda) { Write-Host "      -> $ayuda" }; $script:mal++ } }
  Write-Host "Diagnóstico iProy-Portable v$VER en $env:COMPUTERNAME ($([Environment]::OSVersion.VersionString))"
  Chk ([Environment]::Is64BitOperatingSystem) 'Windows de 64 bits' 'El nodo necesita Windows 10/11 x64'
  Chk ($PSVersionTable.PSVersion.Major -ge 5) "PowerShell $($PSVersionTable.PSVersion)" 'Se necesita PowerShell 5.1 o superior'
  $pol = Get-ExecutionPolicy -Scope MachinePolicy; Chk ($pol -eq 'Undefined' -or $pol -eq 'Bypass' -or $pol -eq 'Unrestricted' -or $pol -eq 'RemoteSigned') "Política de ejecución por GPO: $pol" 'Pedile a TI que permita scripts (la GPO anula -ExecutionPolicy Bypass)'
  Chk ($RAIZ.Length -lt 120) "Largo de la ruta del paquete: $($RAIZ.Length) caracteres" 'Mové la carpeta a una ruta más corta (Windows 5.1 no maneja más de 260 caracteres en total)'
  try { $t = Join-Path $RAIZ ('_prueba_' + [guid]::NewGuid().ToString('N')); Set-Content $t 'x'; Remove-Item $t; $w = $true } catch { $w = $false }
  Chk $w 'Se puede escribir en la carpeta del paquete' 'Copiá el paquete a una carpeta de tu usuario (no en Archivos de programa)'
  Chk (Test-Path $FP) 'FrankenPHP (runtime\win-x64\frankenphp\frankenphp.exe)' 'Ejecutá tools\bajar-runtime.ps1'
  Chk (Test-Path "$MDB\bin\mariadbd.exe") 'MariaDB (runtime\win-x64\mariadb\bin\mariadbd.exe)' 'Ejecutá: iProy-Control.bat instalar-mariadb'
  $vc = @('vcruntime140.dll', 'vcruntime140_1.dll', 'msvcp140.dll') | Where-Object { -not (Test-Path "$FPDIR\$_") -and -not (Test-Path "$MDB\bin\$_") -and -not (Test-Path "$env:WINDIR\System32\$_") }
  Chk (-not $vc) 'DLL de Visual C++ 2015-2022 disponibles' "Faltan: $($vc -join ', '). Se copian solas desde MariaDB al iniciar, o instalá vc_redist.x64.exe"
  foreach ($x in 'pdo_mysql', 'mbstring', 'openssl', 'sodium', 'intl', 'curl', 'zip', 'gd') { if (-not (Test-Path "$FPDIR\ext\php_$x.dll")) { Chk $false "Extensión PHP $x" 'El ZIP de FrankenPHP está incompleto: volvé a bajarlo' } }
  $puertos = @(8080, 8443, 3407); if (Test-Path "$DATA\iproy.env") { CargarEnv; $puertos = @([int]$E.IPROY_PUERTO, [int]$E.IPROY_PUERTO_TLS, [int]$E.IPROY_DB_PORT) }
  foreach ($pt in $puertos) {
    $usado = [bool](Get-NetTCPConnection -State Listen -LocalPort $pt -ErrorAction SilentlyContinue)
    $nuestro = $false; if ($usado -and (Test-Path "$DATA\iproy.env")) { $nuestro = (Vivo 'mariadb') -or (Vivo 'frankenphp') }
    Chk ((-not $usado) -or $nuestro) "Puerto $pt libre" "Otro programa (¿XAMPP, otro MySQL?) usa el puerto ${pt}: cerralo o cambiá el puerto en data\iproy.env"
  }
  $bloq = @(Get-ChildItem -Path $RAIZ -Recurse -File -Include '*.exe', '*.dll', '*.ps1' -ErrorAction SilentlyContinue | Where-Object { Get-Item -LiteralPath $_.FullName -Stream Zone.Identifier -ErrorAction SilentlyContinue }).Count
  Chk ($bloq -eq 0) "Archivos marcados como «bajados de Internet»: $bloq" 'En PowerShell, dentro de la carpeta: Get-ChildItem -Recurse | Unblock-File'
  if (Test-Path "$DATA\iproy.env") { CargarEnv; Write-Host "  i   Nodo existente: $($E.IPROY_NODE) «$($E.IPROY_NOMBRE)» (data\ ya creada; para un nodo nuevo renombrala)" } else { Write-Host '  i   Todavía no hay nodo: el siguiente paso es «init»' }
  if (Test-Path (Join-Path $RAIZ 'MANIFEST.sha256')) { $mv = CmdVerificar -SoloContar; Chk ($mv -eq 0) "Archivos contra MANIFEST: $mv distintos o faltantes" 'Volvé a copiar el paquete' }
  Write-Host ''; Write-Host "Resultado: $($script:ok) OK · $($script:mal) para resolver" -ForegroundColor $(if ($script:mal) { 'Yellow' } else { 'Green' })
}

# ---------- acceso directo en el escritorio y en el menú Inicio (1.1.2) ----------
function ProductoNombre { try { $n = (Get-Content (Join-Path $RAIZ 'producto.json') -Raw | ConvertFrom-Json).nombre } catch { $n = $null }; if ($n) { $n } else { 'iProy' } }
function CmdAccesoDirecto([string]$Quitar) {
  $prod = ProductoNombre
  $esc = [Environment]::GetFolderPath('Desktop'); $menu = Join-Path ([Environment]::GetFolderPath('Programs')) $prod
  $lnkE = Join-Path $esc "$prod.lnk"; $lnkM = Join-Path $menu "$prod.lnk"; $lnkS = Join-Path $menu "Detener $prod.lnk"
  if ($Quitar -eq '--quitar') { Remove-Item $lnkE, $lnkS, $lnkM -ErrorAction SilentlyContinue; Remove-Item $menu -ErrorAction SilentlyContinue; Log "OK accesos directos de $prod quitados"; return }
  # ícono .ico a partir del logo del producto (un .lnk no acepta PNG)
  $ico = Join-Path $RAIZ 'app\app\logo.ico'; $png = Join-Path $RAIZ 'app\app\logo-512.png'; if (-not (Test-Path $png)) { $png = Join-Path $RAIZ 'app\app\logo.png' }
  try { Add-Type -AssemblyName System.Drawing; $bmp = New-Object System.Drawing.Bitmap ([System.Drawing.Image]::FromFile($png)), 256, 256
    $icon = [System.Drawing.Icon]::FromHandle($bmp.GetHicon()); $fs = [IO.File]::Create($ico); $icon.Save($fs); $fs.Close() } catch { $ico = "$env:SystemRoot\System32\shell32.dll,13" }
  $bat = Join-Path $RAIZ (([IO.Path]::GetFileNameWithoutExtension($PSCommandPath)) + '.bat'); if (-not (Test-Path $bat)) { $bat = (Get-ChildItem $RAIZ -Filter '*-Control.bat' | Select-Object -First 1).FullName }
  $sh = New-Object -ComObject WScript.Shell; New-Item -ItemType Directory -Force -Path $menu | Out-Null
  foreach ($d in @(@($lnkE, 'abrir', "Inicia $prod y lo abre en el navegador"), @($lnkM, 'abrir', "Inicia $prod y lo abre en el navegador"), @($lnkS, 'stop', "Detiene $prod (hace una copia de seguridad antes)"))) {
    $l = $sh.CreateShortcut($d[0]); $l.TargetPath = $bat; $l.Arguments = $d[1]; $l.WorkingDirectory = $RAIZ; $l.Description = $d[2]; $l.IconLocation = $ico; $l.WindowStyle = 7; $l.Save() }
  Log "OK escritorio: $lnkE"; Log "OK menú Inicio: $prod (y «Detener $prod»)"
}
function CmdAbrir {
  CargarEnv
  if (Vivo 'frankenphp') { Log "$(ProductoNombre) ya está funcionando · http://localhost:$($E.IPROY_PUERTO)/"; Start-Process "http://localhost:$($E.IPROY_PUERTO)/"; return }
  CmdStart
}

switch ($Comando) {
  'init' { CmdInit }
  'abrir' { CmdAbrir }
  'acceso-directo' { CmdAccesoDirecto $SoloBase }
  'start' { CmdStart }
  'stop' { CmdStop }
  'status' { CmdStatus }
  'instalar-mariadb' { CmdInstalarMariaDB }
  'importar-app' { CmdImportarApp }
  'importar-datos' { CmdImportarDatos }
  'respaldo' { CmdRespaldo }
  'verificar' { CmdVerificar }
  'diagnostico' { CmdDiagnostico }
  'fhir-cliente' { CmdFhirCliente $SoloBase $P2 $P3 $P4 }
  'pim' { CmdPim $SoloBase $P2 $P3 $P4 $P5 $P6 $P7 }
  'red' { CmdRed $SoloBase $P2 }
  'nombre' { CmdNombre $SoloBase }
  'precargar' { CmdPrecargar }
  'clave-auth' { CmdClaveAuth $SoloBase }
  'nucleo' { CmdNucleo $SoloBase $P2 }
  'modulo' { CmdModulo $SoloBase $P2 $P3 }
  'usuario' { CmdUsuario $SoloBase $P2 $P3 $P4 }
  'colmena' { CmdColmena $SoloBase $P2 $P3 $P4 $P5 }
  default { Get-Content $MyInvocation.MyCommand.Path -TotalCount 14 | Select-Object -Skip 2 }
}
