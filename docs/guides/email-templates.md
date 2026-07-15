# Transactional email templates

> Mirrors the prose content of Agnosis's admission, departure, and community-vote emails. HTML markup/styling is omitted — only the actual copy, with `%s`/`%1$s`-style placeholders left as written in source. Sources: `includes/Artist/AdmissionNotification.php`, `includes/Artist/DepartureNotification.php`, `includes/Artist/CommunityCapNotification.php`. All strings are translatable (`__()`/`esc_html__()`). If any of this copy changes, update the relevant PHP file first and mirror the change here.

Every HTML email in this set shares the same header (`EmailBranding::header_html()`) and footer line: **"{site name} — art blooming out of oblivion."**

## Admission (`AdmissionNotification.php`)

### Confirm your application (`build_confirm_body()`)

Sent immediately on `apply()` — a single link, nothing else, since nobody but the applicant has seen anything yet (double opt-in).

> Hi {display name},
>
> One last step before {community name} can review your application: confirm this is really your email address.
>
> **[Confirm my application]**
>
> If you didn't apply, simply ignore this email — nothing happens until this link is clicked.

### Application received (`build_acknowledgment_body()`)

Sent once the applicant confirms; opens the application for community review.

> Hi {display name},
>
> Thank you for applying to {community name}. Your application has been received and is now open for community review.
>
> The community has {window} days to vote. We will let you know the outcome by email.

### Vote request (`build_vote_email_body()`)

Sent to each active artist (or queued for digest-mode artists — see `VoteDigest`).

> Hi {voter name},
>
> {applicant name} has applied to join {community name}. You have {window} days to vote.
>
> **Bio** *(if provided)* — {bio text}
>
> **Portfolio:** {portfolio URL} *(if provided)*
>
> **Statement** *(if provided)* — {statement text}
>
> **[✓ Vote YES]**  **[✕ Vote NO]**
>
> You can change your vote at any time within the voting window.

### Welcome (`build_welcome_body()`)

Sent once the community admits the applicant — the richest email in the set. Deliberately does *not* lead with credentials or a password reset: everything is email-based and needs no WordPress account.

> Hi {display name},
>
> The {community name} community has admitted you as an artist. Welcome!
>
> Your gallery: {gallery URL}
> Your submissions: {my-submissions URL}
>
> **How to share your work — send an email to:**
> *(one row per configured address, e.g. Artwork, Biography, Event, Photo, Pure, Replace, Remove)*
>
> **Subject line conventions:**
> - Artwork (default) — any subject
> - `[Biography]` — biography update
> - `[Event]` — event announcement
> - `[Photo]` — publish as-is, no AI enhancement (fallback for mail apps without To: aliases)
> - `[Pure]` — publish exactly as sent, no AI at all (fallback for mail apps without To: aliases)
>
> **To leave the network and delete your account:** *(if a goodbye address is configured)*
> {goodbye address}
> Send any email (no attachment needed). You will receive a confirmation link — nothing is deleted until you click it.
>
> No login is needed to work with Agnosis — everything above happens by email. If you'd also like to use the site's optional online features (like previewing a submission before it publishes), you can set up a password whenever you like using [password recovery].

### Application expired — applicant (`build_expiry_applicant_body()`, plain text)

> Hi {display name},
>
> Thank you for applying to {community name}. Unfortunately, your application did not receive enough votes within the voting window.
>
> You are welcome to apply again in the future.
>
> {community name}

### Application expired — community (`build_expiry_community_body()`, plain text)

> The application by {display name} has closed without reaching the admission threshold. No action required.
>
> {community name}

## Departure (`DepartureNotification.php`, plain text)

### Confirm your departure (subject: "Confirm your departure from {site}")

> Hi {name},
>
> We received a request to remove your account and all your published work from {site}.
>
> If you made this request, click the link below to confirm. This action is permanent and cannot be undone.
>
> {confirm link}
>
> If you did not make this request, you can ignore this email — your account remains unchanged.
>
> — {site}

### Artist departed — community notice (subject: "An artist has left {site}")

> {artist name} has confirmed their departure from {site}.
>
> Their account and all published work have been permanently deleted.
>
> — {site}

### You've left — departing artist (subject: "You've left {site}")

> Hi {name},
>
> This confirms that your account and everything you published on {site} have been permanently deleted, as you requested.
>
> Nothing tied to you — your artwork, biography, events, or account details — is stored on {site} anymore.
>
> If you didn't request this, please contact the site admin right away.
>
> — {site}

### Suspended, with reinstatement date

> Hi {name},
>
> Your membership at {site} has been temporarily suspended until {date}.
>
> You will be automatically reinstated on that date. If you have questions, please contact the site admin.
>
> — {site}

### Suspended, indefinite (subject: "Your membership at {site} has been suspended")

> Hi {name},
>
> Your membership at {site} has been suspended.
>
> If you have questions, please contact the site admin.
>
> — {site}

### Reinstated (subject: "Your membership at {site} has been reinstated")

> Hi {name},
>
> Your temporary suspension at {site} has ended and your membership has been reinstated. You can now log in and submit work as before.
>
> — {site}

### Community removal vote open (subject: "Community removal vote open at {site}")

> Hi {name},
>
> The {site} community has opened a vote to remove a member. The vote closes on {date}.
>
> Cast your vote:
>
> Vote YES (remove): {yes link}
>
> Vote NO (keep): {no link}
>
> A majority of active members (more than 50%) must vote yes for the removal to proceed. You can change your vote by clicking the other link before the deadline.
>
> — {site}

### Community removal vote passed (subject: "Community removal vote passed at {site}")

> The community removal vote has closed with a majority in favor.
>
> The artist's account and all published work have been permanently deleted from {site}.
>
> — {site}

### Community removal vote did not pass (subject: "Community removal vote did not pass at {site}")

> The community removal vote has closed. The required majority was not reached and the artist's membership remains active.
>
> — {site}

## Community size-cap votes (`CommunityCapNotification.php`, plain text)

### Vote open (subject: "Community size-cap vote open at {site}")

> Hi {name},
>
> The {site} community has opened a vote to change the membership size cap to {proposed cap, or "no limit"}. The vote closes on {date}.
>
> A strict majority of active members (more than 50%) must vote yes for the new cap to be adopted. Sign in to your account to cast your vote.
>
> — {site}

### Cap changed (subject: "Community size cap changed at {site}")

> The community voted to change the membership size cap to {new cap, or "no limit"} (proposal #{id}). The new cap is now in effect.

### Proposal did not pass (subject: "Community size-cap proposal did not pass at {site}")

> A community vote to change the membership size cap (proposal #{id}) closed without a majority. The cap is unchanged.
