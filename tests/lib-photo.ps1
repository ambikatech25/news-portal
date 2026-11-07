# Makes a JPEG like a phone photo: GPS position, camera make/model and an "upright" orientation
# flag (6 = rotate 90 degrees when shown). Used by the image-safety test. Dot-source it.

Add-Type -AssemblyName System.Drawing

function New-ExifProperty($bitmap, [int]$id, [int16]$type, [byte[]]$value) {
    # PropertyItem has no public constructor; create a blank one and fill it in.
    $p = [System.Runtime.Serialization.FormatterServices]::GetUninitializedObject([System.Drawing.Imaging.PropertyItem])
    $p.Id = $id; $p.Type = $type; $p.Len = $value.Length; $p.Value = $value
    $bitmap.SetPropertyItem($p)
}

function ConvertTo-Rational([double[]]$parts) {
    # EXIF "rational" = two 32-bit numbers (numerator / denominator) per part.
    $bytes = New-Object System.Collections.Generic.List[byte]
    foreach ($v in $parts) {
        $bytes.AddRange([BitConverter]::GetBytes([uint32]([math]::Round($v * 10000))))
        $bytes.AddRange([BitConverter]::GetBytes([uint32]10000))
    }
    return , $bytes.ToArray()
}

function Get-PictureMd5($path) {
    # Fingerprint of the compressed picture data (from the start-of-scan marker FF DA to the end),
    # to prove that cleaning left the picture itself untouched.
    $bytes = [IO.File]::ReadAllBytes($path)
    for ($i = 2; $i -lt $bytes.Length - 1; $i++) {
        if ($bytes[$i] -eq 0xFF -and $bytes[$i + 1] -eq 0xDA) {
            $md5 = [Security.Cryptography.MD5]::Create()
            return ([BitConverter]::ToString($md5.ComputeHash($bytes, $i, $bytes.Length - $i)) -replace '-', '').ToLower()
        }
    }
    return ''
}

function New-GpsPhoto($path, [int]$width = 320, [int]$height = 200, [int]$orientation = 6) {
    $bmp = New-Object System.Drawing.Bitmap $width, $height
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.Clear([System.Drawing.Color]::DarkOliveGreen)
    $g.FillRectangle([System.Drawing.Brushes]::Gold, 0, 0, 60, 40)   # marks the top-left corner
    $g.Dispose()
    $ascii = { param($s) [Text.Encoding]::ASCII.GetBytes($s + [char]0) }
    New-ExifProperty $bmp 0x010F 2 (& $ascii 'PhoneMaker')          # Make
    New-ExifProperty $bmp 0x0110 2 (& $ascii 'SecretPhone X1')      # Model
    New-ExifProperty $bmp 0x0112 3 ([BitConverter]::GetBytes([uint16]$orientation))  # 6 = rotate 90, 1 = upright
    New-ExifProperty $bmp 0x0001 2 (& $ascii 'N')                   # GPSLatitudeRef
    New-ExifProperty $bmp 0x0002 5 (ConvertTo-Rational @(21, 15, 3.5))     # GPSLatitude  21 15' 3.5"
    New-ExifProperty $bmp 0x0003 2 (& $ascii 'E')                   # GPSLongitudeRef
    New-ExifProperty $bmp 0x0004 5 (ConvertTo-Rational @(81, 37, 48.2))    # GPSLongitude 81 37' 48.2"
    $bmp.Save($path, [System.Drawing.Imaging.ImageFormat]::Jpeg)
    $bmp.Dispose()
}
