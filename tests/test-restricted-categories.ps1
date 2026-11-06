# Task 5 test: editor-only categories and Contributor image uploads.
#
# Run from the project folder (Docker must be running):
#   powershell -ExecutionPolicy Bypass -File tests\test-restricted-categories.ps1

. "$PSScriptRoot\lib.ps1"

try {
    Write-Host '== Setup: category IDs and temporary test users'
    $cat = Get-CategoryIds
    $users = New-TestUsers @('contributor', 'author', 'editor')
    $c = $users['contributor'].Session
    $a = $users['author'].Session
    $e = $users['editor'].Session

    Write-Host '== Contributor'
    $r = Invoke-Rest $c POST '/wp/v2/posts' @{ title = 'Contributor tries News'; categories = @($cat['news']) }
    Check '1. Contributor cannot create a post in News' ($r.Code -eq 403 -and $r.Data.code -eq 'portal_restricted_category') "HTTP $($r.Code) $($r.Data.code)"
    if ($r.Data.message) { Write-Host ("      message shown: " + $r.Data.message) }

    $r = Invoke-Rest $c POST '/wp/v2/posts' @{ title = 'Contributor mixes Crime + Logistics'; categories = @($cat['crime'], $cat['logistics']) }
    Check '2. Contributor cannot sneak Crime in next to an allowed category' ($r.Code -eq 403) "HTTP $($r.Code)"

    $r = Invoke-Rest $c POST '/wp/v2/posts' @{ title = 'Contributor blog post'; status = 'pending'; categories = @($cat['community-blogs']) }
    $ownPost = $r.Data.id
    Check '3. Contributor CAN submit to Community Blogs (status pending)' ($r.Code -eq 201 -and $r.Data.status -eq 'pending') "HTTP $($r.Code) status=$($r.Data.status)"

    $r = Invoke-Rest $c POST "/wp/v2/posts/$ownPost" @{ categories = @($cat['community-blogs'], $cat['case-files']) }
    Check '4. Contributor cannot move own post into Case Files later' ($r.Code -eq 403) "HTTP $($r.Code)"

    $code = Send-Image $c
    Check '5. Contributor CAN upload an image' ($code -eq 201) "HTTP $code"

    Write-Host '== Author'
    $r = Invoke-Rest $a POST '/wp/v2/posts' @{ title = 'Author tries Case Files'; status = 'publish'; categories = @($cat['case-files']) }
    Check '6. Author cannot publish in Case Files' ($r.Code -eq 403) "HTTP $($r.Code)"

    $r = Invoke-Rest $a POST '/wp/v2/posts' @{ title = 'Author industry post'; status = 'publish'; categories = @($cat['industry']) }
    Check '7. Author CAN publish in Industry' ($r.Code -eq 201 -and $r.Data.status -eq 'publish') "HTTP $($r.Code)"

    Write-Host '== Editor'
    $r = Invoke-Rest $e POST '/wp/v2/posts' @{ title = 'Editor news story'; status = 'publish'; author = $users['author'].Id; categories = @($cat['news']) }
    $editorPost = $r.Data.id
    Check '8. Editor CAN publish in News (on behalf of the Author)' ($r.Code -eq 201) "HTTP $($r.Code)"

    $r = Invoke-Rest $a POST "/wp/v2/posts/$editorPost" @{ title = 'Editor news story (typo fixed by author)'; categories = @($cat['news'], $cat['industry']) }
    $slugs = (Wp post term list $editorPost category --field=slug) -join ','
    Check "9. Author's later edit keeps the Editor's News choice" ($r.Code -eq 200 -and $slugs -match 'news') "HTTP $($r.Code) categories=$slugs"

    Write-Host '== Safety net (not via REST)'
    $id = [int](Wp post create "--user=$($users['contributor'].Login)" '--post_title=Safety net test' "--post_category=$($cat['news'])" --porcelain | Select-Object -Last 1)
    $slugs = (Wp post term list $id category --field=slug) -join ','
    Check '10. News added outside the API is removed (falls back to Community Blogs)' ($slugs -eq 'community-blogs') "categories=$slugs"
}
finally {
    Complete-Test
}
if ($script:Fail -gt 0) { exit 1 }
