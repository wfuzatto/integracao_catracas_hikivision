param(
    [Parameter(Mandatory=$true)][string]$Source,
    [Parameter(Mandatory=$true)][string]$Target
)

Add-Type -AssemblyName System.Drawing
$srcPath = [System.IO.Path]::GetFullPath($Source)
$dstPath = [System.IO.Path]::GetFullPath($Target)
$img = [System.Drawing.Image]::FromFile($srcPath)
try {
    # Apply camera orientation before stripping metadata in the JPEG output.
    if ($img.PropertyIdList -contains 274) {
        $orientation = [BitConverter]::ToUInt16($img.GetPropertyItem(274).Value, 0)
        $rotation = switch ($orientation) {
            2 { 'RotateNoneFlipX' } 3 { 'Rotate180FlipNone' } 4 { 'Rotate180FlipX' }
            5 { 'Rotate90FlipX' } 6 { 'Rotate90FlipNone' } 7 { 'Rotate270FlipX' } 8 { 'Rotate270FlipNone' }
            default { 'RotateNoneFlipNone' }
        }
        $img.RotateFlip([System.Drawing.RotateFlipType]::$rotation)
    }
    $minWidth = 480
    $scale = 1.0
    if ($img.Width -lt $minWidth) {
        $scale = $minWidth / [double]$img.Width
    }
    if ([Math]::Max($img.Width, $img.Height) * $scale -gt 1280) {
        $scale = 1280.0 / [Math]::Max($img.Width, $img.Height)
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
        try {
            $saved = $false
            foreach ($quality in @(90L, 80L, 70L, 60L, 50L)) {
                $params.Param[0] = New-Object System.Drawing.Imaging.EncoderParameter ([System.Drawing.Imaging.Encoder]::Quality), $quality
                $stream = New-Object System.IO.MemoryStream
                try {
                    $bitmap.Save($stream, $codec, $params)
                    if ($stream.Length -le 200KB) {
                        [System.IO.File]::WriteAllBytes($dstPath, $stream.ToArray())
                        $saved = $true
                        break
                    }
                } finally { $stream.Dispose() }
            }
            if (!$saved) { throw 'Foto muito complexa para o limite de 200 KB. Envie um retrato com fundo simples.' }
        } finally { $params.Dispose() }
    } finally {
        $graphics.Dispose()
        $bitmap.Dispose()
    }
} finally {
    $img.Dispose()
}
