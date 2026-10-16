@echo off

REM ==============================================================================
REM Installs SOLR as a service
REM ==============================================================================
REM Examples:
REM ---------
REM installAsService

REM Please change the following two lines if you install SOLR MORE THAN ONCE!
SET SERVICE_NAME=SOLR
SET SERVICE_DESCRIPTION=SOLR service for Enhanced Retrieval
SET PLATFORM=win64

REM Check for admin rights
net session >nul 2>&1
if %errorLevel% == 0 (
  GOTO start
) else (
  GOTO adminhint
)

:adminhint
echo You need administrator rights to run this script.
goto end
:start

IF [%1] == [/?] goto usage

set FG=1
set SOLR_DIR=%~dp0\..\solr-8.11.1\bin\
IF NOT EXIST %SOLR_DIR% GOTO unzip
set NSSMEXE=%CD%\service\%PLATFORM%\nssm.exe

IF "%1" == "java_home" (
   SET JAVA_HOME=%2
   CALL :dequote JAVA_HOME
) 
IF "%JAVA_HOME%" == "" goto nojavahome
IF NOT EXIST "%JAVA_HOME%" GOTO nojavahome

:install
"%NSSMEXE%" install "%SERVICE_NAME%" "%SOLR_DIR%solr.cmd" "start" -f
"%NSSMEXE%" set "%SERVICE_NAME%" Description "%SERVICE_DESCRIPTION%"

IF %ERRORLEVEL% NEQ 0 GOTO failed

timeout /t 5 >NUL
net start SOLR

echo.
echo You should be able to access http://localhost:8983
echo.

goto end

:unzip
@echo SOLR directory not found. forgot to unzip? please check manual
goto end

:failed
@echo SOLR installation FAILED!
goto end

:nojavahome
echo No JAVA_HOME found. maybe Java is not installed? Java 8 or higher is required
goto end

:usage
echo.
echo    ===================================================================
echo                   Installs SOLR as a Windows service
echo.
echo             -- Make sure that Java 8 or higher is installed! --  
echo    ===================================================================
echo.
echo Usage:
echo installAsService  (if JAVA_HOME is set)
echo.
echo If JAVA_HOME is not set you can use the following:
echo installAsService java_home "path/to/jreOrjdk"
echo.
goto end

:DeQuote
for /f "delims=" %%A in ('echo %%%1%%') do set %1=%%~A
Goto :end

:end


