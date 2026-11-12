# Task 17D test: reader news tips. A member sends a tip with a photo, an editor turns it into a
# published News story, and the member sees "Published" in My tips. Also: visitors must sign in,
# tips are never public, photos lose their GPS data, wrong input is refused, 5 tips a day,
# only editors open the queue, decline with a reason, e-mails, audit log, 90-day clean-up.
#
# Run from the project folder (Docker must be running):
#   powershell -ExecutionPolicy Bypass -File tests\test-tips.ps1

. "$PSScriptRoot\lib.ps1"
. "$PSScriptRoot\lib-photo.ps1"

$MailUrl = 'http://localhost:8025'
function Get-Last($lines) { return ($lines | Where-Object { "$_".Trim() } | Select-Object -Last 1) }
function Get-Html($session, $url) {
    if ($session) { return (curl.exe -s -b $session.Jar -c $session.Jar $url | Out-String) }
    return (curl.exe -s $url | Out-String)
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
function Get-PhotoInfo($id) { return ((Wp eval-file /scripts/test-photo-info.php $id) -join '') | ConvertFrom-Json }
function Get-Log($action, $id) { return [int](Get-Last (Wp db query "SELECT COUNT(*) FROM wp_portal_audit_log WHERE action = '$action' AND object_id = $id" --skip-column-names)) }
# Through get_post_meta: "wp post meta get" prints an error (not an empty value) for a deleted field.
function Get-TipMeta($id, $key) { return "$(Get-Last (Wp eval "echo (string) get_post_meta( $id, '_portal_tip_$key', true );"))".Trim() }

# Send a tip through the API (multipart, like the app). Extra curl arguments: photos etc.
function Send-ApiTip($session, $title, [string[]]$extra) {
    $curlArgs = @('-s', '-b', $session.Jar, '-H', "X-WP-Nonce: $($session.Nonce)", '-w', "`n%{http_code}",
        '-F', "title=$title", '-F', 'details=A long enough description of what happened near the market today.',
        '-F', 'where=Raipur', '-F', 'how=saw', '-F', 'consent=1') + $extra
    $raw = (curl.exe @curlArgs "$Base/wp-json/portal/v1/tips" | Out-String).TrimEnd()
    $data = $null; try { $data = $raw.Substring(0, $raw.LastIndexOf("`n")) | ConvertFrom-Json } catch { }
    return @{ Code = [int]($raw.Substring($raw.LastIndexOf("`n") + 1)); Data = $data }
}

# Press a button on the editors' tip screen. Returns where it redirected to.
function Invoke-TipAction($session, $tipId, $do, $reason) {
    $page = Get-Html $session "$Base/wp-admin/admin.php?page=portal-tip&tip=$tipId"
    $nonce = [regex]::Match($page, "(?s)name=""do"" value=""$do"">.*?name=""_wpnonce"" value=""([0-9a-f]+)""").Groups[1].Value
    $curlArgs = @('-s', '-o', 'NUL', '-w', '%{redirect_url}', '-b', $session.Jar, '-c', $session.Jar, '-e', "$Base/wp-admin/admin.php?page=portal-tip&tip=$tipId",
        '--data-urlencode', 'action=portal_tip_action', '--data-urlencode', "tip=$tipId", '--data-urlencode', "do=$do", '--data-urlencode', "_wpnonce=$nonce")
    if ($reason) { $curlArgs += @('--data-urlencode', "reason=$reason") }
    return (curl.exe @curlArgs "$Base/wp-admin/admin-post.php" | Out-String).Trim()
}

$tag = 'T17D-' + [guid]::NewGuid().ToString('N').Substring(0, 6)
$memberIds = @()

try {
    Write-Host '== Setup'
    $users = New-TestUsers @('subscriber', 'contributor', 'editor')
    $m = $users['subscriber'].Session; $mId = $users['subscriber'].Id; $mMail = "$($users['subscriber'].Login)@portal.test"
    $e = $users['editor'].Session; $eMail = "$($users['editor'].Login)@portal.test"
    $memberIds += $mId
    $login2 = 'portal_test_t17dmember2'; Wp user delete $login2 --yes 2>$null | Out-Null
    $pw2 = New-Password
    $m2Id = [int](Get-Last (Wp user create $login2 "$login2@portal.test" --role=subscriber "--user_pass=$pw2" --porcelain)); $memberIds += $m2Id
    $m2 = Connect-User $login2 $pw2
    $photo = Join-Path $Tmp 'tip.jpg'; New-GpsPhoto $photo 320 200 1

    Write-Host '== Visitors'
    $vis = Get-Html $null "$Base/send-news/"
    $homeHtml = Get-Html $null "$Base/"
    Check '1. Visitors see "Sign in to send news" (no form); the header has "Send news"; the page is kept out of Google' ($vis -match 'Sign in to send news' -and $vis -notmatch 'name="portal_tip_nonce"' -and $homeHtml -match 'portal-send-news-link"><a href="http://localhost:8080/send-news/"' -and $vis -match '<meta name=.robots.[^>]*noindex') 'visitor view'

    Write-Host '== A member sends a tip (form, with a GPS photo)'
    $form = Get-Html $m "$Base/send-news/"
    $nonce = [regex]::Match($form, 'name="portal_tip_nonce" value="([0-9a-f]+)"').Groups[1].Value
    $sendArgs = @('-s', '-o', 'NUL', '-w', '%{redirect_url}', '-b', $m.Jar, '-c', $m.Jar, '-e', "$Base/send-news/",
        '-F', 'action=portal_send_tip', '-F', "portal_tip_nonce=$nonce", '-F', "title=$tag Water pipeline burst near the market",
        '-F', 'details=The main water pipeline burst this morning and the road is flooded. Shops are closed.',
        '-F', 'where=Raipur', '-F', 'when=' + (Get-Date).ToString('yyyy-MM-dd'), '-F', 'how=saw', '-F', 'phone=98765 43210',
        '-F', 'credit=anonymous', '-F', 'consent=1', '-F', "photos[]=@$photo;type=image/jpeg")
    $to = (curl.exe @sendArgs "$Base/wp-admin/admin-post.php" | Out-String).Trim()
    $tipId = [int][regex]::Match($to, 'tip_sent=(\d+)').Groups[1].Value
    $status = Get-Last (Wp post get $tipId --field=post_status)
    $photoId = [int](Get-Last (Wp eval "echo (int) ( get_post_meta( $tipId, '_portal_tip_photos', true )[0] ?? 0 );"))
    $info = Get-PhotoInfo $photoId
    $fileName = Get-Last (Wp eval "echo basename( get_attached_file( $photoId ) );")
    Check '2. The tip is saved (private) and the member lands on My tips; the photo has no GPS data and a random file name' ($tipId -gt 0 -and $status -eq 'private' -and $to -match 'section=portal-tips' -and $photoId -gt 0 -and -not $info.gps -and $fileName -match '^tip-[a-z0-9]{12}\.jpg$') "to=$to status=$status photo=$photoId gps=$($info.gps) file=$fileName"

    $receipt = Get-Mail $mMail "TIP-$tipId"
    $alert = Get-Mail $eMail "New reader tip TIP-$tipId"
    Check '3. The member gets a receipt with the reference; editors get an alert without the phone number' ($receipt -and $receipt.Text -match "TIP-$tipId" -and $alert -and $alert.Text -match 'admin\.php\?page=portal-tip' -and $alert.Text -notmatch '98765') "receipt=$([bool]$receipt) alert=$([bool]$alert)"

    Write-Host '== Never public'
    $byId = curl.exe -s -o NUL -w '%{http_code}' "$Base/?p=$tipId"
    $byType = curl.exe -s -o NUL -w '%{http_code}' "$Base/wp-json/wp/v2/portal_tip"
    $media = curl.exe -s -o NUL -w '%{http_code}' "$Base/wp-json/wp/v2/media/$photoId"
    $search = Get-Html $null "$Base/?s=$tag"
    Check '4. Tips are never public: no page, no API listing, photo hidden from the media API, not in search' ($byId -eq '404' -and $byType -eq '404' -and $media -in @('401', '403', '404') -and $search -notmatch "$tag Water") "page=$byId api=$byType media=$media"

    $mine = Get-Html $m "$Base/account/?section=portal-tips&tip_sent=$tipId"
    $other = Get-Html $m2 "$Base/account/?section=portal-tips"
    $api = Invoke-Rest $m GET '/portal/v1/me/tips'
    Check '5. My tips shows the tip as "Received" with a thank-you note; another member cannot see it; the app list has no phone' ($mine -match "$tag Water" -and $mine -match 'portal-tip-state--new">Received<' -and $mine -match "TIP-$tipId" -and $other -notmatch "$tag Water" -and $api.Data[0].id -eq $tipId -and -not ($api.Data[0].PSObject.Properties.Name -contains 'phone')) 'my tips'

    Write-Host '== Wrong input and limits'
    $noConsent = Send-ApiTip $m "$tag no consent" @('-F', 'consent=')
    $badPhone = Send-ApiTip $m "$tag bad phone" @('-F', 'phone=12345')
    $video = Join-Path $Tmp 'clip.mp4'; [IO.File]::WriteAllBytes($video, [byte[]](0, 0, 0, 24, 102, 116, 121, 112, 109, 112, 52, 50) + (New-Object byte[] 2000))
    $badFile = Send-ApiTip $m "$tag video file" @('-F', "photos[]=@$video;type=video/mp4")
    Check '6. Refused with a clear message: no "true to my knowledge" tick, a wrong phone number, a video file' ($noConsent.Code -eq 400 -and $badPhone.Code -eq 400 -and $badPhone.Data.message -match '10-digit' -and $badFile.Code -eq 400) "consent=$($noConsent.Code) phone=$($badPhone.Code) video=$($badFile.Code)"

    $tip2 = Send-ApiTip $m "$tag Second tip to decline" @('-F', 'phone=9123456780', '-F', "photos[]=@$photo;type=image/jpeg")
    $tip2Id = [int]$tip2.Data.id
    foreach ($n in 3..5) { Send-ApiTip $m "$tag Filler tip number $n" @() | Out-Null }
    $sixth = Send-ApiTip $m "$tag Sixth tip today" @()
    Check '7. The app can send tips (201, TIP reference); the 6th tip in a day is refused (429)' ($tip2.Code -eq 201 -and $tip2.Data.reference -eq "TIP-$tip2Id" -and $sixth.Code -eq 429) "second=$($tip2.Code) sixth=$($sixth.Code)"

    Write-Host '== Editors'
    $screen = Get-Html $e "$Base/wp-admin/admin.php?page=portal-tip&tip=$tipId"
    $writer = Get-Html $users['contributor'].Session "$Base/wp-admin/admin.php?page=portal-tip&tip=$tipId"
    $list = Get-Html $e "$Base/wp-admin/edit.php?post_type=portal_tip"
    Check '8. Editors open the tip (details, phone, photo); writers cannot; the menu shows how many tips are new' ($screen -match "$tag Water" -and $screen -match '\+919876543210' -and $screen -match 'wp-image-|attachment-thumbnail' -and $writer -notmatch '9876543210' -and $list -match 'Reader tips <span class="awaiting-mod"><span class="pending-count">\d+') 'editor screen'

    Invoke-TipAction $e $tipId 'checking' | Out-Null
    $checking = Get-Html $m "$Base/account/?section=portal-tips"
    Check '9. "I am checking it": the member sees "Being checked"; written in the audit log' ((Get-TipMeta $tipId 'state') -eq 'checking' -and $checking -match 'portal-tip-state--checking">Being checked<' -and (Get-Log 'tip_checking' $tipId) -eq 1) 'checking'

    $to = Invoke-TipAction $e $tipId 'writeup'
    $storyId = [int][regex]::Match($to, 'post=(\d+)').Groups[1].Value
    $story = Invoke-Rest $e GET "/wp/v2/posts/$($storyId)?context=edit"
    $newsId = [int](Get-Last (Wp term list category --slug=news --field=term_id))
    Check '10. "Write it up" makes a DRAFT News story by the editor, with the reader''s text and photo' ($storyId -gt 0 -and $story.Data.status -eq 'draft' -and $story.Data.author -eq $users['editor'].Id -and ($story.Data.categories -contains $newsId) -and $story.Data.content.raw -match 'road is flooded' -and $story.Data.featured_media -eq $photoId) "story=$storyId status=$($story.Data.status)"

    Invoke-Rest $e POST "/wp/v2/posts/$storyId" @{ status = 'publish' } | Out-Null
    $published = Get-Html $m "$Base/account/?section=portal-tips"
    $link = (Invoke-Rest $null GET "/wp/v2/posts/$storyId").Data.link
    $done = Get-Mail $mMail "TIP-$tipId is published"
    $storyHtml = Get-Html $null $link
    Check '11. Publishing the story: the member sees "Published" with a link, gets an e-mail, and the story says "Based on a reader report."' ((Get-TipMeta $tipId 'state') -eq 'published' -and $published -match 'portal-tip-state--published">Published<' -and $published -match [regex]::Escape($link) -and $done -and $done.Text -match [regex]::Escape($link) -and $storyHtml -match 'portal-tip-credit">Based on a reader report\.<' -and (Get-Log 'tip_published' $tipId) -eq 1) "state=$(Get-TipMeta $tipId 'state') mail=$([bool]$done)"

    $short = Invoke-TipAction $e $tip2Id 'decline' 'no'
    $stillNew = Get-TipMeta $tip2Id 'state'
    Invoke-TipAction $e $tip2Id 'decline' 'We could not confirm this with the police or another source.' | Out-Null
    $declined = Get-Html $m "$Base/account/?section=portal-tips"
    $why = Get-Mail $mMail "About your news tip TIP-$tip2Id"
    Check '12. Decline needs a real reason; then the member sees "Not published" with the reason, by e-mail too' ($short -match 'portal_msg=' -and $stillNew -eq 'new' -and (Get-TipMeta $tip2Id 'state') -eq 'declined' -and $declined -match 'portal-tip-state--declined">Not published<' -and $declined -match 'could not confirm' -and $why -and $why.Text -match 'could not confirm') "state=$(Get-TipMeta $tip2Id 'state') mail=$([bool]$why)"

    Write-Host '== Privacy clean-up after 90 days'
    $photo2 = [int](Get-Last (Wp eval "echo (int) ( get_post_meta( $tip2Id, '_portal_tip_photos', true )[0] ?? 0 );"))
    foreach ($id in $tipId, $tip2Id) { Wp post meta update $id _portal_tip_closed_at 1000 | Out-Null }
    Wp eval "do_action( 'portal_tips_cleanup' );" | Out-Null
    $phone1 = Get-TipMeta $tipId 'phone'; $phone2 = Get-TipMeta $tip2Id 'phone'
    $photo2Left = Get-Last (Wp eval "echo get_post( $photo2 ) ? 'yes' : 'no';")
    $photo1Left = Get-Last (Wp eval "echo get_post( $photoId ) ? 'yes' : 'no';")
    Check '13. 90 days after closing: phone numbers deleted, a declined tip''s photo deleted, the published story''s photo kept' (-not $phone1 -and -not $phone2 -and $photo2 -gt 0 -and $photo2Left -eq 'no' -and $photo1Left -eq 'yes') "phone1=$phone1 phone2=$phone2 photo2=$photo2Left photo1=$photo1Left"
}
finally {
    foreach ($id in $memberIds) {
        $tips = (Wp post list --post_type=portal_tip --post_status=any "--author=$id" --format=ids) -join ' '
        if ($tips.Trim()) { Wp post delete @($tips.Trim() -split ' ') --force | Out-Null }
    }
    if ($m2Id) { Wp user delete $m2Id --yes | Out-Null }
    Complete-Test
}
if ($script:Fail -gt 0) { exit 1 }
