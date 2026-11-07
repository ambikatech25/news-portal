# Task 6 test: portal_* custom fields in the REST API.
#
# Run from the project folder (Docker must be running):
#   powershell -ExecutionPolicy Bypass -File tests\test-custom-fields.ps1

. "$PSScriptRoot\lib.ps1"

try {
    Write-Host '== Setup: category IDs and temporary test users'
    $cat = Get-CategoryIds
    $users = New-TestUsers @('contributor', 'author', 'editor')
    $c = $users['contributor'].Session
    $a = $users['author'].Session
    $e = $users['editor'].Session

    Write-Host '== Editor sets every field'
    # The second approver is not in this list: since Task 14 it is set only by the second
    # editor's own "Give second approval" action, never typed in (see check 6).
    $meta = @{
        portal_verified        = $true
        portal_fir_number      = '123/2026'
        portal_police_station  = 'Civil Lines, Raipur'
        portal_sensitive       = $true
        portal_is_premium      = $true
    }
    # Saved as a draft: a sensitive story cannot be published by one editor alone (Task 14).
    $r = Invoke-Rest $e POST '/wp/v2/posts' @{ title = 'Verified crime story'; status = 'draft'; categories = @($cat['crime']); meta = $meta }
    $post = $r.Data.id
    $m = $r.Data.meta
    Check '1. Editor saves the editorial fields' ($r.Code -eq 201 -and $m.portal_verified -eq $true -and $m.portal_fir_number -eq '123/2026' -and $m.portal_police_station -eq 'Civil Lines, Raipur' -and $m.portal_sensitive -eq $true -and $m.portal_is_premium -eq $true) "HTTP $($r.Code) meta=$($m | ConvertTo-Json -Compress)"
    # The public checks below need a published post: publish the same fields without Sensitive.
    Wp post meta update $post portal_sensitive '' | Out-Null
    Wp post update $post --post_status=publish | Out-Null
    Wp post meta update $post portal_sensitive 1 | Out-Null

    $stored = (Wp post meta get $post portal_fir_number) -join ''
    Check '2. Values are really stored in the database' ($stored -eq '123/2026') "stored=$stored"

    Write-Host '== Public API (logged out)'
    $r = Invoke-Rest $null GET "/wp/v2/posts/$post"
    $m = $r.Data.meta
    $names = @($m.PSObject.Properties.Name)
    Check '3. Public sees Verified, FIR number, police station, Premium' ($r.Code -eq 200 -and $m.portal_verified -eq $true -and $m.portal_fir_number -eq '123/2026' -and $m.portal_police_station -eq 'Civil Lines, Raipur' -and $m.portal_is_premium -eq $true) "HTTP $($r.Code) meta=$($m | ConvertTo-Json -Compress)"
    Check '4. Public does NOT see Sensitive or second approver' (-not ($names -contains 'portal_sensitive') -and -not ($names -contains 'portal_second_approver')) "fields=$($names -join ',')"

    $r = Invoke-Rest $null GET "/wp/v2/posts?include=$post"
    Check '5. Fields appear in the post list /wp-json/wp/v2/posts' ($r.Code -eq 200 -and $r.Data[0].meta.portal_verified -eq $true) "HTTP $($r.Code)"

    $r = Invoke-Rest $e GET "/wp/v2/posts/$post`?context=edit"
    $typed = Invoke-Rest $e POST "/wp/v2/posts/$post" @{ meta = @{ portal_second_approver = 1 } }
    Check '6. Editor (context=edit) sees the internal fields; nobody can type in a second approver' ($r.Code -eq 200 -and $r.Data.meta.portal_sensitive -eq $true -and $r.Data.meta.portal_second_approver -eq 0 -and $typed.Code -eq 403) "HTTP $($r.Code) typed approver=$($typed.Code)"

    Write-Host '== Writers cannot set editorial fields'
    $r = Invoke-Rest $a POST '/wp/v2/posts' @{ title = 'Author self-verifies'; status = 'publish'; categories = @($cat['industry']); meta = @{ portal_verified = $true } }
    Check '7. Author cannot mark own post Verified' ($r.Code -eq 403) "HTTP $($r.Code) $($r.Data.code)"

    $r = Invoke-Rest $c POST '/wp/v2/posts' @{ title = 'Contributor makes it Premium'; categories = @($cat['community-blogs']); meta = @{ portal_is_premium = $true } }
    Check '8. Contributor cannot mark a post Premium' ($r.Code -eq 403) "HTTP $($r.Code) $($r.Data.code)"

    $r = Invoke-Rest $a POST '/wp/v2/posts' @{ title = 'Author normal post'; status = 'publish'; categories = @($cat['industry']) }
    Check '9. Author can still publish normally (fields keep their defaults)' ($r.Code -eq 201 -and $r.Data.meta.portal_verified -eq $false -and $r.Data.meta.portal_fir_number -eq '') "HTTP $($r.Code)"

    Write-Host '== Input cleaning'
    $r = Invoke-Rest $e POST "/wp/v2/posts/$post" @{ meta = @{ portal_fir_number = '<script>alert(1)</script> 456/2026 ' } }
    Check '10. HTML/script is stripped from FIR number' ($r.Code -eq 200 -and $r.Data.meta.portal_fir_number -eq '456/2026') "HTTP $($r.Code) value='$($r.Data.meta.portal_fir_number)'"
}
finally {
    Complete-Test
}
if ($script:Fail -gt 0) { exit 1 }
