@echo off

REM ========================================
REM Uninstalls SOLR as a Windows service
REM ========================================
REM no parameters
REM ========================================

SET SERVICE_NAME=SOLR
SET PLATFORM=win64

IF [%1] == [/?] goto usage

REM Check for admin rights
fsutil > nul
IF %ERRORLEVEL% NEQ 0 GOTO adminhint
goto start
:adminhint
echo You need to be administrator to run this script.
goto end
:start

net stop SOLR
timeout 5

set NSSMEXE=%CD%\service\%PLATFORM%\nssm.exe
"%NSSMEXE%" remove "%SERVICE_NAME%" confirm 

goto end

:usage
echo ========================================
echo  Uninstalls SOLR as a Windows service
echo ========================================
echo  no parameters
echo ========================================

:end