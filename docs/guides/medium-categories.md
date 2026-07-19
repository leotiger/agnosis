# Medium categories

> Mirrors the seeded `agnosis_medium` taxonomy terms. Source: `PromptConfig::CANONICAL_MEDIUMS` in `includes/AI/PromptConfig.php`, seeded (idempotently) by `Activator::seed_medium_terms()` in `includes/Core/Activator.php`.

Agnosis ships 10 canonical medium terms, seeded once on activation:

1. Oil Painting
2. Watercolor
3. Drawing & Illustration
4. Photography
5. Digital Art
6. Sculpture
7. Printmaking
8. Mixed Media
9. Poetry
10. Essay

## How this list actually works

This is a **seed list only**, not the live vocabulary. Once seeded, the
`agnosis_medium` taxonomy itself is the source of truth from that point
on — exactly like WordPress Categories. An admin can freely add, rename,
or remove terms under **Artwork → Mediums** afterward, and every change is
picked up immediately by both the AI prompt (which offers the live list as
`{medium_list}`) and `PostCreator`'s hallucination guard (which validates
the AI's chosen medium against the live list), with no code change or
deploy required.

`PromptConfig::medium_terms()` is what every real caller reads at
runtime — it falls back to the `CANONICAL_MEDIUMS` constant above only
when the taxonomy isn't registered yet (e.g. mid-activation) or has no
terms at all. It also excludes any term Lingua Forge auto-created while
translating a medium term onto a translated post's language sibling, so a
term that only exists as a translation artifact is never offered back as
selectable vocabulary or clutters the admin's term list.

**No per-term descriptions exist.** The seed list carries names only —
`wp_insert_term()` is called with no description argument — so there is no
canonical explanatory text per medium anywhere in the codebase today. An
admin can add one manually under Artwork → Mediums if desired; it just
isn't part of the shipped default.

## Multilingual terms and syncing

> Source: `Admin\TaxonomyLanguageFilter`, `Admin\LanguageAwareTermsListTable`,
> `Compat\LinguaForge` (`sync_term_across_languages()` /
> `sync_all_terms_across_languages()`), `Admin\ArtworkMediumSync`.

On a multilingual site (Lingua Forge active), every medium term above also
exists in an AI-translated copy for each configured language — a French
site sees "Peinture à l'huile" alongside the admin-curated "Oil Painting."
Translated terms live in the *same* `agnosis_medium` taxonomy as the ones
above, not a separate list — WordPress's own term list has no concept of
"language," so Agnosis scopes what's shown instead.

**Artwork → Mediums shows only your own primary-language vocabulary by
default.** A language dropdown above the list switches to any one
configured language's translated terms at a time; translated terms never
mix into the default view, so the 10 canonical terms above (or however many
an admin has added) stay the list an admin actually manages, whatever the
site's language count. The same dropdown and behavior apply to Posts →
Tags.

**Two ways to fill in missing translations on demand**, both gated behind
the `manage_categories` capability:

- **"Sync translations"** (row action, primary-language terms only) —
  creates any missing translated copy of that one term, across every
  configured language, in one click.
- **"Sync all translations"** (button above the list) — runs the same sync
  across every primary-language term at once, for filling in a whole
  backlog rather than clicking through one term at a time. On a large
  vocabulary this can take more than one click: the action stops cleanly
  after about 20 seconds of work and reports how many terms are left —
  clicking it again picks up exactly where it stopped, never redoing work.

**Editing an artwork's medium after it's published propagates
automatically** to that artwork's already-translated sibling posts. A
"Medium translations" box on the artwork edit screen, plus a matching bulk
action on the artwork list screen, can also trigger that same propagation
on demand — useful for artwork/sibling pairs that drifted out of sync
before the automatic version existed.
