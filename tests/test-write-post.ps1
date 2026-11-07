# Task 11 test: "Write a story" page, Become-a-writer requests (approve / turn down),
# story submission -> Pending review, editor-only categories, trusted writers publish.
#
# Run from the project folder (Docker must be running, including mailpit):
#   powershell -ExecutionPolicy Bypass -File tests\test-write-post.ps1

. "$PSScriptRoot\lib.ps1"

$Mail = 'http://localhost:8025'

function Get-Last($lines) { return ($lines | Where-Object { "$_".Trim() } | Select-Object -Last 1) }

function Get-Html($session, $url) {
    if ($session) { return (curl.exe -s -b $session.Jar -c $session.Jar $url | Out-String) }
    return (curl.exe -s $url | Out-String)
}

function Get-Location($session, $url) {
    # GET without following redirects; returns the Location header.
    $hdr = Join-Path $Tmp 'h.txt'
    curl.exe -s -o NUL -D $hdr -b $session.Jar -c $session.Jar $url
    return [regex]::Match((Get-Content $hdr -Raw), '(?im)^Location:\s*(\S+)').Groups[1].Value
}

function Send-WriterRequest($session, $reason) {
    # Submit the Become-a-writer form like a browser (nonce from the page). Returns the redirect.
    $page = Get-Html $session "$Base/write/"
    $nonce = [regex]::Match($page, '(?s)value="portal_writer_request">\s*<input type="hidden" id="_wpnonce" name="_wpnonce" value="([^"]+)"').Groups[1].Value
    $hdr = Join-Path $Tmp 'h.txt'
    curl.exe -s -o NUL -D $hdr -b $session.Jar -c $session.Jar -e "$Base/write/" `
        --data-urlencode 'action=portal_writer_request' --data-urlencode "_wpnonce=$nonce" `
        --data-urlencode "reason=$reason" --data-urlencode 'sample_url=' --data-urlencode 'agree=1' "$Base/wp-admin/admin-post.php"
    return [regex]::Match((Get-Content $hdr -Raw), '(?im)^Location:\s*(\S+)').Groups[1].Value
}

function Send-Story($session, $title, $category) {
    # Submit the WP User Frontend story form exactly as its JavaScript does. Returns the post ID (0 if refused).
    $page = Get-Html $session "$Base/write/"
    $form = [regex]::Match($page, '(?s)<form class="wpuf-form-add.*?</form>').Value
    $nonce = [regex]::Match($form, 'name="_wpnonce" value="([^"]+)"').Groups[1].Value
    $formId = [regex]::Match($form, 'name="form_id" value="(\d+)"').Groups[1].Value
    $pageId = [regex]::Match($form, 'name="page_id" value="(\d+)"').Groups[1].Value
    $json = (curl.exe -s -b $session.Jar -c $session.Jar -e "$Base/write/" `
        --data-urlencode 'action=wpuf_submit_post' --data-urlencode "form_id=$formId" --data-urlencode "page_id=$pageId" `
        --data-urlencode "_wpnonce=$nonce" --data-urlencode '_wp_http_referer=/write/' `
        --data-urlencode "post_title=$title" --data-urlencode "post_content=<p>Story text for $title. Who, what, where and when.</p>" `
        --data-urlencode "category=$category" --data-urlencode 'tags=raipur, trucking' "$Base/wp-admin/admin-ajax.php" | Out-String)
    $id = Get-Last (Wp db query "SELECT ID FROM wp_posts WHERE post_type = 'post' AND post_title = '$title' ORDER BY ID DESC LIMIT 1" --skip-column-names)
    if ("$id" -match '^\d+$') { return [int]$id } else { return 0 }
}

function Get-MailCount($to, $subjectWord) {
    $r = (curl.exe -s "$Mail/api/v1/search?query=to:$to" | Out-String) | ConvertFrom-Json
    return @($r.messages | Where-Object { $_.Subject -match $subjectWord }).Count
}

$tag = 'T11-' + [guid]::NewGuid().ToString('N').Substring(0, 6)
$extra = @()

try {
    curl.exe -s -o NUL -X DELETE "$Mail/api/v1/messages"
    Write-Host '== Setup'
    $cat = Get-CategoryIds
    $users = New-TestUsers @('subscriber', 'administrator', 'author')
    $m = $users['subscriber'].Session
    $mId = $users['subscriber'].Id
    $admin = $users['administrator'].Session

    Write-Host '== Write page for each kind of visitor'
    $v = Get-Html $null "$Base/write/"
    $mp = Get-Html $m "$Base/write/"
    Check '1. Visitors are invited to sign in; members see Become a writer; neither gets the story form' ($v -match 'Write for us' -and $v -notmatch 'wpuf-form-add' -and $mp -match 'Become a writer' -and $mp -match 'name="reason"' -and $mp -notmatch 'wpuf-form-add') 'wrong box'

    Write-Host '== Become a writer'
    $short = Send-WriterRequest $m 'Too short.'
    $after = (Invoke-Rest $m GET '/portal/v1/me/writer-request').Data.status
    Check '2. A too-short request is refused with a message' ($short -match 'portal_writer_error=' -and $after -eq 'none') "redirect=$short"

    $loc = Send-WriterRequest $m "I drive trucks on NH-130 and want to write about road safety and freight prices. $tag"
    $st = Invoke-Rest $m GET '/portal/v1/me/writer-request'
    $again = Invoke-Rest $m POST '/portal/v1/me/writer-request' @{ reason = ('x' * 40); agree = $true }
    Start-Sleep -Seconds 1
    $adminMail = Get-MailCount ((Get-Last (Wp option get admin_email))) 'Writer request'
    Check '3. Valid request is stored as waiting, admins are e-mailed, a second request is refused' ($st.Data.status -eq 'pending' -and $st.Data.can_write -eq $false -and $again.Code -eq 409 -and $adminMail -ge 1) "status=$($st.Data.status) second=$($again.Code) admin mails=$adminMail"

    $list = Get-Html $admin "$Base/wp-admin/users.php?portal_writer_requests=pending"
    $approve = [regex]::Match($list, "href=""([^""]*action=portal_writer_decide[^""]*decision=approve[^""]*user=$mId[^""]*)""").Groups[1].Value -replace '&amp;|&#038;', '&'
    $done = Get-Location $admin $approve
    $role = Get-Last (Wp user get $mId --field=roles)
    Start-Sleep -Seconds 1
    $userMail = Get-MailCount 'portal_test_subscriber@portal.test' 'can now write'
    Check '4. Admin sees the request under Users > Writer requests and approves: member becomes Contributor and is e-mailed' ($list -match "$tag" -and $done -match 'portal_writer_done=approve' -and $role -eq 'contributor' -and $userMail -ge 1) "role=$role mail=$userMail redirect=$done"

    Write-Host '== Writing a story'
    $wp = Get-Html $m "$Base/write/"
    $sel = [regex]::Match($wp, "(?s)<select[^>]*name='category'.*?</select>").Value
    $offered = @([regex]::Matches($sel, 'value="(\d+)"') | ForEach-Object { [int]$_.Groups[1].Value })
    $bad = @($offered | Where-Object { $_ -in @($cat['news'], $cat['crime'], $cat['case-files']) })
    # jQuery must load in the page header here, or WP User Frontend's inline scripts fail (compat.php).
    $jqEarly = $wp.IndexOf('jquery.min.js') -ge 0 -and $wp.IndexOf('jquery.min.js') -lt $wp.IndexOf('</head>')
    Check '5. The new writer gets the story form (scripts load in time); sections never include News, Crime or Case Files' ($wp -match 'wpuf-form-add' -and $jqEarly -and $offered.Count -ge 4 -and $bad.Count -eq 0 -and $offered -contains $cat['community-blogs']) "offered=$($offered -join ',') jquery in head=$jqEarly"

    $sid = Send-Story $m "$tag contributor story" $cat['logistics']
    $status = Get-Last (Wp post get $sid --field=post_status)
    $cats = (Wp post term list $sid category --field=slug) -join ','
    $tags = (Wp post term list $sid post_tag --field=slug) -join ','
    $author = Get-Last (Wp post get $sid --field=post_author)
    Check '6. DONE RULE: a Contributor''s story lands in Pending review, in the chosen section, with tags' ($sid -gt 0 -and $status -eq 'pending' -and $cats -eq 'logistics' -and $tags -match 'raipur' -and [int]$author -eq $mId) "id=$sid status=$status cats=$cats tags=$tags"

    $fid = Send-Story $m "$tag forged news" $cat['news']
    $fstatus = Get-Last (Wp post get $fid --field=post_status)
    $fcats = (Wp post term list $fid category --field=slug) -join ','
    Check '7. A forged request for News is not obeyed: story stays out of News and in Pending' ($fid -eq 0 -or ($fcats -notmatch 'news' -and $fstatus -eq 'pending')) "id=$fid status=$fstatus cats=$fcats"

    $aid = Send-Story $users['author'].Session "$tag author story" $cat['region']
    $astatus = Get-Last (Wp post get $aid --field=post_status)
    Check '8. A trusted writer (Author) publishes directly from the same form' ($aid -gt 0 -and $astatus -eq 'publish') "id=$aid status=$astatus"

    Write-Host '== Turning a request down'
    $login2 = 'portal_test_t11member2'
    Wp user delete $login2 --yes 2>$null | Out-Null
    $pw2 = New-Password
    $m2Id = [int](Get-Last (Wp user create $login2 "$login2@portal.test" --role=subscriber "--user_pass=$pw2" --porcelain))
    $extra += $m2Id
    $m2 = Connect-User $login2 $pw2
    Send-WriterRequest $m2 "I would like to write reviews of local restaurants in Bilaspur and Raipur. $tag" | Out-Null
    $list = Get-Html $admin "$Base/wp-admin/users.php?portal_writer_requests=pending"
    $reject = [regex]::Match($list, "href=""([^""]*action=portal_writer_decide[^""]*decision=reject[^""]*user=$m2Id[^""]*)""").Groups[1].Value -replace '&amp;|&#038;', '&'
    Get-Location $admin $reject | Out-Null
    $r2 = Invoke-Rest $m2 GET '/portal/v1/me/writer-request'
    $role2 = Get-Last (Wp user get $m2Id --field=roles)
    $retry = Invoke-Rest $m2 POST '/portal/v1/me/writer-request' @{ reason = ('I really want to write about food and culture.'); agree = $true }
    Check '9. Turned down: stays a Member, status shows turned down, cannot ask again for 7 days' ($r2.Data.status -eq 'rejected' -and $role2 -eq 'subscriber' -and $retry.Code -eq 429) "status=$($r2.Data.status) role=$role2 retry=$($retry.Code)"

    $forge = Get-Location $m2 ("$Base/wp-admin/admin-post.php?action=portal_writer_decide&decision=approve&user=$m2Id&_wpnonce=abc")
    $role3 = Get-Last (Wp user get $m2Id --field=roles)
    $noAgree = Invoke-Rest $users['subscriber'].Session POST '/portal/v1/me/writer-request' @{ reason = ('y' * 40); agree = $false }
    Check '10. A member cannot approve themselves (forged link refused); writers cannot request again' ($role3 -eq 'subscriber' -and $noAgree.Code -eq 400) "role after forged link=$role3 writer request=$($noAgree.Code)"

    $myPosts = Get-Html $m "$Base/account/?section=post"
    Check '11. The writer sees their pending story under My Account > Posts' ($myPosts -match "$tag contributor story") 'not listed'
}
finally {
    foreach ($id in $extra) {
        foreach ($status in 'any', 'trash') {
            $ids = (Wp post list --post_type=any "--post_status=$status" "--author=$id" --format=ids) -join ' '
            if ($ids.Trim()) { Wp post delete @($ids.Trim() -split ' ') --force | Out-Null }
        }
        Wp user delete $id --yes | Out-Null
    }
    curl.exe -s -o NUL -X DELETE "$Mail/api/v1/messages"
    Complete-Test
}
if ($script:Fail -gt 0) { exit 1 }
