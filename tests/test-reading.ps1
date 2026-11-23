# Task 17B test (controls simplified after launch): reading comfort on every story (text,
# progress bar, always-visible text size and Light / Sepia / Dark, no font downloads), and none
# of it on other pages.
#
# What the buttons DO (size steps, keeping your place, colours over the whole page, remembering
# choices, phone layout on one line, no layout shift) runs in the reader's browser and was checked
# in a real browser (docs/reading.md, "Checked in a browser").
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
    $homeHtml = Get-Html "$Base/en/"

    Write-Host '== On every story'
    $head = [regex]::Match($html, '(?s)<head.*?</head>').Value
    Check '1. The reader''s saved size and colours are applied in the page head, before the page is drawn (no jump, no white flash)' ($head -match "localStorage\.getItem\('portalReader'\)" -and $head -match 'data-portal-theme' -and $head -match '--portal-read-scale') 'head script missing'

    $line = [regex]::Match($html, '(?s)<p class="portal-article-flags__line">.*?</p>').Value
    $minutes = [int][regex]::Match($line, '(\d+) min read').Groups[1].Value
    Check '2. Under the headline, one line: read time, then A- A+ and Light / Sepia / Dark (always visible); progress bar at the top' ($minutes -gt 1 -and $line -match 'class="portal-read-controls"' -and $line -notmatch 'portal-read-controls[^"]*"[^>]*\shidden' -and $html -match 'class="portal-progress"' -and $html -match 'data-portal-progress') "read=$minutes controls=$([bool]($line -match 'portal-read-controls'))"

    Check '3. The old Reading mode button and toolbar are gone' ($html -notmatch 'data-portal-reading-toggle' -and $html -notmatch 'portal-reading-bar' -and $html -notmatch 'Reading mode') 'old reading mode still on the page'

    Check '4. Buttons have names for screen readers: Smaller text, Larger text, and Light / Sepia / Dark (Light pressed by default)' ($line -match 'aria-label="Smaller text"' -and $line -match 'aria-label="Larger text"' -and $line -match 'data-portal-theme-pick="light" aria-pressed="true"' -and $line -match 'data-portal-theme-pick="sepia" aria-pressed="false"' -and $line -match 'data-portal-theme-pick="dark" aria-pressed="false"' -and $line -match '>Sepia<') 'controls incomplete'

    Check '5. The reading script is loaded after the page (deferred)' ($html -match '<script[^>]*defer[^>]*id="portal-reading-js"') 'script missing'

    Write-Host '== Text and fonts'
    $css = (curl.exe -s "$Base/wp-content/themes/portal-child/style.css" | Out-String)
    Check '6. Story text: 18 px on phones, 19 px on desktop (times the reader''s size), tall lines (1.75), Hindi fonts named for Android, Windows and iPhone' ($css -match '(?s)\.single-entry \.entry-content \{[^}]*font-size: calc\(1\.125rem \* var\(--portal-read-scale\)\);[^}]*line-height: 1\.75;' -and $css -match '(?s)min-width: 768px\)[^{]*\{\s*\.single-entry \.entry-content \{\s*font-size: calc\(1\.1875rem \* var\(--portal-read-scale\)\);' -and $css -match '"Noto Sans Devanagari", "Nirmala UI", "Kohinoor Devanagari"') 'css rules missing'

    Check '7. No font files are downloaded (fonts already on the device keep PageSpeed high)' ($html -notmatch 'fonts\.googleapis|fonts\.gstatic|\.woff2?\b' -and $css -notmatch '@font-face') 'web font found'

    Check '8. Sepia and Dark recolour the whole story page, header included (not only the text)' ($css -match '(?m)^html\[data-portal-theme="sepia"\] \{' -and $css -match '(?m)^html\[data-portal-theme="dark"\] \{' -and $css -match 'html\[data-portal-theme\] :is\(#masthead') 'theme rules missing'

    Write-Host '== Only on stories'
    Check '9. Home page has none of it (no progress bar, controls, head script or reading script)' ($homeHtml -notmatch 'portal-progress' -and $homeHtml -notmatch 'portal-read-controls' -and $homeHtml -notmatch "getItem\('portalReader'\)" -and $homeHtml -notmatch 'portal-reading-js') 'found on home'

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
