# Task 18A test: Hindi + English site (Hindi at /, English at /en/), story language switch,
# shared editorial fields, language lists in the API, Hindi interface words.
#
# Run from the project folder (Docker must be running):
#   powershell -ExecutionPolicy Bypass -File tests\test-languages.ps1

. "$PSScriptRoot\lib.ps1"

function Get-Page($url) {
    $raw = (curl.exe -s -w "`n%{http_code} %{redirect_url}" $url | Out-String).TrimEnd()
    $last = $raw.Substring($raw.LastIndexOf("`n") + 1) -split ' ', 2
    return @{ Html = $raw.Substring(0, $raw.LastIndexOf("`n")); Code = [int]$last[0]; Location = "$($last[1])".Trim() }
}

function Get-TermId($slug) {
    return [int]("$(Wp term list category "--slug=$slug" --field=term_id --lang= 2>$null | Select-Object -Last 1)".Trim())
}

try {
    Write-Host '== Two languages'
    $hiHome = Get-Page "$Base/"
    $enHome = Get-Page "$Base/en/"
    Check '1. Hindi home at / (lang="hi", Hindi menu) and English home at /en/ (lang="en-US", English menu)' ($hiHome.Code -eq 200 -and $hiHome.Html -match '<html[^>]+lang="hi' -and $hiHome.Html -match 'समाचार' -and $enHome.Code -eq 200 -and $enHome.Html -match '<html[^>]+lang="en' -and $enHome.Html -match '>News<') "hi=$($hiHome.Code) en=$($enHome.Code)"

    $old = Get-Page "$Base/write/"
    Check '2. Old English addresses still work: /write/ moves permanently to /en/write/' ($old.Code -eq 301 -and $old.Location -eq "$Base/en/write/") "$($old.Code) -> $($old.Location)"

    Check '3. Both home pages offer the other language (English | हिन्दी switch in the menu)' ($hiHome.Html -match "href=""$Base/en/""" -and $enHome.Html -match 'lang="hi"') 'switch missing'

    Write-Host '== A news story in both languages'
    $users = New-TestUsers @('editor')
    $e = $users['editor'].Session
    $tag = 'L' + (Get-Random -Maximum 99999)
    $enNews = Get-TermId 'news'
    $hiNews = Get-TermId 'samachar'

    $en = Invoke-Rest $e POST '/wp/v2/posts' @{ title = "$tag Raipur market reopens"; content = '<p>English text of the story. Who, what, where, when.</p>'; status = 'publish'; categories = @($enNews) }
    $hi = Invoke-Rest $e POST '/wp/v2/posts' @{ title = "$tag रायपुर का बाज़ार फिर खुला"; content = '<p>खबर का हिन्दी पाठ। कौन, क्या, कहाँ, कब।</p>'; status = 'publish'; categories = @($hiNews) }
    $enId = [int]$en.Data.id; $hiId = [int]$hi.Data.id
    $langs = Wp eval "echo pll_get_post_language($enId), ' ', pll_get_post_language($hiId);"
    Check '4. A story sent without a language takes the language of its section (News = en, समाचार = hi)' ($en.Code -eq 201 -and $hi.Code -eq 201 -and "$langs" -match '^en hi$') "en=$($en.Code) hi=$($hi.Code) langs=$langs"

    Check '5. A Hindi title gets a short address in English letters' ($hi.Data.slug -match '^[a-z0-9-]+$' -and $hi.Data.slug -match 'raypur-ka-bazar-phir-khula' -and $hi.Data.link -notmatch '/en/') "slug=$($hi.Data.slug)"

    Wp eval "pll_save_post_translations(array('en' => $enId, 'hi' => $hiId));" | Out-Null
    Invoke-Rest $e POST "/wp/v2/posts/$enId" @{ meta = @{ portal_verified = $true; portal_fir_number = "$tag/2026"; portal_police_station = 'Civil Lines' } } | Out-Null
    $hiMeta = (Invoke-Rest $null GET "/wp/v2/posts/$hiId").Data.meta
    Check '6. Verified, FIR number and police station set on one version are shared by the other' ($hiMeta.portal_verified -eq $true -and $hiMeta.portal_fir_number -eq "$tag/2026" -and $hiMeta.portal_police_station -eq 'Civil Lines') "hi verified=$($hiMeta.portal_verified) fir=$($hiMeta.portal_fir_number)"

    $enPage = Get-Page $en.Data.link
    $hiPage = Get-Page $hi.Data.link
    $enSwitch = [regex]::Match($enPage.Html, '(?s)<nav class="portal-story-lang".*?</nav>').Value
    $hiSwitch = [regex]::Match($hiPage.Html, '(?s)<nav class="portal-story-lang".*?</nav>').Value
    Check '7. Under the headline: हिन्दी | English switch; the current language is marked, the other links to its version' ($enSwitch -match [regex]::Escape("href=""$($hi.Data.link)""") -and $enSwitch -match 'is-current" lang="en"' -and $hiSwitch -match [regex]::Escape("href=""$($en.Data.link)""") -and $hiSwitch -match 'is-current" lang="hi"') "en switch=$([bool]$enSwitch) hi switch=$([bool]$hiSwitch)"

    Check '8. Interface words follow the story: Hindi read time and Verified box on the Hindi page, English on the English page' ($hiPage.Html -match 'मिनट में पढ़ें' -and $hiPage.Html -match 'हमारी समाचार डेस्क द्वारा सत्यापित' -and $enPage.Html -match 'min read' -and $enPage.Html -match 'Verified by our news desk') "hi=$($hiPage.Code) en=$($enPage.Code)"

    Write-Host '== No switch without a published other version'
    Invoke-Rest $e POST "/wp/v2/posts/$hiId" @{ status = 'draft' } | Out-Null
    $enPage2 = Get-Page $en.Data.link
    $solo = Invoke-Rest $e POST '/wp/v2/posts' @{ title = "$tag Only in English"; content = '<p>Just English.</p>'; status = 'publish'; categories = @($enNews) }
    $soloPage = Get-Page $solo.Data.link
    Check '9. No switch while the other version is a draft, nor on a story in one language only' ($enPage2.Html -notmatch 'portal-story-lang' -and $soloPage.Code -eq 200 -and $soloPage.Html -notmatch 'portal-story-lang') "draft pair=$($enPage2.Html -match 'portal-story-lang') solo=$($soloPage.Html -match 'portal-story-lang')"
    Invoke-Rest $e POST "/wp/v2/posts/$hiId" @{ status = 'publish' } | Out-Null

    Write-Host '== API'
    $apiHi = @((Invoke-Rest $null GET "/wp/v2/posts?lang=hi&search=$tag").Data | ForEach-Object { [int]$_.id })
    $apiEn = @((Invoke-Rest $null GET "/wp/v2/posts?lang=en&search=$tag").Data | ForEach-Object { [int]$_.id })
    Check '10. API lists by language: ?lang=hi has only the Hindi version, ?lang=en only the English ones' (($apiHi -contains $hiId) -and -not ($apiHi -contains $enId) -and ($apiEn -contains $enId) -and -not ($apiEn -contains $hiId)) "hi=$($apiHi -join ',') en=$($apiEn -join ',')"

    $sec = Get-Page "$Base/category/samachar/"
    Check '11. The Hindi News section (/category/samachar/) lists the Hindi story, not the English one' ($sec.Code -eq 200 -and $sec.Html -match [regex]::Escape($hi.Data.link) -and $sec.Html -notmatch [regex]::Escape($en.Data.link)) "HTTP $($sec.Code)"
}
finally {
    Complete-Test
}
if ($script:Fail -gt 0) { exit 1 }
