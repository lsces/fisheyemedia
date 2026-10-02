# Fisheyemedia — User Manual

How to use the media library: films, TV shows and music catalogued from folders on disk, with
artwork and details from your local Plex server, MusicBrainz and Wikidata. For how it works inside
(classes, data shapes, settings stored per site), see [`DEVELOPER.md`](DEVELOPER.md).

## What it does

Fisheyemedia turns the folders of an existing media library into browsable galleries:

- **Films** - one page per film, with poster, details (genre, director, cast...), extra artwork and
  the film itself to play.
- **TV Shows** - a page per show, its seasons, and each season's episodes to play.
- **Music** - a page per artist or composer laid out in strips (Studio, Live, Compilation...), a
  page per album with its tracks and credits, and links from every credited person to their
  contact page (with contactwiki).

Nothing is copied: the files stay where they are, and the site just records what's there.

## Setting up

**Media Library Settings** (in the admin menu):

| Setting | What to put there |
|---|---|
| External Disk Storage Path | The folder holding `Films/` and `Music/` (with a trailing `/`) |
| TV Show Storage Path (A-M / N-Z) | Only if TV shows are split across two folders by first letter; otherwise leave blank and keep `TV Shows/` under the main path |
| Plex Library Database File | The full path to Plex's `com.plexapp.plugins.library.db`, for details and artwork; blank to skip Plex |
| Plex API Token | From Plex's Preferences.xml (`PlexOnlineToken`) - only needed for IMDb/TMDb/TheTVDB/MusicBrainz ids |
| MusicBrainz Contact | Your email or site address - MusicBrainz asks every client to identify itself |

The top-level **Films**, **TV Shows**, **Music** and **Library** galleries are created when the
site's media scheme is applied. The **Fisheye** menu has a jump to each.

## Laying out the library

The loaders read the folders, so the layout matters:

```
Films/<Film>.mkv                       a film (or Films/<Film>/<Film>.mkv with extras)
Films/<Collection>/<Film>.mkv          a collection of films
TV Shows/<Show>/Season 01/<Show> - S01E01 - <Title>.mkv
Music/<Artist>/<Album>/1-01 <Track>.flac
Music/<Artist>/<Group>/<Album>/...     Studio, Live, Compilation, Baroque... - one strip each
Music/<Artist>/<Group>/<Collection>/<Album>/...   a box set or volume inside a group
```

Music rules, kept to strictly:

- **An album holds its tracks itself.** A multi-disc album keeps every track in the one folder,
  numbered disc-track (`1-01`, `2-01`...) - no CD1/CD2 subfolders.
- The only other folder inside an album is **`Artwork/`** for scans.
- **A folder directly inside the artist folder that holds albums is a strip** on the artist page,
  titled with the folder's name - use any name that suits: Studio, Live, Compilation, Baroque, Bach
  2000.
- **A folder inside a strip that holds albums is a collection** - a box set, or one volume of a
  big set. It gets its own page, opened from a tile in its strip.
- Albums sitting straight in the artist folder appear in the first strip, without a heading.

Episode files need an `SxxExx` tag in the name for Plex to recognise them - a one-off can use the
year as the season (`S2003E05`).

## Films

1. Open the **Films** gallery and use its **Load Films** icon. Pick a folder (or the top level),
   tick films, **Import Selected** - up to 20 at a time. Tick "fetch images" only if you want the
   artwork downloaded straight away; otherwise it's fetched when a film is first viewed.
2. **Load Collections** creates a gallery for each collection folder (a folder holding more than
   one film) - do this before importing the films inside it.
3. On a film's edit page: **Reload Metadata** (details from Plex), **Reload Images** (artwork),
   **Delete Film**. The Images tab adds extra artwork, or grabs a frame from the video.

## TV shows

1. Open **TV Shows** and use **Load Seasons**. Pick a show folder - this registers the show - then
   tick its seasons and **Load Selected Seasons**. Each season's episode list comes from Plex.
2. **No Plex match?** If the folder name doesn't match Plex's title (spacing, punctuation), the show
   is created but nothing is fetched yet: open the show's edit page, **Search** Plex and pick the
   right entry. Its details and artwork are fetched as soon as you confirm.
3. A show with only one season opens straight on its episodes - there's no separate "Season 1" page.
4. On a season's edit page, **Load Episodes** picks up episodes added since; on the show, **Reload
   Metadata**/**Reload Images**. **Delete Show** removes the show with all its seasons and episodes
   (the video files are never touched).

## Music

### Loading an artist - one folder at a time

1. Open **Music** and use **Add Music Collection**. Each artist/composer folder not yet loaded has
   a **Process** button.
2. **Process** creates the artist's gallery and goes straight to the **people pass** (with
   contactwiki): every person credited on the artist's albums is listed, matched against existing
   contacts and Wikidata. Create them 10 at a time - see contactwiki's manual. When nobody's left,
   it moves on by itself.
3. **Load Albums** opens with a summary - "41 album folders (12 loaded, 29 still to load), 12
   collections (2 done, 10 still to do)" - and, if the artist has several strips, a **Process:**
   row (Everything · Top level · Studio (n) · Bach 2000 (12)...) to work through one strip at a time.
   Tick albums and **Load Selected Albums**, 10 at a time.
4. A **collection** has its own **Process** button: it opens the collection's page to load its
   albums. When the last batch there is done you're taken back up to the same strip, ready for the
   next one.
5. The page title is a trail of links: **Music** › *the artist* › *the collection* - Load Albums.
   **Music** takes you back to Add Music Collection to Process the next folder, the artist link
   back up from a collection, and the last name opens that gallery to see what's been built.

The artist page's **Load Album** / **Load Videos** icons only appear while there's something left
to load.

### The artist and album pages

- **Artist page** - the albums in strips that follow the folders, then the artist's videos.
- **Album page** - cover, credits grouped by job (artist, composer, conductor, orchestra,
  performer), and the track list by disc. Each credited person links to their contact page; on a
  duet or a "feat." track every artist is named and linked, so the album is found from any of them
  (not just from whoever a player like Plex filed it under).
- On the album's edit page: **Reload Tracks** after retagging the files (changes are merged in -
  anything you corrected by hand is kept, and old values go to the History tab), **Reload Images**,
  **Fetch Discogs Link**. In the Tracks tab, extra artists sit directly under their track.

### Videos

A concert or music video in the artist's `Videos/` folder is loaded with **Load Videos** and plays
like a film. The videos appear as the last strip of the artist page.

## Playing

Films, episodes and tracks play from links on their pages. Browsers play `.mp4` directly; for
`.mkv` and similar the browser may hand the file to a player or offer a download.

## Good to know

- **Deleting a music gallery doesn't delete its albums** - they're left unlinked, and the loader
  then won't offer their folders again. Delete the albums first (each album's edit page), or ask an
  administrator.
- Retagging in Picard then **Reload Tracks** is the normal way to correct album and track details.
- Loaders work in batches (20 films/shows, 10 albums, 10 people) to keep each page quick.
