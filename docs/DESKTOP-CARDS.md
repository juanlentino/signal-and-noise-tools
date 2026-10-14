# Desktop cards

The S&N cards on the OpenStation desktop, registered in
`inc/desktop-mode-widgets.php`. Each card reads the site through an ability or a
read-only REST route, refreshes on its own cadence, keeps its last good reading
when a read fails, and says why in plain words.

## SN Queue

Script: `assets/desktop-mode-widget-queue.js`, with its calendar in
`assets/desktop-mode-queue-calendar.js`. Data: the `signal-noise/content-queue`
ability (`inc/abilities-content-queue.php`), read every 60 seconds while the
desktop is in view.

What it shows, top to bottom:

- **The calendar.** Six weeks from the Monday of this week, Monday first, in the
  site's timezone. Every scheduled note is a dot inside its day (past three,
  two dots and "+N"). Today has a solid outline and an underlined number; the
  next-up day is tinted and has a small corner square; empty days are dimmed;
  the 1st of a month shows the month over its number. Notes past the six weeks
  are counted under the grid ("+N more through" the last date). A note whose
  schedule passed before this week is named over the grid ("1 overdue: ...").
  Every mark is a border, outline or underline, so forced-colors mode keeps it,
  and every mark is also in the day's accessible name.
- **The day box.** The selected day's notes, each with its time ("Overdue" when
  the schedule passed) and a link to its editor. It opens on the next-up day
  ("Next up · Tue, Oct 13") and follows it as notes publish. A click or the
  arrow keys pick another day (one tab stop; Home and End go to the week's
  ends); a picked day stays picked through refreshes while it is on the grid.
- **The runway** (see Runway): "Covered through Jan 25, 2027 (103 days)",
  with the scheduled count and the draft count beside it as secondary text.
- **The cadence lines**, one per cluster or gap (see Cadence), up to three,
  then "+N more in Scheduled". Each gap line has a **Planned** button.
- **Drafts**, the newest three the viewer can edit, by title, each linking to
  its editor, with its suggested slot in words on a line under it
  ("suggested: Thu, Oct 30"; the reason is the tooltip; see Suggested slots). An editor
  also sees "+N more"; a contributor sees only their own drafts and no
  site-wide count beside them.
- **Just published**, the last three, and **Open Scheduled**.

### Cadence

The owner's rule (2026-10-12): notes under 2 calendar days apart are a
**cluster**, notes over 7 days apart leave a **gap**. Days are counted in the
site timezone, over the latest published note and every scheduled one, in
date order (an overdue scheduled note included), so a quiet stretch between
the latest published note and the next scheduled one is said too. With
nothing scheduled there is no pair to measure and no line; the card's
"Nothing scheduled" headline says it. A run of notes each under 2 days from the
last is one cluster ("Cluster: 3 notes Oct 20 to Oct 22"); a gap names its
empty days and its length ("Gap: no note Dec 20 to Jan 3, 2027 (16 days
between notes)"). Each line starts with its word, so the amber is never the
only cue, and links to Connections › Scheduled. The thresholds are the
constants `SN_CONTENT_QUEUE_CLUSTER_DAYS` and `SN_CONTENT_QUEUE_GAP_DAYS` in
`inc/abilities-content-queue-cadence.php`, which builds every sentence; the
ability carries them as `cadence`.

This replaces the card's single gap line, which showed only the first hole by
the publishing-cadence flag's statistical threshold. The ability still
returns that `gap` for other readers; the card paints it only for a payload
that has no `cadence` (an older site).

Connections › Scheduled shows the same rule and sentences in a **Cadence**
box, open, on both the native leaf and the classic page, or "No clusters or
gaps from the latest published note through the scheduled ones." Neither twin writes: Planned lives on the card.

### Suggested slots

Phase 2, item 2 (2026-10-13). Each listed draft gets a suggested day and time,
a **suggestion only**: nothing is scheduled, and the only action is the
draft's edit link. One pure function, `sn_content_queue_slots()` in
`inc/abilities-content-queue-slot.php`, builds the day and the sentence for
the card and both Scheduled twins:

- The day is the next open one after today: never under 2 days from a note
  (exactly 2 is allowed), never inside a planned gap.
- The earliest unplanned gap over 7 days (the Cadence walk's own gap) is split
  first: cut into the fewest pieces of 7 days or less, as even as they can be,
  and the draft takes the first cut (the open day nearest it): "splits the
  Oct 25 to Nov 4 gap into 6 and 6 days". Several drafts aimed at one hole
  spread across it (a 20-day hole: 7, 7 and 6 days), never packed at its start.
- Otherwise the last note (or earlier suggestion) plus the median spacing
  between notes, held between 2 and 7 days ("the usual 4 days after the last
  note on Jan 25, 2027"; when held, the reason says the usual would leave a
  gap or make a cluster), walked forward past a planned or taken day ("moved
  to the next open day").
- The time is the median time of day of the scheduled notes in the site
  timezone (the lower middle, so a time the site really uses).
- Drafts are taken newest edit first (ties by ID); each suggestion joins the
  walk for the next, so no two drafts share a day.
- No slot, said in words (on the card: "no slot suggested"): "nothing
  scheduled to read the rhythm from", "fewer than two notes to read the rhythm
  from", or "no open day in the next year".

The ability carries it as `slot` on each `draft_list` row (`day`, `time`,
`label`, `reason`, `text`). On the card the slot is its own 11px line under
the draft's title ("suggested: Fri, Jan 29, 2027", the year only when it is
not this one), so the title keeps the whole row; the sentence is the tooltip.
Connections › Scheduled has a **Suggested slots** box on both twins: the
rule, then each draft's title (a link to its editor) and the same sentence,
"+N more drafts, not given a slot here." past the three, or "No drafts, so no
slot to suggest." / "Drafts not read, so no slot is suggested." Both boxes
(Cadence and Suggested slots) share one read of the schedule.

On the live queue (2026-10-13: 25 scheduled, every gap 4 days or less, the
holiday pause planned) a first draft would be suggested Fri, Jan 29, 2027 at
12:46 PM; there are no drafts today, so the twins say so.

### Runway

Phase 2, item 6 (2026-10-14). How far ahead the schedule covers, in one
sentence built by `sn_content_queue_runway()` in
`inc/abilities-content-queue-runway.php`: "Covered through Jan 25, 2027 (103
days)". The date is the last scheduled note's day; the days are calendar days
from today to that day, both in the site timezone ("(today)" and "(1 day)" at
the short end; the year only when it is not this one). Unknowns are said in
words: "Not covered: nothing is scheduled." and, when every scheduled note is
past its time and not yet published, "Not covered: the last scheduled note,
Oct 12, is overdue." The ability carries it as `runway` (`through`, `days`,
`text`; the first two null for an unknown).

On the card the sentence takes the old depth line's place ("26 scheduled · to
Jan 25, 2027") in the same row, in the card's text color; the scheduled count
and the draft count sit beside it in the muted color ("25 scheduled · 0
drafts", or "Scheduled count not read"). The sentence may wrap; the counts
keep to one line. A payload without `runway` (an older site) keeps the depth
line. Connections › Scheduled has a **Runway** box on both twins, first of
the queue boxes: the same sentence, with the count as its description.

On the live queue (2026-10-14: 25 scheduled, the last on Jan 25, 2027) it
reads "Covered through Jan 25, 2027 (103 days)".

### Planned gaps

A gap can be on purpose (the holidays). **Planned** on a gap line records it
in the option `sn_content_queue_planned_gaps` (as `from|to` days, the newest
20; an older one drops off) through `POST signal-noise/v1/desktop/queue-gap-planned`
(`manage_options`). A gap inside a planned range raises no line on the card
or the Scheduled twins (a range that contains it is enough), and its days
get a quiet dashed outline on the calendar so they do not read as holes.

### Read-only by design

The card reads; it does not edit. The ability is read-only (titles, dates and
edit links; no post body leaves the server), and every action on the card is a
link into wp-admin (the editor, Connections › Scheduled). The one write is the
Planned button above, which stores a pair of dates and nothing else.

### Size

Default 312 × 640, fit to the ordinary card plus one cadence line, and measured live (2026-10-12)
with the real queue: 573 at 265 wide and 549 at 312, chrome included, plus
about 10px of slack, then raised to 640 so one cadence line shows without a
scrollbar (measured with the longest sentence and its Planned button: 627 at
265 wide, where it wraps to three lines, and 585 at 312). A draft's suggested
slot (2026-10-13) is one 11px line under its title, about 15px per listed
draft (45px at the three-draft cap); the ordinary state measured for 640 had
no drafts, so it holds, and a card with drafts and a cadence line scrolls. The
busiest state (a missed note, three cadence lines and "+N more", three drafts with "+N more" and
three Later rows, about 180px more) scrolls inside the card. It was 780, sized
for that busiest state, which left about 200px empty on an ordinary day. The
runway sentence (2026-10-14, 218px at 11px) cannot share its row with the
counts below about 360 wide, so the row is one line taller there (about 14px):
the default went from 640 to 650, BUDGETED, not measured on the desk. The
runway may wrap; the counts never break mid-phrase. The grid's
seven columns are equal shares of the card's width, so they fit any width the
desktop allows (at the 240 minimum a column is about 23px, targets 25px apart);
rows grow a little with the card's width. A card the owner resized keeps its
saved size.
