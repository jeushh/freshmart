@echo off
start "FreshMart API" cmd /k "cd /d %~dp0\..ppspi && php artisan serve --host=127.0.0.1 --port=8000"
start "FreshMart Web" cmd /k "cd /d %~dp0\..pps\web && npm run dev -- --host 127.0.0.1"
