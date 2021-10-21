@echo off
goto begin
 
:usage
echo Usage: %~n0
echo.
echo Starts DokuWiki on a Stick (http://www.dokuwiki.org/dokuwiki_on_a_stick)
echo and waits for user to press a key to stop.
goto end
 
:begin
if not "%1"=="" goto usage
cd server
start "Apache server" /B mapache.exe
echo DokuWiki on a Stick started...
echo.
 
:runbrowser
echo Your web browser will now open http://localhost:8800
echo.
if exist ..\dokuwiki\conf\local.php (
	start http://localhost:8800/
) else (
	start http://localhost:8800/install.php
)
 
:wait
echo To stop DokuWiki on a Stick
pause
 
:stop
ApacheKill.exe
echo ... DokuWiki on a Stick stopped.
echo You can close this window now.
 
:end
