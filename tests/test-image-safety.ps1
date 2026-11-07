# Task 15 test: uploaded photos lose their hidden data (GPS location, camera, dates) on every
# upload route, stay upright, and are not re-compressed when they do not need turning.
#
# Run from the project folder (Docker must be running):
#   powershell -ExecutionPolicy Bypass -File tests\test-image-safety.ps1

. "$PSScriptRoot\lib.ps1"
. "$PSScriptRoot\lib-photo.ps1"

function Get-Last($lines) { return ($lines | Where-Object { "$_".Trim() } | Select-Object -Last 1) }
function Send-Photo($session, $file) {
    # Upload to the Media Library like the block editor / app does. Returns the attachment ID (0 = refused).
    $raw = (curl.exe -s -X POST -b $session.Jar -H "X-WP-Nonce: $($session.Nonce)" -H 'Content-Type: image/jpeg' `
        -H "Content-Disposition: attachment; filename=$([IO.Path]::GetFileName($file))" --data-binary "@$file" "$Base/wp-json/wp/v2/media" | Out-String)
    try { return [int](($raw | ConvertFrom-Json).id) } catch { return 0 }
}
function Get-PhotoInfo($id) { return ((Wp eval-file /scripts/test-photo-info.php $id) -join '') | ConvertFrom-Json }
function Test-Gold($hex) { $r = [Convert]::ToInt32($hex.Substring(1, 2), 16); $g = [Convert]::ToInt32($hex.Substring(3, 2), 16); $b = [Convert]::ToInt32($hex.Substring(5, 2), 16); return ($r -gt 190 -and $g -gt 150 -and $b -lt 110) }
function Test-Clean($i) { return (-not $i.gps -and -not $i.make -and -not $i.model -and -not $i.private_blocks -and $i.orientation -le 1) }

$ewww = @{}

try {
    Write-Host '== Setup'
    $users = New-TestUsers @('editor', 'contributor', 'subscriber')
    $e = $users['editor'].Session
    $phone = Join-Path $Tmp 'phone.jpg'; New-GpsPhoto $phone 320 200 6
    $flat = Join-Path $Tmp 'flat.jpg'; New-GpsPhoto $flat 320 200 1

    Write-Host '== Normal upload (EWWW and the safety net)'
    $id = Send-Photo $e $phone
    $i = Get-PhotoInfo $id
    Check '1. An uploaded phone photo keeps no GPS location, camera make/model or other hidden data' ($id -gt 0 -and (Test-Clean $i)) "id=$id info=$($i | ConvertTo-Json -Compress)"
    Check '2. The photo is turned upright (portrait, marker corner in the right place) and every resized copy is clean too' ($i.height -gt $i.width -and (Test-Gold $i.top_right) -and -not (Test-Gold $i.top_left) -and $i.sizes -ge 1 -and $i.sizes_clean) "size=$($i.width)x$($i.height) TL=$($i.top_left) TR=$($i.top_right) sizes=$($i.sizes) clean=$($i.sizes_clean)"

    Write-Host '== Safety net alone (EWWW switched off)'
    foreach ($k in 'ewww_image_optimizer_noauto', 'ewww_image_optimizer_metadata_remove') { $ewww[$k] = Get-Last (Wp option get $k) }
    Wp option update ewww_image_optimizer_noauto 1 | Out-Null
    Wp option update ewww_image_optimizer_metadata_remove 0 | Out-Null
    $id2 = Send-Photo $e $phone
    $i2 = Get-PhotoInfo $id2
    Check '3. With EWWW switched off, portal-core still removes the GPS data and turns the photo upright' ($id2 -gt 0 -and (Test-Clean $i2) -and $i2.height -gt $i2.width -and (Test-Gold $i2.top_right)) "info=$($i2 | ConvertTo-Json -Compress)"

    $id3 = Send-Photo $e $flat
    $i3 = Get-PhotoInfo $id3
    $before = Get-PictureMd5 $flat
    Check '4. A photo that needs no turning is cleaned losslessly: same size, picture data byte-for-byte unchanged' ($id3 -gt 0 -and (Test-Clean $i3) -and $i3.width -eq 320 -and $i3.height -eq 200 -and $i3.picture_md5 -eq $before) "size=$($i3.width)x$($i3.height) same picture=$($i3.picture_md5 -eq $before)"

    Write-Host '== Other upload routes (EWWW still off)'
    $id4 = Send-Photo $users['contributor'].Session $phone
    $i4 = Get-PhotoInfo $id4
    Check '5. A writer''s upload (Write page / editor) is cleaned the same way' ($id4 -gt 0 -and (Test-Clean $i4)) "info=$($i4 | ConvertTo-Json -Compress)"

    $s = $users['subscriber'].Session
    $raw = (curl.exe -s -X POST -b $s.Jar -H "X-WP-Nonce: $($s.Nonce)" -F "file=@$phone;type=image/jpeg" "$Base/wp-json/portal/v1/me/avatar" | Out-String)
    $avatarId = [int](Get-Last (Wp user meta get $users['subscriber'].Id portal_avatar_id))
    $i5 = Get-PhotoInfo $avatarId
    Check '6. A member''s profile photo (uploaded from the website or app) is cleaned too' ($avatarId -gt 0 -and (Test-Clean $i5)) "avatar=$avatarId info=$($i5 | ConvertTo-Json -Compress)"

    Write-Host '== Photos from before the rule'
    # Put a GPS photo straight into the uploads folder, bypassing the upload rule (like an old photo).
    $uploads = Get-Last (Wp eval "echo wp_upload_dir()['path'];")
    $ErrorActionPreference = 'Continue'   # docker prints normal progress on stderr
    docker compose cp $phone "wordpress:$uploads/old-gps-photo.jpg" 2>&1 | Out-Null
    docker compose exec -T wordpress chown www-data:www-data "$uploads/old-gps-photo.jpg" 2>&1 | Out-Null
    $ErrorActionPreference = 'Stop'
    $oldId = [int](Get-Last (Wp eval "echo wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'old photo', 'post_author' => $($users['editor'].Id) ), '$uploads/old-gps-photo.jpg' );"))
    $dirty = Get-PhotoInfo $oldId
    $report = (Wp eval-file /scripts/clean-existing-photos.php) -join ' '
    $i6 = Get-PhotoInfo $oldId
    Check '7. The one-off clean-up script removes hidden data from photos uploaded before this rule' ($dirty.gps -eq $true -and (Test-Clean $i6) -and $report -match 'cleaned [1-9]') "before gps=$($dirty.gps) after=$($i6 | ConvertTo-Json -Compress) report=$report"
}
finally {
    foreach ($k in $ewww.Keys) { Wp option update $k "$($ewww[$k])" | Out-Null }
    Complete-Test
}
if ($script:Fail -gt 0) { exit 1 }
