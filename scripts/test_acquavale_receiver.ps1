param(
    [string]$ReceiverUrl = 'http://127.0.0.1:37080/visitor/acquavale_receive.php'
)

$ErrorActionPreference = 'Stop'
$Php = 'C:\xampp\php\php.exe'
$secret = (& $Php -r "require 'C:/xampp/htdocs/visitor/config.php'; echo AQV_SHARED_SECRET;" 2>$null | Out-String).Trim()
if ($secret.Length -lt 32) {
    throw 'VALE_AQV_SHARED_SECRET não está configurado com pelo menos 32 caracteres.'
}
$processOnReceive = (& $Php -r "require 'C:/xampp/htdocs/visitor/config.php'; echo AQV_PROCESS_ON_RECEIVE ? '1' : '0';" 2>$null | Out-String).Trim()
if ($processOnReceive -eq '1') {
    throw 'Defina VALE_AQV_PROCESS_ON_RECEIVE=0 para este teste sintético sem HikCentral.'
}

$timestamp = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds().ToString()
$deliveryId = 'synthetic-' + [Guid]::NewGuid().ToString('N')
$payload = [ordered]@{
    version = 1
    event = 'sale.paid'
    consumer = 'vale-visitor'
    order = [ordered]@{
        id = 999999
        order_code = 'SYNTH-' + [Guid]::NewGuid().ToString('N').Substring(0, 12).ToUpperInvariant()
        buyer_email = 'synthetic@example.invalid'
        buyer_phone = '+5500000000000'
        total = 0
        paid_at = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
        claim_token = [Guid]::NewGuid().ToString('N')
        reservation = [ordered]@{ code = 'SYNTH'; id = 999999; guest_name = 'Synthetic Test'; checkin = (Get-Date).ToString('yyyy-MM-dd'); checkout = (Get-Date).ToString('yyyy-MM-dd'); uh = 'TEST' }
        items = @()
        tickets = @([ordered]@{ ticket_id = 999999; ticket_code = 'SYNTH-' + [Guid]::NewGuid().ToString('N').Substring(0, 12).ToUpperInvariant(); valid_from = (Get-Date).ToString('yyyy-MM-dd'); valid_to = (Get-Date).ToString('yyyy-MM-dd'); validation_mode = 'synthetic'; status = 'active'; sku = 'SYNTH'; product_name = 'Synthetic Test'; visitor_id = 999999; first_name = 'Synthetic'; last_name = 'Test'; email = 'synthetic@example.invalid'; phone = '+5500000000000'; document_type = 'OUTRO'; document_number = 'SYNTH'; sex = 'N'; photo_url = 'https://example.invalid/synthetic-photo.jpg' })
    }
}
$raw = $payload | ConvertTo-Json -Depth 8 -Compress
$hmac = [System.Security.Cryptography.HMACSHA256]::new([Text.Encoding]::UTF8.GetBytes($secret))
try {
    $signature = -join ($hmac.ComputeHash([Text.Encoding]::UTF8.GetBytes($timestamp + "`n" + $raw)) | ForEach-Object { $_.ToString('x2') })
} finally {
    $hmac.Dispose()
}
$response = Invoke-WebRequest -Uri $ReceiverUrl -Method Post -ContentType 'application/json' -Headers @{
    'X-AQV-Timestamp' = $timestamp
    'X-AQV-Delivery-Id' = $deliveryId
    'X-AQV-Signature' = 'sha256=' + $signature
} -Body $raw -UseBasicParsing
Write-Output ("HTTP {0} delivery={1}" -f $response.StatusCode, $deliveryId)
Write-Output $response.Content
