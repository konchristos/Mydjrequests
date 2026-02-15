<?php
require_once __DIR__ . '/../app/bootstrap.php';

// ⚠ NO require_dj_login() here

// Page title fallback
$pageTitle = $pageTitle ?? "DJ Login - MyDJRequests";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo e($pageTitle); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">

<style>
    body {
        margin: 0;
        background: #0d0d0f;
        color: #fff;
        font-family: 'Inter', sans-serif;
    }

    .content {
        padding: 40px;
        max-width: 550px;
        margin: 60px auto;
    }

    h1 {
        color: #ff2fd2;
        margin-bottom: 25px;
        text-align: center;
    }

    .card {
        background: #1a1a1f;
        border: 1px solid #292933;
        padding: 30px;
        border-radius: 8px;
        margin-top: 20px;
    }

    input {
        width: 100%;
        padding: 12px;
        margin-top: 8px;
        background: #0f0f12;
        border: 1px solid #292933;
        border-radius: 6px;
        color: #fff;
    }

    input:focus {
        border-color: #ff2fd2;
        outline: none;
    }

    button {
        width: 100%;
        margin-top: 20px;
        background: #ff2fd2;
        border: none;
        padding: 12px;
        border-radius: 6px;
        color: #fff;
        font-size: 16px;
        cursor: pointer;
    }

    button:hover {
        background: #ff4ae0;
    }

    a {
        color: #ff2fd2;
        text-decoration: none;
    }
</style>
</head>
<body>

<div class="content">