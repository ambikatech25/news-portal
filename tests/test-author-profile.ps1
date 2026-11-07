# Task 9 test: writer profile pages - safe profile URL, who has a page, photo upload,
# follower count, stories list with pages, and the profile API.
#
# Run from the project folder (Docker must be running):
#   powershell -ExecutionPolicy Bypass -File tests\test-author-profile.ps1

. "$PSScriptRoot\lib.ps1"

function Get-Page($url) {
    $raw = (curl.exe -s -w "`n%{http_code}" $url | Out-String).TrimEnd()
    return @{
        Code = [int]($raw.Substring($raw.LastIndexOf("`n") + 1))
        Html = $raw.Substring(0, $raw.LastIndexOf("`n"))
    }
}

function Get-Last($lines) {
    # Last non-empty line of WP-CLI output.
    return ($lines | Where-Object { "$_".Trim() } | Select-Object -Last 1)
}

function Send-Avatar($session, $file, $type) {
    # Multipart upload to /portal/v1/me/avatar, like the app would. Returns @{Code; Data}.
    $raw = (curl.exe -s -X POST -b $session.Jar -H "X-WP-Nonce: $($session.Nonce)" `
        -F "file=@$file;type=$type" -w "`n%{http_code}" "$Base/wp-json/portal/v1/me/avatar" | Out-String).TrimEnd()
    $data = $null
    try { $data = $raw.Substring(0, $raw.LastIndexOf("`n")) | ConvertFrom-Json } catch { }
    return @{ Code = [int]($raw.Substring($raw.LastIndexOf("`n") + 1)); Data = $data }
}

$tag = 'T9' + [guid]::NewGuid().ToString('N').Substring(0, 6)

try {
    Write-Host '== Setup: temporary users with public names'
    $users = New-TestUsers @('author', 'editor', 'subscriber')
    $authorId = $users['author'].Id
    $editorId = $users['editor'].Id
    $subId = $users['subscriber'].Id
    Wp user update $authorId "--display_name=Writer $tag" "--description=Covers trucking on NH-130. $tag bio." | Out-Null
    Wp user update $editorId "--display_name=Desk $tag" | Out-Null
    $slug = Get-Last (Wp user get $authorId --field=user_nicename)

    Write-Host '== Profile URL'
    $page = Get-Page "$Base/writer/$slug/"
    $h = $page.Html
    $errors = [regex]::Matches($h, '(Fatal error|Warning|Notice|Deprecated)</b>:').Count
    Check '1. Public profile /writer/<name>/ loads with photo, name, bio and Community writer label' ($slug -eq "writer-$($tag.ToLower())" -and $page.Code -eq 200 -and $errors -eq 0 -and $h -match "<h1 class=""portal-profile__name"">Writer $tag</h1>" -and $h -match "$tag bio" -and $h -match 'Community writer' -and $h -match 'portal-profile__photo') "slug=$slug HTTP $($page.Code) PHP messages=$errors"

    $byLogin1 = (Get-Page "$Base/author/portal_test_author/").Code
    $byLogin2 = (Get-Page "$Base/writer/portal_test_author/").Code
    $leak = (Get-Page "$Base/writer/$slug/").Html -match 'portal_test_author'
    Check '2. The login name is not in the URL, does not open the profile, and is not on the page' ($byLogin1 -eq 404 -and $byLogin2 -eq 404 -and -not $leak) "/author/login=$byLogin1 /writer/login=$byLogin2 login on page=$leak"

    $subSlug = Get-Last (Wp user get $subId --field=user_nicename)
    $subPage = (Get-Page "$Base/writer/$subSlug/").Code
    $subApi = (Invoke-Rest $null GET "/portal/v1/authors/$subId").Code
    Check '3. A plain member (Subscriber) has no public profile: page 404, API 404' ($subPage -eq 404 -and $subApi -eq 404) "page=$subPage api=$subApi"

    Write-Host '== Renaming'
    $loginOnly = Get-Last (Wp user create "portal_test_t9rename" "t9rename@portal.test" --role=author --porcelain)
    $before = Get-Last (Wp user get $loginOnly --field=user_nicename)
    Wp user update $loginOnly "--display_name=Renamed $tag" | Out-Null
    $after = Get-Last (Wp user get $loginOnly --field=user_nicename)
    Wp user update $authorId "--display_name=Someone Else $tag" | Out-Null
    $kept = Get-Last (Wp user get $authorId --field=user_nicename)
    Wp user update $authorId "--display_name=Writer $tag" | Out-Null
    Wp user delete $loginOnly --yes | Out-Null
    Check '4. Name same as login -> writer-<ID>; setting a real name fixes it; a good address never changes on rename' ($before -eq "writer-$loginOnly" -and $after -eq "renamed-$($tag.ToLower())" -and $kept -eq $slug) "before=$before after=$after kept=$kept"

    Write-Host '== Followers'
    $f1 = Get-Last (Wp eval "echo (int) portal_follow_author($subId, $authorId);")
    $f2 = Get-Last (Wp eval "echo (int) portal_follow_author($subId, $authorId);")
    $f3 = Get-Last (Wp eval "echo (int) portal_follow_author($editorId, $authorId);")
    $self = Get-Last (Wp eval "echo (int) portal_follow_author($authorId, $authorId);")
    $member = Get-Last (Wp eval "echo (int) portal_follow_author($authorId, $subId);")
    $api = Invoke-Rest $null GET "/portal/v1/authors/$authorId"
    $profileHtml = (Get-Page "$Base/writer/$slug/").Html
    Check '5. Followers counted once each; following yourself or a member is refused; count on page and API' ($f1 -eq '1' -and $f2 -eq '1' -and $f3 -eq '1' -and $self -eq '0' -and $member -eq '0' -and $api.Data.followers -eq 2 -and $profileHtml -match '<strong[^>]*>2</strong>\s*followers') "self=$self member=$member api=$($api.Data.followers)"

    $fan = Get-Last (Wp user create "portal_test_t9fan" "t9fan@portal.test" --role=subscriber --porcelain)
    Wp eval "portal_follow_author($fan, $authorId);" | Out-Null
    $with = (Invoke-Rest $null GET "/portal/v1/authors/$authorId").Data.followers
    Wp user delete $fan --yes | Out-Null
    $without = (Invoke-Rest $null GET "/portal/v1/authors/$authorId").Data.followers
    Check '6. Deleting a user removes their follows' ($with -eq 3 -and $without -eq 2) "with fan=$with after delete=$without"

    Write-Host '== Stories list and API'
    Wp post generate --count=13 "--post_author=$authorId" --post_status=publish | Out-Null
    $p1 = Get-Page "$Base/writer/$slug/"
    $p2 = Get-Page "$Base/writer/$slug/page/2/"
    $n1 = [regex]::Matches($p1.Html, 'portal-card--tile').Count
    $n2 = [regex]::Matches($p2.Html, 'portal-card--tile').Count
    Check '7. Stories list: 12 per page, page 2 has the 13th' ($n1 -eq 12 -and $p2.Code -eq 200 -and $n2 -eq 1) "page1=$n1 page2=$n2 (HTTP $($p2.Code))"

    $api = Invoke-Rest $null GET "/portal/v1/authors/$authorId"
    $desk = Invoke-Rest $null GET "/portal/v1/authors/$editorId"
    Check '8. API /portal/v1/authors/<id>: name, link, stories, News desk vs community' ($api.Code -eq 200 -and $api.Data.name -eq "Writer $tag" -and $api.Data.link -eq "$Base/writer/$slug/" -and $api.Data.posts -eq 13 -and $api.Data.desk -eq 'community' -and $desk.Data.desk -eq 'news') "HTTP $($api.Code) link=$($api.Data.link) posts=$($api.Data.posts) desk=$($api.Data.desk)/$($desk.Data.desk)"

    Write-Host '== Profile photo'
    Add-Type -AssemblyName System.Drawing
    $png = Join-Path $Tmp 'face.png'
    $bmp = New-Object System.Drawing.Bitmap 300, 300
    $g = [System.Drawing.Graphics]::FromImage($bmp); $g.Clear([System.Drawing.Color]::SteelBlue); $g.Dispose()
    $bmp.Save($png, [System.Drawing.Imaging.ImageFormat]::Png); $bmp.Dispose()
    $fake = Join-Path $Tmp 'fake.png'
    [IO.File]::WriteAllText($fake, '<?php echo "not a photo"; ?>')
    $big = Join-Path $Tmp 'big.png'
    [IO.File]::WriteAllBytes($big, (New-Object byte[] 2200000))

    $sub = $users['subscriber'].Session
    $bad = Send-Avatar $sub $fake 'image/png'
    $huge = Send-Avatar $sub $big 'image/png'
    $anon = (curl.exe -s -o NUL -w '%{http_code}' -X POST -F "file=@$png;type=image/png" "$Base/wp-json/portal/v1/me/avatar")
    Check '9. Photo upload refuses a fake image (400), a file over 2 MB (400) and logged-out visitors (401)' ($bad.Code -eq 400 -and $bad.Data.code -eq 'portal_avatar_type' -and $huge.Code -eq 400 -and $huge.Data.code -eq 'portal_avatar_size' -and $anon -eq '401') "fake=$($bad.Code)/$($bad.Data.code) big=$($huge.Code)/$($huge.Data.code) anon=$anon"

    $a = $users['author'].Session
    $up = Send-Avatar $a $png 'image/png'
    $img = [regex]::Match((Get-Page "$Base/writer/$slug/").Html, '(?s)<div class="portal-profile__photo">.*?<img[^>]+src=''([^'']+)''').Groups[1].Value
    Check '10. Writer uploads a photo: profile page and API show it from our site, not Gravatar' ($up.Code -eq 200 -and $up.Data.avatar -like "$Base/wp-content/uploads/*" -and $img -like "$Base/wp-content/uploads/*") "HTTP $($up.Code) api=$($up.Data.avatar) page=$img"

    $photoId = Get-Last (Wp user meta get $authorId portal_avatar_id)
    $del = (curl.exe -s -o NUL -w '%{http_code}' -X DELETE -b $a.Jar -H "X-WP-Nonce: $($a.Nonce)" "$Base/wp-json/portal/v1/me/avatar")
    $gone = Get-Last (Wp post list --post_type=attachment "--post__in=$photoId" --format=count)
    $after = (Invoke-Rest $null GET "/portal/v1/authors/$authorId").Data.avatar
    Check '11. Removing the photo deletes the file and goes back to Gravatar' ($del -eq '200' -and $gone -eq '0' -and $after -like '*gravatar.com*') "HTTP $del attachments left=$gone avatar=$after"
}
finally {
    Complete-Test
}
if ($script:Fail -gt 0) { exit 1 }
