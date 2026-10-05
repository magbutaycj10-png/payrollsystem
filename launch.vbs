' launch.vbs — double-click this to start the Payroll System silently.
' Finds launch.bat in the same folder as this .vbs file automatically,
' so it works no matter what drive or folder the app is copied to.

Dim sh, fso, thisDir, bat
Set sh  = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")

' Get the folder this .vbs file is in (self-relative, no hardcoded path)
thisDir = fso.GetParentFolderName(WScript.ScriptFullName)
bat     = thisDir & "\launch.bat"

' Run launch.bat hidden (window style 0 = invisible)
sh.Run Chr(34) & bat & Chr(34), 0, False

Set sh  = Nothing
Set fso = Nothing
