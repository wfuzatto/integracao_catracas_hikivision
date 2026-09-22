param(
    [Parameter(Mandatory=$true)][string]$Source,
    [Parameter(Mandatory=$true)][string]$Target
)

Add-Type -AssemblyName System.Drawing
$srcPath = [System.IO.Path]::GetFullPath($Source)
$dstPath = [System.IO.Path]::GetFullPath($Target)
$img = [System.Drawing.Image]::FromFile($srcPath)
try {
    $minWidth = 480
    $scale = 1.0
    if ($img.Width -lt $minWidth) {
        $scale = $minWidth / [double]$img.Width
    }
    $targetWidth = [Math]::Round($img.Width * $scale)
    $targetHeight = [Math]::Round($img.Height * $scale)
    $bitmap = New-Object System.Drawing.Bitmap $targetWidth, $targetHeight, ([System.Drawing.Imaging.PixelFormat]::Format24bppRgb)
    $graphics = [System.Drawing.Graphics]::FromImage($bitmap)
    try {
        $graphics.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
        $graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
        $graphics.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
        $graphics.Clear([System.Drawing.Color]::White)
        $graphics.DrawImage($img, 0, 0, $targetWidth, $targetHeight)
        $codec = [System.Drawing.Imaging.ImageCodecInfo]::GetImageEncoders() | Where-Object { $_.MimeType -eq 'image/jpeg' }
        $params = New-Object System.Drawing.Imaging.EncoderParameters 1
        $params.Param[0] = New-Object System.Drawing.Imaging.EncoderParameter ([System.Drawing.Imaging.Encoder]::Quality), 90L
        $bitmap.Save($dstPath, $codec, $params)
    } finally {
        $graphics.Dispose()
        $bitmap.Dispose()
    }
} finally {
    $img.Dispose()
}
