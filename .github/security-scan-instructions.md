# Project-specific audit instructions — Signal & Noise Tools

Appended to the security audit prompt. These are findings this codebase has
actually shipped, not hypotheticals.

## 1. `sn_settings` subtree clobber — silent data destruction (CRITICAL, bitten 4×)

`sn_settings_save()` in `inc/settings.php` builds a fresh `$sanitized` array and
ends with a **whole-option replace**:

    return (bool) update_option( SN_SETTINGS_OPTION, $sanitized );

Anything not explicitly re-included in `$sanitized` is **silently wiped** the
next time any tab is saved. There is no error, no notice, and no failing test —
the setting simply ceases to exist, and the tab that owns it renders its default
as though the user had never configured it.

This is an integrity issue, not a style one: it destroys user data on an
unrelated action.

**Flag when a change adds a new `sn_settings` subtree — or a new key inside one —
without re-including it in `sn_settings_save()`.** Saving the Identity tab is the
usual trigger, because that form posts only its own fields.

Two subtrees are already preserved by hand, and they are the pattern to follow:

- `login.slug`, read back via `sn_setting( 'login.slug', … )` before the write
- `audit`, re-included wholesale from `get_option( SN_SETTINGS_OPTION )`

Both carry comments naming this bug. A third subtree that omits the same
treatment is the regression to catch.

Report it even when the new subtree is written by a different file or a
different tab's handler — the clobber happens at save time in `settings.php`,
far from wherever the subtree was introduced, which is why it has recurred.
