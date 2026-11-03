@echo off
rem iProy Portable - lanzador Windows. Doble clic = iniciar el nodo.
setlocal
rem Se pasan TODOS los argumentos con %%*: antes eran %%2..%%8 y se perdia el 9.o (p. ej. el valor de -Vista) (1.3.1)
if "%~1"=="" (
  powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0iProy-Control.ps1" start
) else (
  powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0iProy-Control.ps1" %*
)
if errorlevel 1 pause
endlocal
