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
  site's timezone. Every scheduled note is a dot inside its day. Today has a
  solid outline and an underlined number; the next-up day is tinted; empty days
  are dimmed; the 1st of a month shows the month over its number. Notes past the
  six weeks are counted under the grid ("+N more through" the last date).
- **The day box.** The selected day's notes, each with its time and a link to
  its editor. It opens on the next-up day ("Next up · Tue, Oct 13"). A click or
  the arrow keys pick another day (one tab stop; Home and End go to the week's
  ends).
- **The figures.** How many notes are scheduled and the date the queue runs to,
  beside the draft count.
- **The gap line**, when the schedule has a hole by the publishing-cadence
  flag's own threshold, with a **Planned** button.
- **Drafts**, the newest three by title, each linking to its editor.
- **Just published**, the last three, and **Open Scheduled**.

### Planned gaps

A gap can be on purpose (the holidays). **Planned** on the gap line records it
in the option `sn_content_queue_planned_gaps` (as `from|to` days, the newest
20) through `POST signal-noise/v1/desktop/queue-gap-planned`
(`manage_options`). A planned gap no longer raises the gap line, and its days
get a quiet dashed outline on the calendar so they do not read as holes.

### Read-only by design

The card reads; it does not edit. The ability is read-only (titles, dates and
edit links; no post body leaves the server), and every action on the card is a
link into wp-admin (the editor, Connections › Scheduled). The one write is the
Planned button above, which stores a pair of dates and nothing else.

### Size

Default 312 × 600, measured live with the real queue (2026-10-12). The grid's
seven columns are equal shares of the card's width, so they fit any width the
desktop allows (at the 240 minimum a column is about 23px, targets 25px apart);
rows grow a little with the card's width. A card the owner resized keeps its
saved size.
