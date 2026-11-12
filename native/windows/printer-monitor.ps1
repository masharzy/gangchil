[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new()
$ErrorActionPreference = 'Stop'
while ($true) {
 try {
  $physical = @(Get-PnpDevice -PresentOnly -ErrorAction SilentlyContinue | Where-Object { $_.InstanceId -like 'USBPRINT\*' } | ForEach-Object { ($_.FriendlyName -replace '[^a-zA-Z0-9]','').ToLower() })
  $printers = @(Get-CimInstance Win32_Printer | ForEach-Object {
   $state = 'unknown'; $reason = 'Availability not confirmed by Windows'
   if ($_.WorkOffline -or $_.PrinterStatus -eq 7) { $state='offline'; $reason='Windows reports this printer offline' }
   elseif ($_.PortName -like 'USB*') {
    $model=($_.DriverName -replace '[^a-zA-Z0-9]','').ToLower()
    $matched=@($physical | Where-Object { $_.Contains($model) -or ($model.Contains($_) -and $_.Length -gt 5) }).Count -gt 0
    if($matched){$state='online';$reason='USB device present'}else{$state='offline';$reason='USB printer not detected'}
   } elseif ($_.PrinterStatus -eq 4 -or $_.PrinterStatus -eq 5) { $state='online';$reason='Windows reports printer active' }
   [PSCustomObject]@{name=$_.Name;state=$state;reason=$reason;port=$_.PortName}
  })
  [Console]::WriteLine((ConvertTo-Json -InputObject $printers -Compress));[Console]::Out.Flush()
 } catch { [Console]::WriteLine('[]');[Console]::Out.Flush() }
 Start-Sleep -Milliseconds 1500
}
