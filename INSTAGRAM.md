# Instagram Playbook — Life Drawing Randburg

How drawings from a session reach [@lifedrawingrandburg](https://www.instagram.com/lifedrawingrandburg/),
and the voice they carry when they get there. This is the standing reference — consulted every
session — that pairs with the tool `tools/instagram-prep.php`.

The posture is the same as everything else here: **witnessing, not performance.** We post the
*work*, framed as a record of a room where people showed up to draw. No "best of" ranking, no
hype, and the model is held with dignity throughout. Social amplification was deferred for a long
time on purpose; we move gently.

---

## The process

The Instagram pipeline is a **parallel consumer of the same local staging pass** we already use
to import session photos — it never touches the website upload/import code, and it never mutates
the staged originals.

1. **Resolve the prod session id.** Session ids differ between local and production — always use
   the **production** id. Look it up with the explicit date, e.g.
   `php tools/ldrbot-query.php --date=YYYY-MM-DD` on prod (or a direct query), never `CURDATE()`.
2. **Stage the photos** off the phone with `tools/stage-phone-photos.ps1` (add the session's
   `{ id; date }` to its `$map`). Originals land in `storage/photo-import/{id}/`.
3. **Scaffold:** `php tools/instagram-prep.php --session={id} --scaffold` →
   `storage/instagram/{id}/contact-sheet.jpg`, a starter `manifest.json` (every photo listed),
   and a blank `curation.md` worksheet.
4. **Curate** (see below) — fill `curation.md`, then edit `manifest.json` to the chosen set,
   order, and per-image treatment.
5. **Render:** `php tools/instagram-prep.php --session={id}` (use `--dry-run` first) →
   `storage/instagram/{id}/out/` with `01.jpg…`, `caption.txt`, `hashtags.txt`, `alt-text.txt`,
   `preview.jpg`.
6. **Post** the carousel to @lifedrawingrandburg manually; paste `caption.txt`, paste
   `hashtags.txt` as the **first comment**, and the lines of `alt-text.txt` into each slide's alt
   field. Optionally record it: `--mark-posted="<post url>"`.

The login stays with the facilitator. Credentials never live in the repo, the manifest, or the
ledger.

---

## Shooting for the feed

The same photos feed both the website and Instagram, so shoot for both at once — **consistent
margin, square-on**:

- **Whole sheet, small even margin.** Never clip a mark — clipping is unrecoverable; margin gives
  the crop step room to work.
- **Square to the drawing.** A rectangular crop *cannot* correct keystone/perspective (only a
  small `rotate` for tilt is available). Square-on in the moment is the only real fix.
- **Flat, even light**, no glare or hotspots — keeps the tonal adjustment light and keeps the
  website original clean too.
- One drawing per frame; shoot in pose order where practical (it helps the warm-up → sustained arc
  read later).
- **Walk the room in a consistent artist order.** Photographing the boards in the same sequence
  every pose means the *n*-th photo in each pose cluster is the same artist — the signal the
  curation step uses to guarantee coverage across hands without ever needing names (see Curation,
  Stage 3).

---

## Curation

**Collaborative curation.** Claude and the facilitator curate *together*. Claude offers an
independent first reading — a fresh visual response that loosens reliance on habitual preferences.
The facilitator answers with lived knowledge of the room, the participants, consent, and their own
artistic judgment. Through conversation they arrive at the final selection, order, and treatment.
The aim is **not** to eliminate bias or transfer authority, but to place different perspectives in
relation — neither is treated as neutral or final by default. *Every selection responds; roles
circulate.* The matrix is a shared instrument for that conversation, not an arbiter.

We are **not** ranking "best drawings." We assemble a carousel that **witnesses the session's
range** — the arc of poses, the variety of hands and media, the room as it was. Better six to
eight considered images than a dump of twenty.

**Stage 1 — Gates (any "no" cuts the image):**
- **Legible** — the figure reads at Instagram thumbnail size.
- **Clean enough** — in focus; glare fixable with levels, tilt with a small rotate, framing with a
  crop. **Keystone/perspective is not fixable** — badly keystoned shots are out.
- **Dignity** — holds the model with dignity; no crop or emphasis that objectifies the figure.
  When in doubt, cut. (Hard rule.)
- **Not a near-duplicate** — drop the weaker of two near-identical shots unless they're a
  deliberate contrast pair.
- **A drawing** — room/candid snapshots are not the carousel (keep them aside, or use at most one
  as deliberate context).

**Stage 2 — Curate for the set (coverage, not competition).** Aim to span:

| Axis | Span to aim for |
|------|-----------------|
| Pose arc | warm-up gesture → medium → sustained study |
| Medium | charcoal / graphite / ink / conté / mixed |
| Approach | gestural · structural · tonal |
| Authorship | prefer **different hands** so it reads as a room, not one artist |

**Tie-break (1–3 each, only when more than ten pass the gates):**
- **Presence** — does the drawing feel alive / decided? ("Every mark responds.") *Not* technical
  merit.
- **Carries small** — legibility at thumbnail.
- **Adds variety** — fills an axis the set is missing.
- **Photo cleanliness** — focus, glare, tilt.

**Stage 3 — Authorship coverage (a deliberate second pass).** Stage 2 *aims* for different hands;
this pass *guarantees* it. Once the aesthetic selection is provisionally set, map every selected
image to the artist who drew it, then ensure **each artist present in the session has at least one
image** — swapping an under-represented artist's strongest sheet (held to the same gates) in for a
redundant pick where needed. It reads the room as a room, not a highlight reel of one or two hands.

Identifying the artist **without names**: because the facilitator shoots in a consistent
walk-order (see *Shooting for the feed*), the *n*-th photo in each pose's cluster is the same
artist. Line the clusters up by position and position *n* reads down as one hand across the whole
session. A distinctive treatment recurring at the same position (e.g. the same person working in
red across two poses) is a good confirmation the order held. The mapping is a **proposal the
facilitator confirms** with room knowledge — authoritative, because walk-order can break if someone
arrives late, leaves, or skips a pose (unequal cluster sizes are the tell). Record the artist→image
map alongside the selection in `curation.md`.

**Order as a small narrative:**
- **Image 1 is the feed thumbnail** — the most legible, inviting piece (the hook).
- Then walk the arc — gestures → sustained studies — closing on a quiet, considered piece.
- Interleave media and hands so adjacent slides contrast rather than repeat.

Each session records the outcome in `storage/instagram/{id}/curation.md` — the set, the scores,
in/out and the one-line why. It's the record of *why this set* and of the conversation that chose
it.

---

## Captions

Same spirit as the LDRBot feedback in `VOICE.md`: attend to the work, witness the room, never
perform.

- **Open with / weave in an axiom** — *"A practice of witnessing." / "Every mark responds." /
  "Presence before technique." / "Roles circulate."*
- **Name the session** by day and format; gesture at the pose arc (warm-ups → sustained). Never a
  blow-by-blow, never a ranking.
- **Never describe or evaluate the model's body, or the model at all.** "With thanks to our model"
  is the ceiling, and only if it's natural. No artist names (a deliberate choice — the post is
  about the work and the room).
- **No hype words** — never "best", "stunning", "beautiful", "masterful". No exclamation marks
  (the one allowed elsewhere is "Great news!"), no emojis.
- **The caption body ends with the site link:** `lifedrawing.andresclements.com/randburg`.
- **Hashtags go in the first comment** (`hashtags.txt`), not the caption — keeping the caption
  clean and the last line the site link.

**Alt text** follows the same rule as the captions and LDRBot: describe the *drawing* — medium,
composition, the figure's action, the quality of the marks — never the body. One sentence per
slide.

**A deeper source for the register.** The essay *Art and the Weight of Relation*
([`AndreClements/README` → `docs/essays/art_and_the_weight_of_relation.md`](https://github.com/AndreClements/README/blob/main/docs/essays/art_and_the_weight_of_relation.md))
is the philosophical ground under "witnessing, not performance": a work's value is *relation,
configured* — what a making leaves behind — and the viewer is the field the work is weighed in.
Captions may draw **accessible axioms** from it (e.g. *"What a drawing keeps is the relation, not
the hour."*) but never its **vocabulary** — no "relational mass", no physics, no essay-jargon in a
caption. The idea travels; the terminology stays home.

---

## The repost-safety ledger, and an honest And-Yet

`tools/instagram-prep.php --mark-posted=URL` records each posted image's content hash in
`storage/instagram/posted-ledger.json`. On render, the tool warns if a chosen image is already in
the ledger — so we don't repost the same drawing.

**This is repost-detection, not yet a model→post trace.** Production doesn't persist the source
hash, and `process_images.php` rewrites uploaded originals, so a hash taken on prod later won't
match. To trace which posts a withdrawn model appears in — the documented model-takedown gap in
`AuthService::withdrawConsent()` — we'd need to store the staged source hash at import time. Until
then, takedowns from Instagram are handled manually, and we say so plainly. The feedback is always
partial; so is the trace.
