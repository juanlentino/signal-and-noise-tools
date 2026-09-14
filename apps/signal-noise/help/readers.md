# Readers

The queue is composed from ten readers. Each reads one stored result and never runs the job that produced it.

- **Integrity**: the provenance integrity sweep. A subject whose published twin, stored payload or ledger record disagree. The sweep checks ten subjects a day; a failing subject is re-read ahead of the rotation.
- **Anchors**: commits waiting for the Worker to confirm their Bitcoin proof, and commits the Worker never answered.
- **Edge**: the edge worker's health as the deploy status reads it.
- **Citations**: inbound webmentions and citations awaiting verification.
- **Scheduled**: notes and fragments due to publish, open or close within the window.
- **Pending review**: posts and pages in the pending status.
- **Health**: the daily health scan's flagged checks.
- **Watches**: registered watches that have come due. A date-only watch ripened because a date passed and nothing was measured; a state watch ripened because a measurement changed.
- **Machine readers**: the machine-reader snapshot when it is older than its window.
- **Search**: notes Search Console reports as not indexed, and new notes inspected on their own clock.

A reader that cannot answer produces one "unreadable" row rather than silence. Silence would look like "nothing to report".
