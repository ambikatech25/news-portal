# Reading comfort (Task 17B)

On **every story**, for every reader (no setting needed):

| What | Details |
|---|---|
| **Comfortable text** | 18 px on phones, 19 px on computers, tall line spacing (1.75) so Hindi matras (ि ु ॅ) have room |
| **Hindi-friendly font** | Fonts already on the reader's device, named so Hindi uses a proper Devanagari font: *Noto Sans Devanagari* (Android, most readers), *Nirmala UI* (Windows), *Kohinoor Devanagari* (iPhone, Mac), *Mangal* (older Windows). **Nothing is downloaded**, so pages stay fast. |
| **Progress bar** | Thin red line at the very top of the screen that fills as you read |
| **Read time** | "5 min read" under the headline (200 words a minute, Hindi counted correctly) |
| **Reading mode** | Button next to the read time. Hides the header, breaking strip, share row, writer card, comments, related stories and footer. The headline, the story and the series links (Previous / Next part) stay. |

Reading mode toolbar (top of the screen):

- **✕ Exit** (or press **Esc** on a keyboard); the reader stays at the same place in the story.
- **"3 min left"**, counting down as they read; "Finished" at the end.
- **A− / A+**: five text sizes (90 % to 130 %).
- **Light / Sepia / Dark** colours (on phones: three small colour circles).

**What is remembered, and where:** only in the reader's own browser, nothing on our server.
Text size and colours are kept on that device. Reading mode stays on while the reader moves from
story to story (handy for a series) and is off again on their next visit. Because every visitor
gets exactly the same page, LiteSpeed's page cache keeps working (checked by test 10).

Code: theme `inc/reading.php`, `assets/js/reading.js`, the "Reading comfort" part of `style.css`,
and the button in `template-parts/portal/article-flags.php`. Test: `tests/test-reading.ps1`.

## Checked in a browser (12 Nov 2026)

On a long Hindi demo story, at desktop and phone (375 px) size:

- Reading mode on and off from the middle of the story: the same paragraph stayed in the same
  place on screen.
- Dark and Sepia colours, text size 120 %, all remembered after reloading the page.
- After reloading with reading mode on: **no layout shift** (CLS 0), because the choices are applied before
  the page is drawn.
- Phone toolbar on one line (53 px); nothing wider than the screen.
- First visit (nothing saved): CLS 0, largest content shown in 0.9 s locally, page 26 KB,
  no font files downloaded. PageSpeed itself is confirmed on the live site (Task 18, `speed.md`).

## Later

- **Hindi web addresses:** a Hindi headline becomes a long, unreadable address
  (`/community-blogs/%e0%a4%b0%e0%a4%be.../`), cut off after about 200 characters. Better: an
  English (transliterated) address, e.g. `/community-blogs/raipur-ke-purane-bazaar/`. Not part of 17B; to
  be decided before launch.
