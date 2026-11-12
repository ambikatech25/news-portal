# Task 17A test: Series. A writer creates a 3-part series and a reader moves through it in
# order; only writers create series, only into their own series; unpublished parts stay
# hidden; order, cover, My Account and the API work.
#
# Run from the project folder (Docker must be running):
#   powershell -ExecutionPolicy Bypass -File tests\test-series.ps1

. "$PSScriptRoot\lib.ps1"

function Get-Html($session, $url) {
    if ($session) { return (curl.exe -s -b $session.Jar -c $session.Jar $url | Out-String) }
    return (curl.exe -s $url | Out-String)
}
function Get-Code($url) { return (curl.exe -s -o NUL -w '%{http_code}' $url) }
function Get-Last($lines) { return ($lines | Where-Object { "$_".Trim() } | Select-Object -Last 1) }

$tag = 'T17A-' + [guid]::NewGuid().ToString('N').Substring(0, 6)
$seriesIds = @()

try {
    Write-Host '== Setup'
    $cat = Get-CategoryIds
    $users = New-TestUsers @('subscriber', 'contributor', 'author', 'editor')
    $w = $users['contributor'].Session; $wId = $users['contributor'].Id
    $o = $users['author'].Session
    $e = $users['editor'].Session

    Write-Host '== Who can create a series'
    $anon = Invoke-Rest $null POST '/portal/v1/me/series' @{ name = "$tag visitor" }
    $mem = Invoke-Rest $users['subscriber'].Session POST '/portal/v1/me/series' @{ name = "$tag member" }
    $short = Invoke-Rest $w POST '/portal/v1/me/series' @{ name = 'ab' }
    Check '1. Visitors (401) and members (403) cannot create a series; a too-short name is refused (400)' ($anon.Code -eq 401 -and $mem.Code -eq 403 -and $short.Code -eq 400) "visitor=$($anon.Code) member=$($mem.Code) short=$($short.Code)"

    $s = Invoke-Rest $w POST '/portal/v1/me/series' @{ name = "$tag Raipur markets"; description = 'Three walks through old markets.' }
    $sid = $s.Data.id; $seriesIds += $sid
    $owner = Get-Last (Wp term meta get $sid portal_series_owner)
    Check '2. A writer (Contributor) creates a series; it is recorded as theirs' ($s.Code -eq 201 -and $sid -and "$owner" -eq "$wId") "code=$($s.Code) owner=$owner"

    Write-Host '== Three parts'
    $parts = @()
    foreach ($n in 1..3) {
        $p = Invoke-Rest $w POST '/wp/v2/posts' @{ title = "$tag part $n"; status = 'pending'; content = "Part $n text. " * 40; categories = @($cat['community-blogs']) }
        $parts += $p.Data.id
        $a = Invoke-Rest $w POST "/portal/v1/stories/$($p.Data.id)/series" @{ series = $sid }
    }
    $nums = $parts | ForEach-Object { Get-Last (Wp post meta get $_ portal_series_part) }
    $status = Get-Last (Wp post get $parts[0] --field=post_status)
    Check '3. The writer adds 3 stories; they become parts 1, 2, 3 automatically (sent for review, still Pending)' (($nums -join ',') -eq '1,2,3' -and $status -eq 'pending') "parts=$($nums -join ',') status=$status"

    $other = (Invoke-Rest $o POST '/wp/v2/posts' @{ title = "$tag other writer"; status = 'publish'; content = 'x'; categories = @($cat['community-blogs']) }).Data.id
    $steal = Invoke-Rest $o POST "/portal/v1/stories/$other/series" @{ series = $sid }
    $sneak = Invoke-Rest $o POST "/wp/v2/posts/$other" @{ series = @($sid) }
    $sneakTerms = (Wp post term list $other portal_series --field=term_id | Where-Object { "$_" -match '^\d+$' })
    $notMine = Invoke-Rest $w POST "/portal/v1/stories/$other/series" @{ series = $sid }
    Check '4. Another writer cannot add a story to someone else''s series (403, and the plain API field is dropped); a writer cannot add someone else''s story (403)' ($steal.Code -eq 403 -and -not $sneakTerms -and $notMine.Code -eq 403) "steal=$($steal.Code) sneak=$sneakTerms notMine=$($notMine.Code)"

    Write-Host '== Nothing published yet'
    $link = (Invoke-Rest $w GET "/portal/v1/series/$sid").Data.link
    $vis = Invoke-Rest $null GET "/portal/v1/series/$sid"
    $ownPage = Get-Html $w $link
    Check '5. Before any part is published the series is hidden from readers (page and API 404) but its writer can preview it' ((Get-Code $link) -eq '404' -and $vis.Code -eq 404 -and $ownPage -match 'No part is published yet') "page=$(Get-Code $link) api=$($vis.Code)"

    Write-Host '== Publishing parts 1 and 3 (part 2 still in review)'
    foreach ($id in $parts[0], $parts[2]) { Invoke-Rest $e POST "/wp/v2/posts/$id" @{ status = 'publish' } | Out-Null }
    $pub = Invoke-Rest $null GET "/portal/v1/series/$sid"
    $p3 = Invoke-Rest $null GET "/wp/v2/posts/$($parts[2])"
    Check '6. Readers only see published parts, numbered without gaps: part 3 shows as "Part 2 of 2"' ($pub.Code -eq 200 -and $pub.Data.parts.Count -eq 2 -and $p3.Data.portal_series.number -eq 2 -and $p3.Data.portal_series.total -eq 2) "parts=$($pub.Data.parts.Count) p3=$($p3.Data.portal_series.number)/$($p3.Data.portal_series.total)"

    Invoke-Rest $e POST "/wp/v2/posts/$($parts[1])" @{ status = 'publish' } | Out-Null
    $links = $parts | ForEach-Object { (Invoke-Rest $null GET "/wp/v2/posts/$_").Data.link }

    Write-Host '== A reader moves through the series'
    $h1 = Get-Html $null $links[0]; $h2 = Get-Html $null $links[1]; $h3 = Get-Html $null $links[2]
    $next1 = [regex]::Match($h1, '<a[^>]*portal-series-nav__link--next[^>]*href="([^"]+)"').Groups[1].Value
    $next2 = [regex]::Match($h2, '<a[^>]*portal-series-nav__link--next[^>]*href="([^"]+)"').Groups[1].Value
    $prev2 = [regex]::Match($h2, '<a[^>]*portal-series-nav__link--prev[^>]*href="([^"]+)"').Groups[1].Value
    Check '7. Part 1 says "Part 1 of 3" and "Next part" leads to part 2, which leads to part 3 (and back to part 1)' ($h1 -match 'Part 1 of 3' -and $next1 -eq $links[1] -and $next2 -eq $links[2] -and $prev2 -eq $links[0]) "next1=$next1 next2=$next2 prev2=$prev2"
    Check '8. The last part says "Part 3 of 3", has no Next link and offers to follow the writer' ($h3 -match 'Part 3 of 3' -and $h3 -notmatch 'portal-series-nav__link--next' -and $h3 -match 'latest part' -and $h3 -match 'portal-toggle--follow-author') 'last part page'

    $page = Get-Html $null $link
    $order = [regex]::Matches($page, 'portal-series-contents__title" href="([^"]+)"') | ForEach-Object { $_.Groups[1].Value }
    $start = [regex]::Match($page, 'class="portal-button" href="([^"]+)">Start reading').Groups[1].Value
    Check '9. The series page shows name, description, writer, Start reading (part 1) and the contents in order' ($page -match "$tag Raipur markets" -and $page -match 'Three walks' -and $page -match 'portal-series-page__by' -and $start -eq $links[0] -and ($order -join '|') -eq ($links -join '|')) "start=$start order=$($order.Count)"

    Write-Host '== Order, cover, My Account'
    $bad = Invoke-Rest $o POST "/portal/v1/series/$sid" @{ name = "$tag hijack"; order = @($parts[2], $parts[0], $parts[1]) }
    $re = Invoke-Rest $w POST "/portal/v1/series/$sid" @{ name = "$tag Raipur markets"; description = 'Three walks.'; order = @($parts[2], $parts[0], $parts[1]) }
    $after = (Invoke-Rest $null GET "/portal/v1/series/$sid").Data.parts | ForEach-Object { $_.id }
    Add-Type -AssemblyName System.Drawing
    $png = Join-Path $Tmp 'cover.png'; $bmp = New-Object System.Drawing.Bitmap 320, 180; $bmp.Save($png, [System.Drawing.Imaging.ImageFormat]::Png); $bmp.Dispose()
    $cov = (curl.exe -s -b $w.Jar -H "X-WP-Nonce: $($w.Nonce)" -F "cover=@$png;type=image/png" "$Base/wp-json/portal/v1/series/$sid/cover" | Out-String) | ConvertFrom-Json
    Check '10. The writer reorders parts (another writer cannot: 403) and uploads a cover photo' ($bad.Code -eq 403 -and ($after -join ',') -eq (@($parts[2], $parts[0], $parts[1]) -join ',') -and $cov.cover -match '^http') "bad=$($bad.Code) order=$($after -join ',') cover=$($cov.cover)"

    $acct = Get-Html $w "$Base/account/?section=portal-series"
    $write = Get-Html $w "$Base/write/"
    $nonce = [regex]::Match($acct, '(?s)name="portal_do" value="create">.*?name="_wpnonce" value="([0-9a-f]+)"').Groups[1].Value
    curl.exe -s -o NUL -b $w.Jar -c $w.Jar --data-urlencode 'action=portal_series_action' --data-urlencode 'portal_do=create' --data-urlencode "_wpnonce=$nonce" --data-urlencode "_wp_http_referer=/account/" --data-urlencode "name=$tag second series" "$Base/wp-admin/admin-post.php"
    $mine = Invoke-Rest $w GET '/portal/v1/me/series'
    $second = $mine.Data | Where-Object { $_.name -eq "$tag second series" }
    if ($second) { $seriesIds += $second.id }
    $memAcct = Get-Html $users['subscriber'].Session "$Base/account/"
    Check '11. My Account has a My series tab (not for members), the Write page has a Series box, and the Create form works' ($acct -match 'portal-my-series' -and $acct -match "$tag Raipur markets" -and $write -match 'name="portal_series_choice"' -and $write -match "$tag Raipur markets" -and $second -and $memAcct -notmatch 'section=portal-series') "acct=$($acct -match 'portal-my-series') write=$($write -match 'portal_series_choice') created=$([bool]$second)"

    Write-Host '== Deleting a series'
    $del = Invoke-Rest $w DELETE "/portal/v1/series/$sid"
    $still = Invoke-Rest $null GET "/wp/v2/posts/$($parts[0])"
    $meta = Get-Last (Wp post meta list $parts[0] --keys=portal_series_part --format=count)
    Check '12. Deleting a series keeps its stories published, without series or part number' ($del.Code -eq 200 -and $still.Code -eq 200 -and $null -eq $still.Data.portal_series -and "$meta" -eq '0') "del=$($del.Code) story=$($still.Code) meta=$meta"
}
finally {
    foreach ($id in $seriesIds) { Wp term delete portal_series $id 2>$null | Out-Null }
    Complete-Test
}
if ($script:Fail -gt 0) { exit 1 }
