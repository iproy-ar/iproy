# Worker del nodo en Windows: llama a /_iproy/tick.php cada 15 s y, cada 5 min, al puente iProy → Servidor PIM (v0.5).
# 1.7.0: entre ticks, la escucha de la colmena por el hub (/_iproy/escuchar.php).
param([int]$Puerto = 8080)
$RAIZ = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$token = ((Get-Content "$RAIZ\data\secrets.env") | Where-Object { $_ -like 'IPROY_TICK_TOKEN=*' }) -replace '^IPROY_TICK_TOKEN=', ''
# Entre un tick y el siguiente, la escucha de la colmena (1.7.0): un pedido saliente que el hub mantiene abierto hasta que llega un sobre
# (espera larga, como AnyDesk en modo relevo). Si no hay hub, o el hub no espera, duerme hasta el próximo tick.
$ProgressPreference = 'SilentlyContinue'
$i = 0; $prox = [DateTime]::MinValue
while ($true) {
  if ((Get-Date) -ge $prox) {
    try { Invoke-WebRequest -UseBasicParsing -TimeoutSec 120 -Headers @{ 'X-IPROY-Tick' = $token } "http://127.0.0.1:$Puerto/_iproy/tick.php" | Out-Null } catch { }
    if ($i % 20 -eq 0) {
      try { Invoke-WebRequest -UseBasicParsing -TimeoutSec 600 -Method Post -Headers @{ 'X-IPROY-Tick' = $token } "http://127.0.0.1:$Puerto/pim/admin/importar.php" -OutFile "$RAIZ\data\logs\pim_importacion.json" } catch { }
    }
    $i++; $prox = (Get-Date).AddSeconds(15)
  }
  $falta = [int][Math]::Floor(($prox - (Get-Date)).TotalSeconds)
  if ($falta -lt 1) { continue }
  $modo = ''
  try { $r = Invoke-WebRequest -UseBasicParsing -TimeoutSec ($falta + 40) -Headers @{ 'X-IPROY-Tick' = $token } "http://127.0.0.1:$Puerto/_iproy/escuchar.php?espera=$falta"; $modo = [string](($r.Content | ConvertFrom-Json).modo) } catch { }
  if ($modo -ne 'espera') { Start-Sleep -Seconds $falta }
}
