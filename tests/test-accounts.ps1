# Task 10 test: sign-up with consent, e-mail confirmation (via Mailpit), first login,
# where members land, wp-admin / admin bar rules, header link, photo on My Account,
# consent record (e-mail and Google).
#
# Run from the project folder (Docker must be running, including the mailpit service):
#   powershell -ExecutionPolicy Bypass -File tests\test-accounts.ps1
#
# Google sign-in itself needs a real Google account: it is checked by hand (docs/accounts.md).

. "$PSScriptRoot\lib.ps1"

$Mail = 'http://localhost:8025'

function Get-Last($lines) { return ($lines | Where-Object { "$_".Trim() } | Select-Object -Last 1) }

function Invoke-Form($jar, $url, [hashtable]$fields) {
    # POST a normal web form. Returns @{ Code; Location; Html }.
    $hdr = Join-Path $Tmp 'headers.txt'
    $curlArgs = @('-s', '-D', $hdr, '-c', $jar, '-b', $jar)
    foreach ($k in $fields.Keys) { $curlArgs += @('--data-urlencode', "$k=$($fields[$k])") }
    $html = (curl.exe @curlArgs $url | Out-String)
    $h = Get-Content $hdr -Raw
    return @{
        Code     = [int]([regex]::Match($h, 'HTTP/[\d.]+ (\d{3})').Groups[1].Value)
        Location = [regex]::Match($h, '(?im)^Location:\s*(\S+)').Groups[1].Value
        Html     = $html
    }
}

function Get-WithJar($jar, $url) {
    $hdr = Join-Path $Tmp 'headers.txt'
    $html = (curl.exe -s -D $hdr -c $jar -b $jar $url | Out-String)
    $h = Get-Content $hdr -Raw
    return @{
        Code     = [int]([regex]::Match($h, 'HTTP/[\d.]+ (\d{3})').Groups[1].Value)
        Location = [regex]::Match($h, '(?im)^Location:\s*(\S+)').Groups[1].Value
        Html     = $html
    }
}

function Get-UserId($login) {
    $id = Get-Last (Wp user get $login --field=ID 2>$null)
    if ("$id" -match '^\d+$') { return [int]$id } else { return 0 }
}

$tag   = 't10' + [guid]::NewGuid().ToString('N').Substring(0, 6)
$login = "portal_test_$tag"
$email = "$tag@portal.test"
$reg   = "$Base/wp-login.php?action=register"
$made  = @()

try {
    curl.exe -s -o NUL -X DELETE "$Mail/api/v1/messages"   # empty the local mailbox

    Write-Host '== Sign-up form'
    $page = (curl.exe -s $reg | Out-String)
    Check '1. Sign-up page has the consent checkbox with Terms of Use and Privacy Policy links' ($page -match 'name="portal_consent"' -and $page -match '/terms-of-use/' -and $page -match '/privacy-policy/' -and $page -match '18 years old') 'checkbox or links missing'

    $jar = Join-Path $Tmp 'visitor.cookies'
    $r = Invoke-Form $jar $reg @{ user_login = $login; user_email = $email; portal_website = ''; 'wp-submit' = 'Register' }
    Check '2. No account without ticking the consent box' ($r.Html -match 'Please agree to the Terms of Use' -and (Get-UserId $login) -eq 0) "HTTP $($r.Code)"

    $r = Invoke-Form $jar $reg @{ user_login = $login; user_email = $email; portal_consent = '1'; portal_website = 'http://spam.example'; 'wp-submit' = 'Register' }
    Check '3. Bots that fill the hidden trap field are refused' ($r.Html -match 'Sign-up could not be completed' -and (Get-UserId $login) -eq 0) "HTTP $($r.Code)"

    $r = Invoke-Form $jar $reg @{ user_login = $login; user_email = $email; portal_consent = '1'; portal_website = ''; 'wp-submit' = 'Register' }
    $uid = Get-UserId $login
    if ($uid) { $made += $uid }
    $role = Get-Last (Wp user get $uid --field=roles)
    $consent = (Wp user meta get $uid portal_consent --format=json) -join '' | ConvertFrom-Json
    Check '4. With consent: account created as Member (Subscriber), consent recorded (e-mail, Terms version)' ($r.Location -match 'checkemail=registered' -and $uid -gt 0 -and $role -eq 'subscriber' -and $consent.method -eq 'email' -and $consent.terms_version -and $consent.at) "redirect=$($r.Location) id=$uid role=$role consent=$($consent | ConvertTo-Json -Compress)"

    Write-Host '== Confirmation e-mail and first password'
    Start-Sleep -Seconds 2
    $list = (curl.exe -s "$Mail/api/v1/search?query=to:$email" | Out-String) | ConvertFrom-Json
    $msgId = $list.messages[0].ID
    $body = ''
    if ($msgId) { $body = ((curl.exe -s "$Mail/api/v1/message/$msgId" | Out-String) | ConvertFrom-Json).Text }
    $link = [regex]::Match($body, 'https?://\S*action=rp\S*').Value
    Check '5. The "set your password" e-mail arrives (local mail catcher) with a working link' ($list.messages_count -ge 1 -and $link) "messages=$($list.messages_count) link=$link"

    $pw = New-Password
    $ujar = Join-Path $Tmp 'newuser.cookies'
    Get-WithJar $ujar $link | Out-Null                          # sets the reset cookie
    $form = Get-WithJar $ujar "$Base/wp-login.php?action=rp"
    $rpKey = [regex]::Match($form.Html, 'name="rp_key" value="([^"]+)"').Groups[1].Value
    $set = Invoke-Form $ujar "$Base/wp-login.php?action=resetpass" @{ pass1 = $pw; pass2 = $pw; rp_key = $rpKey; 'wp-submit' = 'Save Password' }
    $in = Invoke-Form $ujar "$Base/wp-login.php" @{ log = $login; pwd = $pw; testcookie = '1'; 'wp-submit' = 'Log In' }
    Check '6. After setting a password, the member logs in and lands on My Account (not wp-admin)' ($set.Html -match 'password has been reset' -and $in.Location -eq "$Base/account/") "reset ok=$($set.Html -match 'password has been reset') login redirect=$($in.Location)"

    Write-Host '== What a member sees'
    $admin = Get-WithJar $ujar "$Base/wp-admin/"
    $homePage = Get-WithJar $ujar "$Base/"
    Check '7. Members are sent away from wp-admin and see no admin bar; header shows My account' ($admin.Code -eq 302 -and $admin.Location -match '^http://localhost:8080/?$' -and $homePage.Html -notmatch 'id="wpadminbar"' -and $homePage.Html -match 'portal-account-link"><a href="http://localhost:8080/account/">My account') "wp-admin=$($admin.Code)->$($admin.Location)"

    $acct = Get-WithJar $ujar "$Base/account/"
    Check '8. My Account shows the photo box and the Edit Profile form' ($acct.Code -eq 200 -and $acct.Html -match 'portal-photo-box' -and $acct.Html -match 'name="first_name"') "HTTP $($acct.Code)"

    Add-Type -AssemblyName System.Drawing
    $png = Join-Path $Tmp 'me.png'
    $bmp = New-Object System.Drawing.Bitmap 200, 200
    $g = [System.Drawing.Graphics]::FromImage($bmp); $g.Clear([System.Drawing.Color]::Teal); $g.Dispose()
    $bmp.Save($png, [System.Drawing.Imaging.ImageFormat]::Png); $bmp.Dispose()
    $nonce = [regex]::Match($acct.Html, '(?s)portal_profile_photo">\s*<input type="hidden" id="_wpnonce" name="_wpnonce" value="([^"]+)"').Groups[1].Value
    $hdr = Join-Path $Tmp 'headers.txt'
    curl.exe -s -o NUL -D $hdr -b $ujar -c $ujar -e "$Base/account/" -F 'action=portal_profile_photo' -F "_wpnonce=$nonce" -F "portal_avatar=@$png;type=image/png" "$Base/wp-admin/admin-post.php"
    $loc = [regex]::Match((Get-Content $hdr -Raw), '(?im)^Location:\s*(\S+)').Groups[1].Value
    $photo = Get-Last (Wp user meta get $uid portal_avatar_id)
    Check '9. A member uploads a profile photo from My Account (no wp-admin needed)' ($loc -match 'portal_photo=saved' -and "$photo" -match '^\d+$') "redirect=$loc photo=$photo"

    Write-Host '== Others'
    $logged_out = (curl.exe -s "$Base/" | Out-String)
    $users = New-TestUsers @('contributor')
    $cAdmin = Get-WithJar $users['contributor'].Session.Jar "$Base/wp-admin/"
    $cHome = Get-WithJar $users['contributor'].Session.Jar "$Base/"
    Check '10. Visitors see Sign in in the header; writers keep wp-admin and the admin bar' ($logged_out -match 'portal-account-link"><a href="http://localhost:8080/wp-login.php[^"]*">Sign in' -and $cAdmin.Code -eq 200 -and $cHome.Html -match 'id="wpadminbar"') "writer wp-admin=$($cAdmin.Code)"

    $gid = Get-Last (Wp user create "portal_test_$($tag)g" "$($tag)g@portal.test" --role=subscriber --porcelain)
    $made += [int]$gid
    Wp eval "do_action( 'nsl_register_new_user', $gid );" | Out-Null
    $gc = (Wp user meta get $gid portal_consent --format=json) -join '' | ConvertFrom-Json
    Check '11. Google sign-up (Nextend) also records consent, marked as Google' ($gc.method -eq 'google' -and $gc.at) "consent=$($gc | ConvertTo-Json -Compress)"
}
finally {
    foreach ($id in $made) {
        $ids = (Wp post list --post_type=attachment "--author=$id" --format=ids) -join ' '
        if ($ids.Trim()) { Wp post delete @($ids.Trim() -split ' ') --force | Out-Null }
        Wp user delete $id --yes | Out-Null
    }
    curl.exe -s -o NUL -X DELETE "$Mail/api/v1/messages"
    Complete-Test
}
if ($script:Fail -gt 0) { exit 1 }

