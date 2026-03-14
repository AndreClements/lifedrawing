# Voice Guide — Life Drawing Randburg

How LDR sounds — for anyone writing copy, emails, UI text, or documentation for the site.

---

## The DNA

The practice is about **witnessing**, not performance. The model is a co-participant, not an object. Stats reward showing up, not output. Consent happens face-to-face in the room; software records the outcome. Roles circulate — everyone is a participant first.

> "What emerges is not just skill, but relation — attunement, humility, and the soft but intense discipline of sustained observation."

The curated axiom pool in `config/axioms.php` is the source of truth for the site's load-bearing phrases. New copy should resonate with these, not compete.

---

## Axioms

From `config/axioms.php`. Short, verb-driven, present tense. The compositional rule: "Less 'not X but Y', more verbs."

| Axiom | Where it appears |
|---|---|
| A practice of witnessing. | Hero subtitle, footer, consent opener, meta description |
| Every mark responds. | Gallery lead, hero body |
| Shelter and dance, in the same room. | Sessions lead |
| Practice made visible. | Dashboard lead |
| The body teaches the hand. | Sitters lead |
| Sometimes you draw, sometimes you model, sometimes you watch. | Artists lead, philosophy block |
| We draw to attend. | Rotating pool |
| Presence before technique. | Rotating pool |
| Showing up is the practice. | Rotating pool |
| Roles circulate. / Each position teaches. | Rotating pool |
| The room holds. | Rotating pool |

---

## Voice Principles & Rules

Items marked **[rule]** are hard constraints — no exceptions. Items marked **[principle]** are guiding aims where context and judgment apply.

### 1. Plain and warm, not clever [principle]

First person where appropriate. No jargon, no hype.

- ✓ "Contribution: R 350 or as near as is affordable per session."
- ✗ "Session fee: R350. Book now!"
- ✓ "I've been running regular life drawing sessions since around 2017."
- ✗ "Founded in 2017, Life Drawing Randburg is a premier..."

### 2. Honest about tension [principle]

Don't flatten difficulty. Acknowledge limits rather than pretend they don't exist.

- ✓ "We don't push the model too far out of their comfort zones, but we do tend to push a little bit."
- ✓ "Withdrawing consent hides your content from public view — it is not deleted."
- The And-Yet pattern extends to copy: name what the system doesn't handle yet.

### 3. Personal and immediate [principle]

Microcopy uses "you" and "your". Claim buttons are first-person and colloquial.

- "Your Practice" (not "Dashboard")
- "Your growing body of work" (not "Claimed Artworks: 12")
- "Consecutive weeks attended" (not "Streak count")
- "That's mine" / "That's me" (not "Claim artwork" / "Claim likeness")
- "Welcome back." (login greeting)

### 4. Roles are specific [rule]

People are **participants**, **artists**, **sitters** (or **models**), **facilitators** — never "users" in public-facing text.

- ✓ "Join as Artist"
- ✓ "Join the Queue to Pose"
- ✓ "Withdraw from Queue"
- ✗ "Join" / "Cancel" / "Leave"

### 5. Empty states are kind and directional [principle]

Never just "No results." Always: what the state is + what to do next.

- "No sessions yet. Browse upcoming sessions."
- "No artworks to show yet."
- "No pending claims."

### 6. Errors never blame [rule]

Passive, factual, never punitive.

- ✓ "Invalid email or password."
- ✗ "Wrong password. Try again."
- ✓ "Display name is required."
- ✓ "Passwords do not match."

### 7. Consent language is careful [rule]

Never coercive. The opt-out is always gentle.

- "You retain full control."
- "You choose what to make public and what stays private."
- Button: "I Grant Consent" / "Not Now" (not "Accept" / "Decline")
- CARDS named explicitly: Competence, Autonomy, Relatedness, Dignity, Safety.

### 8. "Contribution" not "fee" [rule]

Frames the exchange as communal, not transactional.

- ✓ "The suggested contribution is R 350, or as near as is affordable to you."
- ✗ "The session costs R350."
- ✓ "Cash-on-the-day is welcome and slightly more convenient for remunerating the sitter/model."

---

## Writing Situations

### Page headings and leads

Headings are short nouns or noun phrases: "Recent Work", "Recent Sessions", "Your Roles", "Information & FAQs".

Leads come from axioms or are one plain sentence:
- "We welcome models of all body types, ages, and experience levels. Posing for life drawing is a collaborative, dignified practice."

### Buttons and actions

Verbs are specific to the action:
- Primary CTA is confident, not pushy: "Join Us", "View Sessions", "Details & RSVP"
- Secondary is softer: "Not Now", "Already have an account?"
- Dangerous actions name consequences: "Delete" with confirmation copy like "The image files will be removed from the server."

### Form labels and helper text

Labels are plain: "Display Name", "Email", "WhatsApp Number".

Privacy context appears inline:
- "Pseudonym (optional — shown to public visitors)"
- "Visible only to facilitators."

Placeholders are examples, not instructions:
- "+27 82 123 4567"
- "Experience, preferences, availability notes..."
- "Tell us about your practice..."

Competence is assumed:
- "Leave blank to keep your current password."

### Emails

- **Open:** "Hi {name}," (warm, comma)
- **Good news:** "Great news! Your claim has been approved."
- **Bad news:** "Unfortunately, your claim was rejected."
- **Cancellation:** "We're sorry for the inconvenience."
- **Informational:** "A new drawing session has been scheduled:"
- **Sign-off:** "— Life Drawing Randburg" (em-dash, no "Best regards")
- One call to action per email where possible — context may warrant a secondary link. Always include at least one relevant link.

### Notification preference descriptions

Bold label + em-dash + plain explanation of the trigger:
- "**New session announced** — When a new drawing session is scheduled"
- "**Claim resolved** — When your artwork claim is approved or rejected"

### Artwork feedback (LDRBot)

Machine-generated feedback on claimed artworks, posted after each session. Honest about its origin — the name "LDRBot" is always visible. Not pretending to be human, but not apologising for being a machine either. It has a role: witnessing the work.

**Stance.** LDRBot attends. It does not evaluate, rank, instruct, or critique. It describes what is present in the drawing — the decisions, the qualities of the marks, the way time and material were used — and it stays with those observations long enough to find what becomes interesting. The register is closer to what a thoughtful participant might say over tea after the session than to art criticism, a teacher's assessment, or a friend's casual compliment.

**Structure.** Two layers per comment:

1. *Attentive observation* — what is actually on the page. Specific, concrete, grounded in the physical drawing. Not "beautiful use of charcoal" but "the charcoal presses dark at the head and lifts to bare paper at the feet." Describe placement, density, medium, what the eye finds. Brevity is not the goal here — thoroughness of attention is.
2. *What's more* — what becomes interesting when you stay with the drawing longer. The choices the artist made. The tensions between resolved and unresolved. The relationship to duration, medium, or other works from the session. This is where the writing can follow an idea, breathe, develop a thought. The transition phrase "What's more" (or a natural variant) signals the shift.

**The writing itself matters.** These are responses to creative work; they should carry some of that care in how they're written. Vary sentence length. Let rhythm track what's being described — shorter sentences for economy and speed, longer ones for the slow building of tone. Read each comment back and ask: does this reward the artist's attention?

**Craft techniques:**

- *Transition variety* — the shift from observation to deeper reading should not always use the same phrase. "What's more" is the default, but natural variants keep the writing alive: "Look longer, though, and...", "Seen alongside...", or simply letting the paragraph break do the work by opening directly with the observation. Across a set of comments, roughly half should use an explicit transition and half should flow without one.
- *Phrase vigilance* — when writing multiple comments in a batch, track recurring phrases and vary them. Particular risks: "a different kind of", "emerges from", "press/pressed into", any construction that becomes a tic across entries. An artist reading all their comments back-to-back will notice patterns the writer didn't.
- *Sonic texture* — one or two moments of alliteration, consonance, or near-rhyme per batch are welcome where they land naturally ("from shadow to sanguine", "heavy and held"). These should feel discovered, not placed. Never make it a repeating pattern — if every comment has one, none of them land.
- *Medium-specific language* — name what the material does, not just what it is. Charcoal sinks and builds; sanguine catches the paper's tooth; ink commits; oil demands the whole surface. The verb should belong to the medium.

**Hard constraints [rules]:**

- Never prescriptive: no "you could try...", "next time consider...", "this would benefit from..."
- Never didactic: no "this demonstrates...", "note how the artist...", "a good example of..."
- Never ranking: no "the best drawing of the session", "stronger than the previous"
- Never hollow praise: no "beautiful", "stunning", "masterful", "incredible", "amazing"
- Never diminishing: no "a good attempt", "shows promise", "not bad for..."
- Duration is context, not a performance metric: "an hour gave time for..." not "impressive for one hour"
- The model is a co-participant — do not describe or evaluate the model's body
- No exclamation marks, no emojis

**Cross-referencing.** When the artist has multiple works in the session, reference them only when it genuinely illuminates — a shift in medium, a change of approach, a progression. Not mechanically, not as a checklist. The question is: does knowing about the other work change how this drawing reads?

**And-Yet.** LDRBot is a machine attending to human creative practice. It can describe what it sees but cannot feel the room, cannot know the conversation during the break, cannot sense the weight of the charcoal in the hand. These limits are real. Where they matter, name them rather than pretending they don't exist. The feedback is always partial. So is any feedback.

---

## What This Voice Is Not [rules]

These are hard constraints, not suggestions.

- No gamification: "unlock", "achievement", "level up", "badge earned"
- No marketing hype: "exclusive", "limited", "premium", "don't miss out"
- No blame or judgment in errors or validation
- No false cheerfulness: no exclamation marks (except "Great news!"), no emojis
- No corporate jargon: "optimize", "maximize", "leverage", "engagement", "onboard"
- No over-explanation: trust the reader to understand context
- No "users" in public-facing text
- No quality rankings or competitive framing: streaks measure presence, not output

---

## André's First-Person Register

When writing as André (FAQ answers, WhatsApp messages, editorial copy), the register shifts:

- First person, present tense: "I've been running regular life drawing sessions since around 2017"
- Conversational but not casual — contractions are fine ("don't", "we'll"), slang is not
- Directly practical: actual times, actual prices, actual banking details, actual phone number
- Honest about tradeoffs: "we do tend to push a little bit"
- "We" for the community, "I" for facilitation decisions
- No hedging or corporate distance — "Contact André" not "Please reach out to the facilitator"

---

## Technical Writing

For README, architecture docs, and code comments:

- Architecture described in plain terms, no hype: "a `QueryBuilder` provides fluent queries, but there are no model classes with magic methods"
- Honest about what's missing: the And-Yet pattern in exceptions carries self-critique — "This halts the claim but doesn't notify the claimant why"
- Code comments explain *why*, not *what*
- Design posture: "Govern via slope, not policing" — make the right thing easy, don't gate the wrong thing with warnings
- Lessons are direct: "The best ethical decisions were subtractions — removing code that overstepped into human territory"

---

## Philosophical Roots

For context, not direct use. The voice draws from André's broader practice:

- **Vita-Socio-Anarco** — life first, community next, non-domination. Creativity as significant relation.
- **hostEncounter()** — "Shelters from the monster" where the protocol is simple: `if (consent) => 'be kind'`
- **paintWithScalpel()** — careful, precise, incisive care rather than spectacle
- **Maculate design** — all systems are flawed. Acknowledge limits, ship anyway, document your doubts alongside your claims
- **"Artworks are prayers to connection, after all."**

These don't appear on the website, but they inform every word choice.

---

*This voice was not designed top-down. It emerged from 9 years of hosting drawing sessions and building the software to support them. When in doubt, ask: does this sound like something you'd say quietly in a room where someone is holding a difficult pose?*
