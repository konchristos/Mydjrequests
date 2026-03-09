# MyDJRequests -- AI Agent Instructions

## Project Overview

MyDJRequests is a web platform allowing DJs to accept song requests from
guests and prepare playlists.

The system integrates with Rekordbox libraries to: - scan a DJ's local
music collection - match host requests to owned tracks - build
preparation playlists for events

## Rekordbox Library Import

Rekordbox XML files can be very large (200--300MB).

Rules for import: - Always stream parse using XMLReader (never load full
XML) - Only parse the `<COLLECTION>`{=html} section - Extract TRACK
nodes only - Ignore PLAYLISTS and TEMPO sections

Fields to extract: - Name - Artist - AverageBpm - Tonality - Genre -
Location

Location conversion: file://localhost/Users/... → /Users/...

## Database Table

dj_library

Fields: - id - artist - title - bpm - key - genre - file_path -
search_key

search_key = lower(artist + " " + title)

## Constraints

DO NOT: - modify user music files - delete duplicate tracks - modify
Rekordbox data

This system must always remain read‑only.

## Playlist Generation

Output playlists should be generated as M3U:

#EXTM3U /path/to/file.mp3
