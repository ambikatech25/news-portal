# Task 12 test: likes, bookmarks, following writers and tags, Following feed, comments
# (members only, first comment approved by hand, spam filter), Report on comments.
#
# Run from the project folder (Docker must be running):
#   powershell -ExecutionPolicy Bypass -File tests\test-engagement.ps1

. "$PSScriptRoot\lib.ps1"

function Get-Last($lines) { return ($lines | Where-Object { "$_".Trim() } | Select-Object -Last 1) }
function Get-Html($session, $url) {
    if ($session) { return (curl.exe -s -b $session.Jar -c $session.Jar $url | Out-String) }
    return (curl.exe -s $url | Out-String)
}
function Send-Rest($session, $method, $path) {
    $raw = (curl.exe -s -X $method -b $session.Jar -H "X-WP-Nonce: $($session.Nonce)" -w "`n%{http_code}" "$Base/wp-json$path" | Out-String).TrimEnd()
    $data = $null; try { $data = $raw.Substring(0, $raw.LastIndexOf("`n")) | ConvertFrom-Json } catch { }
    return @{ Code = [int]($raw.Substring($raw.LastIndexOf("`n") + 1)); Data = $data }
}
function Send-Comment($session, $postUrl, $postId, $text, [switch]$Bot, $wait = 6) {
    # Fill the comment form like a browser: Antispam Bee hides the real box under a secret
    # name and flags comments sent faster than a person can type, so read the page and wait.
    $page = Get-Html $session $postUrl
    $form = [regex]::Match($page, '(?s)<form[^>]*id="commentform".*?</form>').Value
    $secret = [regex]::Match($form, '<textarea[^>]*name="([^"]+)"[^>]*>').Groups[1].Value
    $fields = @('comment_post_ID=' + $postId, 'comment_parent=0')
    foreach ($m in [regex]::Matches($form, '<input[^>]*type="hidden"[^>]*>')) {
        $n = [regex]::Match($m.Value, 'name="([^"]+)"').Groups[1].Value
        $v = [regex]::Match($m.Value, 'value="([^"]*)"').Groups[1].Value
        if ($n -and $n -notin @('comment_post_ID', 'comment_parent')) { $fields += "$n=$v" }
    }
    Start-Sleep -Seconds $wait
    $curlArgs = @('-s', '-o', 'NUL', '-b', $session.Jar, '-c', $session.Jar)
    foreach ($f in $fields) { $curlArgs += @('--data-urlencode', $f) }
    if ($Bot) { $curlArgs += @('--data-urlencode', "comment=$text") }        # bots fill the visible-to-bots field
    else { $curlArgs += @('--data-urlencode', "$secret=$text") }
    curl.exe @curlArgs "$Base/wp-comments-post.php"
    $id = Get-Last (Wp db query "SELECT comment_ID FROM wp_comments WHERE comment_content = '$text' ORDER BY comment_ID DESC LIMIT 1" --skip-column-names)
    if ("$id" -match '^\d+$') { return [int]$id } else { return 0 }
}

$tag = 'T12-' + [guid]::NewGuid().ToString('N').Substring(0, 6)
$extra = @()

try {
    Write-Host '== Setup'
    $cat = Get-CategoryIds
    $users = New-TestUsers @('subscriber', 'author', 'editor')
    $m1 = $users['subscriber'].Session; $m1Id = $users['subscriber'].Id
    $authorId = $users['author'].Id
    $e = $users['editor'].Session
    $login2 = 'portal_test_t12member2'; Wp user delete $login2 --yes 2>$null | Out-Null
    $pw2 = New-Password
    $m2Id = [int](Get-Last (Wp user create $login2 "$login2@portal.test" --role=subscriber "--user_pass=$pw2" --porcelain)); $extra += $m2Id
    $m2 = Connect-User $login2 $pw2

    $t = Invoke-Rest $e POST '/wp/v2/tags' @{ name = "$tag topic" }
    $tagId = $t.Data.id
    $p = Invoke-Rest $users['author'].Session POST '/wp/v2/posts' @{ title = "$tag story"; status = 'publish'; content = 'Text.'; categories = @($cat['logistics']); tags = @($tagId) }
    $postId = $p.Data.id; $postUrl = $p.Data.link
    $draft = (Invoke-Rest $e POST '/wp/v2/posts' @{ title = "$tag draft"; status = 'draft' }).Data.id

    Write-Host '== Visitors'
    $v = Get-Html $null $postUrl
    $vc = (curl.exe -s -o NUL -w '%{http_code}' -X POST "$Base/wp-json/portal/v1/likes/$postId")
    Check '1. Visitors see Like / Save / Follow as sign-in links, cannot like (401) and must log in to comment' ($v -match 'portal-toggle--like" href="[^"]*wp-login' -and $v -match 'portal-toggle--bookmark" href="[^"]*wp-login' -and $v -match 'portal-toggle--follow-author" href="[^"]*wp-login' -and $vc -eq '401' -and $v -match 'logged in</a> to post a comment') "visitor like=$vc"

    Write-Host '== Likes'
    $a = Send-Rest $m1 POST "/portal/v1/likes/$postId"
    $b = Send-Rest $m1 POST "/portal/v1/likes/$postId"
    $c = Send-Rest $m2 POST "/portal/v1/likes/$postId"
    $api = Invoke-Rest $null GET "/wp/v2/posts/$postId"
    Check '2. Members like a story; liking twice counts once; two members = 2 likes (also in /wp/v2/posts)' ($a.Data.liked -eq $true -and $b.Data.likes -eq 1 -and $c.Data.likes -eq 2 -and $api.Data.portal_likes -eq 2) "after m1=$($b.Data.likes) after m2=$($c.Data.likes) api=$($api.Data.portal_likes)"

    $page1 = Get-Html $m1 $postUrl
    $btn = [regex]::Match($page1, '<button[^>]*portal-toggle--like[^>]*>.*?</button>').Value
    $un = Send-Rest $m1 DELETE "/portal/v1/likes/$postId"
    $d = Send-Rest $m1 POST "/portal/v1/likes/$draft"
    Check '3. The page shows the member''s like as pressed with the count; unlike works; drafts cannot be liked' ($btn -match 'aria-pressed="true"' -and $btn -match 'portal-toggle__count">2<' -and $un.Data.liked -eq $false -and $un.Data.likes -eq 1 -and $d.Code -eq 404) "button=$btn unlike=$($un.Data.likes) draft=$($d.Code)"

    Write-Host '== Bookmarks'
    Send-Rest $m1 POST "/portal/v1/bookmarks/$postId" | Out-Null
    $mine = Send-Rest $m1 GET '/portal/v1/me/bookmarks'
    $theirs = Send-Rest $m2 GET '/portal/v1/me/bookmarks'
    $saved = Get-Html $m1 "$Base/account/?section=portal-saved"
    Check '4. Save for later: listed for that member (API and My Account > Saved), private from others' ((@($mine.Data | ForEach-Object { $_.id }) -contains $postId) -and -not (@($theirs.Data | ForEach-Object { $_.id }) -contains $postId) -and $saved -match "$tag story") "mine=$(@($mine.Data).Count) theirs=$(@($theirs.Data).Count)"
    $rm = Send-Rest $m1 DELETE "/portal/v1/bookmarks/$postId"
    $mine = Send-Rest $m1 GET '/portal/v1/me/bookmarks'
    Check '5. Removing a bookmark takes it off the list' ($rm.Data.bookmarked -eq $false -and -not (@($mine.Data | ForEach-Object { $_.id }) -contains $postId)) "left=$(@($mine.Data).Count)"

    Write-Host '== Following'
    $fa = Send-Rest $m1 POST "/portal/v1/follows/author/$authorId"
    $self = Send-Rest $users['author'].Session POST "/portal/v1/follows/author/$authorId"
    $slug = Get-Last (Wp user get $authorId --field=user_nicename)
    $prof = Get-Html $m1 "$Base/writer/$slug/"
    $pbtn = [regex]::Match($prof, '<button[^>]*portal-toggle--follow-author[^>]*>').Value
    Check '6. Follow a writer: count 1, profile shows Following; writers cannot follow themselves' ($fa.Data.following -eq $true -and $fa.Data.followers -eq 1 -and $pbtn -match 'aria-pressed="true"' -and $prof -match "data-portal-count=""follow-author-$authorId"">1<" -and $self.Code -eq 400) "followers=$($fa.Data.followers) self=$($self.Code)"

    $ft = Send-Rest $m2 POST "/portal/v1/follows/tag/$tagId"
    $tagPage = Get-Html $m2 (Invoke-Rest $null GET "/wp/v2/tags/$tagId").Data.link
    $follows = Send-Rest $m2 GET '/portal/v1/me/follows'
    Check '7. Follow a tag from its page: button shows Following; listed in /me/follows' ($ft.Data.following -eq $true -and $tagPage -match 'portal-toggle--follow-tag"[^>]*aria-pressed="true"' -and (@($follows.Data.tags | ForEach-Object { $_.id }) -contains $tagId)) "tag follow=$($ft.Data.following)"

    $feed1 = Get-Html $m1 "$Base/account/?section=portal-following"
    $feed2 = Send-Rest $m2 GET '/portal/v1/me/feed'
    Check '8. Following feed: the writer''s and the tag''s new story appear (My Account > Following and API)' ($feed1 -match "$tag story" -and $feed1 -match 'portal-follow-chips' -and (@($feed2.Data | ForEach-Object { $_.id }) -contains $postId)) 'story missing from feed'

    Write-Host '== Comments'
    $c1 = Send-Comment $m1 $postUrl $postId "$tag first comment"
    $s1 = Get-Last (Wp comment get $c1 --field=comment_approved)
    Wp comment approve $c1 | Out-Null
    # WordPress refuses a second comment from the same person within 15 seconds (flood protection).
    $c2 = Send-Comment $m1 $postUrl $postId "$tag second comment" -wait 16
    $s2 = Get-Last (Wp comment get $c2 --field=comment_approved)
    Check '9. A member''s first comment waits for approval; after one is approved, the next appears at once' ($c1 -gt 0 -and $s1 -eq '0' -and $c2 -gt 0 -and $s2 -eq '1') "first=$s1 second=$s2"

    $bot = Send-Comment $m2 $postUrl $postId "$tag cheap pills http://spam.example" -Bot
    $sb = if ($bot) { Get-Last (Wp comment get $bot --field=comment_approved) } else { 'not saved' }
    Check '10. Spam filter: a bot-style comment (filling the trap field) is marked spam, never shown' ($sb -in @('spam', 'not saved', 'trash')) "status=$sb"

    $withC = Get-Html $null $postUrl
    $rep = Invoke-Rest $null POST '/portal/v1/reports' @{ object_type = 'comment'; object_id = $c2; reason = 'abuse' }
    Check '11. Every approved comment has a Report button, and comment reports are stored' ($withC -match "data-portal-report=""comment"" data-id=""$c2""" -and $rep.Code -eq 201) "report=$($rep.Code)"
}
finally {
    if ($postId) { Wp db query "DELETE FROM wp_portal_reports WHERE object_type = 'comment' AND object_id IN (SELECT comment_ID FROM wp_comments WHERE comment_post_ID = $postId)" | Out-Null }
    if ($tagId) { Wp term delete post_tag $tagId | Out-Null }
    foreach ($id in $extra) { Wp user delete $id --yes | Out-Null }
    Complete-Test
}
if ($script:Fail -gt 0) { exit 1 }
