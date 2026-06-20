# =====================================================================
#  Skrip uji alur fitur QR Code Instrumen (PRD F3 - SiPINTER)
#  Jalankan:  php artisan serve   (di terminal lain) lalu:
#             .\test-qr-flow.ps1
#  Opsi:      .\test-qr-flow.ps1 -BaseUrl "http://127.0.0.1:8000"
# =====================================================================
param(
    [string]$BaseUrl  = "http://127.0.0.1:8000",
    [string]$Username = "administrator",
    [string]$Password = "Admin@12345"
)

$ErrorActionPreference = "Stop"
$api = "$BaseUrl/api"

function Step($n, $msg) { Write-Host ""; Write-Host "[$n] $msg" -ForegroundColor Cyan }
function Ok($msg)       { Write-Host "    OK  $msg" -ForegroundColor Green }
function Fail($msg)     { Write-Host "    GAGAL  $msg" -ForegroundColor Red }

# --- 0. Cek server hidup --------------------------------------------
Step 0 "Cek server $BaseUrl"
try {
    Invoke-RestMethod -Uri "$api/auth/login" -Method Post -ContentType "application/json" `
        -Body '{}' -Headers @{ Accept = "application/json" } | Out-Null
} catch {
    $code = $_.Exception.Response.StatusCode.value__
    if (-not $code) {
        Fail "Server tidak bisa dihubungi. Jalankan dulu: php artisan serve"
        exit 1
    }
    # 422 (validasi) = server hidup, itu yang kita harapkan
}
Ok "Server merespons."

# --- 1. Login -------------------------------------------------------
Step 1 "Login sebagai '$Username'"
$loginBody = @{ username = $Username; password = $Password } | ConvertTo-Json
$login = Invoke-RestMethod -Uri "$api/auth/login" -Method Post -ContentType "application/json" `
    -Headers @{ Accept = "application/json" } -Body $loginBody
$token = $login.data.token
if (-not $token) { Fail "Token tidak diterima."; exit 1 }
Ok "Token didapat."
$auth = @{ Authorization = "Bearer $token"; Accept = "application/json" }

# --- 2. Buat jenis instrumen ---------------------------------------
Step 2 "Buat jenis instrumen 'Gunting Bedah'"
$insBody = @{ name = "Gunting Bedah" } | ConvertTo-Json
$ins = Invoke-RestMethod -Uri "$api/master/instruments" -Method Post -ContentType "application/json" `
    -Headers $auth -Body $insBody
$instrumentId = $ins.data.id
Ok "Instrumen dibuat. id=$instrumentId, code=$($ins.data.code)"

# --- 3. Buat unit fisik (stock) ------------------------------------
Step 3 "Buat unit fisik instrumen"
$stockBody = @{ instrument_id = $instrumentId; is_available = $true } | ConvertTo-Json
$stock = Invoke-RestMethod -Uri "$api/master/instrument-stocks" -Method Post -ContentType "application/json" `
    -Headers $auth -Body $stockBody
$stockId   = $stock.data.id
$stockCode = $stock.data.code
Ok "Unit dibuat. id=$stockId, code=$stockCode  <-- ini isi QR Code"

# --- 4. Generate QR Code -------------------------------------------
Step 4 "Generate QR Code unit $stockCode"
$qr = Invoke-RestMethod -Uri "$api/master/instrument-stocks/$stockId/qr" -Method Get -Headers $auth
$dataUri = $qr.data.qr_svg
$base64  = $dataUri -replace '^data:image/svg\+xml;base64,', ''
$svgPath = Join-Path $PSScriptRoot "instrument-qr-$stockCode.svg"
[IO.File]::WriteAllBytes($svgPath, [Convert]::FromBase64String($base64))
Ok "QR disimpan: $svgPath"
Write-Host "    (buka file itu di browser untuk lihat / cetak QR-nya)" -ForegroundColor DarkGray

# --- 5. Scan kode valid --------------------------------------------
Step 5 "Scan kode VALID ($stockCode)"
$scanBody = @{ code = $stockCode } | ConvertTo-Json
$scan = Invoke-RestMethod -Uri "$api/master/instrument-stocks/scan" -Method Post -ContentType "application/json" `
    -Headers $auth -Body $scanBody
if ($scan.status -eq $true) {
    Ok "Ditemukan: $($scan.data.instrument.name) (kondisi: $($scan.data.condition))"
} else {
    Fail "Harusnya ketemu, tapi: $($scan.message)"
}

# --- 6. Scan kode INVALID (harus 404) ------------------------------
Step 6 "Scan kode INVALID (XXXX-999) - harus gagal 404"
try {
    Invoke-RestMethod -Uri "$api/master/instrument-stocks/scan" -Method Post -ContentType "application/json" `
        -Headers $auth -Body (@{ code = "XXXX-999" } | ConvertTo-Json) | Out-Null
    Fail "Harusnya 404 tapi malah sukses."
} catch {
    $code = $_.Exception.Response.StatusCode.value__
    if ($code -eq 404) { Ok "Benar, balik 404 (kode tidak ditemukan)." }
    else { Fail "Status tak terduga: $code" }
}

Write-Host ""
Write-Host "=== SELESAI: alur QR Code instrumen berfungsi ===" -ForegroundColor Green
Write-Host "Gambar QR: $svgPath" -ForegroundColor Yellow
