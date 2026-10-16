# Leaf anatomy: status line, Setup card, How this works

Measurement › Analytics (2026-10-16) is the reference leaf. Its structure is
three kit helpers in `inc/openstation-kit-anatomy.php`. Use the parts a leaf
needs; a leaf is not incomplete without one.

## `snt_kit_status_line( array $chips, string $door_html = '' )`

One row of chips, the door (`snt_kit_door()`) on the right. A chip is
`['tone' => ok|warn|bad|neutral, 'label' => ..., 'title' => ...]`.

- **Use** when the leaf has state a reader checks at a glance: a pipeline,
  a connection, a count of things that can go wrong. It sits in the leaf's
  first card, never in a fold.
- **Not** for a leaf of settings or reference with no state. No chips beat
  decorative chips. A problem also gets an open `snt_kit_notice()` there.

## `snt_kit_setup_card( string $title, array $folds, string $description = '' )`

One card of folds, each `['title', 'summary', 'body', 'open' => bool]`.

- **Use** for settings and reference that change once in a while: keys,
  endpoints, tuning, mirrors of settings that live elsewhere.
- `summary` is the setting's current value ("Configured", "45 days,
  relaxed"). It paints on the closed line, so closing hides detail, never a
  fact. No summary means the fold paints open.
- `open` is true when something inside needs the reader now: unset, failed
  to read, incomplete.
- **Not** for status, warnings or a control in regular use (a Revoke, an
  enroll form). Those stay open in their own card.

## `snt_kit_how_fold( string $summary, string $body )`

A small, closed "How this works" at the foot of a card, for long
explanatory prose the card would otherwise lead with.

- **Use** when a card needs more than two sentences of explanation.
- **Not** for a hint a control needs to be used safely; that sits beside
  the control, or in its `confirm`.
- Fold memory is keyed by heading per window (`assets/os-host.js`), so two
  How this works folds on one leaf share one remembered toggle. Keep one per leaf.

## Layout

The Analytics leaf is: the status card, a full-width card if one is wide
(the Edge card), then `snt_kit_grid()` of the cards that belong together.
Three across is a design decision, not a quota: put three on a row only when
the three are read together. Two that belong together share a row; a lone
wide table takes the row.
When a leaf has both a status card and a Setup card, they share the first row; the working content follows.

## Rules that come with it

- Every leaf that calls `snt_kit_setup_card()` or `snt_kit_how_fold()` gets
  a line in `tests/sn-dashboard-no-folds.php` with the reason the fold is
  earned. The guard fails until it does.
- One-button actions use `snt_kit_form( $action, $inner, array( 'inline' =>
  true ) )`: compact, side by side in an `<os-cluster>`, a danger outline with
  `danger`. Never a form inside a table cell (`tests/owner-devices.php`).
- Remove no figure, control or form when moving a leaf onto this shape.
