<?php
// public_html/privacy.php
require_once __DIR__ . '/app/bootstrap_public.php';

$pageTitle = 'Privacy Policy';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo e($pageTitle); ?> | MYDJREQUESTS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>

<style>
body {
    background: #0b0b10;
    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    margin: 0;
    padding: 0;
}

.privacy-wrap {
    max-width: 760px;
    margin: 40px auto;
    padding: 0 16px;
}

.privacy-card {
    background: #111116;
    border: 1px solid #1f1f29;
    border-radius: 12px;
    padding: 20px;
}

.privacy-card h1 {
    color: #ffffff;
    font-size: 26px;
    margin-top: 0;
    margin-bottom: 6px;
}

.privacy-card h2 {
    color: #f2f2f7;
    font-size: 18px;
    margin-top: 26px;
    margin-bottom: 8px;
    border-bottom: 1px solid #2a2a35;
    padding-bottom: 4px;
}

.privacy-card p,
.privacy-card li {
    color: #d7d7df;
    line-height: 1.6;
}

.privacy-card .meta {
    color: #aaa;
    font-size: 13px;
    margin-bottom: 16px;
}

.privacy-card a {
    color: #ff7be6;
    text-decoration: none;
}
.privacy-card a:hover {
    text-decoration: underline;
}


.privacy-nav {
    display: flex;
    gap: 12px;
    margin-bottom: 14px;
}

.nav-btn {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 999px;
    background: #1f1f29;
    color: #ff7be6;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    border: 1px solid #2a2a35;
}

.nav-btn:hover {
    background: #2a2a35;
}


</style>

<body>

<div class="privacy-wrap">
    <div class="privacy-card">
        <h1>Privacy Policy</h1>
        
        <div class="privacy-nav">
    <a href="javascript:history.back()" class="nav-btn">← Back</a>
    <a href="/" class="nav-btn">Home</a>
</div>
        
        <div class="meta">Last updated: February 8, 2026</div>


<h2>1. Introduction</h2>
<p>
    MYDJREQUESTS (“we”, “us”, “our”) is committed to protecting your privacy.
    This Privacy Policy explains how we collect, use, store, and disclose
    personal information when you use the MYDJREQUESTS platform (“Service”).
</p>
<p>
    We comply with the <strong>Privacy Act 1988 (Cth)</strong> and the
    <strong>Australian Privacy Principles (APPs)</strong>.
</p>

<h2>2. Personal Information We Collect</h2>
<ul>
    <li>Name and email address (DJ accounts)</li>
    <li>Account credentials and profile information</li>
    <li>IP address, device information, and browser type</li>
    <li>Cookies, session identifiers, and guest tokens</li>
    <li>Messages, song requests, votes, and event interaction data</li>
    <li>Support enquiries and communications</li>
</ul>
<p>
    We do not intentionally collect sensitive information.
</p>

<h2>3. How We Collect Personal Information</h2>
<ul>
    <li>When you create or manage an account</li>
    <li>When you use the Service or access event pages</li>
    <li>When you submit requests, messages, or feedback</li>
    <li>When you contact us for support</li>
    <li>Through cookies and similar technologies</li>
</ul>

<h2>4. How We Use Personal Information</h2>
<ul>
    <li>Operate, maintain, and improve the Service</li>
    <li>Authenticate users and secure accounts</li>
    <li>Enable event functionality and messaging</li>
    <li>Troubleshoot issues and monitor performance</li>
    <li>Comply with legal and regulatory obligations</li>
</ul>

<h2>5. Cookies</h2>
<p>
    We use cookies and similar technologies to maintain sessions,
    support core functionality, and improve user experience.
    Disabling cookies may affect Service functionality.
</p>

<h2>6. Disclosure of Personal Information</h2>
<ul>
    <li>Service providers (such as hosting and email providers)</li>
    <li>Payment providers (for example, Stripe, when enabled)</li>
    <li>Regulators, courts, or law enforcement where required by law</li>
</ul>
<p>
    We do not sell personal information.
</p>

<h2>7. Overseas Processing</h2>
<p>
    Some third-party service providers may process personal information
    outside Australia. We take reasonable steps to ensure appropriate
    safeguards are in place.
</p>

<h2>8. Data Security</h2>
<p>
    We take reasonable steps to protect personal information from misuse,
    loss, unauthorised access, modification, or disclosure.
    However, no system is completely secure.
</p>

<h2>9. Data Retention</h2>
<p>
    We retain personal information only for as long as reasonably necessary
    to operate the Service, comply with legal obligations, resolve disputes,
    and enforce our agreements.
</p>
<p>
    Information may be retained after account closure where required by law
    or for legitimate business purposes, including security, fraud prevention,
    and dispute resolution.
</p>

<h2>10. Children and Minors</h2>
<p>
    The Service is not directed at children under the age of 13.
    If we become aware that personal information has been collected from
    a child without appropriate consent, we will take reasonable steps
    to delete that information.
</p>

<h2>11. Logs and Analytics</h2>
<p>
    We may collect technical logs and usage data for security, analytics,
    performance monitoring, and troubleshooting purposes.
    This data may be used in identifiable or aggregated form where
    reasonably necessary to operate and protect the Service.
</p>

<h2>12. Access and Correction</h2>
<p>
    You may request access to, or correction of, your personal information
    by contacting us using the details below.
</p>

<h2>13. Complaints</h2>
<p>
    If you have a complaint about how we handle personal information,
    please contact us first so we can attempt to resolve it.
</p>
<p>
    If unresolved, you may lodge a complaint with the
    Office of the Australian Information Commissioner (OAIC).
</p>

<h2>14. Changes to This Policy</h2>
<p>
    We may update this Privacy Policy from time to time.
    The updated version will be published on this page with a revised
    “Last updated” date.
</p>

<h2>15. Contact</h2>
<p>
    For privacy-related enquiries or requests:<br>
    Email: <a href="mailto:info@mydjrequests.com">info@mydjrequests.com</a><br>
    MYDJREQUESTS (ABN 22 842 315 565)
</p>
    </div>
</div>

</body>
</html>