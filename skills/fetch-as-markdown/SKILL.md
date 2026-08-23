# Fetch juanlentino.com content as Markdown

Any page on this site will answer in Markdown if you ask for it. Use this when
you want the prose without parsing HTML.

## How

Send `Accept: text/markdown` with an ordinary GET:

```
GET https://juanlentino.com/notes/ HTTP/1.1
Accept: text/markdown
```

The response is `text/markdown`. Without the header you get the normal HTML —
the URL does not change, and there is no `.md` suffix to append.

## Notes

- Conversion happens at the edge, after the cache lookup, so `Vary: Accept`
  rides both representations and a cached Markdown body cannot reach a browser.
- Measured on a live note: 131,594 bytes of HTML became 6,643 of Markdown.
- Structured alternatives exist if you want data rather than prose: every note
  has a `.json` twin, and `/feed/json/` lists them.

## Validate

```
curl -s -H 'Accept: text/markdown' https://juanlentino.com/notes/ | head -5
```

You should see Markdown, not `<!DOCTYPE html>`.
