# Reading comfort (Task 17B, controls simplified 23 Nov 2026)

On **every story**, for every reader (no setting needed):

| What | Details |
|---|---|
| **Comfortable text** | 18 px on phones, 19 px on computers, tall line spacing (1.75) so Hindi matras (ि ु ॅ) have room |
| **Hindi-friendly font** | Fonts already on the reader's device, named so Hindi uses a proper Devanagari font: *Noto Sans Devanagari* (Android, most readers), *Nirmala UI* (Windows), *Kohinoor Devanagari* (iPhone, Mac), *Mangal* (older Windows). **Nothing is downloaded**, so pages stay fast. |
| **Progress bar** | Thin red line at the very top of the screen that fills as you read |
| **Reading controls** | One line under the headline, always visible: **5 min read · A− A+ · Light / Sepia / Dark** |

- **A− / A+**: five text sizes (90 % to 130 %). The paragraph you are reading stays in place.
  A button greys out at the smallest or largest size.
- **Light / Sepia / Dark**: the **whole story page** changes colour (header, story, writer card,
  buttons, related stories, footer). On phones the three buttons are small colour circles.
- The header, Follow, share row, comments, related stories and Next part stay as normal
  (there is no separate "reading mode" any more: the normal page is the reading page).

**What is remembered, and where:** only in the reader's own browser, nothing on our server.
Text size and colours are kept on that device for every story. They are applied before the page
is drawn, so there is no jump and no white flash in Dark. Because every visitor gets exactly the
same page, LiteSpeed's page cache keeps working (checked by test 10).

Code: theme `inc/reading.php`, `assets/js/reading.js`, the "Reading comfort" part of `style.css`,
and the line in `template-parts/portal/article-flags.php`. Test: `tests/test-reading.ps1`.

## Checked in a browser (23 Nov 2026)

At desktop and phone (375 px) size:

- Dark: header, story, writer card, Like / Save / Comments, share row, related stories and footer
  all dark; text readable.
- Sepia on a phone: the controls fit on one line under the headline.
- Text size 120 % and Dark remembered after reloading the page; A− back to 100 % in two steps.
