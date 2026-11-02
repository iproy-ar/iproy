# Worker del nodo en Windows: llama a /_iproy/tick.php cada 15 s y, cada 5 min, al puente iProy → Servidor PIM (v0.5).
param([int]$Puerto = 8080)
$RAIZ = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$token = ((Get-Content "$RAIZ\data\secrets.env") | Where-Object { $_ -like 'IPROY_TICK_TOKEN=*' }) -replace '^IPROY_TICK_TOKEN=', ''
$i = 0
while ($true) {
  try { Invoke-WebRequest -UseBasicParsing -TimeoutSec 120 -Headers @{ 'X-IPROY-Tick' = $token } "http://127.0.0.1:$Puerto/_iproy/tick.php" | Out-Null } catch { }
  if ($i % 20 -eq 0) {
    try { Invoke-WebRequest -UseBasicParsing -TimeoutSec 600 -Method Post -Headers @{ 'X-IPROY-Tick' = $token } "http://127.0.0.1:$Puerto/pim/admin/importar.php" -OutFile "$RAIZ\data\logs\pim_importacion.json" } catch { }
  }
  $i++
  Start-Sleep -Seconds 15
}
