$sess = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$r = Invoke-WebRequest -Uri "http://localhost:8000/index.php?page=login" -Method GET -WebSession $sess -UseBasicParsing
$csrf = ($r.Content | Select-String 'name="_csrf_token" value="([^"]+)"' -AllMatches | ForEach-Object { $_.Matches.Groups[1].Value })[0]
$body = "username=admin&password=admin123&_csrf_token=$csrf&portal="
$r2 = Invoke-WebRequest -Uri "http://localhost:8000/index.php?page=login" -Method POST -Body $body -WebSession $sess -UseBasicParsing -MaximumRedirection 5 -ErrorAction Ignore
Write-Host "Login: $($r2.StatusCode) -> $($r2.BaseResponse.ResponseUri)"

$pages = @('dashboard','products','customers','sales','catalog','settings','finance_dashboard','finance_bank','delivery_dashboard','store_dashboard','credit_management','purchase_orders','stock','reports','users','roles')

foreach ($pg in $pages) {
    $rp = Invoke-WebRequest -Uri "http://localhost:8000/index.php?page=$pg" -Method GET -WebSession $sess -UseBasicParsing -ErrorAction Ignore
    $denied = if ($rp.Content -match 'Access Denied') { 'DENIED' } else { 'OK' }
    Write-Host ($pg + ": " + $rp.StatusCode + " - " + $denied + " - " + $rp.Content.Length + " bytes")
}