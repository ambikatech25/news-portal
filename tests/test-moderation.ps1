# Task 16 test: Reports screen, take down / restore / dismiss, suspending accounts, the Grievance
# form (Fluent Forms) and the audit log.
#
# Run from the project folder (Docker must be running, including mailpit):
#   powershell -ExecutionPolicy Bypass -File tests\test-moderation.ps1

. "$PSScriptRoot\lib.ps1"

$MailUrl = 'http://localhost:8025'

function Get-Last($lines) { return ($lines | Where-Object { "$_".Trim() } | Select-Object -Last 1) }
function Get-Html($session, $url) {
    if ($session) { return (curl.exe -s -b $session.Jar -c $session.Jar $url | Out-String) }
    return (curl.exe -s $url | Out-String)
}
function Get-MailCount($to, $subjectPart) {
    $out = Join-Path $Tmp 'mail.json'
    curl.exe -s -o $out "$MailUrl/api/v1/search?query=to:$to"
    $r = [IO.File]::ReadAllText($out, [Text.Encoding]::UTF8) | ConvertFrom-Json
    return @($r.messages | Where-Object { $_.Subject -like "*$subjectPart*" }).Count
}
function Get-Mail($to, $subjectPart) {
    $out = Join-Path $Tmp 'mail.json'
    curl.exe -s -o $out "$MailUrl/api/v1/search?query=to:$to"
    $r = [IO.File]::ReadAllText($out, [Text.Encoding]::UTF8) | ConvertFrom-Json
    $m = @($r.messages | Where-Object { $_.Subject -like "*$subjectPart*" }) | Select-Object -First 1
    if (-not $m) { return $null }
    curl.exe -s -o $out "$MailUrl/api/v1/message/$($m.ID)"
    return ([IO.File]::ReadAllText($out, [Text.Encoding]::UTF8) | ConvertFrom-Json)
}
function Get-Log($action, $id) {
    $q = "SELECT COUNT(*) FROM wp_portal_audit_log WHERE action = '$action'"
    if ($id) { $q += " AND object_id = $id" }
    return [int](Get-Last (Wp db query $q --skip-column-names))
}
function Send-Decision($session, $type, $id, $decision, $reason) {
    # Use the form on the Reports screen exactly like an editor's browser.
    $tab = if ($decision -eq 'restore') { 'taken_down' } else { 'open' }
    $page = Get-Html $session "$Base/wp-admin/admin.php?page=portal-reports&status=$tab"
    $nonce = [regex]::Match($page, "(?s)name=""object_id"" value=""$id"">\s*<input type=""hidden"" id=""_wpnonce"" name=""_wpnonce"" value=""([^""]+)""").Groups[1].Value
    $hdr = Join-Path $Tmp 'h.txt'
    curl.exe -s -o NUL -D $hdr -b $session.Jar -c $session.Jar -e "$Base/wp-admin/admin.php?page=portal-reports" `
        --data-urlencode 'action=portal_report_decide' --data-urlencode "object_type=$type" --data-urlencode "object_id=$id" `
        --data-urlencode "_wpnonce=$nonce" --data-urlencode "decision=$decision" --data-urlencode "reason=$reason" "$Base/wp-admin/admin-post.php"
    return [uri]::UnescapeDataString([regex]::Match((Get-Content $hdr -Raw), '(?im)^Location:\s*(\S+)').Groups[1].Value)
}

$tag = 'T16-' + [guid]::NewGuid().ToString('N').Substring(0, 6)
$extra = @()

try {
    curl.exe -s -o NUL -X DELETE "$MailUrl/api/v1/messages"
    Write-Host '== Setup'
    $cat = Get-CategoryIds
    $users = New-TestUsers @('editor', 'administrator', 'author')
    Reset-ReportLimit   # visitors may send 10 reports an hour; all test runs share one IP
    $e = $users['editor'].Session; $eId = $users['editor'].Id
    $admin = $users['administrator'].Session; $adminId = $users['administrator'].Id
    $memberLogin = 'portal_test_t16member'; Wp user delete $memberLogin --yes 2>$null | Out-Null
    $memberPw = New-Password
    $mId = [int](Get-Last (Wp user create $memberLogin "$memberLogin@portal.test" --role=subscriber "--user_pass=$memberPw" --porcelain)); $extra += $mId
    $m = Connect-User $memberLogin $memberPw

    $p = Invoke-Rest $users['author'].Session POST '/wp/v2/posts' @{ title = "$tag reported story"; content = 'Claims about a named person.'; status = 'publish'; categories = @($cat['region']) }
    $pid1 = $p.Data.id; $link1 = $p.Data.link

    Write-Host '== A report arrives'
    Invoke-Rest $null POST '/portal/v1/reports' @{ object_type = 'post'; object_id = $pid1; reason = 'false_info'; details = "This is not true, the person was not there. $tag" } | Out-Null
    Invoke-Rest $m POST '/portal/v1/reports' @{ object_type = 'post'; object_id = $pid1; reason = 'abuse' } | Out-Null
    $screen = Get-Html $e "$Base/wp-admin/admin.php?page=portal-reports"
    $row = [regex]::Match($screen, "(?s)<tr class=""portal-report-row"" data-type=""post"" data-id=""$pid1"">.*?</tr>").Value
    Check '1. Reports appear on the Reports screen, grouped (2 reports, both reasons, the reader''s note); menu shows a count' ($row -match "$tag reported story" -and $row -match '<strong>2</strong>' -and $row -match 'False or misleading' -and $row -match 'Hate, abuse' -and $row -match 'person was not there' -and $screen -match 'awaiting-mod') 'row missing or incomplete'
    Start-Sleep -Seconds 1
    Check '2. Editors are e-mailed about the first report on an item only (no flood)' ((Get-MailCount 'portal_test_editor@portal.test' "New report: $tag") -eq 1) "mails=$(Get-MailCount 'portal_test_editor@portal.test' "New report: $tag")"

    Write-Host '== Take down'
    $noReason = Send-Decision $e 'post' $pid1 'takedown' ''
    Check '3. A takedown without a reason is refused' ($noReason -match 'portal_error=' -and (Get-Last (Wp post get $pid1 --field=post_status)) -eq 'publish') "redirect=$noReason"

    $done = Send-Decision $e 'post' $pid1 'takedown' "Unverified claims about a private person. $tag"
    $status = Get-Last (Wp post get $pid1 --field=post_status)
    $public = curl.exe -s -o NUL -w '%{http_code}' $link1
    $closed = [int](Get-Last (Wp db query "SELECT COUNT(*) FROM wp_portal_reports WHERE object_id = $pid1 AND status = 'taken_down' AND resolution_note LIKE '%Unverified%'" --skip-column-names))
    Start-Sleep -Seconds 1
    $writerMail = Get-Mail 'portal_test_author@portal.test' 'taken down'
    Check '4. Take down: story hidden (status Taken down, public 404), its reports closed with the reason, writer e-mailed why' ($done -match 'portal_done=takedown' -and $status -eq 'portal_takedown' -and $public -eq '404' -and $closed -eq 2 -and $writerMail -and $writerMail.Text -match 'Unverified claims') "status=$status public=$public closed=$closed mail=$([bool]$writerMail)"

    $logRow = Get-Last (Wp db query "SELECT details FROM wp_portal_audit_log WHERE action = 'post_taken_down' AND object_id = $pid1" --skip-column-names)
    Check '5. DONE RULE: the report AND the takedown are both in the audit log (with the editor and the reason)' ((Get-Log 'report_received' $pid1) -eq 2 -and (Get-Log 'post_taken_down' $pid1) -eq 1 -and $logRow -match 'Unverified claims' -and $logRow -match '"reports":2') "reports logged=$(Get-Log 'report_received' $pid1) takedown logged=$(Get-Log 'post_taken_down' $pid1) details=$logRow"

    $rs = Send-Decision $e 'post' $pid1 'restore' ''
    Check '6. A taken-down story can be restored (published again, logged)' ($rs -match 'portal_done=restore' -and (Get-Last (Wp post get $pid1 --field=post_status)) -eq 'publish' -and (Get-Log 'post_restored' $pid1) -eq 1) "redirect=$rs"

    Write-Host '== Comments and dismissing'
    $cid = [int](Get-Last (Wp comment create "--comment_post_ID=$pid1" "--comment_content=Rude comment $tag" "--user_id=$mId" --comment_approved=1 --porcelain))
    Invoke-Rest $null POST '/portal/v1/reports' @{ object_type = 'comment'; object_id = $cid; reason = 'abuse' } | Out-Null
    Send-Decision $e 'comment' $cid 'takedown' 'Abusive language.' | Out-Null
    Invoke-Rest $null POST '/portal/v1/reports' @{ object_type = 'post'; object_id = $pid1; reason = 'spam' } | Out-Null
    $dis = Send-Decision $e 'post' $pid1 'dismiss' 'Not spam, it is a normal news story.'
    $dTab = Get-Html $e "$Base/wp-admin/admin.php?page=portal-reports&status=dismissed"
    Check '7. Comments can be taken down (trash); reports can be dismissed with a reason (story stays up); both logged' ((Get-Last (Wp comment get $cid --field=comment_approved)) -eq 'trash' -and (Get-Log 'comment_taken_down' $cid) -eq 1 -and $dis -match 'portal_done=dismiss' -and (Get-Log 'reports_dismissed' $pid1) -eq 1 -and $dTab -match 'Not spam, it is a normal' -and (Get-Last (Wp post get $pid1 --field=post_status)) -eq 'publish') "comment=$(Get-Last (Wp comment get $cid --field=comment_approved)) dismiss=$dis"

    Write-Host '== Suspending an account'
    $form = Get-Html $admin "$Base/wp-admin/admin.php?page=portal-suspend&user=$mId"
    $bn = [regex]::Match($form, 'name="_wpnonce" value="([^"]+)"').Groups[1].Value
    curl.exe -s -o NUL -b $admin.Jar -c $admin.Jar -e "$Base/wp-admin/" --data-urlencode 'action=portal_ban' --data-urlencode "user=$mId" --data-urlencode "_wpnonce=$bn" --data-urlencode 'reason=Repeated abusive comments.' "$Base/wp-admin/admin-post.php"
    $me = Invoke-Rest $m GET '/wp/v2/users/me'
    $jar2 = Join-Path $Tmp 'relogin.cookies'
    curl.exe -s -o NUL -c $jar2 -b $jar2 "$Base/wp-login.php"   # like a browser: load the page first (test cookie)
    $login = (curl.exe -s -c $jar2 -b $jar2 --data-urlencode "log=$memberLogin" --data-urlencode "pwd=$memberPw" --data-urlencode 'testcookie=1' "$Base/wp-login.php" | Out-String)
    $noCookie = -not ((Get-Content $jar2 -Raw) -match 'wordpress_logged_in')
    $adminBan = Get-Last (Wp eval "echo is_wp_error( portal_ban_user( $adminId, 'test reason', $adminId ) ) ? 'refused' : 'banned';")
    # 401 or 403: either way the old session no longer counts as logged in.
    Check '8. Suspend: the member is logged out at once, cannot log in again (clear message), admins cannot be suspended; logged' ($me.Code -in @(401, 403) -and $login -match 'has been suspended' -and $noCookie -and $adminBan -eq 'refused' -and (Get-Log 'user_banned' $mId) -eq 1) "session=$($me.Code) message=$($login -match 'has been suspended') no cookie=$noCookie admin=$adminBan"

    $users2 = Get-Html $admin "$Base/wp-admin/users.php"
    $ub = [regex]::Match($users2, "href=""([^""]*action=portal_unban[^""]*user=$mId[^""]*)""").Groups[1].Value -replace '&amp;|&#038;', '&'
    curl.exe -s -o NUL -b $admin.Jar $ub
    $again = $null; try { $again = Connect-User $memberLogin $memberPw } catch { }
    Check '9. Reinstate: the member can log in again; logged' ($ub -and $again -and (Get-Log 'user_unbanned' $mId) -eq 1) "link=$([bool]$ub) relogin=$([bool]$again)"

    Write-Host '== Grievance form'
    $gjar = Join-Path $Tmp 'visitor.cookies'
    $gpage = (curl.exe -s -c $gjar -b $gjar "$Base/grievance-officer/" | Out-String)
    $formId = Get-Last (Wp option get portal_grievance_form_id)
    $gnonce = [regex]::Match($gpage, "name=""_fluentform_$($formId)_fluentformnonce"" value=""([^""]+)""").Groups[1].Value
    $inner = "names%5Bfirst_name%5D=Sunita&names%5Blast_name%5D=Sahu&email=sunita.$tag%40portal.test&complaint_type=" + [uri]::EscapeDataString('My privacy: personal information or photo') + "&content_url=" + [uri]::EscapeDataString($link1) + "&details=" + [uri]::EscapeDataString("My photo was used without permission. $tag") + "&declaration=on&_fluentform_$($formId)_fluentformnonce=$gnonce&_wp_http_referer=%2Fgrievance-officer%2F"
    $resp = (curl.exe -s -c $gjar -b $gjar -e "$Base/grievance-officer/" --data-urlencode 'action=fluentform_submit' --data-urlencode "form_id=$formId" --data-urlencode "data=$inner" "$Base/wp-admin/admin-ajax.php" | Out-String)
    $entry = [int](Get-Last (Wp db query "SELECT id FROM wp_fluentform_submissions WHERE form_id = $formId AND response LIKE '%$tag%' ORDER BY id DESC LIMIT 1" --skip-column-names))
    Start-Sleep -Seconds 1
    $ack = Get-Mail "sunita.$tag@portal.test" "GRV-$entry"
    $officer = Get-MailCount (Get-Last (Wp option get admin_email)) "New grievance GRV-$entry"
    Check '10. Grievance form: entry stored, reference GRV-<n> shown and e-mailed to the complainant, Grievance Officer e-mailed, logged' ($entry -gt 0 -and $resp -match "GRV-$entry" -and $ack -and $ack.Text -match '15 days' -and $officer -ge 1 -and (Get-Log 'grievance_received' $entry) -eq 1) "entry=$entry shown=$($resp -match "GRV-$entry") ack=$([bool]$ack) officer=$officer"

    Write-Host '== Who can see what'
    Wp user set-role $mId contributor | Out-Null
    $logPage = Get-Html $admin "$Base/wp-admin/admin.php?page=portal-audit-log"
    $edLog = curl.exe -s -o NUL -w '%{http_code}' -b $e.Jar "$Base/wp-admin/admin.php?page=portal-audit-log"
    $wrRep = curl.exe -s -o NUL -w '%{http_code}' -b $users['author'].Session.Jar "$Base/wp-admin/admin.php?page=portal-reports"
    $csvUrl = [regex]::Match($logPage, 'href="([^"]*action=portal_audit_csv[^"]*)"').Groups[1].Value -replace '&amp;|&#038;', '&'
    $csv = (curl.exe -s -b $admin.Jar $csvUrl | Out-String)
    Check '11. Audit log: admins see it and can download CSV (role changes logged too); editors cannot open it; writers cannot open Reports' ($logPage -match 'Story taken down' -and $logPage -match 'Account suspended' -and $logPage -match 'Role changed' -and $edLog -eq '403' -and $wrRep -eq '403' -and $csv -match 'post_taken_down' -and $csv -match 'grievance_received') "editor log=$edLog writer reports=$wrRep csv=$($csv.Length) chars"
}
finally {
    foreach ($id in $extra) { Wp user delete $id --yes | Out-Null }
    curl.exe -s -o NUL -X DELETE "$MailUrl/api/v1/messages"
    Complete-Test
}
if ($script:Fail -gt 0) { exit 1 }
