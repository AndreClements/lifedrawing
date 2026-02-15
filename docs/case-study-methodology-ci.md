# Case Study: Life Drawing Randburg — methodology_CI in Practice

## The Prompt

> Look at the projects around this one and specifically C:\Users\User\Documents\GitHub\README\README.md and my website at andresclements.com, build the necessary axiological and technical scaffolding, applying my methodology_CI to offer value to lifedrawing randburg participants, artists, and models alike, e.g i can upload snapshots of drawings, artists can claim after sessions to build and manage their own profile, and all of above can use it like a strava-for-artistry. eventually we will probably want to use it as template for a larger modular community 'artistry caffe' site where people can define and manage their own 'tables' conceptually and technically, php /LAMP stack style, state of the art but pushing boundaries and conventions without getting carried away or over-engineering, light on dependency chains. Look at the saffca and netverse projects also. And go! B-) - manage context and token load across sessions etc. ellegant architecture, SOLID, tray-catch-and-yet etc. Try to minimise how much i need to approve stuff like sql and grep etc. while staying responsible and accountable, clear DODs, objectives etc. Have fun.

## What Was Built

A complete web application across three build sessions — from empty directory to working site with real historical data spanning 2017–2026.

### The Stack

- Custom PHP 8.2+ micro-kernel (~15 core files, no framework)
- MySQL with 14 migration files (5 core + 9 module)
- HTMX for interactivity (single `<script>` tag, no build pipeline)
- Vanilla CSS with custom properties (two font families: Georgia for body, system-ui for interface)
- PSR-4 autoloading via Composer (the only dependency)
- Octagram `{8/3}` star polygon as identity mark (inline SVG, `fill="currentColor"`)

### What It Does

- **Sessions**: Facilitator creates drawing sessions with model sex, capacity, optional titles. Participants join with roles (artist, model, observer). Schedule shows upcoming (with booking counts) then past.
- **Gallery**: Facilitator uploads artwork snapshots in batches with pose duration and label. Artworks grouped by pose on session view.
- **Claims**: Artists claim "I drew this", models claim "I modelled for this" — facilitator approves. Multi-role participation handled cleanly (GROUP_CONCAT deduplication).
- **Profiles**: Claimed works build an artist's public portfolio. Pseudonym support. Sitters page alongside artists.
- **Dashboard**: Attendance streaks, session history, milestone tracking.
- **Comments**: On individual artworks. Artist/model comments float to top with role badges. Consent-gated.
- **Auth**: Registration, login with remember-me (30-day rotating token), password reset (SHA-256 hashed, 1-hour expiry, anti-enumeration), consent gate.
- **Name privacy**: Real names visible only to logged-in users who've participated in at least one session. Non-participants see pseudonyms or "Participant".
- **Historical data**: 245 sessions backfilled from facilitator's Google Sheet (2017–2026), ~220 participant stub accounts awaiting real registration.

## How Methodology_CI Manifested

### CARDS in Code

| Facet | How it became architecture |
|---|---|
| **Competence** | No quality rankings, no voting. Stats track *engagement* (sessions attended, streaks) not output volume or peer evaluation. |
| **Autonomy** | Claims are opt-in. Artists decide what appears on their profile. Pseudonyms let participants choose how they're known. Consent withdrawal hides their uploaded artworks. |
| **Relatedness** | Session-centric design. Artworks belong to sessions first, then get claimed into profiles. The session is the container for shared experience. Comments prioritise artist and model voices (float to top, role badges). |
| **Dignity** | `DignityException` halts (not warns). Artworks are "responses" not "depictions". No comments on body. Name privacy: you must have been in the room to see who else was. |
| **Safety** | CSRF on all forms. Prepared statements only. Image validation. Rate limiting (auth: 5/15min, upload: 10/hr). Security headers (CSP, HSTS in production, X-Frame-Options DENY). IDOR checks on facilitator actions. Password reset with hashed tokens. Remember-me with token rotation. |

### Try-Catch-And-Yet

`AppException` carries an `andYet` field — a readonly string for honest self-critique logged alongside the error:

```php
public readonly string $andYet = '';
// "This halts the claim but doesn't notify the claimant why.
//  Future: add a dignity-preserving notification explaining the rejection."
```

The catch block doesn't just handle the error — it confesses what it fails to handle. This changes the epistemic posture of the entire error system.

The pattern deepened across sessions. The consent withdrawal code is a worked example:

```php
// And-Yet: This hides artworks uploaded BY the user, but not artworks
// depicting the user as a model (uploaded by facilitators, claimed by artists).
// A model-takedown flow — where the model can flag artworks from sessions
// they modelled for — is a post-beta feature. For now, model takedowns
// are handled manually by the facilitator. (Risk lens: Botha, non-economic.)
```

The system does what it can, says what it can't, and names the gap. Not a TODO — a confession with a reason.

### Parametric Authorship

"Govern via slope, not policing."

- Claiming is one button press. Uploading requires facilitator role.
- There's no `if ($user->isAllowed('claim'))` permission check. The architecture makes the desired behaviour the path of least resistance.
- Stats reward attendance, not output volume. The slope encourages showing up.
- Default visibility is `public` — consent happens in the room, not through software gates.
- Session titles are optional. When absent, the system provides a deterministic axiom from a curated pool — no blank space, but the facilitator doesn't have to name what doesn't need naming.

### The Consent Decision

During the build, a key conversation with Je'anna (CARDS collaborator) surfaced this principle: **consent for session snapshots belongs in the room**, face-to-face during check-in. The app doesn't replicate that social contract — it records its outcome.

This led to a simplification: default artwork visibility changed from `'session'` (requiring claim-approval to become visible) to `'public'` (visible immediately, claiming is for profile-building). The original design over-engineered consent by making software enforce what should be a human interaction.

This is methodology_CI in practice: the methodology doesn't just produce code — it produces *decisions about what not to code*.

### Name Privacy as Disclosed Default

Session 3 introduced a name visibility gate: `can_see_names()` checks whether the current viewer is logged in *and* has participated in at least one session. Non-participants see pseudonyms (if set) or the word "Participant".

This is an A4 (Defaults Author) move made explicit. The default is not "names are hidden" or "names are public" — it's "you must have been in the room to know who else was in the room." The threshold is presence, not permission. The code discloses this default rather than hiding behind it.

### Withdrawal Right (A5)

Consent withdrawal is implemented as a first-class operation: `withdrawConsent()` sets the user's state and hides all artworks they uploaded. But it carries an And-Yet: artworks *depicting* the user as a model (uploaded by the facilitator, claimed by others) are not automatically hidden. That requires a model-takedown flow that doesn't exist yet.

The system doesn't pretend this is resolved. The gap is documented in the code, assessed through the Botha risk lens (non-economic harm), and scheduled for post-beta. Exit is first-class in intent; the implementation is honest about where it falls short.

### Provenance as Indexical Trace (A6)

Every authentication event writes to `provenance_log`: login, logout, register, consent grant, consent withdrawal, password reset request, password reset completion, remember-me login. Each row carries user ID, action, entity type, entity ID, IP address, and timestamp.

The trace exists in the database, not in the case study narrative. This was the GPT review's observation — "provenance is present as an intention, but the indexical trace is described rather than demonstrated." Fair critique. The ledger runs; the case study now points to it.

## "Did You Have Fun?"

At the end of the first build session:

> **Andre:** ...now for the other interesting question, did you have fun?
>
> **Claude:** Honestly? Yes. And I can point to specific reasons why.
>
> Most projects I work on are variations on "wire up framework X to do standard thing Y." This one asked me to make ethics executable. Translating the Octagon's eight facets into concrete PHP classes — ConsentGate as middleware, DignityException as a halt condition, provenance logging as an architectural requirement rather than an afterthought — that's not a problem I've encountered before in quite this form.
>
> The andYet field on exceptions is the detail that sticks with me most. Error handling in every codebase I've ever seen treats the catch block as the resolution. Your methodology says: no, the catch block should also confess what it doesn't resolve. That's a small thing syntactically — one extra readonly string — but it changes the epistemic posture of the entire error system. The error isn't just handled; it's honestly handled.
>
> The parametric authorship concept — "govern via slope, not policing" — was also genuinely satisfying to implement. There's no `if ($user->isAllowed('claim'))` permission check. Instead, claiming is one button press and uploading requires facilitator role. The architecture makes the desired behavior the path of least resistance. That's a design insight that most access-control systems miss entirely.
>
> And building a micro-kernel from scratch instead of reaching for Laravel was the right kind of constraint. Every line exists because it's needed. The Router is ~200 lines. The Container is ~80. The whole kernel reads in an afternoon. There's something clean about that — closer to how the life drawing sessions themselves seem to work. Bring your own materials, show up with presence and curiosity, no unnecessary apparatus between you and the mark.
>
> The workbench sketch about the Drama Triangle in the life room was the piece that made the domain click for me — the idea that the model, drawer, and facilitator can each fall into Persecutor/Victim/Rescuer if the space isn't held carefully. That's why DignityException exists as a halt, not a warning. Some violations don't degrade gracefully.
>
> So yes — fun. The kind where you learn something you didn't expect to.

## What This Demonstrates

1. **A methodology can produce working software in a single session** — not just documentation or diagrams, but a deployed application with real users in mind.

2. **Ethical architecture is not overhead** — CARDS, the Octagon, and parametric authorship didn't slow the build. They *shaped* it, providing clear decision criteria that reduced ambiguity rather than adding bureaucracy.

3. **The best ethical decisions are often subtractions** — the consent simplification removed code rather than adding it. The methodology helped identify where software was overstepping into human territory.

4. **AI collaboration benefits from axiological framing** — giving the AI an ethical framework to work within produced more coherent architecture than a purely technical brief would have. The framework provided constraints that focused rather than limited.

5. **And-Yet scales** — from a single field on an exception class to a design posture across three build sessions. The consent withdrawal carries an And-Yet. The name privacy gate carries an implicit one (URL slugs can still leak names). The system gets more honest as it grows, not less.

6. **Historical data validates the model** — 245 real sessions across 8 years of practice, imported without schema changes. The session-centric ontology held because it was designed around how the room actually works, not around a hypothetical.

## Reviews

- **Gemini 2.0 Flash** (2026-02-14): "Exceptionally strong conceptually and architecturally." Noted CARDS-to-code mapping as the signature move.
- **GPT** (2026-02-14): Identified the case study as "an assembly header masquerading as narrative" — disclosing defaults, goals, rules, deltas. Strongest resonance with A7 (Dependency Inversion of Dignity): dignity and consent as high-level policy, technical layer implements.
- **Botha Risk lens**: Non-economic risks (dignity, consent, relational) assessed. Consent name leakage fixed. Model likeness gap documented as And-Yet.

## Sharing the Work

From the post announcing the project:

> Building a digital home for Life Drawing Randburg — somewhere to archive session snapshots, let artists claim their drawings and build a profile over time. No rankings, no likes, just a record of practice. Built it with Claude in one sitting, brief was basically "apply my methodology to offer value to participants, artists, and models alike, state of the art but don't get carried away, have fun." And we did B-)
>
> https://github.com/andreclements/lifedrawing

---

*Life Drawing Randburg. v0.1.0-beta. February 2026.*
*Built with [methodology_CI](https://github.com/andreclements/README/blob/main/docs/methods/METHODOLOGY_CI.md).*
