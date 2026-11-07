# Task 13 test: review workflow - editors approve, request changes or reject (with a reason
# e-mailed to the writer); PublishPress tells editors when a story waits for review.
#
# Run from the project folder (Docker must be running, including mailpit):
#   powershell -ExecutionPolicy Bypass -File tests\test-review.ps1

. "$PSScriptRoot\lib.ps1"

$MailUrl = 'http://localhost:8025'

function Get-Last($lines) { return ($lines | Where-Object { "$_".Trim() } | Select-Object -Last 1) }
function Get-Mail($to, $subjectPart) {
    # PublishPress sends in the background (WordPress cron): run due jobs first, then look.
    Wp cron event run --due-now | Out-Null
    Start-Sleep -Seconds 1
    $out = Join-Path $Tmp 'mail.json'
    curl.exe -s -o $out "$MailUrl/api/v1/search?query=to:$to"
    $r = [IO.File]::ReadAllText($out, [Text.Encoding]::UTF8) | ConvertFrom-Json
    $m = @($r.messages | Where-Object { $_.Subject -like "*$subjectPart*" }) | Select-Object -First 1
    if (-not $m) { return $null }
    curl.exe -s -o $out "$MailUrl/api/v1/message/$($m.ID)"
    return ([IO.File]::ReadAllText($out, [Text.Encoding]::UTF8) | ConvertFrom-Json)
}
function Get-Status($id) { return Get-Last (Wp post get $id --field=post_status) }
function Get-PublicCode($id) { return (curl.exe -s -o NUL -w '%{http_code}' -L "$Base/?p=$id") }

$tag = 'T13-' + [guid]::NewGuid().ToString('N').Substring(0, 6)

try {
    curl.exe -s -o NUL -X DELETE "$MailUrl/api/v1/messages"
    Write-Host '== Setup'
    $cat = Get-CategoryIds
    $users = New-TestUsers @('contributor', 'editor', 'author')
    $w = $users['contributor'].Session; $wId = $users['contributor'].Id
    $e = $users['editor'].Session
    $writerMail = 'portal_test_contributor@portal.test'
    $editorMail = 'portal_test_editor@portal.test'

    Write-Host '== Submitting'
    $s = Invoke-Rest $w POST '/wp/v2/posts' @{ title = "$tag story one"; content = 'First version.'; status = 'pending'; categories = @($cat['logistics']) }
    $id = $s.Data.id
    $notice = Get-Mail $editorMail "$tag story one"
    Check '1. Writer submits: story waits for review and editors get a 'Waiting for review' e-mail with an edit link' ((Get-Status $id) -eq 'pending' -and $notice -and $notice.Subject -match 'Waiting for review' -and $notice.Text -match 'post.php\?post=') "status=$(Get-Status $id) mail=$([bool]$notice)"

    $own = Invoke-Rest $w POST "/portal/v1/review/$id" @{ decision = 'approve' }
    $auth = Invoke-Rest $users['author'].Session POST "/portal/v1/review/$id" @{ decision = 'approve' }
    Check '2. Only editors can review: the writer and an Author are refused (403)' ($own.Code -eq 403 -and $auth.Code -eq 403 -and (Get-Status $id) -eq 'pending') "writer=$($own.Code) author=$($auth.Code)"

    Write-Host '== Request changes'
    $noNote = Invoke-Rest $e POST "/portal/v1/review/$id" @{ decision = 'changes'; note = 'fix' }
    $ch = Invoke-Rest $e POST "/portal/v1/review/$id" @{ decision = 'changes'; note = "Please add the date of the event and a quote from the driver. $tag" }
    $mail = Get-Mail $writerMail 'Changes requested'
    Check '3. Request changes needs a reason (10+ characters); the story goes to Changes requested' ($noNote.Code -eq 400 -and $ch.Code -eq 200 -and (Get-Status $id) -eq 'portal_changes') "no note=$($noNote.Code) status=$(Get-Status $id)"
    $hist = Invoke-Rest $w GET "/portal/v1/review/$id"
    Check '4. The writer gets the reason by e-mail and can read it in the review history; the story is not public' ($mail -and $mail.Text -match "quote from the driver" -and $hist.Data.history[-1].note -match 'quote from the driver' -and (Get-PublicCode $id) -eq '404') "mail=$([bool]$mail) public=$(Get-PublicCode $id)"

    Write-Host '== Writer fixes and resubmits'
    curl.exe -s -o NUL -X DELETE "$MailUrl/api/v1/messages"
    $up = Invoke-Rest $w POST "/wp/v2/posts/$id" @{ content = 'Second version with the date and a quote.'; status = 'pending' }
    $again = Get-Mail $editorMail "$tag story one"
    Check '5. After the writer edits and resubmits, the story is back in review and editors are told again' ($up.Code -eq 200 -and (Get-Status $id) -eq 'pending' -and $again) "HTTP $($up.Code) status=$(Get-Status $id) mail=$([bool]$again)"

    Write-Host '== Approve'
    $ap = Invoke-Rest $e POST "/portal/v1/review/$id" @{ decision = 'approve' }
    $pub = Get-Mail $writerMail 'Published'
    Check '6. Editor approves: published, writer e-mailed with the link, history shows both decisions' ((Get-Status $id) -eq 'publish' -and $pub -and $ap.Data.history.Count -eq 2 -and (Get-PublicCode $id) -eq '200') "status=$(Get-Status $id) history=$($ap.Data.history.Count)"

    $late = Invoke-Rest $e POST "/portal/v1/review/$id" @{ decision = 'reject'; note = 'Too late to reject this one.' }
    Check '7. A story that is already published cannot be "reviewed" again (409)' ($late.Code -eq 409) "HTTP $($late.Code)"

    Write-Host '== Reject'
    $s2 = Invoke-Rest $w POST '/wp/v2/posts' @{ title = "$tag story two"; content = 'Copied text.'; status = 'pending'; categories = @($cat['region']) }
    $id2 = $s2.Data.id
    $rj = Invoke-Rest $e POST "/portal/v1/review/$id2" @{ decision = 'reject'; note = "This text is copied from another website. $tag" }
    $rmail = Get-Mail $writerMail 'Not published'
    $edit = Invoke-Rest $w POST "/wp/v2/posts/$id2" @{ content = 'Trying again'; status = 'pending' }
    Check '8. Reject: final status Rejected, writer gets the reason, cannot edit or resubmit it, not public' ($rj.Code -eq 200 -and (Get-Status $id2) -eq 'portal_rejected' -and $rmail -and $rmail.Text -match 'copied from another website' -and $edit.Code -eq 403 -and (Get-PublicCode $id2) -eq '404') "status=$(Get-Status $id2) mail=$([bool]$rmail) writer edit=$($edit.Code)"

    Write-Host '== wp-admin'
    $s3 = Invoke-Rest $w POST '/wp/v2/posts' @{ title = "$tag story three"; content = 'Good story.'; status = 'pending'; categories = @($cat['industry']) }
    $id3 = $s3.Data.id
    $list = (curl.exe -s -b $e.Jar "$Base/wp-admin/edit.php?post_status=pending" | Out-String)
    $link = [regex]::Match($list, "href=""([^""]*action=portal_review_approve[^""]*post=$id3[^""]*)""").Groups[1].Value -replace '&amp;|&#038;', '&'
    curl.exe -s -o NUL -b $e.Jar $link
    Check '9. Posts list: editors see "Approve & publish" on waiting stories and it works' ($link -and (Get-Status $id3) -eq 'publish') "link found=$([bool]$link) status=$(Get-Status $id3)"

    $changesList = (curl.exe -s -b $e.Jar "$Base/wp-admin/edit.php?post_status=portal_rejected" | Out-String)
    $editor = (curl.exe -s -b $e.Jar "$Base/wp-admin/post.php?post=$id2&action=edit" | Out-String)
    Check '10. wp-admin lists stories by the new statuses, and the block editor loads the Review panel' ($changesList -match "$tag story two" -and $editor -match 'review-panel\.js') 'list or panel missing'

    $mine = Invoke-Rest $w GET "/portal/v1/review/$id"
    $other = Invoke-Rest $users['author'].Session GET "/portal/v1/review/$id"
    Check '11. The review history is visible to the writer and editors only' ($mine.Code -eq 200 -and $other.Code -eq 403) "writer=$($mine.Code) other=$($other.Code)"
}
finally {
    curl.exe -s -o NUL -X DELETE "$MailUrl/api/v1/messages"
    Complete-Test
}
if ($script:Fail -gt 0) { exit 1 }
