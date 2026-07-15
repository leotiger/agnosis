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
