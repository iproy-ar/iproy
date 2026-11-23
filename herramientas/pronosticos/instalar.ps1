# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
#
# Pronósticos de iProy con Google TimesFM: instalación en Windows 10/11 (la usa el instalador de iProy; también se corre a mano).
# Deja TODO dentro de una sola carpeta, sin instalar Python en el sistema ni tocar otros Python que haya en la PC:
#   <Carpeta>\runtime\          Python 3.12.10 «embebible» de python.org (sin registro, sin PATH, sin accesos directos)
#   <Carpeta>\timesfm_iproy.py  el programa que calcula y guarda los pronósticos en el nodo
#   <Carpeta>\modelo\           el modelo google/timesfm-2.5-200m-pytorch (925 MB, se baja una vez en partes de 300 MB verificadas)
# y programa la corrida semanal (lunes 03:17) en el Programador de tareas, con tu usuario.
# Cada descarga de Python y pip se verifica con SHA-256; PyTorch y TimesFM se instalan con pip fijados a una versión.
#
# Uso: powershell -ExecutionPolicy Bypass -File instalar.ps1 -Nodo C:\...\iProy-Portable [-Carpeta DIR] [-SinModelo] [-SinProgramar] [-SinCorrer]
#      Sin nodo (solo para probar el cálculo): -Carpeta DIR -SinProgramar -SinCorrer
# La última línea es «RESULTADO {json}» (la lee el instalador gráfico). No escribe contraseñas ni tokens.
param([string]$Nodo = '', [string]$Carpeta = '', [switch]$SinModelo, [switch]$SinProgramar, [switch]$SinCorrer)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
try { [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 } catch { }

$PY = @{ url = 'https://www.python.org/ftp/python/3.12.10/python-3.12.10-embed-amd64.zip'; sha = '4acbed6dd1c744b0376e3b1cf57ce906f9dc9e95e68824584c8099a63025a3c3'; version = '3.12.10' }
$PIP = @{ url = 'https://files.pythonhosted.org/packages/07/25/ee8c38ae72d66b844ce8eb9711ba905dba21ca1a8a0c960057c41cd23337/pip-26.3-py3-none-any.whl'
          sha = 'f3b60b20ffaadd4415ed7957212a38ac63fa4c5fa375bd0f4a797777940fad13'; archivo = 'pip-26.3-py3-none-any.whl' }
$TORCH = 'torch==2.15.0'; $PAQUETES = @('timesfm==3.0.2', 'numpy>=1.26.4'); $INDICE = 'https://download.pytorch.org/whl/cpu'
$MODELO = 'google/timesfm-2.5-200m-pytorch'
# El modelo (925 MB) se baja en partes de 300 MB fijado a una revisión: cada parte se verifica con SHA-256 y, si la red se corta,
# sigue desde donde quedó; al final se unen y el archivo entero se verifica contra el SHA-256 que publica Hugging Face.
$MOD = @{ base = 'https://huggingface.co/google/timesfm-2.5-200m-pytorch/resolve/1d952420fba87f3c6dee4f240de0f1a0fbc790e3'
          tam = [long]925181104; sha = '2f776efe6245e42b24bc4153ffdf61810140210e4bd3b01fb21f7aa779ab6ce8'
          config = 'cd3315b760d5cc7e278d7afdf41b897031ced888fc6c115bd9b3ac0ea2c47408'
          partes = @('e173a5f70481ab6bc4be2f3a3166e58746b4ac9de7db46b5c91ae44b8fd06a85', '3146999acf38e32dee5f894c9cd4411cce077a496b421d54cbd0574a19995bd6', '0b5959788ca82e0d9217c120ab315336f7feab33f44f451861c29e0c6031ab01') }
$PARTE = [long]314572800   # 300 MB
$AQUI = Split-Path -Parent $MyInvocation.MyCommand.Path

if (-not $Nodo -and -not $Carpeta) { throw 'Indicá -Nodo o -Carpeta' }
if (-not $Carpeta) { $Carpeta = Join-Path $Nodo 'python' }
$Carpeta = [IO.Path]::GetFullPath($Carpeta)
New-Item -ItemType Directory -Force -Path $Carpeta | Out-Null
$t0 = Get-Date; $rt = Join-Path $Carpeta 'runtime'; $exe = Join-Path $rt 'python.exe'; $desc = Join-Path $Carpeta 'descargas'

function Bajar($url, $destino, $sha) {
  if (-not (Test-Path $destino)) { Write-Host "Bajando $url"; Invoke-WebRequest -UseBasicParsing -Uri $url -OutFile $destino }
  $h = (Get-FileHash -Algorithm SHA256 $destino).Hash.ToLower()
  if ($h -ne $sha) { Remove-Item $destino -Force; throw "SHA-256 distinto en $url (esperado $sha, vino $h)" }
}
function Sha([string]$f) { (Get-FileHash -Algorithm SHA256 $f).Hash.ToLower() }
function UrlFinal([string]$url) {   # Hugging Face redirige a su CDN con una dirección firmada que vence: se pide de nuevo para cada parte
  $r = [Net.HttpWebRequest]::Create($url); $r.Method = 'HEAD'; $r.AllowAutoRedirect = $false; $r.UserAgent = 'iProy-instalador'; $r.Timeout = 60000
  try { $s = $r.GetResponse() } catch [Net.WebException] { if ($_.Exception.Response) { $s = $_.Exception.Response } else { throw } }
  try { $loc = $s.Headers['Location']; if ($loc) { return (New-Object Uri ((New-Object Uri $url), $loc)).AbsoluteUri } else { return $url } } finally { $s.Close() }
}
function BajarParte([string]$url, [string]$archivo, [long]$ini, [long]$largo) {
  $ya = [long]0; if (Test-Path $archivo) { $ya = (Get-Item $archivo).Length }
  if ($ya -gt $largo) { Remove-Item $archivo -Force; $ya = [long]0 }
  if ($ya -eq $largo) { return }
  if ($ya -gt 0) { Write-Host ('   sigue desde {0:N0} MB' -f ($ya / 1MB)) }
  $r = [Net.HttpWebRequest]::Create((UrlFinal $url)); $r.UserAgent = 'iProy-instalador'; $r.Timeout = 60000; $r.ReadWriteTimeout = 120000
  $r.AddRange([long]($ini + $ya), [long]($ini + $largo - 1))
  $s = $r.GetResponse()
  try {
    if ([int]$s.StatusCode -ne 206) { throw "el servidor no aceptó la descarga por partes (HTTP $([int]$s.StatusCode))" }
    $in = $s.GetResponseStream(); $out = [IO.File]::Open($archivo, [IO.FileMode]::Append, [IO.FileAccess]::Write)
    try {
      $buf = New-Object byte[] 1048576; $marca = $ya
      while (($n = $in.Read($buf, 0, $buf.Length)) -gt 0) {
        $out.Write($buf, 0, $n); $ya += $n
        if ($ya - $marca -ge 50MB) { Write-Host ('   {0:N0} de {1:N0} MB' -f ($ya / 1MB), ($largo / 1MB)); $marca = $ya }
      }
    } finally { $out.Close(); $in.Close() }
  } finally { $s.Close() }
  if ($ya -ne $largo) { throw "la parte quedó incompleta ($ya de $largo bytes)" }
}
function BajarModelo([string]$dir) {
  New-Item -ItemType Directory -Force -Path $dir | Out-Null
  $final = Join-Path $dir 'model.safetensors'
  for ($k = 1; ; $k++) { try { Bajar "$($MOD.base)/config.json" (Join-Path $dir 'config.json') $MOD.config; break } catch { if ($k -ge 3) { throw }; Write-Host "config.json: $($_.Exception.Message). Reintento en 60 s"; Start-Sleep -Seconds 60 } }
  if ((Test-Path $final) -and (Get-Item $final).Length -eq $MOD.tam -and (Sha $final) -eq $MOD.sha) { Write-Host 'Modelo ya descargado y verificado'; return }
  $pd = Join-Path $desc 'modelo'; New-Item -ItemType Directory -Force -Path $pd | Out-Null
  $n = $MOD.partes.Count; $arch = @()
  for ($i = 0; $i -lt $n; $i++) {
    $ini = [long]$i * $PARTE; $largo = [Math]::Min($PARTE, $MOD.tam - $ini)
    $f = Join-Path $pd ('model.safetensors.parte{0}de{1}' -f ($i + 1), $n); $arch += $f
    for ($k = 1; ; $k++) {
      try {
        if (-not ((Test-Path $f) -and (Get-Item $f).Length -eq $largo -and (Sha $f) -eq $MOD.partes[$i])) {
          Write-Host ('Parte {0} de {1} ({2:N0} MB)' -f ($i + 1), $n, ($largo / 1MB))
          BajarParte "$($MOD.base)/model.safetensors" $f $ini $largo
          if ((Sha $f) -ne $MOD.partes[$i]) { Remove-Item $f -Force; throw "SHA-256 distinto en la parte $($i + 1): se baja de nuevo" }
        }
        Write-Host ('Parte {0} de {1} verificada' -f ($i + 1), $n); break
      } catch {
        if ($k -ge 5) { throw }
        Write-Host ("Parte {0}: {1}. Reintento {2} de 4 en 60 s (sigue desde donde quedó)" -f ($i + 1), $_.Exception.Message, $k); Start-Sleep -Seconds 60
      }
    }
  }
  Write-Host 'Uniendo las partes'
  $tmp = "$final.uniendo"; $out = [IO.File]::Create($tmp)
  try { foreach ($f in $arch) { $in = [IO.File]::OpenRead($f); try { $in.CopyTo($out, 1048576) } finally { $in.Close() } } } finally { $out.Close() }
  if ((Sha $tmp) -ne $MOD.sha) { Remove-Item $tmp -Force; throw 'El modelo unido no coincide con el SHA-256 publicado por Hugging Face' }
  Move-Item -Force $tmp $final
  Remove-Item -Recurse -Force $pd   # las partes ya no hacen falta (925 MB menos)
  Write-Host "Modelo listo y verificado: $final"
}
function Correr([string[]]$a, [hashtable]$envExtra = @{}, [int]$intentos = 3) {
  foreach ($k in $envExtra.Keys) { Set-Item -Path "Env:$k" -Value $envExtra[$k] }
  for ($i = 1; $i -le $intentos; $i++) {   # una red que se corta un rato no hace fallar la instalación: se reintenta
    Write-Host ('$ python ' + ($a -join ' ') + $(if ($i -gt 1) { " (intento $i de $intentos)" } else { '' }))
    $eap = $ErrorActionPreference; $ErrorActionPreference = 'Continue'   # PowerShell 5.1: lo que pip escribe en stderr no es un error
    try { & $exe @a 2>&1 | ForEach-Object { "$_" } | Select-Object -Last 15 | ForEach-Object { Write-Host $_ } } finally { $ErrorActionPreference = $eap }
    if ($LASTEXITCODE -eq 0) { return }
    if ($i -lt $intentos) { Write-Host 'Falló; reintento en 60 s (si es la red, suele volver sola)'; Start-Sleep -Seconds 60 }
  }
  throw "Falló: python $($a[0..2] -join ' ') (código $LASTEXITCODE, $intentos intentos)"
}

# 1 · Python embebible (si ya está, se reutiliza)
if (-not (Test-Path $exe)) {
  New-Item -ItemType Directory -Force -Path $desc, $rt | Out-Null
  $zip = Join-Path $desc 'python-3.12.10-embed-amd64.zip'
  Bajar $PY.url $zip $PY.sha
  Expand-Archive -Path $zip -DestinationPath $rt -Force
  # habilita site-packages (el embebible viene sin ellas)
  $pth = Get-ChildItem $rt -Filter 'python*._pth' | Select-Object -First 1
  $l = Get-Content $pth.FullName | Where-Object { $_ -notmatch '^#?\s*import site' }
  Set-Content -Path $pth.FullName -Value (@($l) + 'Lib\site-packages' + 'import site') -Encoding ASCII
}
# 2 · pip (desde su wheel verificado), PyTorch CPU y TimesFM
if (-not (Test-Path (Join-Path $rt 'Lib\site-packages\pip'))) {
  $whl = Join-Path $desc $PIP.archivo
  Bajar $PIP.url $whl $PIP.sha
  # se corre desde el wheel con «python -c»: lanzado como «…whl\pip», pip se niega a instalarse a sí mismo en Windows («To modify pip…»)
  Correr @('-c', "import sys; sys.path.insert(0, r'$whl'); from pip._internal.cli.main import main; sys.exit(main(['install', '--no-index', '--no-warn-script-location', '--disable-pip-version-check', r'$whl']))")
}
Correr @('-m', 'pip', 'install', '--no-warn-script-location', '--disable-pip-version-check', '--no-input', '--index-url', $INDICE, $TORCH)
Correr (@('-m', 'pip', 'install', '--no-warn-script-location', '--disable-pip-version-check', '--no-input') + $PAQUETES)
Copy-Item -Force (Join-Path $AQUI 'timesfm_iproy.py') (Join-Path $Carpeta 'timesfm_iproy.py')
$hf = Join-Path $Carpeta 'hf'; $dirModelo = Join-Path $Carpeta 'modelo'
if (-not $SinModelo) { BajarModelo $dirModelo }
$ErrorActionPreference = 'Continue'; $tv = (& $exe -c 'import timesfm, torch; print(torch.__version__)' 2>$null | Select-Object -Last 1); $ErrorActionPreference = 'Stop'
$res = [ordered]@{ carpeta = $Carpeta; python = $exe; python_version = $PY.version; torch = "$tv"; timesfm = '3.0.2'; modelo = $(if ($SinModelo) { $null } else { "$MODELO (rev. 1d95242) en $dirModelo" }); programado = $null; primera_corrida = $null }

# 3 · corrida semanal en el Programador de tareas (con tu usuario; se reemplaza si ya estaba)
if ($Nodo -and -not $SinProgramar) {
  $nombre = 'iProy - Pronosticos TimesFM (' + (Split-Path $Nodo -Leaf) + ')'
  $log = Join-Path $Carpeta 'timesfm.log'
  $acc = New-ScheduledTaskAction -Execute 'cmd.exe' -Argument ('/c ""' + $exe + '" "' + (Join-Path $Carpeta 'timesfm_iproy.py') + '" --nodo "' + $Nodo + '" >> "' + $log + '" 2>&1"') -WorkingDirectory $Carpeta
  $dis = New-ScheduledTaskTrigger -Weekly -DaysOfWeek Monday -At '03:17'
  $cfg = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 2)
  Register-ScheduledTask -TaskName $nombre -Action $acc -Trigger $dis -Settings $cfg -Description 'iProy: calcula los pronósticos de órdenes con Google TimesFM y los guarda en el nodo' -Force | Out-Null
  $res.programado = "Programador de tareas: «$nombre», los lunes a las 03:17"
}
if ($Nodo -and -not $SinCorrer) {
  $env:HF_HOME = $hf; $ErrorActionPreference = 'Continue'
  $sal = & $exe (Join-Path $Carpeta 'timesfm_iproy.py') --nodo $Nodo 2>&1 | ForEach-Object { "$_" }
  $res.primera_corrida = $(if ($LASTEXITCODE -eq 0) { ($sal | Where-Object { $_ -like 'Corrida*' } | Select-Object -Last 1) } else { 'falló: ' + ($sal | Select-Object -Last 1) })
}
$res.segundos = [int]((Get-Date) - $t0).TotalSeconds
Write-Host ('RESULTADO ' + ($res | ConvertTo-Json -Compress))
