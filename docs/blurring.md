# Guide for editors: hiding faces, number plates and other identifying details

CityCharcha removes hidden photo data (GPS location, phone model, time) automatically on upload
(see "What the website does for you" below). It **cannot** see what is *in* the picture. Hiding a
face or a number plate is the editor's job, and it must happen **before** the photo is uploaded.

> This guide is practical advice, not legal advice. Ask your lawyer to confirm it for your
> Editorial & Corrections Policy.

## What must be hidden

Always hide anything that could identify:

- **Victims of sexual offences**: face, name, home, school, workplace, relatives, anything that
  leads to them. Indian law forbids disclosing their identity (Bharatiya Nyaya Sanhita, the
  section that replaced IPC 228A).
- **Children** who are victims or witnesses (POCSO Act), or who are accused / in conflict with the
  law (Juvenile Justice Act): face, name, school, address, parents' names.
- **Witnesses** and people who could be put in danger by being recognised.
- **Number plates** of private vehicles, unless the vehicle itself is the story and an editor approves.
- **Addresses, house numbers, phone numbers, documents** (Aadhaar, ID cards, FIR copies with
  personal details) visible in the photo.
- **Uniform badges / name tags** of people who are not public officials acting in public.

When in doubt, hide it, or do not use the photo. Mark such stories **Sensitive** so a second
editor checks them (see `sensitive.md`).

## The golden rules

1. **Hide before uploading.** WordPress keeps the original file of every upload, even after you
   crop or edit it inside WordPress. An unblurred photo that was uploaded once is on the server.
   If it happens: delete it from the Media Library (Delete permanently) and upload the hidden version.
2. **Use solid boxes or strong pixelation, not light blur.** A soft blur can sometimes be
   sharpened or recognised (people know a face, a car, a T-shirt). A solid black box is safest.
3. **Hide enough.** Cover the whole face including hair outline and distinctive marks, the whole
   plate, and the surroundings that give it away (a shop sign next to a house, a school logo).
4. **Check the small version too.** Look at the photo at phone size; a "hidden" face is
   sometimes still recognisable from far away.
5. **Captions and file names matter too.** Do not name the person in the caption, alt text or the
   file name (`ramesh-victim.jpg` is visible to anyone who opens the image).

## How to do it (free tools)

### On a phone (fastest)

| Phone | Steps |
|---|---|
| **Android (Google Photos)** | Open the photo → **Edit** → **Markup** → choose the **pen**, the thickest size and black → paint over the face/plate until fully covered → **Done** → **Save as copy**. Upload the copy. |
| **iPhone (Photos)** | Open the photo → **Edit** → the **pen tip** icon (Markup) → **+** → **Shape** → a square → make it **filled** and black → place and stretch it over the face/plate → **Done** → **Save**. |
| **Any phone** | Often the simplest: **crop** the person or vehicle out of the picture (Edit → Crop), then save as a copy. Check that no reflection, shadow or sign still gives them away. |

### On a computer

| Tool | Steps |
|---|---|
| **Photopea** (free, in the browser: photopea.com, nothing to install) | **File → Open** the photo → **Rectangle Select** (press M) around the face/plate → **Filter → Pixelate → Mosaic**, cell size **25 or more** (or **Edit → Fill → Black**) → **File → Export as → JPG**. |
| **Windows Paint** | Open the photo → **Shapes → Rectangle**, **Fill: Solid colour**, black → draw over the area → **Save as → JPEG**. |
| **Canva** (free account) | Upload the photo → add a black **square element** over the area → **Share → Download → JPG**. |

Then upload the new file as usual (Write page or Media Library).

## What the website does for you (automatic, Task 15)

On every upload (Media Library, the Write page, profile photos, the app):

- **Hidden photo data is removed**: GPS location, phone make/model, date and time, owner names,
  captions stored inside the file. The original upload is cleaned too, not only the resized copies.
- **Phone photos are turned upright** before that data is removed, so they never appear sideways.
- If a photo cannot be cleaned, it is **refused** with a message, never published with its data.

This does **not** hide faces or plates. That is what this guide is for.

## Checklist before publishing a crime or sensitive story

- [ ] No victim, child or witness can be recognised in any photo (face, school uniform, house, vehicle).
- [ ] Number plates, addresses and documents in the photos are covered.
- [ ] Hidden versions were uploaded; any unhidden upload was deleted permanently.
- [ ] Captions, alt text and file names name nobody who must stay anonymous.
- [ ] The story is marked **Sensitive** and a second editor has approved it.
