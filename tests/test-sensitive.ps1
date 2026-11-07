# Task 14 test: Sensitive stories need two different editors (second approval) before publishing,
# on every route, with a clear message when publishing is blocked.
#
# Run from the project folder (Docker must be running):
#   powershell -ExecutionPolicy Bypass -File tests\test-sensitive.ps1

. "$PSScriptRoot\lib.ps1"

function Get-Last($lines) { return ($lines | Where-Object { "$_".Trim() } | Select-Object -Last 1) }
function Get-Status($id) { return Get-Last (Wp post get $id --field=post_status) }
function Get-Approver($id) { $v = Get-Last (Wp post meta get $id portal_second_approver 2>$null); if ("$v" -match '^\d+$') { return [int]$v } else { return 0 } }

$tag = 'T14-' + [guid]::NewGuid().ToString('N').Substring(0, 6)

try {
    Write-Host '== Setup: a writer, Editor A and Editor B (admin)'
    $cat = Get-CategoryIds
    $users = New-TestUsers @('contributor', 'editor', 'administrator')
    $w = $users['contributor'].Session
    $a = $users['editor'].Session; $aId = $users['editor'].Id
    $b = $users['administrator'].Session; $bId = $users['administrator'].Id

    Write-Host '== One editor alone cannot publish'
    $one = Invoke-Rest $a POST '/wp/v2/posts' @{ title = "$tag one-click"; status = 'publish'; categories = @($cat['crime']); meta = @{ portal_sensitive = $true } }
    Check '1. Ticking Sensitive and pressing Publish in one go is refused with a clear message' ($one.Code -eq 403 -and $one.Data.code -eq 'portal_sensitive_blocked' -and $one.Data.message -match 'Sensitive') "HTTP $($one.Code) $($one.Data.message)"

    $s = Invoke-Rest $w POST '/wp/v2/posts' @{ title = "$tag minor victim story"; content = 'Details.'; status = 'pending'; categories = @($cat['region']) }
    $id = $s.Data.id
    Invoke-Rest $a POST "/wp/v2/posts/$id" @{ meta = @{ portal_sensitive = $true } } | Out-Null
    $ap = Invoke-Rest $a POST "/portal/v1/review/$id" @{ decision = 'approve' }
    $pub = Invoke-Rest $a POST "/wp/v2/posts/$id" @{ status = 'publish' }
    Check '2. Approve & publish (Review panel) and Publish (editor) are both refused without a second approval' ($ap.Code -eq 409 -and $ap.Data.message -match 'second editor must give their approval' -and $pub.Code -eq 403 -and (Get-Status $id) -eq 'pending') "review=$($ap.Code) publish=$($pub.Code) status=$(Get-Status $id)"

    $typed = Invoke-Rest $a POST "/wp/v2/posts/$id" @{ meta = @{ portal_second_approver = $bId } }
    Check '3. An editor cannot type in a colleague as second approver' ($typed.Code -eq 403 -and (Get-Approver $id) -eq 0) "HTTP $($typed.Code) approver=$(Get-Approver $id)"

    $writer = Invoke-Rest $w POST "/portal/v1/review/$id/second-approval"
    Check '4. A writer cannot give a second approval' ($writer.Code -eq 403 -and (Get-Approver $id) -eq 0) "HTTP $($writer.Code)"

    Write-Host '== Second approval'
    $ga = Invoke-Rest $a POST "/portal/v1/review/$id/second-approval"
    $self = Invoke-Rest $a POST "/portal/v1/review/$id" @{ decision = 'approve' }
    Check '5. Editor A gives the second approval, but then cannot publish it alone either' ($ga.Code -eq 200 -and $ga.Data.sensitive.approver.id -eq $aId -and $self.Code -eq 409 -and $self.Data.message -match 'different editor must publish' -and (Get-Status $id) -eq 'pending') "give=$($ga.Code) self publish=$($self.Code)"

    $done = Invoke-Rest $b POST "/portal/v1/review/$id" @{ decision = 'approve' }
    Check '6. DONE RULE: Editor B (a different editor) can now publish' ($done.Code -eq 200 -and (Get-Status $id) -eq 'publish') "HTTP $($done.Code) status=$(Get-Status $id)"

    Write-Host '== Other routes and edge cases'
    $own = Invoke-Rest $b POST '/wp/v2/posts' @{ title = "$tag own story"; status = 'draft'; categories = @($cat['crime']); meta = @{ portal_sensitive = $true } }
    $ownApprove = Invoke-Rest $b POST "/portal/v1/review/$($own.Data.id)/second-approval"
    Check '7. An editor cannot be the second approver of a story they wrote' ($ownApprove.Code -eq 403 -and $ownApprove.Data.code -eq 'portal_second_own') "HTTP $($ownApprove.Code)"

    $s2 = Invoke-Rest $w POST '/wp/v2/posts' @{ title = "$tag second story"; content = 'More.'; status = 'pending'; categories = @($cat['region']) }
    $id2 = $s2.Data.id
    Wp post meta update $id2 portal_sensitive 1 | Out-Null
    $list = (curl.exe -s -b $a.Jar "$Base/wp-admin/edit.php?post_status=pending" | Out-String)
    $link = [regex]::Match($list, "href=""([^""]*action=portal_review_approve[^""]*post=$id2[^""]*)""").Groups[1].Value -replace '&amp;|&#038;', '&'
    $hdr = Join-Path $Tmp 'h.txt'
    curl.exe -s -o NUL -D $hdr -b $a.Jar $link
    $loc = [regex]::Match((Get-Content $hdr -Raw), '(?im)^Location:\s*(\S+)').Groups[1].Value
    Check '8. Posts list "Approve & publish" is refused too, and the reason is shown' ((Get-Status $id2) -eq 'pending' -and [uri]::UnescapeDataString($loc) -match 'second editor') "status=$(Get-Status $id2) redirect=$loc"

    Wp "--user=$aId" post update $id2 --post_status=publish | Out-Null
    Check '9. Any other route (here: command line as an editor) keeps the story in Pending' ((Get-Status $id2) -eq 'pending') "status=$(Get-Status $id2)"

    Invoke-Rest $b POST "/portal/v1/review/$id2/second-approval" | Out-Null
    $before = Get-Approver $id2
    Invoke-Rest $a POST "/wp/v2/posts/$id2" @{ meta = @{ portal_sensitive = $false } } | Out-Null
    $cleared = Get-Approver $id2
    Invoke-Rest $a POST "/wp/v2/posts/$id2" @{ meta = @{ portal_sensitive = $true } } | Out-Null
    $again = Invoke-Rest $a POST "/portal/v1/review/$id2" @{ decision = 'approve' }
    Check '10. Unticking Sensitive clears the approval: ticking it again needs a fresh second approval' ($before -eq $bId -and $cleared -eq 0 -and $again.Code -eq 409) "before=$before after untick=$cleared approve again=$($again.Code)"

    Invoke-Rest $b POST "/portal/v1/review/$id2/second-approval" | Out-Null
    $notMine = Invoke-Rest $a DELETE "/portal/v1/review/$id2/second-approval"
    $mine = Invoke-Rest $b DELETE "/portal/v1/review/$id2/second-approval"
    $state = Invoke-Rest $a GET "/portal/v1/review/$id2"
    Check '11. Only the approver (or an admin) can withdraw; the Review panel data shows the state' ($notMine.Code -eq 403 -and $mine.Code -eq 200 -and (Get-Approver $id2) -eq 0 -and $state.Data.sensitive.is -eq $true -and $state.Data.sensitive.can_publish -eq $false -and $state.Data.sensitive.can_approve -eq $true) "other=$($notMine.Code) own=$($mine.Code) can_publish=$($state.Data.sensitive.can_publish)"
}
finally {
    Complete-Test
}
if ($script:Fail -gt 0) { exit 1 }
