# Resend & merge behavior

How Agnosis decides that an incoming email is an update to an existing post
rather than a brand-new one, and what actually happens to the old content
once it decides that — per post type and intake lane. All of this lives in
`Publishing\PostCreator::handle()`'s "Duplicate / singleton resolution"
block and the handful of `find_*()`/`merge_*()` helpers it calls.

This is a reference for reasoning about behavior, not a spec for new code —
if you change any of the methods named below, update this doc to match in
the same PR.

## The short version

| Lane | How the target post is found | Text | Media |
|---|---|---|---|
| `replace@` | Exact subject == title, across `agnosis_artwork` + `agnosis_event`. No AI fuzzy matching. | Fully regenerated from this submission | Accumulates (artwork) — see caveats below |
| Plain resend to `artwork@`/`photo@`/`pure@` | Three layers, cheapest first: exact subject == title → exact image MD5 hash → AI fuzzy text comparison (last 30 days) | Fully regenerated from this submission | Accumulates, deduped by hash |
| `bio@` | None needed — one biography per artist, always the same post (`find_singleton_post()`) | AI-**merged** with the existing text, not replaced | Capped at one image; new photo replaces old |
| `event@` / `[Event]` | Exact subject == title, scoped to `agnosis_event` only. No AI fuzzy matching. | Fully regenerated from this submission (never merged with the old text) | Accumulates, deduped by hash |

"Accumulates" for artwork/event media is the ordinary case (a gallery
submission grows across resends); `agnosis_biography` and the
`TextPosterGenerator` synthetic poster (pure@ text-only submissions with no
photo — see `includes/Publishing/TextPosterGenerator.php`) are the two
carved-out exceptions that cap at one attachment and replace it instead of
piling on. Both are documented in `merge_gallery()`'s own docblock
(`includes/Publishing/PostCreator.php`).

## replace@: explicit, exact-title, no guessing

Resolved *before* the AI pipeline runs (`find_post_by_subject()` against
`['agnosis_artwork', 'agnosis_event']`, exact match on the resent subject
line as the existing post's title, scoped to that artist). This has to
happen early so the matched post's `_agnosis_intake_endpoint` meta can force
the SAME pure/photo/artwork processing strategy the artist originally used,
before `Pipeline::process()`/`process_raw()` ever runs — replacing a pure@
poem via replace@ still generates a poster, even though the resend itself
went to replace@, not pure@.

No AI fuzzy matching is used here at all — replace is destructive (it
overwrites the matched post), so a miss doesn't guess: it creates a new
artwork and carries a "did you mean...?" fuzzy suggestion into the review
email (`$pending_merge_miss_suggestion`) rather than silently merging into
the wrong post.

## Plain resend to the original address: a three-layer detector

An ordinary resend to `artwork@`/`photo@`/`pure@` (not replace@) has no
explicit "this is a replacement" signal, so `find_duplicate_post()` guesses,
cheapest check first:

1. **Exact subject == existing post title** (free DB query).
2. **Exact MD5 hash of the attached image** already recorded on one of the
   artist's posts (`_agnosis_image_hash` meta) — same binary, same artwork,
   regardless of subject or AI-generated title.
3. **AI fuzzy comparison** — only reached if neither of the above hit. A
   cheap text-only model call compares this submission's subject/AI
   title/tags against the artist's last 30 days of artwork posts and asks
   "is this the same artwork, misspelled/reworded/~90% similar?"

First layer to match wins; layer 3 returns 0 (genuinely new artwork) if
nothing clears the bar.

## Biography: a true singleton, and the one lane where text actually merges

`find_singleton_post()` doesn't inspect the subject at all — there is only
ever one biography per artist, so every `bio@` email lands on that same
post (or its pending-update staging draft, if one exists — see
`create_post()`'s "True staging" handling).

Unlike artwork/event, the body text is not a flat replacement:
`Pipeline::merge_biography()` runs the previously stored text
(`_agnosis_artist_prompt`) and the new email through an AI call that merges
them — "I just won the Premio Nacional" gets folded into the existing
biography rather than replacing the whole thing — gated on the
`agnosis_ai_merge_biography` setting. The image is capped at one
(`merge_gallery()`'s `agnosis_biography` branch), replacing whatever was
there rather than accumulating.

## Event: title-matched like replace@, but text is never merged

An artist can have several events, so `event@` can't blindly merge into "the"
event the way biography does. It uses the same exact-title mechanism
replace@ uses (`find_post_by_subject()`), just scoped to `agnosis_event`
only: a subject matching an existing event's title updates that event in
place; no match creates a new one. No AI fuzzy layer — that's tuned for
artwork photo/description matching and doesn't apply to "which event is
this."

Body text is **not** incrementally merged the way biography's is — a new
event announcement is treated as superseding the previous one wholesale, by
design (a venue/date correction, not an addendum). `$singleton` stays `true`
for events (inherited from `resolve_post_type()`), but by this point in
`handle()` it only gates the optional AI "polish" pass
(`agnosis_ai_polish_event`) — the merge decision itself is the plain
title-match above, not the singleton branch.

## What "merge" replaces vs. accumulates, once a target is found

`build_post_content()` regenerates `post_content` from scratch from *this*
submission's AI result on every call, for every type — so for artwork and
event, body text is a flat replacement no matter which detection layer
found the match. Biography is the one exception, via the AI-merge step
above, which happens *before* `build_post_content()` ever runs (it rewrites
`$submission['description']` in place).

The gallery is the opposite for ordinary artwork: `merge_gallery()`'s
default is `array_unique(array_merge($existing_gallery, $new_gallery))`,
deduped by MD5 hash — old images stay, new ones are appended, since a real
gallery submission is expected to accumulate photos across resends.
`agnosis_biography` and the `TextPosterGenerator` synthetic poster are the
two special-cased exceptions (see `merge_gallery()`'s own docblock) that cap
at one attachment and replace it instead — the poster exception was added
2026-07-22 (see CHANGELOG.md 0.9.46) once regenerating a poster on every
resend was found to pile up stale copies instead of showing the corrected
one.

## A side effect worth knowing: a fuzzy match can silently rename the post

`create_post()` always writes `post_title` from *this* resend's subject
line, unconditionally, on every update:

```php
'post_title' => '' !== $original_title ? $original_title : ( $ai_title ?: __( 'Untitled', 'agnosis' ) ),
```

`$original_title` is always the current submission's (cleaned) subject —
this runs regardless of which layer of `find_duplicate_post()` (or
`find_post_by_subject()`) actually found the match. So a resend with a
reworded subject that gets caught by the image-hash or AI-fuzzy layer
doesn't just attach its content to the existing post — it also **renames**
that post to the new subject line. This follows directly from "the subject
is the artist's most deliberate title signal," but it's a real, non-obvious
consequence of using the title as the matching denominator, not just a
side note.

## Reference

| Concern | Method | File |
|---|---|---|
| Route to post type + singleton/photo/pure flags | `resolve_post_type()`, `resolve_indicator()` | `includes/Publishing/PostCreator.php` |
| replace@ pre-pipeline target resolution | `handle()` (`$is_replace` branch) | `includes/Publishing/PostCreator.php` |
| Biography singleton lookup | `find_singleton_post()` | `includes/Publishing/PostCreator.php` |
| Standard artwork 3-layer duplicate detection | `find_duplicate_post()` | `includes/Publishing/PostCreator.php` |
| Exact-title lookup (replace@, event@) | `find_post_by_subject()` | `includes/Publishing/PostCreator.php` |
| Biography incremental text merge | `Pipeline::merge_biography()` | `includes/AI/Pipeline.php` |
| Gallery accumulate/replace rules | `merge_gallery()` | `includes/Publishing/PostCreator.php` |
| Post content (re)generation | `build_post_content()` | `includes/Publishing/PostCreator.php` |
| Synthetic text-poster (pure@, no photo) | `TextPosterGenerator::generate()` | `includes/Publishing/TextPosterGenerator.php` |
