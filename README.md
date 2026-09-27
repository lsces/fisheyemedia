# Fisheyemedia

A [Bitweaver](https://github.com/lsces/bitweaver) package extending
[fisheye](https://github.com/lsces/fisheye)'s photo gallery machinery to catalogue a film/TV/music
library, currently bootstrapped from a local [Plex Media Server](https://www.plex.tv/) install as
a convenient, already-populated metadata/artwork source.

**Status**: early, active development, personal use — built primarily for the author's own media
library. Film and TV cataloguing are in real use; music is planned (the content type is registered
but has no view/edit pages or Plex integration yet).

## Why this exists

A media library scanned from disk needs the same things a photo gallery already has: browsable
thumbnail grids, per-item detail pages, and structured metadata (genre, cast, external links).
Rather than a separate package duplicating that machinery, fisheye's existing gallery/xref system
is extended here to cover it — the actual gap over plain photo galleries is just a handful of new
content types (a film, a TV season, a TV show) and their own metadata vocabulary, both of which
fisheye's existing framework already supports generically.

Requires fisheye active, and only registers its content types/gallery layouts when it is.

Plex isn't required by fisheye's own photo-gallery features at all, and even here it should be
thought of as a *source*, not a dependency — every reload action copies its data (title, genre,
cast, description, poster/backdrop images, external IDs) into this package's own storage rather
than reading from Plex live, so nothing here actually depends on Plex staying installed once a
library's been backfilled. It's used purely because it's a convenient, already-populated place to
backfill from instead of typing all of that in by hand — the intent is to switch it off entirely
once real local metadata/artwork exists for everything it's fed.

## What it does

- **Film cataloguing** — a film as its own content type (title, genre/director/writer/cast,
  content rating, duration, external links), backed by its own video file; a "Reload Metadata"
  action backfills all of that from a local Plex library in one click, and a "Reload Images" action
  fetches alternate poster/backdrop artwork
- **TV show cataloguing** — a show/season/episode hierarchy, each level with its own metadata where
  Plex actually has it (a season inherits nothing invented — Plex's own data model has real facts
  at the show and episode level, not the season level, and the UI reflects that rather than
  synthesizing something that isn't there); a "Load Episodes" action pulls a season's full episode
  list including each episode's own cast/rating/duration and screenshot thumbnail
- **Discover-and-import pages** for both films and shows — scan a storage folder for what isn't
  registered yet and pick a batch to bring in, rather than a manual per-item process
- **Falls back gracefully when Plex has nothing** — a manual title search to fix a mismatched
  automatic match, and a video-frame-grab fallback (on demand or automatic) when Plex genuinely has
  no artwork for something
- **Direct playback links** for episode video files with real HTTP Range support, rather than
  requiring a separate media player for the underlying files

See [`MANUAL.md`](MANUAL.md) for the full current architecture — the content-type hierarchy, how
storage roots and Plex matching work, and the complete list of what isn't built yet.

## What's planned

- Music/album/track cataloguing, mirroring the film/TV build-out (the content type is registered
  but has no view/edit pages or Plex integration yet)
- A "Show"/"Artist" browsing level that isn't itself a stored record — computed live from its
  seasons/albums, rather than the real gallery object a show currently has to be
- A fully-unattended "scan the whole library and register anything new" importer — today's
  discover-and-import pages are pick-a-batch, capped, not a walk-the-whole-library tool
- Managing the metadata vocabulary (which fields exist per content type) through a bitweaver admin
  UI, rather than a hand-authored scheme file applied once
- A plain "Videos" gallery for one-off single-episode shows, and a combined show/season page for
  the common case of a show with only one season

## Requirements

- [Bitweaver](https://github.com/lsces/bitweaver) 5.x
- [`fisheye`](https://github.com/lsces/fisheye) package, active — this package extends its gallery/
  image content types and reuses its generic xref/gallery-layout framework
- [`liberty`](https://github.com/lsces/liberty) package — the underlying generic content/xref
  framework both packages are built on
- An `image_processor` configured (`gd` or `imagick`) for thumbnail generation
- A local Plex Media Server install, with its library database path and (for artwork/external-ID
  lookups specifically) an API token configured — only used for the backfill actions, and only
  until a library's been backfilled; not needed at all once metadata/artwork already exists
  locally for everything you care about

Since this package isn't through a stable install/upgrade cycle yet, see `MANUAL.md` in this repo
for the current schema-deployment approach if you're installing it fresh.
