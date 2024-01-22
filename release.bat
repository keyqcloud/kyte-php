@echo off
setlocal enabledelayedexpansion

@echo off
setlocal enabledelayedexpansion

:: Define the print_error function
goto :main

:print_error
if not "%~1"=="" (
    echo ^<ESC^>[1;31m%~1^<ESC^>[0m
)
exit /b

:main
if "%~1"=="" goto :eof

:: Check the CHANGELOG.md
for /F "tokens=2" %%i in ('findstr "## " CHANGELOG.md') do (
    set changelog_version=%%i
    goto version_check
)
:version_check
if not "%changelog_version%"=="%~1" (
    call :print_error "Version in CHANGELOG.md does not match the release version."
    exit /b 1
)

:: Define the path to the Version.php file
set "php_version_file_path=src\Core\Version.php"

:: Extract the version from the PHP file
for /F "tokens=2 delims==" %%a in ('findstr /C:"const MAJOR" "%php_version_file_path%"') do set major=%%a
for /F "tokens=2 delims==" %%b in ('findstr /C:"const MINOR" "%php_version_file_path%"') do set minor=%%b
for /F "tokens=2 delims==" %%c in ('findstr /C:"const PATCH" "%php_version_file_path%"') do set patch=%%c

:: Remove semicolons
set "major=!major:;=!"
set "minor=!minor:;=!"
set "patch=!patch:;=!"

:: Combine to form the version
set "php_version=!major!.!minor!.!patch!"

:: Compare the desired version with the PHP version
if not "%~1"=="!php_version!" (
    call :print_error "Version mismatch: Desired version %~1, Version.php is !php_version!"
    exit /b 1
)

echo Creating tag for release version %~1
git tag "v%~1"

if not ERRORLEVEL 0 (
    call :print_error "Git tag creation failed."
)

echo Git tag created successfully for v%~1.

:: Push the tag to the origin
git push origin --tags

if not ERRORLEVEL 0 (
    call :print_error "Git push failed."
)

echo Git push successful. New release v%~1 is available
