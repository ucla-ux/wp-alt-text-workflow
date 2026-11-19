# A real run: Claude's draft, then a person's review

What came out of one run of this workflow on the rehearsal site: 26 images with no alt text. No install needed; open the two CSVs in a spreadsheet or on GitHub.

| File | What it is |
|---|---|
| `alt-results.1-claude-draft.csv` | Exactly what `alt-generate.py` wrote (`claude-sonnet-5-5`, Message Batches API) |
| `alt-results.2-after-review.csv` | The same file after a person read every row and edited five |

Columns: `id, alt, route, needs_human, note, context, model`. See the main README for what they mean.

## What the draft got right

- Charts, maps and unreadable images came back `needs_human=1` instead of guessed (rows 12, 11, 32, 80).
- A 156-character alt was held back, not written (row 68).
- People are named from their page, with the setting described and not their appearance (rows 56, 71).
- No alt starts with "image of" or "photo of".

## What the person changed

| Row | Image | Change | Why |
|---|---|---|---|
| 86 | Map of Los Angeles | `needs_human` 0 → 1 | A data map routed as a photo. The draft described it, but a short alt can't say what the colors mean. A person writes this |
| 68 | Ramen | Trimmed 156 → 118 characters, `needs_human` 1 → 0 | Over the 150-character limit |
| 10 | Placeholder portrait | "Placeholder profile silhouette…; no photo available." → "Ada Lovelace" | A headshot's alt is the person's name |
| 35 | Butterfly on a finger | "a outstretched" → "an outstretched" | Grammar |
| 53 | Social media graphic | Rewritten as a plain transcription of its text | The draft added "Green text on navy" and "raised", which the image does not say |

The miss in row 86 comes from the audit, not the model: images are sorted by file name, and `la-country-map` had no chart word in it. The review step exists to catch exactly that.

## What happens next

`alt-apply.php` writes the 21 ready rows into the Media Library and leaves the 5 `needs_human` rows for a person. See the main README, step 6.

The images in this run are the presenter's own photos and a few synthetic test images.
