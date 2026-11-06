# Task 8 test: article page - Verified box, read time, author card, share links,
# related stories, Report button and the reports API.
#
# Run from the project folder (Docker must be running):
#   powershell -ExecutionPolicy Bypass -File tests\test-article-page.ps1
#
# Works with or without the demo content: every check uses this run's own posts.

. "$PSScriptRoot\lib.ps1"

function Get-Page($url) {
    $raw = (curl.exe -s -w "`n%{http_code}" $url | Out-String).TrimEnd()
    return @{
        Code = [int]($raw.Substring($raw.LastIndexOf("`n") + 1))
        Html = $raw.Substring(0, $raw.LastIndexOf("`n"))
    }
}

function Get-ReportCount($postId) {
    return [int](Wp db query "SELECT COUNT(*) FROM wp_portal_reports WHERE object_type = 'post' AND object_id = $postId" --skip-column-names | Where-Object { $_.Trim() } | Select-Object -Last 1)
}

$tag = 'T8-' + [guid]::NewGuid().ToString('N').Substring(0, 6)   # makes this run's titles unique
$testPosts = @()

try {
    Write-Host '== Setup: category IDs, temporary users, test posts'
    $cat = Get-CategoryIds
    $users = New-TestUsers @('editor', 'author', 'subscriber')
    $e = $users['editor'].Session
    $a = $users['author'].Session
    $s = $users['subscriber'].Session

    # A Hindi word, built from its letters so this file stays plain ASCII.
    $hindi = -join ([char[]](0x0938, 0x092E, 0x093E, 0x091A, 0x093E, 0x0930))   # "samachar" (news)
    $body = (@($hindi) * 401) -join ' '                                           # 401 words -> 3 min

    $r = Invoke-Rest $e POST '/wp/v2/posts' @{ title = "$tag verified"; status = 'publish'; content = $body; categories = @($cat['logistics']); meta = @{ portal_verified = $true; portal_fir_number = '88/2026'; portal_police_station = 'Kotwali T8' } }
    $verified = $r.Data
    $r = Invoke-Rest $e POST '/wp/v2/posts' @{ title = "$tag fir not verified"; status = 'publish'; content = 'Short story.'; categories = @($cat['logistics']); meta = @{ portal_verified = $false; portal_fir_number = '55/2026'; portal_police_station = 'Hidden PS T8' } }
    $unverified = $r.Data
    $r = Invoke-Rest $a POST '/wp/v2/posts' @{ title = "$tag community"; status = 'publish'; content = 'A writer post.'; categories = @($cat['community-blogs']) }
    $community = $r.Data
    $testPosts = @($verified.id, $unverified.id, $community.id)

    Write-Host '== Verified box'
    $page = Get-Page $verified.link
    $h = $page.Html
    $errors = [regex]::Matches($h, '(Fatal error|Warning|Notice|Deprecated)</b>:').Count
    Check '1. Article page loads (HTTP 200) with no PHP errors' ($page.Code -eq 200 -and $errors -eq 0) "HTTP $($page.Code), PHP messages: $errors"

    $box = [regex]::Match($h, '(?s)<aside class="portal-verified".*?</aside>').Value
    Check '2. Verified post shows the Verified box with FIR number and police station' ($box -match 'Verified by our news desk' -and $box -match '88/2026' -and $box -match 'Kotwali T8') "box found: $($box.Length -gt 0)"

    $h2 = (Get-Page $unverified.link).Html
    Check '3. Not verified: no Verified box and the typed-in FIR / police station are NOT shown' ($h2 -match "$tag fir not verified" -and $h2 -notmatch 'portal-verified' -and $h2 -notmatch '55/2026' -and $h2 -notmatch 'Hidden PS T8') 'badge or FIR leaked onto the page'

    Write-Host '== Read time'
    $api = Invoke-Rest $null GET "/wp/v2/posts/$($verified.id)"
    Check '4. Read time counts Hindi words: 401 words = 3 min on the page and portal_read_minutes = 3' ($h -match '3 min read' -and $api.Data.portal_read_minutes -eq 3) "api=$($api.Data.portal_read_minutes)"

    Write-Host '== Author card, share links, Kadence extras'
    $card = [regex]::Match($h, '(?s)<section class="portal-author-card".*?</section>').Value
    $card3 = [regex]::Match((Get-Page $community.link).Html, '(?s)<section class="portal-author-card".*?</section>').Value
    Check '5. Author card: editor shown as News desk, community author as Community writer' ($card -match 'News desk' -and $card -match 'portal_test_editor' -and $card3 -match 'Community writer' -and $card3 -match 'portal_test_author') "editor card: $($card.Length) chars, writer card: $($card3.Length) chars"

    $enc = [uri]::EscapeDataString($verified.link)
    $shares = [regex]::Matches($h, '<a class="portal-share__btn[^"]*"\s+href="([^"]+)" target="_blank" rel="noopener noreferrer nofollow"')
    $wa = ($shares | Where-Object { $_.Groups[1].Value -like 'https://wa.me/*' } | Select-Object -First 1).Groups[1].Value
    Check '6. Five share links (open safely in a new tab); WhatsApp link carries this article''s address' ($shares.Count -eq 5 -and $wa -and $wa.Contains($enc)) "links=$($shares.Count) whatsapp=$wa"

    $dialogs = [regex]::Matches($h, 'id="portal-report-dialog"').Count
    Check '7. Report button and one Report form on the page; Kadence related carousel and prev/next removed' ($h -match "data-portal-report=""post"" data-id=""$($verified.id)""" -and $dialogs -eq 1 -and $h -notmatch '<div class="entry-related' -and $h -notmatch '<nav class="navigation post-navigation') "report forms=$dialogs"

    Write-Host '== Related stories'
    $rel = Invoke-Rest $null GET "/portal/v1/related/$($verified.id)"
    $relIds = @($rel.Data | ForEach-Object { $_.id })
    $block = [regex]::Match($h, '(?s)<section class="portal-related".*?</section>').Value
    Check '8. Related: 4 posts, never the article itself, same-section story included (API and page)' ($rel.Code -eq 200 -and $relIds.Count -eq 4 -and $relIds -notcontains $verified.id -and $relIds -contains $unverified.id -and $block -match "$tag fir not verified") "HTTP $($rel.Code) ids=$($relIds -join ',')"

    Write-Host '== Reports API'
    $rep = @{ object_type = 'post'; object_id = $verified.id; reason = 'false_info'; details = 'Wrong date in the second paragraph.' }
    $r1 = Invoke-Rest $null POST '/portal/v1/reports' $rep
    $r2 = Invoke-Rest $null POST '/portal/v1/reports' $rep
    $n = Get-ReportCount $verified.id
    Check '9. A visitor can report; sending the same report again is counted once' ($r1.Code -eq 201 -and $r2.Code -eq 200 -and $r2.Data.duplicate -eq $true -and $n -eq 1) "first=$($r1.Code) second=$($r2.Code) rows=$n"

    $bad = Invoke-Rest $null POST '/portal/v1/reports' @{ object_type = 'post'; object_id = $verified.id; reason = 'i-dont-like-it' }
    $long = Invoke-Rest $null POST '/portal/v1/reports' @{ object_type = 'post'; object_id = $verified.id; reason = 'other'; details = ('x' * 1001) }
    $r = Invoke-Rest $e POST '/wp/v2/posts' @{ title = "$tag draft"; status = 'draft'; categories = @($cat['news']) }
    $testPosts += $r.Data.id
    $draft = Invoke-Rest $null POST '/portal/v1/reports' @{ object_type = 'post'; object_id = $r.Data.id; reason = 'spam' }
    Check '10. Rejected: unknown reason (400), text over 1000 characters (400), draft post (404)' ($bad.Code -eq 400 -and $long.Code -eq 400 -and $draft.Code -eq 404) "reason=$($bad.Code) long=$($long.Code) draft=$($draft.Code)"

    $m = Invoke-Rest $s POST '/portal/v1/reports' @{ object_type = 'post'; object_id = $unverified.id; reason = 'victim_identity' }
    $who = [int](Wp db query "SELECT reporter_id FROM wp_portal_reports WHERE object_id = $($unverified.id) LIMIT 1" --skip-column-names | Where-Object { $_.Trim() } | Select-Object -Last 1)
    $reasons = Invoke-Rest $null GET '/portal/v1/report-reasons'
    Check '11. A logged-in member''s report is saved under their account; reasons list is public for the app' ($m.Code -eq 201 -and $who -eq $users['subscriber'].Id -and @($reasons.Data).Count -ge 5) "HTTP $($m.Code) reporter=$who expected=$($users['subscriber'].Id) reasons=$(@($reasons.Data).Count)"
}
finally {
    if ($testPosts.Count) {
        Wp db query ("DELETE FROM wp_portal_reports WHERE object_type = 'post' AND object_id IN (" + ($testPosts -join ',') + ")") | Out-Null
    }
    # The visitor rate counter (a transient) is left to expire by itself within the hour.
    Complete-Test
}
if ($script:Fail -gt 0) { exit 1 }
