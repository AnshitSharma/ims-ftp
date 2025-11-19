@echo off
REM Server Build Validation Test - Windows
REM Usage: run_server_validation.bat [base_url]

setlocal

set BASE_URL=%1

if "%BASE_URL%"=="" (
    set BASE_URL=http://localhost:8000
)

echo.
echo ========================================================================
echo BDC IMS - Server Build Validation Test
echo ========================================================================
echo Base URL: %BASE_URL%
echo ========================================================================
echo.
echo This test will:
echo   - Create 3 complete server configurations
echo   - Test all component types (CPU, RAM, Storage, NIC, PCIe, HBA)
echo   - Validate riser slot edge cases
echo   - Validate onboard NIC detection
echo   - Generate detailed logs
echo.
echo Press any key to start or Ctrl+C to cancel...
pause >nul

php tests\server_build_validation.php %BASE_URL%

set EXIT_CODE=%ERRORLEVEL%

echo.
echo ========================================================================
if %EXIT_CODE% EQU 0 (
    echo Test completed successfully!
    echo Check detailed logs in: tests\reports\
) else (
    echo Test failed with errors.
    echo Check logs for details: tests\reports\
)
echo ========================================================================
echo.

pause
exit /b %EXIT_CODE%
