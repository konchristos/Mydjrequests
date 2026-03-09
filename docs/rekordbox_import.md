# Rekordbox XML Import

## Purpose

Import a DJ's local Rekordbox library into MyDJRequests.

## XML Structure

`<DJ_PLAYLISTS>`{=html} `<COLLECTION>`{=html} `<TRACK />`{=html}
`</COLLECTION>`{=html} `<PLAYLISTS>`{=html} `</DJ_PLAYLISTS>`{=html}

Only the COLLECTION section is required.

## Import Strategy

Use PHP XMLReader to stream parse the file.

Steps: 1. Open XML file 2. Jump to COLLECTION node 3. Iterate through
TRACK nodes 4. Extract metadata 5. Batch insert records into database

## Performance Notes

-   Parse in batches (500 records per insert)
-   Avoid loading full XML into memory
-   Skip PLAYLISTS section entirely
