# Baja FrankenPHP v1.13.3 (Windows) de GitHub y verifica el sha256 fijado el 28-10-2026.
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
try {
$RAIZ = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$url = 'https://github.com/php/frankenphp/releases/download/v1.13.3/frankenphp-windows-x86_64.zip'
$sha = '89b99aed53fbf8bfe718a816a987001d499bf92e2e1288e882285a8d940ba00e'   # 60415937 B
$zip = Join-Path $RAIZ 'descargas\frankenphp-windows-x86_64.zip'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
Invoke-WebRequest -UseBasicParsing -Uri $url -OutFile $zip -ProxyUseDefaultCredentials
$h = (Get-FileHash $zip -Algorithm SHA256).Hash.ToLower()
if ($h -ne $sha) { Remove-Item $zip; throw "sha256 distinto ($h): NO se instala" }
$dst = Join-Path $RAIZ 'runtime\win-x64\frankenphp'
New-Item -ItemType Directory -Force -Path $dst | Out-Null
Expand-Archive -Path $zip -DestinationPath $dst -Force
Write-Host "OK FrankenPHP verificado ($($sha.Substring(0,16))) y descomprimido en runtime\win-x64\frankenphp"
} catch { Write-Host "ERROR: $_" -ForegroundColor Red }
Read-Host 'Enter para salir'
