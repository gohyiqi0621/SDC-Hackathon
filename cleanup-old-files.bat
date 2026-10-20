@echo off
REM ---------------------------------------------------------------------
REM  cleanup-old-files.bat - removes files left over from the old layout.
REM  Double-click it once from the SDC-Hackathon folder. It only deletes
REM  the paths listed below, then deletes itself.
REM ---------------------------------------------------------------------
cd /d "%~dp0"

echo Removing old email folder (now the dashboard in src\)...
if exist "email" rmdir /s /q "email"

echo Removing old design files...
del /q "public\assets\css\base.css" "public\assets\css\layout.css" "public\assets\css\components.css" "public\assets\css\email.css" 2>nul
del /q "public\assets\js\main.js" "public\assets\js\ui.js" "public\assets\js\api.js" "public\assets\js\email.js" 2>nul
if exist "public\assets\fonts" rmdir /s /q "public\assets\fonts"
if exist "public\assets\icons" rmdir /s /q "public\assets\icons"
if exist "public\fonts" rmdir /s /q "public\fonts"

echo Removing old views and controllers...
if exist "src\Views\email" rmdir /s /q "src\Views\email"
del /q "src\Views\partials\icons.php" "src\Views\layouts\flashes.php" 2>nul
del /q "src\Views\financial\_nav.php" "src\Views\chatbot\customer.php" "src\Views\chatbot\staff.php" "src\Views\chatbot\index.php" 2>nul
del /q "src\Controllers\EmailController.php" 2>nul

echo Done.
pause
(goto) 2>nul & del "%~f0"
