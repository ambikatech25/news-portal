# Task 17B test: reading comfort on every story (text, progress bar, reading mode markup,
# no font downloads), and none of it on other pages.
#
# What the buttons DO (reading mode on/off, keeping your place, text size, Light/Sepia/Dark,
# remembering choices, Esc, phone toolbar on one line, no layout shift) runs in the reader's
# browser and was checked in a real browser (docs/reading.md, "Checked in a browser").
#
# Run from the project folder (Docker must be running):
#   powershell -ExecutionPolicy Bypass -File tests\test-reading.ps1

. "$PSScriptRoot\lib.ps1"

function Get-Html($url) { return (curl.exe -s $url | Out-String) }

$tag = 'T17B-' + [guid]::NewGuid().ToString('N').Substring(0, 6)

try {
    Write-Host '== Setup'
    $cat = Get-CategoryIds
    $users = New-TestUsers @('author')
    $para = 'रायपुर के पुराने बाज़ार में सुबह से ही चहल-पहल रहती है और लोग ताज़ी सब्ज़ियाँ ख़रीदते हैं। '
    $body = ((1..40) | ForEach-Object { "<p>$($para * 3)</p>" }) -join ''
    $p = Invoke-Rest $users['author'].Session POST '/wp/v2/posts' @{ title = "$tag हिंदी लेख"; status = 'publish'; content = $body; categories = @($cat['community-blogs']) }
    $html = Get-Html $p.Data.link
    $homeHtml = Get-Html "$Base/"

    Write-Host '== On every story'
    $head = [regex]::Match($html, '(?s)<head.*?</head>').Value
    Check '1. The reader''s saved choices are applied in the page head, before the page is drawn (no jump)' ($head -match "localStorage\.getItem\('portalReader'\)" -and $head -match "sessionStorage\.getItem\('portalReading'\)" -and $head -match 'portal-reading') 'head script missing'

    $minutes = [int][regex]::Match($html, '(\d+) min read').Groups[1].Value
    $barMinutes = [int][regex]::Match($html, 'data-portal-minutes="(\d+)"').Groups[1].Value
    Check '2. Progress bar and reading-mode toolbar are on the story; "minutes left" starts from the same read time' ($html -match 'class="portal-progress"' -and $html -match 'data-portal-progress' -and $html -match 'class="portal-reading-bar"' -and $minutes -gt 1 -and $barMinutes -eq $minutes) "read=$minutes bar=$barMinutes"

    $toggle = [regex]::Match($html, '(?s)<button[^>]*data-portal-reading-toggle[^>]*>').Value
    Check '3. "Reading mode" button sits under the headline, starts hidden (shown by the script) and is a real toggle' ($toggle -match '\shidden' -and $toggle -match 'aria-pressed="false"' -and $html -match 'portal-reading-toggle__label">Reading mode<') $toggle

    Check '4. Toolbar has Exit, smaller/larger text and Light / Sepia / Dark, with names for screen readers' ($html -match 'data-portal-reading-exit' -and $html -match 'aria-label="Smaller text"' -and $html -match 'aria-label="Larger text"' -and $html -match 'data-portal-theme-pick="light"' -and $html -match 'data-portal-theme-pick="sepia"' -and $html -match 'data-portal-theme-pick="dark"' -and $html -match 'role="toolbar"') 'toolbar parts missing'

    Check '5. The reading script is loaded (after the page, deferred) with its words ready for Hindi translation' ($html -match '<script[^>]*defer[^>]*id="portal-reading-js"' -and $html -match 'Exit reading mode' -and $html -match 'min left') 'script missing'

    Write-Host '== Text and fonts'
    $css = (curl.exe -s "$Base/wp-content/themes/portal-child/style.css" | Out-String)
    Check '6. Story text: 18 px on phones, 19 px on desktop, tall lines (1.75), Hindi fonts named for Android, Windows and iPhone' ($css -match '(?s)\.single-entry \.entry-content \{[^}]*font-size: 1\.125rem;[^}]*line-height: 1\.75;' -and $css -match '(?s)min-width: 768px\)[^{]*\{\s*\.single-entry \.entry-content \{\s*font-size: 1\.1875rem;' -and $css -match '"Noto Sans Devanagari", "Nirmala UI", "Kohinoor Devanagari"') 'css rules missing'

    Check '7. No font files are downloaded (fonts already on the device keep PageSpeed high)' ($html -notmatch 'fonts\.googleapis|fonts\.gstatic|\.woff2?\b' -and $css -notmatch '@font-face') 'web font found'

    $hideRule = [regex]::Match($css, 'html\.portal-reading :is\(([^)]*)\)\s*\{\s*display: none').Groups[1].Value
    $mustHide = @('#masthead', '.portal-breaking', '#colophon', '.portal-article-actions', '.portal-author-card', '#comments', '.portal-comments-panel', '.portal-byline-follow', '.portal-related')
    $missing = $mustHide | Where-Object { $hideRule -notlike "*$_*" }
    Check '8. Reading mode hides header, breaking strip, Follow, share row, writer card, comments, related and footer, and has sepia + dark colours' ($hideRule -and -not $missing -and $css -match 'data-portal-theme="sepia"\]' -and $css -match 'data-portal-theme="dark"\]') "not hidden: $($missing -join ', ')"

    Write-Host '== Only on stories'
    Check '9. Home page has none of it (no progress bar, toolbar, head script or reading script)' ($homeHtml -notmatch 'portal-progress' -and $homeHtml -notmatch 'portal-reading-bar' -and $homeHtml -notmatch "getItem\('portalReader'\)" -and $homeHtml -notmatch 'portal-reading-js') 'found on home'

    $anon = curl.exe -s -o NUL -w '%{http_code}' $p.Data.link
    # LiteSpeed adds an "optimized at <time>" comment to each copy; ignore it.
    $clean = { param($h) ($h -replace '<!-- Page (optimized|supported) by LiteSpeed[^>]*-->', '') -replace '\s', '' }
    $same = & $clean (Get-Html $p.Data.link)
    Check '10. Story pages are the same for every visitor (choices live in each browser), so page caching stays safe' ($anon -eq '200' -and (& $clean $html) -eq $same) "code=$anon"
}
finally {
    Complete-Test
}
if ($script:Fail -gt 0) { exit 1 }
