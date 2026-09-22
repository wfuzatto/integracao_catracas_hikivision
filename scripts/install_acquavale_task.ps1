$TaskName = "ValeVisitor-AcquaValeSync"
$Php = "C:\xampp\php\php.exe"
$Script = "C:\xampp\htdocs\visitor\acquavale_worker.php"
$Action = New-ScheduledTaskAction -Execute $Php -Argument ('"' + $Script + '"')
$Trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
$Settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit (New-TimeSpan -Minutes 5) -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -MultipleInstances IgnoreNew
Register-ScheduledTask -TaskName $TaskName -Action $Action -Trigger $Trigger -Settings $Settings -RunLevel Highest -Force
Write-Host "Tarefa $TaskName instalada."
