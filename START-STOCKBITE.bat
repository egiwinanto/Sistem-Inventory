@echo off
setlocal
cd /d "%~dp0"

set PHP_BIN=php
where php >nul 2>nul
if errorlevel 1 (
    if exist "C:\xampp\php\php.exe" (
        set PHP_BIN=C:\xampp\php\php.exe
    ) else (
        echo.
        echo PHP tidak ditemukan.
        echo Instal XAMPP atau tambahkan PHP ke PATH.
        echo.
        pause
        exit /b 1
    )
)

%PHP_BIN% -m | findstr /I "pdo_sqlite" >nul
if errorlevel 1 (
    echo.
    echo Ekstensi PDO SQLite belum aktif.
    echo Buka php.ini, aktifkan extension=pdo_sqlite dan extension=sqlite3,
    echo lalu jalankan file ini kembali.
    echo.
    pause
    exit /b 1
)

start "" http://127.0.0.1:8080
%PHP_BIN% -S 127.0.0.1:8080 router.php
endlocal
