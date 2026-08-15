#!/usr/bin/env bash
# Tag merge pass for the juanlentino.com notes corpus — 83 tags -> 23.
#
# GENERATED from tag-merge-map.md by parsing its own per-post table, so it
# cannot drift from the map. Regenerate rather than hand-edit.
#
# WHY THIS IS A SCRIPT AND NOT AN MCP CALL: there is no MCP path to reassign
# tags on existing posts. Verified against the tool contracts themselves —
# suggest-tags states "does not assign anything", prune-unused-tags only
# deletes terms that ALREADY have zero posts, and update-post-surfaces carries
# no tags field. ADR-0002 proposes closing that gap with a term-level
# tag_merge change.type on sn-apply; until it ships, this is the path.
#
# WHAT IT DOES: sets each post\'s COMPLETE tag list to the map\'s After column.
# `wp post term set` replaces the whole set, so merges and deletions both fall
# out of it and the operation is idempotent — re-running changes nothing.
#
# USAGE (from the WordPress root, e.g. over Cloudways SSH):
#   ./tag-merge-apply.sh          # dry run, prints every command, changes nothing
#   ./tag-merge-apply.sh --apply  # executes
#
# AFTERWARDS: 60 terms are left with zero posts. Sweep them with the MCP tool
# signal-noise/prune-unused-tags, which deletes only zero-post terms and so
# cannot touch anything still in use.
set -euo pipefail

APPLY=0
[ "${1:-}" = "--apply" ] && APPLY=1
# Pass "$@" through UNCHANGED — never eval. An earlier cut used eval "$@",
# which re-parses the joined string and destroys the quoting, so every
# multi-word term ("AI Training", "Music Rights") would have arrived as two
# separate tags and silently corrupted the taxonomy it was meant to fix.
run() {
	if [ "$APPLY" = "1" ]; then
		"$@"
	else
		printf '%q ' "$@"
		printf '\n'
	fi
}

command -v wp >/dev/null || { echo "wp-cli not found on PATH" >&2; exit 1; }
wp option get home >/dev/null || { echo "wp-cli cannot reach this WordPress install" >&2; exit 1; }

echo "# tags before: $(wp term list post_tag --hide_empty=0 --format=count)"

# A list binds nobody
run wp post term set 2213 post_tag "AI Training" "Authorship" "Music Rights" "Provenance" --by=name

# Nobody is paid to check
run wp post term set 2184 post_tag "Cryptographic Signatures" "Music Distribution" "Provenance" "Standards" --by=name

# The estate cannot sign
run wp post term set 2180 post_tag "Authorship" "Cryptographic Signatures" "Legacy Catalog" "Music Rights" --by=name

# Being read is not being cited
run wp post term set 2183 post_tag "AI Training" "Authorship" --by=name

# The signer keeps moving
run wp post term set 1969 post_tag "Artist Verification" "Content Authenticity" "Cryptographic Signatures" "Provenance" --by=name

# Provenance is the wrong half
run wp post term set 1986 post_tag "Content Authenticity" "Provenance" --by=name

# An empty field says nothing
run wp post term set 2286 post_tag "AI Disclosure" "Authorship" "Provenance" --by=name

# The rights files nobody reads
run wp post term set 2071 post_tag "AI Training" "Music Rights" --by=name

# Payment systems pay what they can name
run wp post term set 2088 post_tag "Black Box Royalties" "Music Metadata" "Music Royalties" --by=name

# The master never moves
run wp post term set 1943 post_tag "Authorship" "Music Rights" "Provenance" --by=name

# The label comes last
run wp post term set 1848 post_tag "AI Disclosure" "Music Metadata" --by=name

# Trust doesn't disappear, it relocates
run wp post term set 1743 post_tag "Artist Verification" "Cryptographic Signatures" "Music Metadata" "Provenance" --by=name

# The pen is not the notary
run wp post term set 1716 post_tag "C2PA" "Content Authenticity" "Cryptographic Signatures" "Provenance" --by=name

# Better models erase the evidence
run wp post term set 2076 post_tag "AI Training" "Music Rights" "Provenance" --by=name

# Provenance signs the claim, not the truth
run wp post term set 1721 post_tag "Content Authenticity" "Cryptographic Signatures" "Provenance" --by=name

# The gate is not the signature
run wp post term set 1675 post_tag "Artist Verification" "Cryptographic Signatures" "Independent Artists" "Provenance" --by=name

# Two kinds of provenance
run wp post term set 1661 post_tag "C2PA" "Digital Identity" "Music Rights" "Provenance" --by=name

# Provenance as a CFO problem
run wp post term set 1589 post_tag "AI Music" "Music Rights" "Music Royalties" "Provenance" --by=name

# Where provenance has to live
run wp post term set 1835 post_tag "AI Music" "Authorship" "Provenance" --by=name

# Why platforms wait on provenance
run wp post term set 1593 post_tag "AI Music" "Music Distribution" "Music Industry" "Provenance" --by=name

# The unlabeled majority
run wp post term set 1858 post_tag "AI Disclosure" "AI Music" "Music Industry" --by=name

# How a music file gets corrected
run wp post term set 1572 post_tag "Cryptographic Signatures" "Music Metadata" "Provenance" --by=name

# The court found the floor
run wp post term set 1833 post_tag "Black Box Royalties" "Music Rights" "Provenance" --by=name

# Open standards or no standards
run wp post term set 1591 post_tag "Music Distribution" "Music Metadata" "Provenance" "Standards" --by=name

# The seat was never given
run wp post term set 1681 post_tag "Artist Verification" "Music Industry" "Music Royalties" "Provenance" --by=name

# Start here
run wp post term set 1746 post_tag "Content Authenticity" "Digital Identity" "Music Rights" "Provenance" --by=name

# The music industry talks to itself in code
run wp post term set 1523 post_tag "Music Industry" "Writing" --by=name

# Who vouches for the independent artist?
run wp post term set 1570 post_tag "Authorship" "Cryptographic Signatures" "Digital Identity" "Independent Artists" "Provenance" --by=name

# What happens to old music?
run wp post term set 1568 post_tag "Legacy Catalog" "Music Metadata" "Standards" --by=name

# Signing the inputs at the source
run wp post term set 1581 post_tag "Cryptographic Signatures" "Music Metadata" "Music Production" "Provenance" --by=name

# Falsifiability is the line
run wp post term set 1531 post_tag "AI Detection" "Cryptographic Signatures" "Music Royalties" "Provenance" --by=name

# Fingerprints, not name tags
run wp post term set 1566 post_tag "Cryptographic Signatures" "Digital Identity" "Music Industry" "Music Metadata" "Music Royalties" --by=name

# Detection scales the wrong way
run wp post term set 1587 post_tag "AI Detection" "AI Music" "Provenance" --by=name

# Five layers, one system
run wp post term set 1518 post_tag "Music Distribution" "Music Production" "Music Royalties" "Provenance" --by=name

# Five years of remote freelance work
run wp post term set 1516 post_tag "Freelance Business" --by=name

# Music's billion-dollar metadata problem
run wp post term set 1504 post_tag "Black Box Royalties" "Music Metadata" "Music Rights" "Music Royalties" "Provenance" --by=name

# Verifying the artist isn't enough
run wp post term set 1549 post_tag "AI Music" "Artist Verification" "Content Authenticity" "Music Distribution" --by=name

# Where AI actually saves time in record production
run wp post term set 1514 post_tag "AI Music" "Music Production" --by=name

# Provenance is for humans, not against AI
run wp post term set 1498 post_tag "AI Detection" "Authorship" "Music Rights" "Provenance" --by=name

# Where artist signatures live
run wp post term set 1551 post_tag "Artist Verification" "Content Authenticity" "Cryptographic Signatures" "Independent Artists" "Music Distribution" --by=name

# Pricing in dollars from Argentina
run wp post term set 1512 post_tag "Freelance Business" --by=name

# Why C2PA isn't enough for music
run wp post term set 1495 post_tag "C2PA" "Content Authenticity" "Music Distribution" "Music Metadata" "Provenance" --by=name

echo "# tags after (before prune): $(wp term list post_tag --hide_empty=0 --format=count)"
echo "# terms now at zero posts, ready for prune-unused-tags:"
wp term list post_tag --hide_empty=0 --field=name --format=csv \
  | while read -r t; do
      [ "$(wp term list post_tag --name="$t" --field=count --format=csv 2>/dev/null | tail -1)" = "0" ] && echo "  $t"
    done || true
