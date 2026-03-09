# Playlist Engine

## Purpose

Generate DJ preparation playlists based on event song requests.

## Workflow

Host submits requests ↓ System matches tracks against DJ library ↓ Best
versions selected ↓ Playlist generated ↓ DJ imports playlist into
Rekordbox

## Playlist Format

#EXTM3U /path/to/file.mp3

## Track Selection Rules

1.  Prefer intro edits
2.  Prefer extended mixes
3.  Avoid radio edits where possible

## Future Enhancements

-   BPM matching
-   Harmonic key matching
-   Energy flow optimization
