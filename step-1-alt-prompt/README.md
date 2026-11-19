# Step 1: Alt text for one image

Easy step. No install, no site access, no code. One image at a time.

## Use it

1. Open Claude (or any AI tool that accepts images) and attach the image.
2. Paste the prompt below. Fill in the three `[brackets]`.
3. Read the answer. Edit it. Paste it into the **Alt Text** field in Media → Library.

## Prompt

```text
Write alt text for the attached image.

Where it appears: [page title and one line about the page]
Who the site is for: [for example, a university research institute]
Names I can give you: [names of the people in it, or "none"]

Rules:
- One sentence, under 150 characters, American spelling.
- Say what a sighted reader gets from this image on this page. Do not start
  with "image of" or "photo of". Do not repeat the caption or nearby text.
- Include text that appears in the image if it matters.
- People: never describe appearance (hair, skin, age, body, clothing,
  expression) and never guess gender, race, ethnicity, disability, religion
  or nationality. Never identify anyone from their face. Use a name only if I
  gave it above. Otherwise describe the activity and setting with neutral
  words ("a person", "people", "they").
- Chart, map or infographic: give a one-line summary, then tell me it needs
  a longer description written by a person.
- If you cannot tell what it shows, say so. Do not guess.

Reply with the alt text, then one line on anything I should check.
```

## Check before you paste

- Does it tell me something the page does not?
- Is every name right, and is nobody's appearance described?
- Is anything invented?

AI drafts. A person decides. This is the same rule the automated steps use.

## More than a handful?

Use the automated steps: [`alt-find`](../step-2-alt-find/), [`alt-generate`](../step-3-alt-generate/), [`alt-import`](../step-4-alt-import/).
