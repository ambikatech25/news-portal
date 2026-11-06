# Task 5 test: editor-only categories and Contributor image uploads.
#
# Logs in as temporary test users and talks to the REST API exactly like the
# block editor does (login cookie + X-WP-Nonce). Cleans up after itself.
#
# Run from the project folder (Docker must be running):
#   powershell -ExecutionPolicy Bypass -File tests\test-restricted-categories.ps1

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
$Base = 'http://localhost:8080'
$Tmp  = Join-Path $env:TEMP ('portal-test-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
New-Item -ItemType Directory -Path $Tmp | Out-Null
$env:Path = "C:\Program Files\Docker\Docker\resources\bin;" + $env:Path
Push-Location $Root

$script:Pass = 0
$script:Fail = 0

function Wp {
    # Run a WP-CLI command and return its output lines (container chatter removed).
    # Docker writes status lines to stderr; PowerShell 5.1 would treat them as fatal under 'Stop'.
    $ErrorActionPreference = 'Continue'
    $out = & docker compose run --rm wpcli @args 2>&1 | Where-Object { "$_" -notmatch 'Container news-portal' }
    return $out | ForEach-Object { "$_" }
}

function New-Password {
    $chars = (48..57) + (65..90) + (97..122)
    return -join (1..24 | ForEach-Object { [char]($chars | Get-Random) })
}

function Check($name, $condition, $detail) {
    if ($condition) { $script:Pass++; Write-Host ("PASS  " + $name) }
    else { $script:Fail++; Write-Host ("FAIL  " + $name + "  -> " + $detail) }
}

function Connect-User($login, $password) {
    # Log in like a browser, then fetch the REST nonce like the block editor does.
    $jar = Join-Path $Tmp "$login.cookies"
    curl.exe -s -o NUL -c $jar -b $jar "$Base/wp-login.php"
    curl.exe -s -o NUL -c $jar -b $jar --data-urlencode "log=$login" --data-urlencode "pwd=$password" `
        --data-urlencode "testcookie=1" "$Base/wp-login.php"
    $nonce = (curl.exe -s -c $jar -b $jar "$Base/wp-admin/admin-ajax.php?action=rest-nonce" | Out-String).Trim()
    if ($nonce -notmatch '^[0-9a-f]{10}$') { throw "Login failed for $login (no REST nonce)" }
    return @{ Jar = $jar; Nonce = $nonce }
}

function Invoke-Rest($session, $method, $path, $body) {
    $file = Join-Path $Tmp 'body.json'
    $args = @('-s', '-X', $method, '-b', $session.Jar, '-H', "X-WP-Nonce: $($session.Nonce)", '-w', "`n%{http_code}")
    if ($body) {
        [IO.File]::WriteAllText($file, ($body | ConvertTo-Json -Compress))
        $args += @('-H', 'Content-Type: application/json', '--data-binary', "@$file")
    }
    $raw = (curl.exe @args "$Base/wp-json$path" | Out-String).TrimEnd()
    $code = [int]($raw.Substring($raw.LastIndexOf("`n") + 1))
    $json = $raw.Substring(0, $raw.LastIndexOf("`n"))
    $data = $null
    try { $data = $json | ConvertFrom-Json } catch { }
    return @{ Code = $code; Data = $data }
}

function Send-Image($session) {
    # A real 40x40 PNG made on the fly.
    Add-Type -AssemblyName System.Drawing
    $png = Join-Path $Tmp 'test-photo.png'
    $bmp = New-Object System.Drawing.Bitmap 40, 40
    $bmp.Save($png, [System.Drawing.Imaging.ImageFormat]::Png)
    $bmp.Dispose()
    $raw = (curl.exe -s -X POST -b $session.Jar -H "X-WP-Nonce: $($session.Nonce)" `
        -H 'Content-Type: image/png' -H 'Content-Disposition: attachment; filename=test-photo.png' `
        --data-binary "@$png" -w "`n%{http_code}" "$Base/wp-json/wp/v2/media" | Out-String).TrimEnd()
    return [int]($raw.Substring($raw.LastIndexOf("`n") + 1))
}

$users = @{}
try {
    Write-Host '== Setup: category IDs and temporary test users'
    $cat = @{}
    foreach ($slug in 'news', 'crime', 'case-files', 'logistics', 'industry', 'community-blogs') {
        $cat[$slug] = [int](Wp term list category "--slug=$slug" --field=term_id | Select-Object -Last 1)
    }
    foreach ($role in 'contributor', 'author', 'editor') {
        $login = "portal_test_$role"
        Wp user delete $login --yes 2>$null | Out-Null   # leftovers from an aborted run
        $pw = New-Password
        $id = [int](Wp user create $login "$login@portal.test" "--role=$role" "--user_pass=$pw" --porcelain | Select-Object -Last 1)
        $users[$role] = @{ Id = $id; Login = $login; Session = (Connect-User $login $pw) }
    }
    $c = $users['contributor'].Session
    $a = $users['author'].Session
    $e = $users['editor'].Session

    Write-Host '== Contributor'
    $r = Invoke-Rest $c POST '/wp/v2/posts' @{ title = 'Contributor tries News'; categories = @($cat['news']) }
    Check '1. Contributor cannot create a post in News' ($r.Code -eq 403 -and $r.Data.code -eq 'portal_restricted_category') "HTTP $($r.Code) $($r.Data.code)"
    if ($r.Data.message) { Write-Host ("      message shown: " + $r.Data.message) }

    $r = Invoke-Rest $c POST '/wp/v2/posts' @{ title = 'Contributor mixes Crime + Logistics'; categories = @($cat['crime'], $cat['logistics']) }
    Check '2. Contributor cannot sneak Crime in next to an allowed category' ($r.Code -eq 403) "HTTP $($r.Code)"

    $r = Invoke-Rest $c POST '/wp/v2/posts' @{ title = 'Contributor blog post'; status = 'pending'; categories = @($cat['community-blogs']) }
    $ownPost = $r.Data.id
    Check '3. Contributor CAN submit to Community Blogs (status pending)' ($r.Code -eq 201 -and $r.Data.status -eq 'pending') "HTTP $($r.Code) status=$($r.Data.status)"

    $r = Invoke-Rest $c POST "/wp/v2/posts/$ownPost" @{ categories = @($cat['community-blogs'], $cat['case-files']) }
    Check '4. Contributor cannot move own post into Case Files later' ($r.Code -eq 403) "HTTP $($r.Code)"

    $code = Send-Image $c
    Check '5. Contributor CAN upload an image' ($code -eq 201) "HTTP $code"

    Write-Host '== Author'
    $r = Invoke-Rest $a POST '/wp/v2/posts' @{ title = 'Author tries Case Files'; status = 'publish'; categories = @($cat['case-files']) }
    Check '6. Author cannot publish in Case Files' ($r.Code -eq 403) "HTTP $($r.Code)"

    $r = Invoke-Rest $a POST '/wp/v2/posts' @{ title = 'Author industry post'; status = 'publish'; categories = @($cat['industry']) }
    Check '7. Author CAN publish in Industry' ($r.Code -eq 201 -and $r.Data.status -eq 'publish') "HTTP $($r.Code)"

    Write-Host '== Editor'
    $r = Invoke-Rest $e POST '/wp/v2/posts' @{ title = 'Editor news story'; status = 'publish'; author = $users['author'].Id; categories = @($cat['news']) }
    $editorPost = $r.Data.id
    Check '8. Editor CAN publish in News (on behalf of the Author)' ($r.Code -eq 201) "HTTP $($r.Code)"

    $r = Invoke-Rest $a POST "/wp/v2/posts/$editorPost" @{ title = 'Editor news story (typo fixed by author)'; categories = @($cat['news'], $cat['industry']) }
    $slugs = (Wp post term list $editorPost category --field=slug) -join ','
    Check "9. Author's later edit keeps the Editor's News choice" ($r.Code -eq 200 -and $slugs -match 'news') "HTTP $($r.Code) categories=$slugs"

    Write-Host '== Safety net (not via REST)'
    $id = [int](Wp post create "--user=$($users['contributor'].Login)" '--post_title=Safety net test' "--post_category=$($cat['news'])" --porcelain | Select-Object -Last 1)
    $slugs = (Wp post term list $id category --field=slug) -join ','
    Check '10. News added outside the API is removed (falls back to Community Blogs)' ($slugs -eq 'community-blogs') "categories=$slugs"
}
finally {
    Write-Host '== Cleanup'
    foreach ($u in $users.Values) { Wp user delete $u.Id --yes | Out-Null }
    Pop-Location
    Remove-Item -Recurse -Force $Tmp -ErrorAction SilentlyContinue
    Write-Host ("Removed {0} test users and their posts/images." -f $users.Count)
}

Write-Host ''
Write-Host ("RESULT: {0} passed, {1} failed" -f $script:Pass, $script:Fail)
if ($script:Fail -gt 0) { exit 1 }
