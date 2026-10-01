# Fisheyemedia Package — Reference Manual

How the package actually works today - current behaviour only, not a history of how it got here.

## What this is

Real media cataloguing (film/TV/music libraries scanned from disk, backed by a local Plex Media
Server install as the metadata/artwork source), extending [fisheye](https://github.com/lsces/fisheye)'s
gallery/xref machinery rather than duplicating it — the gap over plain photo galleries is just
extra mime types, extra `gallery_views` layouts, and per-content-type xref vocabulary, all of
which fisheye's existing framework already supports generically.

Requires fisheye active (`bit_setup_inc.php` guards on `isPackageActive('fisheye')` and returns
early otherwise). Every class here extends one of fisheye's own base classes
(`FisheyeGallery`/`FisheyeImage`, via the intermediate `FisheyeMediaGallery`/`FisheyeMediaImage`/
`FisheyeMediaTrait`), and the gallery-layout extensibility fisheye exposes via its generic
`registerService()`/`getServiceValues()` mechanism is how this package's three grid layouts
(`film_grid`/`program_grid`/`music_grid`) plug in without fisheye needing any hardcoded knowledge
of this package.

## Content type hierarchy

Phantom subclasses ring-fence xref vocabulary via `content_type_guid`, the same pattern
`Contact`/`ContactPerson`/`ContactBusiness` uses — each subclass has no behavioural difference
from its base beyond its own `registerContentType()` call. `LibertyXrefType`'s dual-guid
`IN(contentTypeGuid, packageGuid)` scoping means an item registered at one guid is invisible to
another — so Season-level fields don't leak onto a Film, TheTVDB doesn't show up as an option on
a Film, MusicBrainz doesn't show up on a Season, etc.

| Class | Extends | Represents | Own file? |
|---|---|---|---|
| `FisheyeFilm` | `FisheyeMediaImage` | A single film | Yes — the video itself, a real `LibertyMime` attachment |
| `FisheyeSeason` | `FisheyeMediaImage` | One season of a show | No — pure metadata container over its episodes' xref rows |
| `FisheyeProgram` | `FisheyeMediaGallery` | A TV show | No — but has its own attachment slot for a selected thumbnail (see below) |
| `FisheyeAlbum` | `FisheyeMediaImage` | A music album | Yes — real thumbnail attachment (cover art), tracks are raw xrefs like Season's episodes |

Hierarchy: **Show → Season → Episode** / **Artist → Album → Track**; a Film stands alone
(single-level). Show/Composer as a genuine top-level browsable concept (a computed listing with no
`liberty_content` row of its own, same idea as `food`'s `FoodDay` pattern) is designed but not
built — for now, a show is just a real `FisheyeProgram` gallery holding `FisheyeSeason` members.
**Artist is the one exception** — an artist's own gallery (`music_grid` pagination) has a real
Plex-style page of its own now, see "Music discography" below, rather than plain drill-down.

**Flat single-season shows** — some shows (a one-off documentary registered as a show rather than a
film, e.g. to get real cast/episode metadata) have their episode file(s) sitting directly in the
show folder, no `Season 01/` subfolder at all. `load_program.php` treats this as an implicit
season using the sentinel folder name `'.'` (unambiguous — `scandir()` already skips it as a real
entry); `FisheyeSeason::registerFromDisk()` resolves that sentinel to "season dir == show dir" and
titles the season plainly `"<show> - Season 1"` rather than `"<show> - ."`.

**A show having only one episode registered is not a workaround — it's just what a genuine one-off
looks like.** The one real external constraint is the filename itself: Plex's own scanner expects
an `SxxExx`-style tag to recognize and play TV content at all, so a standalone one-off still needs
one (`S2003E05`, year-as-season is a common real-world convention for this) purely to satisfy
Plex's naming scheme — that's a Plex-compatibility requirement on the file on disk, not an internal
data-model compromise. This package's own Show → Season → Episode model already represents a
single-episode season correctly and doesn't need a separate "plain Videos gallery" alternative for
this case.

**Deleting a show** (`FisheyeProgram::expunge()`, an override — not the shared
`FisheyeGallery::expunge()`, which only ever recurses into sub-*galleries*, never plain gallery
items like a season) cascades: every season is expunged first (which itself cleans up its own
episode xrefs and images via the normal `FisheyeImage`/`LibertyMime` expunge path), then the show's
own gallery/content rows. Scoped to `FisheyeProgram` deliberately, not the shared base class — a
season is never meaningfully linked into more than one show the way a photo can be linked into more
than one gallery, so there's no case here needing the "keep it if it's still in another gallery"
check `FisheyeGallery::expunge()`'s sub-gallery recursion already does.

**Collections** (a franchise, or a show grouping its seasons) don't need a new content type —
`FisheyeGallery::addItem()` takes any `content_id` with no content-type check, and its own
`isInGallery()` guard already checks both directions before inserting, so nesting a gallery inside
another gallery is a safe, anticipated case.

## View/edit page pairs

Each real media content type gets its own `view_X.php`/`edit_X.php` pair, replacing the generic
`view.php`/`edit.php`/`view_image.php`/`edit_image.php` a plain fisheye gallery/photo uses:

- `view_film.php` / `edit_film.php`
- `view_program.php` / `edit_program.php`
- `view_season.php` / `edit_season.php`

**Single-season shows skip the season page entirely.** `view_program.php` checks the show's own
season count; when there's exactly one, it loads that season's episode/image xref data itself
(same shape `view_season.php` uses) and dispatches to `view_program_single_season.tpl` instead of
the normal season-grid template — no click-through to a dummy "Season 1". Layout: show
thumbnail/summary 50/50 on the left, the episode detail panel (swaps per-episode) on the right,
episode grid along the bottom. The real `FisheyeSeason` object still exists underneath (`edit_season.php`
still reachable directly if ever needed) — this is display-level only, not a storage change. The
episode-detail-panel and episode-grid+JS blocks are shared includes (`episode_detail_panels_inc.tpl`,
`episode_grid_inc.tpl`) used by both this template and `view_season.tpl`, not duplicated.

**View pages are pure display** — no `$_REQUEST` action handling, no update-permission checks
beyond what rendering needs. **Edit pages own every mutating action**: title edit via the generic
`store()` call, the generic liberty xref table (`list_xref.tpl`/`add_xref.php`/`edit_xref.php` —
reused as-is, no bespoke per-field markup needed since every item already registers with an
existing generic template), and the Plex reload actions (see below).

**Both `getEditUrl()` and `getDisplayUrl()` need an explicit override on every phantom subclass.**
The generic defaults on fisheye's own base classes point at fisheye's own generic page (`fisheye/
edit.php` for `getEditUrl()`, an `image_id`/`gallery_id`-keyed generic view for `getDisplayUrl()`)
— which is wrong for any of these types and fails in different ways depending on which is missing:
- Missing `getEditUrl()` override: a fatal error the first time anything tries to redirect there
  (the generic gallery `edit.php` calls methods a phantom-gallery subclass doesn't have).
- Missing `getDisplayUrl()` override: no fatal error, just a silently wrong link — gallery-grid
  templates all call this generically, so a member without its own override quietly routes to the
  wrong page (this bit fisheye's own `FisheyeFilm` once — see `getDisplayUrlFromHash()`'s own
  docblock in `fisheye/includes/classes/FisheyeImage.php` for why the fix belongs on the subclass's
  own override, not a base-class guess).

The shared `gallery_breadcrumb_inc.tpl` component (fisheye's own) is **not type-aware** — its
ancestor links are built via a hardcoded pretty-url pattern that always lands on the generic
gallery view, regardless of the target's real content type. A season's own page instead links
directly to its parent via the parent object's own `getDisplayUrl()`, one level only (a season is
always exactly one level below its show, so a full breadcrumb chain isn't needed there).

**When resolving a parent/ancestor object of unknown real type, use `FisheyeGallery::lookup()`
(which routes through `getLibertyObject()`'s normal polymorphic dispatch), never a bare
`new FisheyeGallery(...)`** — the latter is always a plain `FisheyeGallery` instance regardless of
the row's actual registered type, silently defeating any `getDisplayUrl()`/`getEditUrl()` override
the real subclass has.

## Storage roots

Two independent storage roots, both resolved via `liberty/plugins/mime.film.php`:

- `mime_film_get_storage_root()` — the plain root (`fisheye_disk_storage_root` config key), used
  by films and music.
- `mime_film_get_tvshow_storage_root( $pShowTitle )` — TV shows only, split by the show title's
  first letter into two configurable roots (`fisheye_tvshow_storage_root_am` /
  `fisheye_tvshow_storage_root_nz`) — a real filesystem split some deployments use for a large TV
  library, not merely cosmetic.

**Every content class exposes its own `getImageStorageRoot()`** (film: the plain root; season/
program: the TV-specific root, resolved via the show title) — any code that needs to resolve a
storage root for xref file data (image/episode file streaming, the generic xref-file-replace hooks
below) must call this polymorphically on the content object, never hardcode either
`mime_film_get_storage_root()` call directly. The two roots can coincide in a given deployment
(making a hardcoded call look like it works) but diverge in general — this has been a real,
repeated bug source.

## Bulk import (`load_film.php` / `load_program.php`)

Discover-and-pick admin pages, capped (`LOAD_FILM_LIMIT`/`LOAD_PROGRAM_LIMIT`, both 20) — not a
"scan and register everything" tool. Each registration is cheap (a `store()`/gallery-link/Plex
backfill), the one genuinely expensive step (thumbnail generation) is never triggered here, only
lazily per item on first view.

- `load_film.php` — flat: pick a folder under `Films/` (or a real subfolder standing in for a
  collection), pick films from a checkbox list, `$pFetchImages` opt-in (a bulk 20-film import
  paying for N image downloads at once is a real cost worth choosing explicitly).
- `load_program.php` — two levels, matching the real show → season → episode structure: pick an
  unregistered show folder (registers the show, cheap, no images fetched yet — see the halt below),
  then pick season folders under it (each selected season is created, seeded with one real episode
  file, and immediately synced against Plex for its full episode list — no separate "fetch episodes
  later" step, since a season with no episodes at all isn't useful). Handles the flat single-season
  case (no `Season 01/` subfolder) via the `'.'` sentinel described above.

Both pages resolve their own "top level, not a real collection/show" gallery id via
`FisheyeGallery::getTopGalleryId( $pTitle )` (a plain title lookup against `fisheye_gallery`/
`liberty_content`) rather than a hardcoded, install-order-dependent gallery_id constant — the very
first two galleries created on a given install happen to be "Films"/"TV Shows", so a literal `1`/`2`
worked by coincidence but wasn't a safe assumption for another install.

**`FisheyeProgram::registerFromDisk()` halts before fetching metadata/images if there's no Plex
match** (case/spacing mismatches like Plex's own `"Dinnerladies"` vs an on-disk `"Dinner Ladies"`
folder are common, and a folder name genuinely can't hold a colon Plex's own title might have) —
the show record itself still gets created (cheap, and gives the manual-match tools below something
to attach a match to), but no metadata/image fetch runs until a match is actually confirmed,
automatic or manual. `load_program.tpl` surfaces this with a link straight to the show's edit page
to fix it.

**Manual Plex match** (`edit_program.php`, shown whenever `$gContent->hasPlexMatch()` is false) —
`FisheyeProgram::searchPlexShows( $pQuery )` runs a plain `LIKE` search against the local Plex
SQLite db (same one every other Plex lookup in this class already reads directly — no need for
Plex's own HTTP search API); picking a result calls `setPlexMatchOverride( $pMetadataItemId )`,
which stores it as a `plex_match` xref (`xkey` = the Plex `metadata_items.id`) that
`matchPlexShowMetadataItem()` checks first from then on, ahead of the automatic title lookup.
`plex_match` is a purely internal bookkeeping value — never shown through the generic xref grid, no
`liberty_xref_item` config row registered for it, read/written via plain direct SQL rather than the
generic `lookupXrefByItem()`/`loadXrefInfo()` helpers (both require that config row to exist).
Confirming a match immediately runs the metadata/image fetch that was held back by the halt above.

## Xref-based metadata

Standard `liberty_xref_group`/`liberty_xref_item` vocabulary per content type — genre/director/
writer/star (grouped under its own `cast` tab, not lumped in with single-value fields)/
content_rating/duration, plus external-ID link items (`imdb`/`tmdb`/`tvdb` as applicable per type)
using the generic `href` template. All served by fisheye's existing generic xref admin UI — no
bespoke per-field markup needed anywhere in this extension.

**Pages must never hardcode which `x_group` an item lives in.** Use
`FisheyeMediaTrait::liveXrefs()` (a flat pass across every loaded group, same "ignore group name"
spirit as the existing `findByItem()`/`allItems()`) and bucket by item name only. A page that reads
`$xrefInfo->mGroups['metadata']` directly breaks silently the next time an item moves to a
different tab — already happened once with `star`'s move into `cast`. `liveXrefs()` skips liberty's
synthetic `history` group (rows with an `end_date`); liberty's own `LibertyXrefContent::allXrefs()`
includes it on purpose, for the generic grid's History tab, so media pages never call that directly.

**Reloads reconcile, never wipe** (albums so far — `FisheyeAlbum::reconcileXrefItem()`). Each
row is matched by a natural key (a track's file path, a person's id, the item itself for a
single-valued one): unchanged rows are left alone with their `entry_date`, a changed value archives
the old row (into history) and inserts the new one, a row the files no longer mention is archived,
and a hand-edited row (`last_update_date` later than `entry_date`) is never touched. Film/Season/
Program/featurette reloads still delete-and-rebuild — the same fix is pending there.

**Episode** is a `liberty_xref` row under its season's own `content_id` (not a separate gallery
level) — `xkey_ext` holds the video file path relative to the season's storage root, `data` holds
a JSON blob (title/summary/air_date/director/writer/star/content_rating/duration/a `thumb` image
path), `xorder` is the episode number. `Track` (album/song) is the same shape, one level down from
an album — see "Music discography" below for its own `data` JSON shape (`disc`/`track` fields) and
display template.

**Alternate images** (`image` item, `images` group) — extra poster/backdrop artwork for a film,
album, season, or show, stored as ordinary xref rows rather than a second `LibertyMime` attachment
row (multiple attachments per `content_id` is not supported on this stack). `xkey_ext` is a bare
filename, resolved via the owning content object's own `getExtraImagePath()` — every content type
overrides this to resolve against its own `storage/attachments/<branch>/` (the same home every
other attachment/derived file already uses, always web-writable by construction, unlike the
external media library tree `getImageStorageRoot()` points at); `xorder` gives display order.
Rendered via the shared, collapsible `images_strip_inc.tpl` (starts closed) — a stopgap
presentation layer, expected to eventually be replaced by real cast/crew imagery once that data
exists.

**The Images tab has its own group-tab override**, `templates/xref/view_images_group.tpl` (Film/
Season/Program all share the one file — identical to liberty's generic `list_xref.tpl` except the
Add link), which replaces the generic add-a-bare-row-then-edit-it flow with a real one-step upload
(`add_image_xref.php` + `FisheyeMediaTrait::addImageXrefFile()`) and, where supported, a "Grab
Thumbnail from Video" action (see below). **This only fires when `liberty_xref_group.template =
'images'` for the `images` x_group row on each of the three content types** — that's a per-site DB
config value (same table the Xref Groups admin page itself writes to directly, no history/schema-
file tracking), not something schema/install files set, so a fresh install or another server needs
it applied by hand:
```sql
UPDATE liberty_xref_group SET template='images'
WHERE x_group='images' AND content_type_guid IN ('fisheyefilm','fisheyeseason','fisheyeprogram');
```
**Templates here can't call a bare PHP function inside `{if}`** (`{if method_exists(...)}` fails as
"unknown modifier" on this Smarty setup) — only a real method call on an object works. Capability
checks (`$gContent->supportsAddImage()`, `$gContent->canGrabVideoFrame()`) are real methods for
exactly this reason, not a `method_exists()` call inlined into the template.

## Music discography (Artist/Album/Track)

**Discography categories are a flat xref, not a nested gallery.** Studio/Live/Compilation/
Remaster/Single/Soundtrack/Tribute/Other (`FISHEYEALBUM_CATEGORY_FOLDER_NAMES`,
`isCategoryFolder()`) exist as real folders on disk (`Music/<Artist>/Studio/<Album>/`), but an
album registered under one is linked *directly* into the artist's own gallery — the category name
is stored as a plain `category` xref (`item='category'`, `xkey_ext` = the category name) on the
album itself, not as a nested gallery level. `load_album.php`'s candidate scan transparently
flattens this: a category folder's own contents show up as `"Category/Album"` entries, never the
bare category name. **Box sets are the one exception** — a real `CDxx`/`Volume`-numbered set of
distinct recordings (`isBoxSetFolder()`) still gets a genuine nested gallery via
`FisheyeAlbum::createSubGallery()`, since that's a real structural grouping (pick a handful of
discs at a time), not just a type label — this still applies even when the box set sits inside a
category folder (`"Studio/Some Box Set"`).

Because the gallery hierarchy no longer carries the category folder segment,
`FisheyeAlbum::getImageStorageRoot()` reads the album's own `category` xref first and prepends it
to the path before its existing gallery-parent walk, only when the xref has a value — an album
with no category (there shouldn't be any, but if one existed) resolves exactly as it always did,
no regression.

**Artist gallery view is a Plex-style strip layout, not a paginated grid.** `music_grid`'s own
template (`gallery_views/music_grid/fisheye_music_grid_inc.tpl`) no longer paginates at all — it
calls `FisheyeGallery::getCategorizedItems()`, which loads every item in the gallery in one go and
buckets each `FisheyeAlbum` by its own `category` xref (one bulk `liberty_xref` query, not
per-item) into `FISHEYEALBUM_CATEGORY_FOLDER_NAMES` order; a nested `FisheyeGallery` item (a box
set, or the "Videos" subgallery below) carries no category of its own and lands in a trailing
"Collections" bucket instead. Each non-empty bucket renders as its own flowing strip, no
`{pagination}` widget. The **Load Album**/**Load Videos** icons (`music_gallery_icons_inc.tpl`)
only show when `hasUnloadedAlbumCandidates()`/`hasUnloadedVideoCandidates()` find something
actually still unregistered — cheap short-circuit scans mirroring `load_album.php`/`load_video.php`'s
own candidate logic.

**Videos** — a concert DVD or music video sitting in an artist's own `Videos/` subfolder registers
as a real `FisheyeFilm` (same content type normal Films use), linked into a small nested "Videos"
gallery under the artist (`FisheyeMediaGallery::findOrCreateNestedGallery()`, `FISHEYE_PAGINATION_FILM_GRID`
so it renders as a film grid, not the site's default Galleriffic style). Doesn't gate on a Plex
match the way `load_film.php` does — Plex almost never scans this path, so every selected video
registers regardless, with Plex metadata fetched opportunistically when a match does exist.

**Fetch Discogs Link** lives on `edit_album.php` as a per-album button now (`fFetchDiscogs` →
`fetchDiscogsLink()`), not a separate batch page. MusicBrainz's own API etiquette policy asks every
client to identify itself with real contact info in its User-Agent string — set the (optional)
`fisheye_musicbrainz_contact` config value on this package's own admin settings page to supply it.

**Track data cleanup** — `extractCommonTags()`'s promote-if-identical-across-every-track mechanism
(`FISHEYEALBUM_COMMON_TAG_MAP` for a 1:1 tag→xref, `FISHEYEALBUM_COMMON_TAG_ALTERNATES` for a tag
with more than one common spelling) also covers `ARTISTS`/`ARTISTSORT`/`LANGUAGE` (previously only
plain `ARTIST` was promoted, leaving these as per-track noise on every normal, non-tribute album).
`FISHEYEALBUM_IGNORED_TAG_KEYS` drops `COMMENT`/`ID3V1COMMENT` (ripper-tool artifacts) and the four
`REPLAYGAIN*` tags (encoder-normalization noise) outright, on both counts regardless of
punctuation — `normalizeTagKey()` strips all non-alphanumerics before comparison either way.

**Track disc/number is now structural, not just embedded in the title.** Each track's stored `data`
JSON has its own `track` field always, and a `disc` field only when the album is genuinely
multi-disc (`reloadTracks()`/`registerFromDisk()` compute this once per album, not per track) — a
single-disc album never stores `disc` at all. `templates/xref/fisheyealbum/view_json-list_item.tpl`
(a content-type-specific override of liberty's generic `view_json-list_item.tpl`, resolved via
`getXrefRecordTemplate()`) shows `Disc-Track` (or bare `Track` when single-disc) in the first
column instead of the row's auto-numbered title, and excludes `disc`/`track` from the generic
per-row key-dump table below it. The Tracks tab itself needs `liberty_xref_item.template` set to
`'json-list'` for the `track` item (a per-site DB value, same caveat as the `images` template
above — not schema/install-file-driven).

`view_album.php`'s own public track listing (grouped by disc, one `<h3>Disc N</h3>` heading per
group when `multiDisc`) appends that disc's own `TSST` (ID3v2) / `DISCSUBTITLE` (Vorbis) tag after
the heading when present — the same tag precedence `getDiscTitle()` already uses for a box set's
own per-disc title, here just extra context on a single flattened multi-disc album rather than the
title itself.

**Where this is heading next**: `artist`/`composer`/`conductor`/`orchestra`/`performer` are still
plain-text xrefs (`template='text'`, a name string in `xkey_ext`) as of this writing — a design for
linking these to a real `Contact` record instead (shared bio/external-links, one identity reused
across every credit anywhere in the system) is written up at `contact/MANUAL-WIKI.md`, not yet
built.

## Real thumbnail attachments (Season/Program)

A season or show has no file of its own, but both `FisheyeImage` and `FisheyeGallery` descend from
`LibertyMime`, so both have an unused attachment slot — a real image can be stored there to get
proper generated thumbnails through the standard machinery, rather than a xref-based reference
(which can't select a size and never gets a real generated thumbnail).

Two real gotchas here, both fisheye-base-class-specific (see fisheye's own `MANUAL.md` for the
general version of this note):
- **`FisheyeGallery::load()`/`store()` shortcut straight to `LibertyContent`'s own versions**,
  never touching attachment data at all. Any code on a gallery-based phantom subclass that needs
  its own attachment must call `\Bitweaver\Liberty\LibertyMime::load()`/`::store()` explicitly
  (class-scoped, not `parent::`) rather than relying on `$this->load()`/`$this->store()`.
- **`FisheyeGallery::getThumbnailImage()`'s own recursion treats any member that `is_a()` a
  `FisheyeGallery` as "just another gallery to bubble through"** — a gallery-based phantom
  subclass that has its own real thumbnail attachment must override `getThumbnailImage()` to
  short-circuit and return itself once it has that data, or a parent gallery's own thumbnail
  lookup will bubble straight past it into one of its members instead. `FisheyeProgram` does this.

A generic `promoteImageToThumbnail( $pRelativePath )` hook lets an already-downloaded alternate
image be promoted into the real thumbnail slot later ("change the auto-pick"); each content class
implements this appropriately for its own storage shape (a film reuses its poster-sidecar
convention since its one attachment slot is already the video file itself; season/program swap the
attachment directly).

## Plex integration

Plex is a bootstrap/backfill **source**, not a runtime dependency — every reload action below
copies data (text fields, artwork files) into this package's own storage (`liberty_xref`/
`liberty_content`/a real attachment, per the sections above) rather than reading from Plex live at
display time. The intent is that Plex is switched off entirely once real local metadata/artwork
exists for everything it's fed — nothing here reads from Plex outside these explicit, one-off
reload actions.

Config keys (this package's own admin settings page, `admin/admin_fisheyemedia_settings.php`):
- `fisheye_plex_db_path` — Plex's own library SQLite database. World-readable by default, no
  permission workaround needed for text metadata.
- `fisheye_plex_token` — needed for anything going through Plex's local HTTP API (artwork
  endpoints, external-ID guids) — lives in Plex's own `Preferences.xml`, which is *not*
  world-readable, so this has to be copied in by hand once.

Each content class matches itself against Plex's `metadata_items` table:
- Film — by the real absolute file path (`realpath()` against the storage root, since Plex's own
  `media_parts.file` doesn't know about any symlink the storage root itself might be).
- Season — no file of its own, so matched via one of its own episode xref rows' file path, walking
  Plex's `metadata_items.parent_id` from episode → season.
- Show — matched by exact title against Plex's own show-level (`metadata_type=2`) records.

Plex's `metadata_items.metadata_type`: `1`=movie, `2`=show, `3`=season, `4`=episode. Its
`tags`/`taggings` `tag_type`: `1`=genre, `4`=director, `5`=writer, `6`=actor/star (not documented
anywhere by Plex itself, confirmed empirically). **Genre never exists below show level** in Plex's
own data model — a season-level record carries no genre/director/writer/star/rating/duration at
all; that data only exists one level down, per-episode.

Three separate, idempotent reload actions per edit page (kept deliberately separate — different
weight/frequency, not one action doing everything):
- **Reload Metadata** — text fields + external-ID guids. Rebuild-not-diff: deletes every xref row
  it's about to write before re-inserting, since a repeated run with no delete step just appends
  duplicates (there's no natural per-value key to update in place).
- **Reload Images** — alternate poster/backdrop artwork from Plex's `/posters`/`/arts` local API
  endpoints (TMDB-backed, `w342`/`w780` presets re-resized down to a 400px bounding box via the
  shared resize helper below). Idempotent **per type** (poster vs. art) — a type only re-fetches
  if every existing row of that type has first been deleted, so tidying one type down to empty
  doesn't block ever re-fetching it without also wiping the other type. Also auto-attaches Plex's
  own currently-`selected="1"` poster as the real thumbnail attachment the first time it runs
  (checked via an empty attachment slot, so a later manual override is never clobbered). **Season
  only**: if Plex genuinely has zero photos (no match, no `fisheye_plex_token` configured, or a
  real match with no artwork at all — not uncommon for a barebones single-episode entry), falls
  back to grabbing a video frame — see below.
- **Load Episodes** (season only) — pulls the season's full episode list from Plex, including each
  episode's own text metadata and its own Plex-generated screenshot thumbnail. **No Plex match at
  all** (a manually-curated show Plex/TVDB has never heard of): falls back to registering every
  real episode file already in the season's folder directly, parsing the `SnnEnn`/title out of the
  `Show - SnnEnn - Title.ext` filename convention, with each episode's thumbnail sourced from a
  local video frame grab (see below) instead of Plex's API. Reachable without visiting this page
  at all whenever a season's on-disk file count exceeds what's registered — `load_program.php`
  (already linked from the show page's own icon bar) detects the mismatch and offers a one-click
  reload there directly, since most shows here are single-season and never need this page for
  anything else.

## Video frame-grab fallback

`\Bitweaver\Liberty\mime_film_grab_video_frame( $pSourceFile, $pDestJpegPath, $pSeekSeconds=60 )`
(`liberty/plugins/mime.film.php`) — ffmpegthumbnailer first, falling back to a plain `ffmpeg` seek-
and-grab. Originally built for a plain film's own attachment thumbnail
(`mime_film_get_thumbnail_url()`), factored out so content types with no attachment of their own
can call it directly against a video file.

`FisheyeMediaTrait::grabVideoFrameIntoImageXref( $pVideoFile )` — the shared "grab a frame from
this video and store it as a new `image` xref on this content object" engine (resize, next-
`xorder`, `storeXref()`). Two callers, both public and exposed as an on-demand "Grab Thumbnail from
Video" link on the Images tab (`$gContent->canGrabVideoFrame()` gates it — see above) as well as
internally by Reload Images' own automatic fallback:
- `FisheyeSeason::grabVideoFrameImage()` — grabs from its own seed episode file.
- `FisheyeProgram::grabVideoFrameImage()` — a show has no video of its own, so walks its seasons
  (`loadImages()`) for the first one with a usable episode file.

A manual click always grabs a fresh one (no "already has an image" check — a deliberate "add one
more", same as uploading via Add Image); the automatic fallback inside Reload Images checks first
and is a no-op once a real `image` xref already exists.

## Generic xref-file hooks (`liberty/edit_xref.php`)

The shared xref controller knows nothing about this package specifically — three
`method_exists()`-gated hooks let a content class handle its own file lifecycle for an xref row
that references a file:
- `replaceXrefFile( $pItem, $pXkeyExt, $pTmpPath )` — an uploaded file replaces what an xref row
  already references, in place (the row's own `xkey_ext` never changes). **Refuses when
  `$pXkeyExt` is empty** — correct for its actual job, but means a row with no file yet (e.g. one
  created via the old generic add-then-edit flow, before Add Image existed) can never be fixed
  through this route; use Add Image to create a fresh row instead of trying to "edit" a blank one.
- `deleteXrefFile( $pItem, $pXkeyExt )` — cleans up the physical file on a real hard-delete
  (`expunge=3`) of the row, distinguished from an Archive (soft-delete via `update` permission).
- `promoteImageToThumbnail( $pRelativePath )` — see above.

Each content class implements these against its own image storage location (`getExtraImagePath()`)
and its own understanding of which `item` values apply — the controller just calls them
generically if they exist.

**`FisheyeMediaTrait::addImageXrefFile( $pTmpPath, $pOriginalName )` is a related but separate
mechanism** — not one of `edit_xref.php`'s three hooks (it *creates* a new row rather than acting
on an existing one), called instead from the dedicated `add_image_xref.php` page the Images tab's
own group-tab override links to. Resolves its destination via `getExtraImagePath('')` (returns
empty for a content type with no image storage location), and by `$gContent->supportsAddImage()`
for the template-visible check (see above).

## Video playback

A film's own video file is a real `LibertyMime` attachment, played via fisheye's standard
`liberty/mime/video/player.tpl` (`<video>` tag fed `media_url`/`source_url`/`mime_type`/
`download_url`). An episode's own video file is **not** an attachment — it's a raw xref
`xkey_ext` path — so it has no equivalent serving route by default. `play_episode.php` fills this
gap: an `xref_id`-only, no-path-from-user-input streaming endpoint with real single-range HTTP
Range support (needed for a `<video>` element's own seek bar; without it, a large file often won't
even start playing in some browsers).

Deliberately a plain link rather than an inline `<video>` tag on any page listing episodes — a
real media library commonly mixes containers Chrome/Firefox play natively (`.mp4`) with ones they
don't reliably support inline (`.mkv`). A `<video src>` pointed at an unsupported container fails
silently with no visible error; a plain link lets the browser or OS decide (inline playback if it
can, an external player or a download prompt otherwise).

## Known limitations / not yet built

- Show/Composer as a genuine top-level browsable type (the `FoodDay`-pattern computed listing) —
  not built; a show today is a real gallery object, browsed by drilling down from a parent gallery
  rather than any kind of aggregated cross-show view. Artist got its own real page instead (the
  "Music discography" section's Plex-style strip layout) rather than this computed-listing pattern.
- Fully-automatic "scan the whole storage root and register anything new, no picking" import —
  `load_film.php`/`load_program.php` (see above) cover discover-and-pick, capped at 20 at a time;
  nothing yet walks a whole library unattended.
- Season-level Plex metadata reload — deliberately not built; Plex's own data model has nothing
  at that level to fetch for this kind of content.
- Music/album/track build-out — see the dedicated "Music discography" section above for current
  shape (category flattening, artist strip layout, track data cleanup). Still not built: a
  whole-library unattended scan (per-artist `load_album.php`/`load_video.php` are discover-and-pick,
  same as Film/Program, not a walk-everything importer); Discogs as an actual image source (the
  `discogs` xref item is just an external link today, same as `mbid`); linking `artist`/`composer`/
  `conductor`/`orchestra`/`performer` to a real `Contact` record instead of a plain-text xref
  (design at `contact/MANUAL-WIKI.md`, not started); and, following on from that, modelling a band/
  ensemble as a `ContactBusiness` with membership that changes over time — an open question noted
  in that same design doc, not just an implementation gap.
