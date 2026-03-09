# System Architecture -- MyDJRequests

## Overview

Event Requests ↓ Library Matching Engine ↓ DJ Library Database ↓
Playlist Builder ↓ M3U Export for Rekordbox

## Components

### 1. Rekordbox Importer

-   Parses Rekordbox XML
-   Extracts track metadata
-   Stores in dj_library table

### 2. Library Matcher

-   Compares requested songs with DJ library
-   Uses normalized search keys

### 3. Playlist Builder

-   Selects best version of each track
-   Generates M3U playlist for Rekordbox

### 4. Missing Track Reporter

-   Shows requested songs not found in DJ library
-   Suggests Spotify links
