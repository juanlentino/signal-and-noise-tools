# Attention

Attention is a queue, not a task list. Each item is one reading from one reader (see [Readers](readers.md)), with the kind, a subject, what was observed, the time of the reading and where it came from.

## Reading an item

Select an item to open its detail: Subject, What, When ("as of" a time in the site's timezone, see [Stamps](stamps.md)) and Source. The actions under it open the place that owns the reading: a note, a page, or a door into S&N Home.

## What clears an item

An item leaves the queue when its reader's next reading no longer carries it. A scheduled note leaves when it publishes. An integrity failure leaves when the next sweep re-reads the subject clean. A Search item leaves when the coverage inspection reports the note indexed. Acknowledge hides an item until its stamp changes; it does not resolve anything.

## Filters

The strip at the top filters by kind. The search box matches subject and observation text. Filters never change what the readers measured, only what is shown.

## Two states that look alike

An empty queue and a queue nobody has read look the same without a date, so the empty state names when the readers last looked. A reading from a sweep that ran before a fix was installed is still the last reading until the sweep runs again; the detail's When line tells you which.
