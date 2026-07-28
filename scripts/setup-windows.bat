@echo off
cd /d %~dp0\..ppspi
if not exist .env copy .env.example .env
call composer install
php artisan key:generate
cd /d %~dp0\..pps\web
if not exist .env copy .env.example .env
call npm install
echo Setup complete. Run scripts\start-windows.bat
