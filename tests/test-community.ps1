# Task 17C test: the Community page fills itself from published content (newest stories,
# trending writers, new series) and editors pick stories (Editorial panel or one click in
# wp-admin → Posts). Writers cannot pick; drafts, News stories and suspended writers never show.
#
# Run from the project folder (Docker must be running):
#   powershell -ExecutionPolicy Bypass -File tests\test-community.ps1

. "$PSScriptRoot\lib.ps1"

function Get-Html($session, $url) {
    if ($session) { return (curl.exe -s -b $session.Jar -c $session.Jar $url | Out-String) }
    return (curl.exe -s $url | Out-String)
}
function Get-Last($lines) { return ($lines | Where-Object { "$_".Trim() } | Select-Object -Last 1) }
function Get-Community { return (Invoke-Rest $null GET '/portal/v1/community').Data }
function Get-Section($html, $id) { return [regex]::Match($html, "(?s)aria-labelledby=""$id"".*?</section>").Value }

$tag = 'T17C-' + [guid]::NewGuid().ToString('N').Substring(0, 6)
$seriesId = 0

try {
    Write-Host '== Setup'
    $cat = Get-CategoryIds
    $users = New-TestUsers @('subscriber', 'contributor', 'author', 'editor')
    $a = $users['author'].Session; $aId = $users['author'].Id
    $e = $users['editor'].Session
    $url = "$Base/community/"

    Write-Host '== The page'
    $page = Get-Html $null $url
    $homeHtml = Get-Html $null "$Base/"
    $inMenu = $homeHtml -match '(?s)class="menu-item[^"]*"><a href="http://localhost:8080/community/"[^>]*>(<span[^>]*>)?Community'
    $homeLink = $homeHtml -match 'portal-blogs-title">\s*<a href="http://localhost:8080/community/"'
    Check '1. /community/ opens, the main menu''s "Community" goes there, and the home page''s Community block links to it' ((curl.exe -s -o NUL -w '%{http_code}' $url) -eq '200' -and $inMenu -and $homeLink) "menu=$inMenu home=$homeLink"

    $vis = [regex]::Match($page, 'class="portal-button" href="[^"]*">([^<]+)<').Groups[1].Value
    $mem = [regex]::Match((Get-Html $users['subscriber'].Session $url), 'class="portal-button" href="[^"]*">([^<]+)<').Groups[1].Value
    $wri = [regex]::Match((Get-Html $users['contributor'].Session $url), 'class="portal-button" href="[^"]*">([^<]+)<').Groups[1].Value
    Check '2. The button fits the reader: visitors "Sign up to write", members "Become a writer", writers "Write a story"' ($vis -eq 'Sign up to write' -and $mem -eq 'Become a writer' -and $wri -eq 'Write a story') "visitor=$vis member=$mem writer=$wri"

    Write-Host '== Fills itself'
    $story = Invoke-Rest $a POST '/wp/v2/posts' @{ title = "$tag community story"; status = 'publish'; content = 'Text.'; categories = @($cat['community-blogs']) }
    $sid = $story.Data.id
    $news = (Invoke-Rest $e POST '/wp/v2/posts' @{ title = "$tag news story"; status = 'publish'; content = 'Text.'; categories = @($cat['news']) }).Data.id
    $draft = (Invoke-Rest $a POST '/wp/v2/posts' @{ title = "$tag draft story"; status = 'draft'; content = 'Text.'; categories = @($cat['community-blogs']) }).Data.id
    $latest = Get-Section (Get-Html $null $url) 'portal-latest-title'
    $c = Get-Community
    Check '3. A newly published community story appears under "Newest community stories"; News stories and drafts do not' ($latest -match "$tag community story" -and $latest -notmatch "$tag news story" -and $latest -notmatch "$tag draft story" -and ($c.latest | Where-Object { $_.id -eq $sid })) 'newest list'

    Write-Host '== Editors pick stories'
    $self = Invoke-Rest $a POST "/wp/v2/posts/$sid" @{ meta = @{ portal_editors_pick = $true } }
    $selfMeta = Get-Last (Wp post meta get $sid portal_editors_pick)
    Check '4. A writer cannot pick their own story' ($self.Code -eq 403 -and -not $selfMeta) "code=$($self.Code) meta=$selfMeta"

    $pick = Invoke-Rest $e POST "/wp/v2/posts/$sid" @{ meta = @{ portal_editors_pick = $true } }
    $c = Get-Community
    $picksHtml = Get-Section (Get-Html $null $url) 'portal-picks-title'
    $latestHtml = Get-Section (Get-Html $null $url) 'portal-latest-title'
    Check '5. An editor switches on "Editor''s pick": the story is first under Editors'' picks and no longer repeated in Newest' ($pick.Code -eq 200 -and $c.picks[0].id -eq $sid -and $picksHtml -match "$tag community story" -and $latestHtml -notmatch "$tag community story") "code=$($pick.Code) first=$($c.picks[0].id)"

    $list = Get-Html $e "$Base/wp-admin/edit.php?post_type=post&s=$tag"
    $link = [regex]::Match($list, "href=""([^""]*action=portal_pick[^""]*post=$sid[^""]*)""").Groups[1].Value -replace '&amp;|&#038;', '&'
    $newsLink = $list -match "action=portal_pick[^""]*post=$news\b"
    curl.exe -s -o NUL -b $e.Jar -c $e.Jar -e "$Base/wp-admin/edit.php" $link
    $after = Get-Last (Wp post meta get $sid portal_editors_pick)
    Check '6. wp-admin → Posts: one click "Remove from picks" works for editors; News stories get no pick link' ($link -match 'pick=0' -and -not $after -and -not $newsLink -and $list -match "Editor&#039;s pick|Editor's pick") "link=$link after=$after news=$newsLink"

    $log = Get-Last (Wp db query "SELECT GROUP_CONCAT(action ORDER BY id) FROM wp_portal_audit_log WHERE object_type='post' AND object_id=$sid AND action IN ('pick','unpick')" --skip-column-names)
    Check '7. Picking and un-picking are written in the audit log' ($log -eq 'pick,unpick') "log=$log"

    Invoke-Rest $e POST "/wp/v2/posts/$draft" @{ meta = @{ portal_editors_pick = $true } } | Out-Null
    Check '8. A picked story that is not published (draft) is never shown' (-not ((Get-Community).picks | Where-Object { $_.id -eq $draft })) 'draft shown'

    Write-Host '== Trending writers'
    Wp post meta update $sid _portal_views 999999 | Out-Null
    Wp transient delete portal_trending_writers | Out-Null
    $c = Get-Community
    $writersHtml = Get-Section (Get-Html $null $url) 'portal-writers-title'
    Check '9. The writer with the most reads on recent community stories is first under Trending writers (page and API)' ($c.writers[0].id -eq $aId -and $writersHtml -match ('href="' + [regex]::Escape($c.writers[0].link) + '"')) "first=$($c.writers[0].id) expected=$aId"

    Wp user meta update $aId portal_banned '{"by":1}' | Out-Null
    $banned = Get-Community
    Wp user meta delete $aId portal_banned | Out-Null
    Check '10. A suspended writer disappears from Trending writers at once' (-not ($banned.writers | Where-Object { $_.id -eq $aId })) 'still listed'

    Write-Host '== New series'
    $s = Invoke-Rest $a POST '/portal/v1/me/series' @{ name = "$tag series" }
    $seriesId = $s.Data.id
    $before = (Get-Community).series | Where-Object { $_.id -eq $seriesId }
    Invoke-Rest $a POST "/portal/v1/stories/$sid/series" @{ series = $seriesId } | Out-Null
    $c = Get-Community
    $seriesHtml = Get-Section (Get-Html $null $url) 'portal-series-title'
    Check '11. A series shows under "New series" only once it has a published part (newest first, with writer and parts)' (-not $before -and $c.series[0].id -eq $seriesId -and $c.series[0].parts_count -eq 1 -and $seriesHtml -match "$tag series") "before=$([bool]$before) first=$($c.series[0].id)"
}
finally {
    if ($seriesId) { Wp term delete portal_series $seriesId 2>$null | Out-Null }
    Wp transient delete portal_trending_writers | Out-Null
    Complete-Test
}
if ($script:Fail -gt 0) { exit 1 }
