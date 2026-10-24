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

## Books

Books are catalogued one **edition** at a time (the ISBN is the key), with their credits, publisher, series,
cover and pictures. A book is a record of its own, filed in a gallery for its author (or in a category gallery
when the author has only one or two books), under a top-level **Library** gallery. Authors and publishers are
Contact records, so a contact's page lists the books that credit them.

### Loading a library from a catalogue spreadsheet

The loaders work from a spreadsheet with one row per book (title, author(s), ISBN, publisher, year, format,
category and notes). They are the icons on the Library gallery's toolbar. Run them in this order; each page
shows what is loaded, what is next and how many are done, and works in small batches:

1. **Authors from Open Library** - reads the uploaded sheet, groups the books by author and creates the author contacts
   (using Wikidata and Open Library where they know the person). Tick the authors to create. Authors with one
   or two books can have those loaded in the same step.
2. **Add Books** (or **Add this author's books** on an author's gallery) - a tick-list of that author's books still to load, ten at a
   time. Each book is filled from the sheet and from Open Library.
3. **Series from Wikidata and Open Library** and **Series from folders** - propose series, let you choose the
   grouping, put a book into an existing series and rearrange the order. Books in a series are filed under
   the series rather than in the author's own gallery.
4. **Book details from Open Library** - fills blank fields from Open Library, finds covers, lists the books with no image at
   all and has a box for replacing placeholder covers.

### Covers and pictures

- **Cover:** the book's front cover is its tile. It is found automatically (Open Library, then Google Books)
  or added by hand; the first image added to a book with no tile becomes the tile. Covers are kept at no more
  than 400 pixels on the longest side, the size the Library shows.
- **Covers from a gallery:** **Covers from a gallery** reads a gallery of cover pictures on another
  bitweaver site (images titled with the book's ISBN-13), matches them to books, shows the current cover
  beside the new one and replaces the ones you tick.
- **Extras:** a film, album, book, TV season or programme page has an **Extras** button beside its title. The button counts what is behind it by kind - for example **Featurettes (12) Extras (5)** - and shows only the kinds there are. It opens a
  panel over the top of the page body. With more than one kind of material the panel has a tab for each, and each
  tab has its own icons: a film's **Featurettes** tab (its bonus videos: pick a card for the details, **Play**
  swaps the film's own player for it and closes the panel, press the button again to go back to the film) has
  **Reload Featurettes**, and the **Extras** tab (the item's own images and those in its pictures folder - the same kind as the button's
  **Extras (n)** count; with only that kind there are no tabs and no heading) has
  **Add Pictures** and **Open pictures folder**. The edit icon at the top right of the page is the way to edit
  the item itself. The button is hidden when there is nothing to show and nothing the visitor could add. The
  list is also a page of its own (`extras.php?content_id=`), so it can be linked to.
  On a TV season (and a single-season show) the episodes are the page, shown without tabs; the season's featurettes
  and pictures are in the Extras panel.
- **Pictures folder:** an item's extra pictures (front, spine, back, inside pages, posters, booklet scans) live
  in an ordinary fisheye gallery, **Images / <Types> / <title>** (for example `Images / Books / <book title>`).
  **Add Pictures** makes the folder if it is not there yet and opens the normal upload with it already chosen.
  The folder behaves like any other gallery (layout, ordering, comments). On the item's edit page the **Images**
  tab lists the item's own images and the folder's, **Set as Thumbnail** works on either kind, and **Move to
  pictures** moves an image of the item's own into the folder; the tile is unaffected.
- A site that holds book pictures elsewhere can bring them in with a **Local Imports** script
  (see the liberty manual), which files them into these folders.

### Adding books from a list of ISBNs

**Add Books from ISBNs** (the plus icon on the Library gallery) is the quick way to add books that are not on
a spreadsheet - best done one author at a time.

1. Type or paste the ISBNs (one per line, or separated by commas or spaces; up to 20 at a time) and press
   **Look up**. Nothing is saved yet. Each ISBN is read from Open Library and listed with its title, authors
   and where it would be filed.
2. The list says whether the author has a gallery, will get one with this batch, or the book will be filed
   under the **Category** you gave (Fiction by default). An ISBN already in the Library cannot be ticked.
3. An ISBN Open Library cannot place gets a title and author box. Fill both in and press **Look up again**
   to load it from what you typed (its cover and details are still fetched where Open Library has any).
4. **Load the ticked books** adds them, with credits, cover, and the series Wikidata gives.

An author with two or more books gets a gallery of their own. When it is made, books of theirs already in the
Library are brought into it and taken out of the Category gallery they were sitting in, so the next Clarke
or Feist book moves the earlier one out of Fiction by itself. A book's own Category is not changed.

### Adding or correcting a book's details by hand

On a book's edit page the **Book Details** tab lists the details held for the book; each row has its own edit icon and the tab has an add
link for anything that is missing (pages, format, a publisher...).

- **ISBN:** the ISBN row shows the ISBN-13 and the ISBN-10. Add or edit it by typing either number, with or without hyphens; the other form is
  worked out and stored beside it, and a number whose check digit is wrong is refused. A book with no ISBN can be given one this way.
- **Reload from Open Library** (on the same page) then looks the book up by its ISBN and fills in what is blank: the Open Library ids, pages,
  format, language, publisher and a cover. It never replaces something that is already filled in.

- **Reload from Wikidata** (beside Reload from Open Library) asks Wikidata about the book through its author and adds, where the book has
  nothing yet, the series it belongs to with its number ("The Lord of the Rings, #1") and the work's Wikidata id. The same two details are
  written automatically when a book is filed into a series as it is loaded, so the book carries them even where Open Library has no series
  text. Like the Open Library reload it only fills blanks. It does not move the book between galleries; the series page does that. It needs
  the author to be a contact with a Wikidata item.

### Credits and contacts

Authors, editors, illustrators, translators and narrators are linked to Contact records. If a credit shows as
plain text, **Link Book Credits** (dry run first, then `?apply=1`) links every credit whose name resolves to one
contact. **Merge Contact Credits** moves one contact's book credits and other names to another when a person
was entered twice. A book's credited people appear on its page; a contact's page lists the books that credit
them.

## Playing

Films, episodes and tracks play from links on their pages. Browsers play `.mp4` directly; for
`.mkv` and similar the browser may hand the file to a player or offer a download.

## Good to know

- **Deleting a music gallery doesn't delete its albums** - they're left unlinked, and the loader
  then won't offer their folders again. Delete the albums first (each album's edit page), or ask an
  administrator.
- Retagging in Picard then **Reload Tracks** is the normal way to correct album and track details.
- Loaders work in batches (20 films/shows, 10 albums, 10 people) to keep each page quick.
