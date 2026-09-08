param(
    [string]$Url = 'http://127.0.0.1:8000/webhooks/payment-provider',
    [string]$Secret = 'local-demo-secret-change-me',
    [string]$PayloadPath = ''
)

if ($PayloadPath -eq '') {
    $PayloadPath = Join-Path $PSScriptRoot '..\examples\payment-succeeded.json'
}

$rawBody = Get-Content -LiteralPath $PayloadPath -Raw
$timestamp = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
$key = [Text.Encoding]::UTF8.GetBytes($Secret)
$message = [Text.Encoding]::UTF8.GetBytes("$timestamp.$rawBody")
$hmac = [Security.Cryptography.HMACSHA256]::new($key)
$signature = [Convert]::ToHexString($hmac.ComputeHash($message)).ToLowerInvariant()

Invoke-RestMethod -Method Post -Uri $Url -ContentType 'application/json' `
    -Headers @{ 'Payment-Signature' = "t=$timestamp,v1=$signature" } `
    -Body $rawBody
