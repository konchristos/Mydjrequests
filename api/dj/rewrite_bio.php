<?php
// /api/dj/rewrite_bio.php
header("Content-Type: application/json");

// Load bootstrap (for session + db + config)
require_once __DIR__ . '/../../app/bootstrap.php';

// Must be logged in
require_dj_login();

$djId = $_SESSION['dj_id'] ?? 0;
if (!$djId) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// ======================
//  INPUTS
// ======================
$bio    = trim($_POST['bio'] ?? '');
$tone   = trim($_POST['tone'] ?? 'friendly');  // default
$length = trim($_POST['length'] ?? 'medium');  // default

if ($bio === '') {
    echo json_encode(['success' => false, 'error' => 'Bio cannot be empty']);
    exit;
}

// ======================
//  API KEY
// ======================
$apiKey = OPENAI_API_KEY;

if (!$apiKey) {
    echo json_encode(['success' => false, 'error' => 'OpenAI API key missing']);
    exit;
}

// ======================
//  TONE MAPPING
// ======================
$toneLabels = [
    "professional" => "Write in a clean, polished, confident professional tone.",
    "friendly"     => "Write in a warm, conversational, welcoming tone.",
    "energetic"    => "Write in an upbeat, lively, high-energy tone.",
    "dj_hype"      => "Write with hype, charisma, DJ swagger, excitement and crowd appeal."
];

$toneInstruction = $toneLabels[$tone] ?? $toneLabels['friendly'];

// ======================
//  LENGTH MAPPING
// ======================
$lengthLabels = [
    "short"  => "Keep the bio very short (1–2 sentences).",
    "medium" => "Keep it moderately short (2–4 sentences).",
    "long"   => "Make it slightly longer but still readable (4–6 sentences)."
];

$lengthInstruction = $lengthLabels[$length] ?? $lengthLabels['medium'];

// ======================
//  FINAL PROMPT
// ======================
$prompt = "
Rewrite this DJ biography. Do NOT invent facts.

Tone:
$toneInstruction

Length:
$lengthInstruction

Bio:
$bio
";

$payload = [
    "model" => "gpt-4o-mini",
    "messages" => [
        ["role" => "system", "content" => "You are a world-class editor specializing in DJ biographies."],
        ["role" => "user", "content" => $prompt]
    ],
    "temperature" => 0.7
];

// ======================
//  CURL REQUEST
// ======================
$curl = curl_init("https://api.openai.com/v1/chat/completions");
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "Authorization: Bearer $apiKey"
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
]);

$response = curl_exec($curl);
$error = curl_error($curl);

// Debug: raw response from OpenAI
file_put_contents("/home/mydjrequests/openai_debug.log", $response);

curl_close($curl);

// Handle failure
if ($error) {
    echo json_encode(['success' => false, 'error' => 'OpenAI error: ' . $error]);
    exit;
}

$data = json_decode($response, true);

if ($data === null) {
    echo json_encode([
        'success' => false,
        'error'   => 'JSON decode failed',
        'raw_response' => $response
    ]);
    exit;
}

// Validate
if (!isset($data["choices"][0]["message"]["content"])) {
    echo json_encode([
        'success' => false,
        'error'   => 'Invalid API response',
        'raw'     => $data
    ]);
    exit;
}

$rewritten = trim($data["choices"][0]["message"]["content"]);

echo json_encode([
    'success' => true,
    'rewritten_bio' => $rewritten
]);