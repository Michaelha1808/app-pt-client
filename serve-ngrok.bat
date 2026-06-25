@echo off
REM ── CaloEye: build PWA + serve qua ngrok (test push/deep-link trên thiết bị thật) ──
REM Yêu cầu: ngrok đã cấu hình authtoken (ngrok config add-authtoken <token>)

echo [1/3] Building frontend (PWA service worker)...
call npm run build:dev
if errorlevel 1 (
  echo Build failed. Aborting.
  exit /b 1
)

echo [2/3] Starting Laravel server on http://127.0.0.1:8000 ...
start "caloeye-laravel" cmd /c "php artisan serve --host=127.0.0.1 --port=8000"

echo [3/3] Opening ngrok tunnel -> port 8000 ...
echo Lay URL public tai dashboard: http://127.0.0.1:4040
D:\laragon\bin\ngrok\ngrok.exe http 8000
