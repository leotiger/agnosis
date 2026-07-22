# Technical reference docs

Internal architecture/behavior write-ups for contributors — how a piece of
the plugin actually works under the hood, as opposed to `docs/guides/`
(which mirrors literal in-plugin copy shown to artists/visitors). These are
not generated from source and are not shown anywhere in the plugin itself;
they exist so a non-obvious cross-file behavior can be understood without
re-deriving it from a fresh read of the code every time.

**Source of truth is always the PHP.** Each file names the class(es)/method(s)
it describes. If that code changes in a way that changes the behavior
described here, the doc needs a matching update — nothing keeps these in
sync automatically.

- [`resend-and-merge-behavior.md`](./resend-and-merge-behavior.md) — how a resent email is matched to an existing post and what actually gets merged vs. replaced vs. accumulated, per post type/lane (`Publishing\PostCreator::handle()` and its duplicate/singleton-resolution helpers)
