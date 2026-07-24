# Run this once as Administrator to auto-start the server on login
$taskName = "ChoreographyManager"
$scriptPath = "D:\Programs\Adam\Artist Asset Tools\start-server.vbs"

$action = New-ScheduledTaskAction -Execute "wscript.exe" -Argument "`"$scriptPath`""
$trigger = New-ScheduledTaskTrigger -AtLogOn
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -RunLevel Highest -Force

Write-Host "Scheduled task '$taskName' created. Server will auto-start on login."
Write-Host "To start now, run: Start-ScheduledTask -TaskName '$taskName'"
