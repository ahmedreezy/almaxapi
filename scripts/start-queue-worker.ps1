# start-queue-worker.ps1
# Runs `php artisan queue:work` and restarts it automatically if it crashes.
# Usage (one-time, to register as a scheduled task):
#   Register-ScheduledTask -TaskName "AlmaxQueueWorker" `
#     -Action (New-ScheduledTaskAction -Execute "powershell.exe" `
#               -Argument "-WindowStyle Hidden -NonInteractive -File `"$PSScriptRoot\start-queue-worker.ps1`"") `
#     -Trigger (New-ScheduledTaskTrigger -AtLogOn) `
#     -Settings (New-ScheduledTaskSettingsSet -RestartCount 99 -RestartInterval (New-TimeSpan -Minutes 1)) `
#     -RunLevel Highest -Force

$apiDir   = Split-Path -Parent $PSScriptRoot   # …\almaxapi
$phpPath  = "php"                               # adjust if php.exe is not on PATH

Write-Host "[queue] Starting Almax queue worker in $apiDir" -ForegroundColor Cyan

while ($true) {
    Write-Host "[queue] Launching: php artisan queue:work --sleep=3 --tries=3 --max-time=3600" -ForegroundColor Yellow
    $proc = Start-Process -FilePath $phpPath `
                          -ArgumentList "artisan", "queue:work", "--sleep=3", "--tries=3", "--max-time=3600" `
                          -WorkingDirectory $apiDir `
                          -NoNewWindow -PassThru -Wait
    Write-Host "[queue] Worker exited with code $($proc.ExitCode). Restarting in 5 seconds..." -ForegroundColor Red
    Start-Sleep -Seconds 5
}
