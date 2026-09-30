$ErrorActionPreference = "Stop"
$TaskName = "ValeVisitor-AcquaValeSync"
$Php = 'C:\xampp\php\php-win.exe'
$Script = 'C:\xampp\htdocs\visitor\acquavale_worker.php'
if (!(Test-Path -LiteralPath $Php) -or !(Test-Path -LiteralPath $Script)) {
    throw 'PHP sem console ou worker do AcquaVale nao encontrado.'
}
$Action = New-ScheduledTaskAction -Execute $Php -Argument ('"' + $Script + '"') -WorkingDirectory 'C:\xampp\htdocs\visitor'
$Trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
$Settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -MultipleInstances IgnoreNew -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
$Principal = New-ScheduledTaskPrincipal -UserId ([Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited
Register-ScheduledTask -TaskName $TaskName -Action $Action -Trigger $Trigger -Settings $Settings -Principal $Principal -Force
Write-Host "Tarefa $TaskName instalada."
