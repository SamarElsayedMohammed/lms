param(
    [Parameter(Mandatory = $true)]
    [int]$TargetProcessId,
    [Parameter(Mandatory = $true)]
    [string]$BaseUrl,
    [int]$DurationSeconds = 60,
    [int]$IntervalSeconds = 5,
    [string]$EvidenceDirectory = "$env:TEMP\skillso-stability",
    [string]$Phase = "active-load"
)

$ErrorActionPreference = 'Stop'
$DurationSeconds = [Math]::Max(1, [Math]::Min(259200, $DurationSeconds))
$IntervalSeconds = [Math]::Max(1, [Math]::Min(60, $IntervalSeconds))
New-Item -ItemType Directory -Path $EvidenceDirectory -Force | Out-Null
$safePhase = $Phase -replace '[^a-zA-Z0-9_-]', '_'
$runId = "$([DateTime]::UtcNow.ToString('yyyyMMddTHHmmssZ'))-$safePhase"
$csvPath = Join-Path $EvidenceDirectory "$runId-process.csv"

'timestamp_utc,process_id,process_rss_bytes,process_cpu_seconds,system_available_bytes,liveness_status,readiness_status' | Set-Content -LiteralPath $csvPath
$deadline = (Get-Date).AddSeconds($DurationSeconds)

while ((Get-Date) -lt $deadline) {
    $process = Get-Process -Id $TargetProcessId -ErrorAction SilentlyContinue
    $os = Get-CimInstance Win32_OperatingSystem
    $live = 0
    $ready = 0
    try { $live = (Invoke-WebRequest -UseBasicParsing -TimeoutSec 5 "$($BaseUrl.TrimEnd('/'))/api/health/live").StatusCode } catch { $live = [int]$_.Exception.Response.StatusCode }
    try { $ready = (Invoke-WebRequest -UseBasicParsing -TimeoutSec 5 "$($BaseUrl.TrimEnd('/'))/api/health/ready").StatusCode } catch { $ready = [int]$_.Exception.Response.StatusCode }
    $rss = if ($process) { $process.WorkingSet64 } else { 0 }
    $cpu = if ($process) { $process.CPU } else { 0 }
    $available = [int64]$os.FreePhysicalMemory * 1024
    "$([DateTime]::UtcNow.ToString('o')),$TargetProcessId,$rss,$cpu,$available,$live,$ready" | Add-Content -LiteralPath $csvPath
    Start-Sleep -Seconds $IntervalSeconds
}

Write-Output $csvPath
