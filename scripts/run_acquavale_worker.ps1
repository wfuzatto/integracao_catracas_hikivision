$ErrorActionPreference = 'Stop'
$AppRoot = Split-Path $PSScriptRoot -Parent
$LogDir = Join-Path $AppRoot 'storage/logs'
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
$Process = Start-Process -FilePath 'C:\xampp\php\php.exe' -ArgumentList ('"' + (Join-Path $AppRoot 'acquavale_worker.php') + '"') -WindowStyle Hidden -Wait -PassThru -RedirectStandardOutput (Join-Path $LogDir 'acquavale-worker-last.json') -RedirectStandardError (Join-Path $LogDir 'acquavale-worker-last-error.log')
exit $Process.ExitCode
