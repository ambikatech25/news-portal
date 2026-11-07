# Task 11 extra: fill the story form from a Word file or pasted text (ChatGPT), Word template,
# and the "AI tools helped" disclosure.
#
# Run from the project folder (Docker must be running):
#   powershell -ExecutionPolicy Bypass -File tests\test-story-import.ps1

. "$PSScriptRoot\lib.ps1"
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

function Get-Last($lines) { return ($lines | Where-Object { "$_".Trim() } | Select-Object -Last 1) }

$W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'

function New-Docx($path, $bodyXml, $linkTarget) {
    # A minimal real .docx: the same parts Word writes.
    if (Test-Path $path) { Remove-Item $path }
    $zip = [IO.Compression.ZipFile]::Open($path, 'Create')
    $parts = @{
        '[Content_Types].xml' = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>'
        '_rels/.rels' = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>'
        'word/_rels/document.xml.rels' = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId9" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="' + $linkTarget + '" TargetMode="External"/></Relationships>'
        'word/document.xml' = '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="' + $W + '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body>' + $bodyXml + '</w:body></w:document>'
    }
    foreach ($name in $parts.Keys) {
        $entry = $zip.CreateEntry($name)
        $sw = New-Object IO.StreamWriter($entry.Open(), (New-Object Text.UTF8Encoding($false)))
        $sw.Write($parts[$name]); $sw.Close()
    }
    $zip.Dispose()
}

function P($text, $style, $bold) {
    $ppr = if ($style) { "<w:pPr><w:pStyle w:val=""$style""/></w:pPr>" } else { '' }
    $rpr = if ($bold) { '<w:rPr><w:b/></w:rPr>' } else { '' }
    return "<w:p>$ppr<w:r>$rpr<w:t xml:space=""preserve"">$text</w:t></w:r></w:p>"
}

function Send-Import($session, $file, $type, $text) {
    # POST /portal/v1/story-import with a file (multipart) or pasted text. Returns @{Code; Data}.
    $curlArgs = @('-s', '-X', 'POST', '-w', "`n%{http_code}")
    if ($session) { $curlArgs += @('-b', $session.Jar, '-H', "X-WP-Nonce: $($session.Nonce)") }
    if ($file) { $curlArgs += @('-F', "file=@$file;type=$type") }
    if ($text) {
        $tf = Join-Path $Tmp 'paste.txt'
        [IO.File]::WriteAllText($tf, $text, (New-Object Text.UTF8Encoding($false)))
        $curlArgs += @('-F', "text=<$tf")
    }
    # Save the reply to a file and read it as UTF-8 (PowerShell 5.1 garbles Hindi read from a pipe).
    $out = Join-Path $Tmp 'reply.json'
    $curlArgs[4] = '%{http_code}'
    $code = (curl.exe @curlArgs -o $out "$Base/wp-json/portal/v1/story-import" | Out-String).Trim()
    $data = $null
    try { $data = [IO.File]::ReadAllText($out, [Text.Encoding]::UTF8) | ConvertFrom-Json } catch { }
    return @{ Code = [int]$code; Data = $data }
}

$tag = 'T11i-' + [guid]::NewGuid().ToString('N').Substring(0, 6)

try {
    Write-Host '== Setup'
    $cat = Get-CategoryIds
    $users = New-TestUsers @('contributor', 'subscriber', 'editor')
    $c = $users['contributor'].Session
    $attBefore = Get-Last (Wp post list --post_type=attachment --format=count)

    Write-Host '== Template'
    $tpl = Join-Path $Tmp 'template.docx'
    $code = curl.exe -s -o $tpl -w '%{http_code}' "$Base/wp-admin/admin-post.php?action=portal_story_template"
    $z = [IO.Compression.ZipFile]::OpenRead($tpl)
    $r = New-Object IO.StreamReader(($z.Entries | Where-Object FullName -eq 'word/document.xml').Open()); $xml = $r.ReadToEnd(); $r.Close(); $z.Dispose()
    $sections = [regex]::Match($xml, 'Choose one: ([^<]+)').Groups[1].Value
    $blank = Send-Import $c $tpl 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' $null
    Check '1. Word template: 4 labels, only writers'' sections; an untouched template fills nothing (examples in [brackets] are skipped)' ($code -eq '200' -and $xml -match '>HEADLINE<' -and $xml -match '>STORY<' -and $sections -match 'Logistics' -and $sections -notmatch 'News|Crime|Case Files' -and $blank.Data.title -eq '' -and $blank.Data.content -eq '' -and $blank.Data.tags -eq '' -and (@($blank.Data.warnings) -join ' ') -match 'No headline') "HTTP $code sections=$sections blank title=$($blank.Data.title) tags=$($blank.Data.tags)"

    Write-Host '== Word file'
    $body = (P 'My story about trucks' 'Title') + (P 'Some notes before the labels that must be ignored.') +
        (P 'HEADLINE' '' $true) + (P "Rest stop for truck drivers opens on NH-130 $tag") +
        (P 'Section:' '' $true) + (P 'Logistics') +
        (P 'TAGS' '' $true) + (P 'Raipur, trucking; NH-130') +
        (P 'STORY' '' $true) +
        "<w:p><w:r><w:t xml:space=""preserve"">Drivers now have a </w:t></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>free</w:t></w:r><w:r><w:t xml:space=""preserve""> place to rest, says the </w:t></w:r><w:r><w:rPr><w:i/></w:rPr><w:t>transport office</w:t></w:r><w:r><w:t>.</w:t></w:r></w:p>" +
        (P 'What drivers get' 'Heading2') +
        "<w:p><w:pPr><w:numPr><w:ilvl w:val=""0""/><w:numId w:val=""1""/></w:numPr></w:pPr><w:r><w:t>Clean toilets</w:t></w:r></w:p>" +
        "<w:p><w:pPr><w:numPr><w:ilvl w:val=""0""/><w:numId w:val=""1""/></w:numPr></w:pPr><w:r><w:t>Cheap meals</w:t></w:r></w:p>" +
        "<w:p><w:r><w:t xml:space=""preserve"">Details on the </w:t></w:r><w:hyperlink r:id=""rId9""><w:r><w:t>official site</w:t></w:r></w:hyperlink></w:p>" +
        "<w:p><w:r><w:drawing>picture</w:drawing></w:r></w:p>"
    $doc = Join-Path $Tmp 'story.docx'
    New-Docx $doc $body 'https://example.org/rest-stop'
    $imp = Send-Import $c $doc 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' $null
    $d = $imp.Data
    Check '2. Word file fills headline, section, tags; notes before the first label are ignored' ($imp.Code -eq 200 -and $d.title -eq "Rest stop for truck drivers opens on NH-130 $tag" -and $d.category -eq $cat['logistics'] -and $d.tags -eq 'Raipur, trucking, NH-130') "HTTP $($imp.Code) title=$($d.title) cat=$($d.category) tags=$($d.tags)"
    Check '3. Story keeps bold, italic, sub-heading, list and link; the picture is not copied but mentioned' ($d.content -match '<strong>free</strong>' -and $d.content -match '<em>transport office</em>' -and $d.content -match '<h2>What drivers get</h2>' -and $d.content -match '<ul><li>Clean toilets</li><li>Cheap meals</li></ul>' -and $d.content -match '<a href="https://example.org/rest-stop">official site</a>' -and $d.content -notmatch '<img' -and (@($d.warnings) -join ' ') -match 'picture') "content=$($d.content) warnings=$(@($d.warnings) -join ' | ')"

    Write-Host '== Pasted text (ChatGPT style)'
    $paste = "**HEADLINE:** Steel prices rise in Bhilai $tag`n**SECTION:** Industry`n**TAGS:** steel, Bhilai, prices`n**STORY:**`nPrices rose by **5 per cent** this week.`n`n## Why`n- Higher coal costs`n- More demand`n`nRead the [report](https://example.org/report)."
    $p = Send-Import $c $null $null $paste
    Check '4. Pasted ChatGPT text fills every field; markdown becomes formatting' ($p.Code -eq 200 -and $p.Data.title -eq "Steel prices rise in Bhilai $tag" -and $p.Data.category -eq $cat['industry'] -and $p.Data.tags -eq 'steel, Bhilai, prices' -and $p.Data.content -match '<strong>5 per cent</strong>' -and $p.Data.content -match '<h2>Why</h2>' -and $p.Data.content -match '<li>Higher coal costs</li>' -and $p.Data.content -match 'href="https://example.org/report"') "HTTP $($p.Code) title=$($p.Data.title) content=$($p.Data.content)"

    $hindiTitle = -join ([char[]](0x0928, 0x092F, 0x093E, 0x0020, 0x092A, 0x0941, 0x0932))   # "naya pul" (new bridge)
    $hindiLabel = -join ([char[]](0x0936, 0x0940, 0x0930, 0x094D, 0x0937, 0x0915))           # "sheershak" (headline)
    $storyLabel = -join ([char[]](0x0915, 0x0939, 0x093E, 0x0928, 0x0940))                   # "kahani" (story)
    $h = Send-Import $c $null $null "${hindiLabel}: $hindiTitle`n${storyLabel}:`nRaipur mein naya pul khula."
    $news = Send-Import $c $null $null "HEADLINE: Big crime story`nSECTION: Crime`nSTORY:`nSomething happened."
    Check '5. Hindi labels work; asking for an editor-only section (Crime) is refused with a clear note' ($h.Data.title -eq $hindiTitle -and $h.Data.content -match 'naya pul' -and $null -eq $news.Data.category -and (@($news.Data.warnings) -join ' ') -match 'Only editors') "hindi title ok=$($h.Data.title -eq $hindiTitle) crime cat=$($news.Data.category) warn=$(@($news.Data.warnings) -join ' | ')"

    $plain = Send-Import $c $null $null "New bus route to Bastar $tag`n`nThe route starts on Monday.`n`nTickets cost 120 rupees."
    Check '6. Text without any labels: first line becomes the headline, the rest the story' ($plain.Data.title -eq "New bus route to Bastar $tag" -and $plain.Data.content -match '<p>The route starts on Monday.</p>') "title=$($plain.Data.title)"

    Write-Host '== Safety'
    $evil = Send-Import $c $null $null "HEADLINE: Test <script>alert(1)</script>`nSTORY:`n<script>alert(2)</script> Hello <img src=x onerror=alert(3)> [click](javascript:alert(4))"
    # Typed tags may show as harmless text (&lt;script&gt;); what must never come through is a real tag or link.
    $all = $evil.Data.title + $evil.Data.content
    Check '7. Scripts, image tricks and javascript: links never become real tags or links' ($evil.Code -eq 200 -and $all -notmatch '<script|<img|href="javascript' ) "title=$($evil.Data.title) content=$($evil.Data.content)"

    $fake = Join-Path $Tmp 'fake.docx'; [IO.File]::WriteAllText($fake, 'not really word')
    $pdf = Join-Path $Tmp 'story.pdf'; [IO.File]::WriteAllText($pdf, '%PDF-1.4 fake')
    $big = Join-Path $Tmp 'big.txt'; [IO.File]::WriteAllBytes($big, (New-Object byte[] 2200000))
    $a = Send-Import $c $fake 'application/octet-stream' $null
    $b = Send-Import $c $pdf 'application/pdf' $null
    $g = Send-Import $c $big 'text/plain' $null
    Check '8. A fake Word file, a PDF and a file over 2 MB are refused with a helpful message' ($a.Code -eq 400 -and $a.Data.code -eq 'portal_import_type' -and $b.Code -eq 400 -and $b.Data.code -eq 'portal_import_pdf' -and $g.Code -eq 400 -and $g.Data.code -eq 'portal_import_size') "fake=$($a.Code)/$($a.Data.code) pdf=$($b.Code)/$($b.Data.code) big=$($g.Code)/$($g.Data.code)"

    $anon = Send-Import $null $doc 'application/octet-stream' $null
    $mem = Send-Import $users['subscriber'].Session $doc 'application/octet-stream' $null
    $attAfter = Get-Last (Wp post list --post_type=attachment --format=count)
    Check '9. Only writers can import (visitor 401, member 403); uploaded documents are never stored' ($anon.Code -eq 401 -and $mem.Code -eq 403 -and $attAfter -eq $attBefore) "visitor=$($anon.Code) member=$($mem.Code) files before/after=$attBefore/$attAfter"

    Write-Host '== Write page and AI disclosure'
    $wp = (curl.exe -s -b $c.Jar "$Base/write/" | Out-String)
    $mp = (curl.exe -s -b $users['subscriber'].Session.Jar "$Base/write/" | Out-String)
    Check '10. Writers see the import box (template link, upload, paste, ChatGPT prompt); members do not' ($wp -match 'class="portal-import"' -and $wp -match 'action=portal_story_template' -and $wp -match 'data-portal-import-file' -and $wp -match 'data-portal-import-prompt' -and $wp -match 'name="portal_ai_assisted\[\]"' -and $mp -notmatch 'portal-import') 'box or AI checkbox missing'

    $form = [regex]::Match($wp, '(?s)<form class="wpuf-form-add.*?</form>').Value
    $nonce = [regex]::Match($form, 'name="_wpnonce" value="([^"]+)"').Groups[1].Value
    $formId = [regex]::Match($form, 'name="form_id" value="(\d+)"').Groups[1].Value
    $pageId = [regex]::Match($form, 'name="page_id" value="(\d+)"').Groups[1].Value
    curl.exe -s -o NUL -b $c.Jar -e "$Base/write/" --data-urlencode 'action=wpuf_submit_post' --data-urlencode "form_id=$formId" --data-urlencode "page_id=$pageId" `
        --data-urlencode "_wpnonce=$nonce" --data-urlencode "post_title=$tag ai story" --data-urlencode "post_content=<p>Text.</p>" `
        --data-urlencode "category=$($cat['region'])" --data-urlencode 'portal_ai_assisted[]=yes' "$Base/wp-admin/admin-ajax.php"
    $sid = Get-Last (Wp db query "SELECT ID FROM wp_posts WHERE post_type = 'post' AND post_title = '$tag ai story' LIMIT 1" --skip-column-names)
    $meta = Get-Last (Wp post meta get $sid portal_ai_assisted)
    $ed = Invoke-Rest $users['editor'].Session GET "/wp/v2/posts/${sid}?context=edit"
    Check '11. "AI tools helped" tick is saved and visible to editors (API), not added to the story text' ($meta -eq 'yes' -and $ed.Data.meta.portal_ai_assisted -eq 'yes' -and $ed.Data.content.raw -notmatch 'AI tools') "meta=$meta api=$($ed.Data.meta.portal_ai_assisted)"
}
finally {
    Complete-Test
}
if ($script:Fail -gt 0) { exit 1 }
