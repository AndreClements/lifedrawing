# Case Study: Life Drawing Randburg — methodology_CI in Practice

## The Prompt

> Look at the projects around this one and specifically C:\Users\User\Documents\GitHub\README\README.md and my website at andresclements.com, build the necessary axiological and technical scaffolding, applying my methodology_CI to offer value to lifedrawing randburg participants, artists, and models alike, e.g i can upload snapshots of drawings, artists can claim after sessions to build and manage their own profile, and all of above can use it like a strava-for-artistry. eventually we will probably want to use it as template for a larger modular community 'artistry caffe' site where people can define and manage their own 'tables' conceptually and technically, php /LAMP stack style, state of the art but pushing boundaries and conventions without getting carried away or over-engineering, light on dependency chains. Look at the saffca and netverse projects also. And go! B-) - manage context and token load across sessions etc. ellegant architecture, SOLID, tray-catch-and-yet etc. Try to minimise how much i need to approve stuff like sql and grep etc. while staying responsible and accountable, clear DODs, objectives etc. Have fun.

## What Was Built

A complete web application in a single extended session (~6 hours of active collaboration), from empty directory to working site with 72 files and 6,400 lines of code.

### The Stack

- Custom PHP 8.2+ micro-kernel (~15 core files, no framework)
- MySQL with 6 migration files (2 core + 4 module)
- HTMX for interactivity (single `<script>` tag, no build pipeline)
- Vanilla CSS with custom properties
- PSR-4 autoloading via Composer (the only dependency)

### What It Does

- **Sessions**: Facilitator creates drawing sessions, participants join with roles (artist, model, observer)
- **Gallery**: Facilitator uploads snapshots of drawings from sessions
- **Claims**: Artists claim "I drew this", models claim "I modelled for this" — facilitator approves
- **Profiles**: Claimed works build an artist's public portfolio over time
- **Dashboard**: Attendance streaks, session history, milestone tracking, weekly heatmap
- **Landing page**: Public face explaining LDR to newcomers

## How Methodology_CI Manifested

### CARDS in Code

| Facet | How it became architecture |
|---|---|
| **Competence** | No quality rankings, no voting. Stats track *engagement* (sessions attended, streaks) not output volume or peer evaluation. |
| **Autonomy** | Claims are opt-in. Visibility is user-controlled. Artists decide what appears on their profile. |
| **Relatedness** | Session-centric design. Artworks belong to sessions first, then get claimed into profiles. The session is the container for shared experience. |
| **Dignity** | `DignityException` halts (not warns). Artworks are "responses" not "depictions". No comments on body. |
| **Safety** | CSRF on all forms. Prepared statements only. Image validation. Rate limiting middleware. |

### Try-Catch-And-Yet

`AppException` carries an `andYet` field — a readonly string for honest self-critique logged alongside the error:

```php
public readonly string $andYet = '';
// "This halts the claim but doesn't notify the claimant why.
//  Future: add a dignity-preserving notification explaining the rejection."
```

The catch block doesn't just handle the error — it confesses what it fails to handle. This changes the epistemic posture of the entire error system.

### Parametric Authorship

"Govern via slope, not policing."

- Claiming is one button press. Uploading requires facilitator role.
- There's no `if ($user->isAllowed('claim'))` permission check. The architecture makes the desired behaviour the path of least resistance.
- Stats reward attendance, not output volume. The slope encourages showing up.
- Default visibility is `public` — consent happens in the room, not through software gates.

### The Consent Decision

During the build, a key conversation with Je'anna (CARDS collaborator) surfaced this principle: **consent for session snapshots belongs in the room**, face-to-face during check-in. The app doesn't replicate that social contract — it records its outcome.

This led to a simplification: default artwork visibility changed from `'session'` (requiring claim-approval to become visible) to `'public'` (visible immediately, claiming is for profile-building). The original design over-engineered consent by making software enforce what should be a human interaction.

This is methodology_CI in practice: the methodology doesn't just produce code — it produces *decisions about what not to code*.

## "Did You Have Fun?"

At the end of the build session:

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

---

*Life Drawing Randburg. v0.0.1. February 2026.*
*Built with [methodology_CI](https://github.com/andresclements/README/blob/main/docs/methods/METHODOLOGY_CI.md).*
