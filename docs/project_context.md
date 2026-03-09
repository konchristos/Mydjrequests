# Project Context -- MyDJRequests

## Goal

Allow DJs to receive song requests and automatically build playlists
from their existing music collection.

## Core Capabilities

1.  Accept song requests from event guests
2.  Import DJ library from Rekordbox XML
3.  Match requested songs to owned tracks
4.  Generate preparation playlists for DJs
5.  Highlight missing songs before events

## Constraints

-   Library scanning must be read‑only
-   XML files may exceed 300MB
-   System must support 100k+ tracks
-   Import process must be memory efficient

## Technology Stack

Backend: PHP Database: MySQL External APIs: Spotify (for metadata
enrichment) DJ Software: Rekordbox

## Key Feature Modules

-   Rekordbox XML importer
-   Library matching engine
-   Playlist generator
-   Event request system
-   Spotify metadata enrichment
