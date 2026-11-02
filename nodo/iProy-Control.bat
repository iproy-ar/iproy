@echo off
rem iProy Portable - lanzador Windows. Doble clic = iniciar el nodo.
setlocal
set "CMD=%~1"
if "%CMD%"=="" set "CMD=start"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0iProy-Control.ps1" %CMD% %2 %3 %4 %5 %6 %7 %8
if errorlevel 1 pause
endlocal
