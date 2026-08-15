#!/usr/bin/env bash
# Tag merge pass for the juanlentino.com notes corpus — 83 assigned tags -> 23.
#
# GENERATED from tag-merge-map.md by parsing its own per-post table, so the
# commands cannot drift from the map. Regenerate rather than hand-editing.
#
# WHY NAMES ARE RESOLVED TO IDS FIRST: `wp post term set --by=` accepts ONLY
# `slug` or `id`. An earlier cut of this script passed `--by=name` and every
# command failed with "Invalid value specified for 'by'". Slugs are not safe to
# derive from names either (a slug can be anything), so the script reads the
# real name->term_id map from the site once and passes ids.
#
# USAGE, from the WordPress root:
#   ./tag-merge-apply.sh          # DRY RUN — prints, changes nothing
#   ./tag-merge-apply.sh --apply  # executes
set -euo pipefail

APPLY=0
[ "${1:-}" = "--apply" ] && APPLY=1

command -v wp >/dev/null || { echo "wp-cli not found on PATH" >&2; exit 1; }
wp option get home >/dev/null || { echo "wp-cli cannot reach this WordPress install" >&2; exit 1; }

if [ "$APPLY" = "1" ]; then
	echo "=== APPLYING — this writes to $(wp option get home) ==="
else
	echo "=== DRY RUN — nothing will be written. Re-run with --apply to execute. ==="
fi
echo "# tags before: $(wp term list post_tag --hide_empty=0 --format=count)"

# One read for the whole name->id map. --fields order is fixed so awk can rely
# on it; names here contain no commas or quotes (asserted at generation time).
MAP="$(mktemp)"
trap 'rm -f "$MAP"' EXIT
wp term list post_tag --hide_empty=0 --fields=term_id,name --format=csv | tail -n +2 > "$MAP"

tid() {
	awk -F, -v want="$1" '{ n=$2; for (i=3; i<=NF; i++) n = n "," $i; gsub(/^"|"$/, "", n); if (n == want) { print $1; exit } }' "$MAP"
}

# Resolve every term the map needs BEFORE writing anything, so a typo or a
# renamed term stops the run at zero changes instead of halfway through.
MISSING=0
for t in \
	"AI Detection" \
	"AI Disclosure" \
	"AI Music" \
	"AI Training" \
	"Artist Verification" \
	"Authorship" \
	"Black Box Royalties" \
	"C2PA" \
	"Content Authenticity" \
	"Cryptographic Signatures" \
	"Digital Identity" \
	"Freelance Business" \
	"Independent Artists" \
	"Legacy Catalog" \
	"Music Distribution" \
	"Music Industry" \
	"Music Metadata" \
	"Music Production" \
	"Music Rights" \
	"Music Royalties" \
	"Provenance" \
	"Standards" \
	"Writing"
do
	if [ -z "$(tid "$t")" ]; then echo "UNRESOLVED TERM: $t" >&2; MISSING=1; fi
done
[ "$MISSING" = "0" ] || { echo "Aborting: the map references terms this site does not have." >&2; exit 1; }
echo "# all 23 surviving terms resolved to ids"

set_tags() {
	local pid="$1"; shift
	local ids=()
	local t
	for t in "$@"; do ids+=("$(tid "$t")"); done
	if [ "$APPLY" = "1" ]; then
		wp post term set "$pid" post_tag "${ids[@]}" --by=id
	else
		printf 'wp post term set %s post_tag %s --by=id   # %s\n' "$pid" "${ids[*]}" "$*"
	fi
}

# A list binds nobody
set_tags 2213 "AI Training" "Authorship" "Music Rights" "Provenance"

# Nobody is paid to check
set_tags 2184 "Cryptographic Signatures" "Music Distribution" "Provenance" "Standards"

# The estate cannot sign
set_tags 2180 "Authorship" "Cryptographic Signatures" "Legacy Catalog" "Music Rights"

# Being read is not being cited
set_tags 2183 "AI Training" "Authorship"

# The signer keeps moving
set_tags 1969 "Artist Verification" "Content Authenticity" "Cryptographic Signatures" "Provenance"

# Provenance is the wrong half
set_tags 1986 "Content Authenticity" "Provenance"

# An empty field says nothing
set_tags 2286 "AI Disclosure" "Authorship" "Provenance"

# The rights files nobody reads
set_tags 2071 "AI Training" "Music Rights"

# Payment systems pay what they can name
set_tags 2088 "Black Box Royalties" "Music Metadata" "Music Royalties"

# The master never moves
set_tags 1943 "Authorship" "Music Rights" "Provenance"

# The label comes last
set_tags 1848 "AI Disclosure" "Music Metadata"

# Trust doesn't disappear, it relocates
set_tags 1743 "Artist Verification" "Cryptographic Signatures" "Music Metadata" "Provenance"

# The pen is not the notary
set_tags 1716 "C2PA" "Content Authenticity" "Cryptographic Signatures" "Provenance"

# Better models erase the evidence
set_tags 2076 "AI Training" "Music Rights" "Provenance"

# Provenance signs the claim, not the truth
set_tags 1721 "Content Authenticity" "Cryptographic Signatures" "Provenance"

# The gate is not the signature
set_tags 1675 "Artist Verification" "Cryptographic Signatures" "Independent Artists" "Provenance"

# Two kinds of provenance
set_tags 1661 "C2PA" "Digital Identity" "Music Rights" "Provenance"

# Provenance as a CFO problem
set_tags 1589 "AI Music" "Music Rights" "Music Royalties" "Provenance"

# Where provenance has to live
set_tags 1835 "AI Music" "Authorship" "Provenance"

# Why platforms wait on provenance
set_tags 1593 "AI Music" "Music Distribution" "Music Industry" "Provenance"

# The unlabeled majority
set_tags 1858 "AI Disclosure" "AI Music" "Music Industry"

# How a music file gets corrected
set_tags 1572 "Cryptographic Signatures" "Music Metadata" "Provenance"

# The court found the floor
set_tags 1833 "Black Box Royalties" "Music Rights" "Provenance"

# Open standards or no standards
set_tags 1591 "Music Distribution" "Music Metadata" "Provenance" "Standards"

# The seat was never given
set_tags 1681 "Artist Verification" "Music Industry" "Music Royalties" "Provenance"

# Start here
set_tags 1746 "Content Authenticity" "Digital Identity" "Music Rights" "Provenance"

# The music industry talks to itself in code
set_tags 1523 "Music Industry" "Writing"

# Who vouches for the independent artist?
set_tags 1570 "Authorship" "Cryptographic Signatures" "Digital Identity" "Independent Artists" "Provenance"

# What happens to old music?
set_tags 1568 "Legacy Catalog" "Music Metadata" "Standards"

# Signing the inputs at the source
set_tags 1581 "Cryptographic Signatures" "Music Metadata" "Music Production" "Provenance"

# Falsifiability is the line
set_tags 1531 "AI Detection" "Cryptographic Signatures" "Music Royalties" "Provenance"

# Fingerprints, not name tags
set_tags 1566 "Cryptographic Signatures" "Digital Identity" "Music Industry" "Music Metadata" "Music Royalties"

# Detection scales the wrong way
set_tags 1587 "AI Detection" "AI Music" "Provenance"

# Five layers, one system
set_tags 1518 "Music Distribution" "Music Production" "Music Royalties" "Provenance"

# Five years of remote freelance work
set_tags 1516 "Freelance Business"

# Music's billion-dollar metadata problem
set_tags 1504 "Black Box Royalties" "Music Metadata" "Music Rights" "Music Royalties" "Provenance"

# Verifying the artist isn't enough
set_tags 1549 "AI Music" "Artist Verification" "Content Authenticity" "Music Distribution"

# Where AI actually saves time in record production
set_tags 1514 "AI Music" "Music Production"

# Provenance is for humans, not against AI
set_tags 1498 "AI Detection" "Authorship" "Music Rights" "Provenance"

# Where artist signatures live
set_tags 1551 "Artist Verification" "Content Authenticity" "Cryptographic Signatures" "Independent Artists" "Music Distribution"

# Pricing in dollars from Argentina
set_tags 1512 "Freelance Business"

# Why C2PA isn't enough for music
set_tags 1495 "C2PA" "Content Authenticity" "Music Distribution" "Music Metadata" "Provenance"

echo "# tags after (before prune): $(wp term list post_tag --hide_empty=0 --format=count)"
echo "# terms now at zero posts, ready for prune-unused-tags:"
# ONE call, not one per term. The previous cut filtered with --name= per term,
# which was not applied, so `tail -1` returned an arbitrary row and the list was
# simply wrong — it named terms that still had posts.
wp term list post_tag --hide_empty=0 --fields=name,count --format=csv \
	| tail -n +2 | awk -F, '$NF == 0 { $NF=""; sub(/,$/, ""); print "  " $0 }'
