# Task 7 test: home page blocks, Breaking strip, top story, badges, view counter, Trending.
#
# Run from the project folder (Docker must be running):
#   powershell -ExecutionPolicy Bypass -File tests\test-home-page.ps1
#
# Works with or without the demo content: every check looks for this run's own
# uniquely named posts.

. "$PSScriptRoot\lib.ps1"

function Get-Home {
    $raw = (curl.exe -s -w "`n%{http_code}" "$Base/" | Out-String).TrimEnd()
    return @{
        Code = [int]($raw.Substring($raw.LastIndexOf("`n") + 1))
        Html = $raw.Substring(0, $raw.LastIndexOf("`n"))
    }
}

function Get-Block($html, $class) {
    # The HTML of the first <section> (or the breaking <div>) whose class contains $class.
    if ($class -eq 'portal-breaking') {
        $m = [regex]::Match($html, '(?s)<div class="portal-breaking".*?</ul>')
    } else {
        $m = [regex]::Match($html, '(?s)<section[^>]*class="[^"]*\b' + [regex]::Escape($class) + '\b[^"]*".*?</section>')
    }
    if ($m.Success) { return $m.Value } else { return '' }
}

function Get-Card($html, $title) {
    # The <article> card that contains $title.
    $m = [regex]::Match($html, '(?s)<article[^>]*>(?:(?!</article>).)*?' + [regex]::Escape($title) + '.*?</article>')
    if ($m.Success) { return $m.Value } else { return '' }
}

$tag = 'T7-' + [guid]::NewGuid().ToString('N').Substring(0, 6)   # makes this run's titles unique

try {
    Write-Host '== Setup: category IDs and temporary test users'
    $cat = Get-CategoryIds
    $users = New-TestUsers @('author', 'editor')
    $a = $users['author'].Session
    $e = $users['editor'].Session

    Write-Host '== Breaking switch'
    $r = Invoke-Rest $a POST '/wp/v2/posts' @{ title = "$tag author breaking"; status = 'publish'; categories = @($cat['industry']); meta = @{ portal_breaking = $true } }
    Check '1. An Author cannot mark a post Breaking' ($r.Code -eq 403) "HTTP $($r.Code) $($r.Data.code)"

    $r = Invoke-Rest $e POST '/wp/v2/posts' @{ title = "$tag fresh breaking"; status = 'publish'; categories = @($cat['news']); meta = @{ portal_breaking = $true } }
    $breaking = $r.Data.id
    $old = (Get-Date).ToUniversalTime().AddHours(-30).ToString('yyyy-MM-ddTHH:mm:ss')
    $r = Invoke-Rest $e POST '/wp/v2/posts' @{ title = "$tag old breaking"; status = 'publish'; date_gmt = $old; categories = @($cat['news']); meta = @{ portal_breaking = $true } }

    $r = Invoke-Rest $null GET '/portal/v1/breaking'
    $titles = @($r.Data | ForEach-Object { $_.title }) -join ' | '
    Check '2. API /portal/v1/breaking lists the new Breaking post, not the 30-hour-old one' ($r.Code -eq 200 -and $titles -match "$tag fresh breaking" -and $titles -notmatch "$tag old breaking") "HTTP $($r.Code) titles=$titles"

    Write-Host '== Top story, badges, community blog'
    $r = Invoke-Rest $e POST '/wp/v2/posts' @{ title = "$tag top story"; status = 'publish'; sticky = $true; categories = @($cat['logistics']); meta = @{ portal_verified = $true; portal_fir_number = '77/2026'; portal_police_station = 'Test PS' } }
    $top = $r.Data.id
    $r = Invoke-Rest $e POST '/wp/v2/posts' @{ title = "$tag plain story"; status = 'publish'; categories = @($cat['region']) }
    $plain = $r.Data.id
    $r = Invoke-Rest $e POST '/wp/v2/posts' @{ title = "$tag premium story"; status = 'publish'; categories = @($cat['industry']); meta = @{ portal_is_premium = $true } }
    $r = Invoke-Rest $a POST '/wp/v2/posts' @{ title = "$tag community post"; status = 'publish'; categories = @($cat['community-blogs']) }

    $page = Get-Home
    $h = $page.Html
    $errors = [regex]::Matches($h, '(Fatal error|Warning|Notice|Deprecated)</b>:').Count
    Check '3. Home page loads (HTTP 200) with no PHP errors' ($page.Code -eq 200 -and $errors -eq 0) "HTTP $($page.Code), PHP messages: $errors"

    $strip = Get-Block $h 'portal-breaking'
    Check '4. Red strip shows the new Breaking post, not the old one' ($strip -match "$tag fresh breaking" -and $strip -notmatch "$tag old breaking") "strip found: $($strip.Length -gt 0)"

    Check '5. The sticky post is the top story' ((Get-Block $h 'portal-top-story') -match "$tag top story") 'top story block does not contain it'

    $topCard = Get-Card $h "$tag top story"
    $plainCard = Get-Card $h "$tag plain story"
    $premCard = Get-Card $h "$tag premium story"
    Check '6. Verified badge on the verified post only' ($topCard -match 'portal-badge--verified' -and $plainCard -and $plainCard -notmatch 'portal-badge') "verified card: $($topCard.Length) chars, plain card: $($plainCard.Length) chars"
    Check '7. Premium label on the Premium post' ($premCard -match 'portal-badge--premium') "card found: $($premCard.Length -gt 0)"

    $blogs = Get-Block $h 'portal-blogs'
    $latest = Get-Block $h 'portal-latest'
    Check '8. Community post is in Community Blogs, not in Latest (news desk)' ($blogs -match "$tag community post" -and $latest -notmatch "$tag community post" -and $latest -match "$tag plain story") 'wrong block'

    Write-Host '== View counter'
    $r1 = Invoke-Rest $null POST "/portal/v1/views/$plain"
    $r2 = Invoke-Rest $null POST "/portal/v1/views/$plain"
    $r = Invoke-Rest $null GET "/wp/v2/posts/$plain"
    Check '9. Two reads counted; portal_views shows 2 in /wp/v2/posts' ($r1.Code -eq 200 -and $r2.Data.views -eq 2 -and $r.Data.portal_views -eq 2) "HTTP $($r1.Code), counter=$($r2.Data.views), api=$($r.Data.portal_views)"

    $r = Invoke-Rest $e POST '/wp/v2/posts' @{ title = "$tag draft"; status = 'draft'; categories = @($cat['news']) }
    $d = Invoke-Rest $null POST "/portal/v1/views/$($r.Data.id)"
    $n = Invoke-Rest $null POST '/portal/v1/views/99999999'
    Invoke-Rest $e POST "/wp/v2/posts/$plain" @{ portal_views = 99999 } | Out-Null
    $after = (Invoke-Rest $null GET "/wp/v2/posts/$plain").Data.portal_views
    Check '10. Drafts and missing posts are not counted; nobody can type in a view count' ($d.Code -eq 404 -and $n.Code -eq 404 -and $after -eq 2) "draft=$($d.Code) missing=$($n.Code) views after edit attempt=$after"

    Write-Host '== Trending'
    Wp post meta update $top _portal_views 999999 | Out-Null
    $r = Invoke-Rest $null GET '/portal/v1/trending?per_page=5'
    $trend = Get-Block (Get-Home).Html 'portal-trending'
    Check '11. Most-read post is #1 in /portal/v1/trending and on the home page' ($r.Code -eq 200 -and $r.Data[0].id -eq $top -and $r.Data.Count -eq 5 -and $trend -match "$tag top story") "HTTP $($r.Code) first=$($r.Data[0].id) expected=$top count=$($r.Data.Count)"
}
finally {
    Complete-Test
}
if ($script:Fail -gt 0) { exit 1 }
