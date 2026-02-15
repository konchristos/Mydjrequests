<?php
header('Content-Type: application/json');

echo json_encode([
    'client_id_exists'     => (bool)getenv('SPOTIFY_CLIENT_ID'),
    'client_secret_exists' => (bool)getenv('SPOTIFY_CLIENT_SECRET'),
    'client_id_length'     => strlen((string)getenv('SPOTIFY_CLIENT_ID')),
    'client_secret_length' => strlen((string)getenv('SPOTIFY_CLIENT_SECRET')),
]);