Unicode true
!include "MUI2.nsh"
!define MUI_ICON "app-icon.ico"
Name "Gangchil"
OutFile "dist/Gangchil-Setup.exe"
InstallDir "$LOCALAPPDATA\Programs\Gangchil"
RequestExecutionLevel user
SetCompressor /SOLID lzma
!insertmacro MUI_PAGE_WELCOME
!insertmacro MUI_PAGE_INSTFILES
!define MUI_FINISHPAGE_RUN "$INSTDIR\Gangchil.exe"
!insertmacro MUI_PAGE_FINISH
!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES
!insertmacro MUI_LANGUAGE "English"
Section "Gangchil"
SetOutPath "$INSTDIR"
File /r "dist\Gangchil-win32-x64\*"
CreateShortcut "$DESKTOP\Gangchil.lnk" "$INSTDIR\Gangchil.exe" "" "$INSTDIR\resources\app\app-icon.ico"
CreateDirectory "$SMPROGRAMS\Gangchil"
CreateShortcut "$SMPROGRAMS\Gangchil\Gangchil.lnk" "$INSTDIR\Gangchil.exe" "" "$INSTDIR\resources\app\app-icon.ico"
WriteUninstaller "$INSTDIR\Uninstall.exe"
WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\Gangchil" "DisplayName" "Gangchil"
WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\Gangchil" "UninstallString" '$\"$INSTDIR\Uninstall.exe$\"'
SectionEnd
Section "Uninstall"
Delete "$DESKTOP\Gangchil.lnk"
RMDir /r "$SMPROGRAMS\Gangchil"
RMDir /r "$INSTDIR"
DeleteRegKey HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\Gangchil"
SectionEnd
