$sess = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$r = Invoke-WebRequest -Uri "http://localhost:8000/index.php?page=login" -Method GET -WebSession $sess -UseBasicParsing
$csrf = ($r.Content | Select-String 'name="_csrf_token" value="([^"]+)' -AllMatches | ForEach-Object { $_.Matches.Groups[1].Value })[0]
$body = "username=admin&password=admin123&_csrf_token=$csrf&portal="
$r2 = Invoke-WebRequest -Uri "http://localhost:8000/index.php?page=login" -Method POST -Body $body -WebSession $sess -UseBasicParsing -MaximumRedirection 5 -ErrorAction Ignore

$pages = @('dashboard','products','customers','sales','catalog','settings','credit_management','credit_control','credit_reports','purchase_orders','goods_receiving','suppliers','stock','batch_expiry','stock_movements','invoice','receipt','delivery_dashboard','finance_dashboard','finance_reports','reports','categories','users','roles','backup')

foreach ($p in $pages) {
    $rp = Invoke-WebRequest -Uri "http://localhost:8000/index.php?page=$p" -Method GET -WebSession $sess -UseBasicParsing -ErrorAction Ignore
    $status = if ($rp.Content -match 'Access Denied') { 'DENIED' } elseif ($rp.StatusCode -eq 200) { 'OK' } else { "ERROR $($rp.StatusCode)" }
    $len = $rp.Content.Length
    Write-Host "$p: $status - ${len} bytes"
}