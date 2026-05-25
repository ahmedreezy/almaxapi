@echo off
:: Double-click this file (or add it to Windows startup) to launch the queue worker.
:: It restarts automatically if it crashes.
title Almax Queue Worker
:loop
cd /d "%~dp0.."
echo [%TIME%] Starting php artisan queue:work ...
php artisan queue:work --sleep=3 --tries=3 --max-time=3600
echo [%TIME%] Worker stopped. Restarting in 5 seconds...
timeout /t 5 /nobreak >nul
goto loop
