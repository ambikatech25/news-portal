# Task 17 test: SEO (Rank Math + portal-core rules), security hardening, Wordfence, backups,
# LiteSpeed settings and no Gravatar. (Speed itself is measured with Lighthouse / PageSpeed,
# see docs/speed.md.)
#
# Run from the project folder (Docker must be running):
#   powershell -ExecutionPolicy Bypass -File tests\test-seo-security.ps1

. "$PSScriptRoot\lib.ps1"

function Get-Last($lines) { return ($lines | Where-Object { "$_".Trim() } | Select-Object -Last 1) }
function Get-Page($url) {
    $hdr = Join-Path $Tmp 'h.txt'
    $html = (curl.exe -s -D $hdr $url | Out-String)
    return @{ Html = $html; Headers = (Get-Content $hdr -Raw) }
}
function Get-JsonLdTypes($html) {
    # All "@type" values in the page's structured data (JSON-LD).
    $types = @()
    foreach ($m in [regex]::Matches($html, '(?s)<script type="application/ld\+json"[^>]*>(.*?)</script>')) {
        $types += [regex]::Matches($m.Groups[1].Value, '"@type":"([^"]+)"') | ForEach-Object { $_.Groups[1].Value }
    }
    return $types
}

$tag = 'T17-' + [guid]::NewGuid().ToString('N').Substring(0, 6)

try {
    Write-Host '== Setup'
    $cat = Get-CategoryIds
    $users = New-TestUsers @('editor', 'author')
    $e = $users['editor'].Session
    $news = Invoke-Rest $e POST '/wp/v2/posts' @{ title = "$tag news story"; content = 'Facts.'; status = 'publish'; categories = @($cat['logistics']) }
    $blog = Invoke-Rest $users['author'].Session POST '/wp/v2/posts' @{ title = "$tag blog post"; content = 'My view.'; status = 'publish'; categories = @($cat['community-blogs']) }
    $pending = Invoke-Rest $e POST '/wp/v2/posts' @{ title = "$tag pending story"; content = 'Not yet.'; status = 'pending'; categories = @($cat['region']) }
    Wp eval "portal_take_down( 'post', $($blog.Data.id), 'Test takedown for the sitemap check.', $($users['editor'].Id) );" | Out-Null

    Write-Host '== Security'
    $homePage = Get-Page "$Base/"
    $login = Get-Page "$Base/wp-login.php"
    $need = @('X-Content-Type-Options: nosniff', 'X-Frame-Options: SAMEORIGIN', 'Referrer-Policy: strict-origin-when-cross-origin', 'Permissions-Policy:')
    $missing = @($need | Where-Object { $homePage.Headers -notmatch [regex]::Escape($_) -or $login.Headers -notmatch [regex]::Escape($_) })
    Check '1. Security headers on pages and on the login page (no framing, no type guessing, referrer and permissions limits)' ($missing.Count -eq 0) "missing: $($missing -join ' | ')"

    $xml = '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName></methodCall>'
    $xf = Join-Path $Tmp 'x.xml'; [IO.File]::WriteAllText($xf, $xml)
    $xr = (curl.exe -s -H 'Content-Type: text/xml' --data-binary "@$xf" "$Base/xmlrpc.php" | Out-String)
    Check '2. XML-RPC is switched off (no methods offered, no X-Pingback advert)' ($xr -notmatch 'wp.getUsersBlogs|pingback.ping|metaWeblog' -and $homePage.Headers -notmatch 'X-Pingback') "xmlrpc answer: $($xr.Substring(0, [Math]::Min(150, $xr.Length)))"

    Check '3. The WordPress version is not advertised; no Gravatar requests (built-in avatars)' ($homePage.Html -notmatch '<meta name="generator" content="WordPress' -and $homePage.Html -notmatch 'gravatar\.com' -and (Get-Page $news.Data.link).Html -notmatch 'gravatar\.com') 'generator or gravatar found'

    Write-Host '== Search engines'
    $index = (curl.exe -s "$Base/sitemap_index.xml" | Out-String)
    $postMap = (curl.exe -s "$Base/post-sitemap.xml" | Out-String)
    $pageMap = (curl.exe -s "$Base/page-sitemap.xml" | Out-String)
    Check '4. Sitemaps: index lists stories, pages, sections and writers' ($index -match 'post-sitemap' -and $index -match 'page-sitemap' -and $index -match 'category-sitemap' -and $index -match 'author-sitemap') "index length=$($index.Length)"
    Check '5. Published stories are in the sitemap; pending, taken-down and private pages (account, write) are not' ($postMap -match [regex]::Escape($news.Data.link) -and $postMap -notmatch "$tag-pending" -and $postMap -notmatch "$tag-blog-post" -and $pageMap -notmatch '/account/' -and $pageMap -notmatch '/write/' -and $pageMap -match '/grievance-officer/') "news in=$($postMap -match [regex]::Escape($news.Data.link))"

    $robots = (curl.exe -s "$Base/robots.txt" | Out-String)
    Check '6. robots.txt points search engines to the sitemap' ($robots -match 'Sitemap:\s*\S*sitemap_index\.xml') "robots=$robots"

    $newsTypes = Get-JsonLdTypes (Get-Page $news.Data.link).Html
    $blogPub = Invoke-Rest $users['author'].Session POST '/wp/v2/posts' @{ title = "$tag second blog"; content = 'Opinion.'; status = 'publish'; categories = @($cat['community-blogs']) }
    $blogTypes = Get-JsonLdTypes (Get-Page $blogPub.Data.link).Html
    Check '7. Structured data: news story = NewsArticle, community post = BlogPosting, publisher = an organisation' (($newsTypes -contains 'NewsArticle') -and ($blogTypes -contains 'BlogPosting') -and -not ($blogTypes -contains 'NewsArticle') -and (($newsTypes -contains 'Organization') -or ($newsTypes -contains 'NewsMediaOrganization'))) "news=$($newsTypes -join ',') blog=$($blogTypes -join ',')"

    $title = [regex]::Match($homePage.Html, '<title>([^<]+)</title>').Groups[1].Value
    $desc = [regex]::Match($homePage.Html, '<meta name="description" content="([^"]+)"').Groups[1].Value
    $acct = (Get-Page "$Base/account/").Html
    $slug = Get-Last (Wp user get $users['author'].Id --field=user_nicename)
    $prof = (Get-Page "$Base/writer/$slug/").Html
    Check '8. Home title and description set; My Account is noindex; writer profiles may appear in Google' ($title -match '^CityCharcha' -and $desc -match 'Chhattisgarh' -and $acct -match '<meta name=.robots. content=.[^>]*noindex' -and $prof -notmatch '<meta name=.robots. content=.[^>]*noindex') "title=$title desc=$desc"

    Write-Host '== Wordfence, backups, cache'
    $wf = @{}
    foreach ($k in 'loginSec_maxFailures', 'loginSec_disableAuthorScan', 'other_hideWPVersion', 'loginSec_breachPasswds') { $wf[$k] = Get-Last (Wp db query "SELECT val FROM wp_wfconfig WHERE name = '$k'" --skip-column-names) }
    $authorApi = Invoke-Rest $e GET "/wp/v2/users/$($users['author'].Id)?context=view"
    Check '9. Wordfence: lockout after 5 failures, leaked passwords blocked for publishers; editors can see story authors again' ($wf['loginSec_maxFailures'] -eq '5' -and $wf['loginSec_breachPasswds'] -eq 'pubs' -and $wf['loginSec_disableAuthorScan'] -eq '0' -and $authorApi.Code -eq 200 -and $authorApi.Data.name) "wf=$($wf | ConvertTo-Json -Compress) author api=$($authorApi.Code)"

    $ud = @{}
    foreach ($k in 'updraft_interval', 'updraft_interval_database', 'updraft_retain_db') { $ud[$k] = Get-Last (Wp option get $k) }
    $svc = (Wp option get updraft_service --format=json) -join ''
    Check '10. Backups scheduled: database daily (keep 14), files weekly, destination Google Drive' ($ud['updraft_interval_database'] -eq 'daily' -and $ud['updraft_interval'] -eq 'weekly' -and $ud['updraft_retain_db'] -eq '14' -and $svc -match 'googledrive') "ud=$($ud | ConvertTo-Json -Compress) service=$svc"

    $exc = (Wp eval "echo wp_json_encode( \LiteSpeed\Conf::cls()->conf( 'cache-exc' ) );") -join ''
    $defer = Get-Last (Wp eval "echo (int) \LiteSpeed\Conf::cls()->conf( 'optm-js_defer' );")
    Check '11. LiteSpeed Cache: personal and form pages never cached; script deferring off (keeps forms working)' ($exc -match 'grievance-officer' -and $exc -match 'account' -and $defer -eq '0') "exc=$exc defer=$defer"
}
finally {
    Complete-Test
}
if ($script:Fail -gt 0) { exit 1 }
