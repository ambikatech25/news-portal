# Shared helpers for the PowerShell tests. Dot-source it:  . "$PSScriptRoot\lib.ps1"
#
# Tests log in as temporary users and talk to the REST API exactly like the block
# editor does (login cookie + X-WP-Nonce). Passwords are random and never printed.

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
$Base = 'http://localhost:8080'
$Tmp  = Join-Path $env:TEMP ('portal-test-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
New-Item -ItemType Directory -Path $Tmp | Out-Null
$env:Path = "C:\Program Files\Docker\Docker\resources\bin;" + $env:Path
Set-Location $Root

$script:Pass = 0
$script:Fail = 0
$script:TestUsers = @{}

function Wp {
    # Run a WP-CLI command and return its output lines (container chatter removed).
    # -T: no pseudo-terminal, so nothing bypasses this filter and prints straight to the screen.
    # Docker writes status lines to stderr; PowerShell 5.1 would treat them as fatal under 'Stop'.
    $ErrorActionPreference = 'Continue'
    $out = & docker compose run --rm -T wpcli @args 2>&1 | Where-Object { "$_" -notmatch 'Container news-portal' }
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

function Get-CategoryIds {
    $ids = @{}
    foreach ($slug in 'news', 'crime', 'case-files', 'logistics', 'industry', 'region', 'community-blogs') {
        $ids[$slug] = [int](Wp term list category "--slug=$slug" --field=term_id | Select-Object -Last 1)
    }
    return $ids
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

function New-TestUsers([string[]]$roles) {
    # Creates portal_test_<role> users and logs each one in. Returns @{ role = @{Id; Login; Session} }.
    foreach ($role in $roles) {
        $login = "portal_test_$role"
        Wp user delete $login --yes | Out-Null   # leftovers from an aborted run
        $pw = New-Password
        $id = [int](Wp user create $login "$login@portal.test" "--role=$role" "--user_pass=$pw" --porcelain | Select-Object -Last 1)
        $script:TestUsers[$role] = @{ Id = $id; Login = $login; Session = (Connect-User $login $pw) }
    }
    return $script:TestUsers
}

function Invoke-Rest($session, $method, $path, $body) {
    # $session = $null sends an anonymous (logged-out) request.
    $file = Join-Path $Tmp 'body.json'
    $curlArgs = @('-s', '-X', $method, '-w', "`n%{http_code}")
    if ($session) { $curlArgs += @('-b', $session.Jar, '-H', "X-WP-Nonce: $($session.Nonce)") }
    if ($body) {
        [IO.File]::WriteAllText($file, ($body | ConvertTo-Json -Compress -Depth 5))
        $curlArgs += @('-H', 'Content-Type: application/json', '--data-binary', "@$file")
    }
    $raw = (curl.exe @curlArgs "$Base/wp-json$path" | Out-String).TrimEnd()
    $code = [int]($raw.Substring($raw.LastIndexOf("`n") + 1))
    $json = $raw.Substring(0, $raw.LastIndexOf("`n"))
    $data = $null
    try { $data = $json | ConvertFrom-Json } catch { }
    return @{ Code = $code; Data = $data }
}

function Send-Image($session) {
    # Uploads a real 40x40 PNG made on the fly. Returns the HTTP status code.
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

function Reset-ReportLimit {
    # Visitors may send 10 reports an hour (portal-core). All test runs share one IP, so reset
    # that counter before a test sends reports, or repeated runs within an hour fail with 429.
    Wp db query "DELETE FROM wp_options WHERE option_name LIKE '\_transient%portal\_report\_rate\_%'" | Out-Null
}

function Complete-Test {
    # Always call from a finally block: removes test users (and their posts/images) and temp files.
    Write-Host '== Cleanup'
    foreach ($u in $script:TestUsers.Values) {
        # Deleting a user only moves their posts to the Trash, and "any" status skips trashed
        # posts - so delete every post of theirs (all statuses) permanently first.
        # --force also removes each post's revisions.
        foreach ($status in 'any', 'trash') {
            $ids = (Wp post list --post_type=any "--post_status=$status" "--author=$($u.Id)" --format=ids) -join ' '
            if ($ids.Trim()) { Wp post delete @($ids.Trim() -split ' ') --force | Out-Null }
        }
        Wp user delete $u.Id --yes | Out-Null
    }
    Remove-Item -Recurse -Force $Tmp -ErrorAction SilentlyContinue
    Write-Host ("Removed {0} test users and their posts/images." -f $script:TestUsers.Count)
    Write-Host ''
    Write-Host ("RESULT: {0} passed, {1} failed" -f $script:Pass, $script:Fail)
}
