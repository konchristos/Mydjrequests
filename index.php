<?php
// Load global maintenance mode + app
require_once __DIR__ . '/app/bootstrap.php';

// Load your real public landing page
//require_once __DIR__ . '/landing_original.php';


// Load your current public landing page
require_once __DIR__ . '/landing_modern_light_hybrid.php';
