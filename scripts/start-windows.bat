@echo off
start "FreshMart API" cmd /k "cd /d ""%~dp0\..\apps\api"" && php artisan serve --host=127.0.0.1 --port=8000"
start "FreshMart Web" cmd /k "cd /d ""%~dp0\..\apps\web"" && npm run dev -- --host 127.0.0.1"
