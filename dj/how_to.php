<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login();

$pageTitle = "How To - MyDJRequests";

require __DIR__ . '/layout.php';
?>

<style>
    .howto-wrap { max-width: 980px; margin: 0 auto; }
    .howto-hero {
        background: #121218;
        border: 1px solid #252531;
        border-radius: 14px;
        padding: 22px;
        margin-bottom: 22px;
    }
    .howto-hero h1 { margin: 0 0 8px; font-size: 28px; }
    .howto-hero p { margin: 0; color: #bbb; }

    .howto-section {
        background: #13131a;
        border: 1px solid #252531;
        border-radius: 14px;
        padding: 20px;
        margin-bottom: 18px;
    }
    .howto-section h2 {
        margin: 0 0 10px;
        font-size: 20px;
        color: #ff2fd2;
    }
    .howto-section h3 {
        margin: 14px 0 6px;
        font-size: 16px;
        color: #e6e6f0;
    }
    .howto-section p { margin: 6px 0; color: #cfcfd8; line-height: 1.6; }
    .howto-section ul { margin: 6px 0 0 18px; color: #cfcfd8; }
    .howto-section li { margin: 6px 0; }
    .howto-note {
        margin-top: 10px;
        font-size: 13px;
        color: #9c9cab;
    }
    .badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 700;
        margin-right: 6px;
    }
    .badge-upcoming { background: rgba(0, 153, 255, 0.15); color: #6cc6ff; }
    .badge-live { background: rgba(95, 219, 110, 0.18); color: #5fdb6e; }
    .badge-ended { background: rgba(255, 87, 87, 0.15); color: #ff8b8b; }
</style>

<div class="howto-wrap">
    <div class="howto-hero">
        <h1>How To Use MyDJRequests</h1>
        <p>Detailed guidance for DJs (and a patron overview) during Alpha testing.</p>
    </div>

    <div class="howto-section">
        <h2>Quick Start Checklist</h2>
        <ul>
            <li>Complete your public profile so guests can recognise you.</li>
            <li>Create an event inside <strong>My Events</strong>.</li>
            <li>Open the event to access the QR code and request link.</li>
            <li>Set the event status to <strong>Live</strong> when you start.</li>
            <li>Watch requests in your DJ panel and update statuses as you play.</li>
        </ul>
        <p class="howto-note">Tip: Use the request link/QR before the event so guests can bookmark it.</p>
    </div>

    <div class="howto-section">
        <h2>Managing Events</h2>
        <p>Create events for each gig or stream so requests are grouped correctly.</p>
        <h3>Event lifecycle</h3>
        <p>
            <span class="badge badge-upcoming">Upcoming</span>
            Your event is scheduled and visible. Guests can prepare requests.
        </p>
        <p>
            <span class="badge badge-live">Live</span>
            Requests are active and the event is treated as in progress.
        </p>
        <p>
            <span class="badge badge-ended">Ended</span>
            The event is finished; new requests should stop.
        </p>
        <p class="howto-note">Only one event can be Live at a time. Going Live will end any other Live event automatically.</p>
    </div>

    <div class="howto-section">
        <h2>Print QR Code for Venue</h2>
        <p>Use this for in-person events so patrons can scan from around the room.</p>
        <ul>
            <li>Download and print the event QR code to place around the venue (booth, bar, entry, tables).</li>
            <li>Use multiple print sizes depending on venue layout and lighting.</li>
            <li>Test scan distance before the event starts.</li>
        </ul>
    </div>

    <div class="howto-section">
        <h2>Dynamic QR Code + OBS (Streaming)</h2>
        <p>The event page includes a dynamic QR code and an OBS Browser Source URL for livestream overlays.</p>
        <ul>
            <li>Open your event and find the <strong>OBS Browser Source URL</strong>.</li>
            <li>Copy the URL and add it as a Browser Source in OBS.</li>
            <li>Set a suitable width/height in OBS so the QR code stays sharp.</li>
            <li>The QR code updates automatically as your event changes.</li>
        </ul>
        <p class="howto-note">The OBS URL looks like: <code>qr/live_embed.php?dj=YOUR_UUID&amp;t=init</code></p>
    </div>

    <div class="howto-section">
        <h2>Requests + Guest Messaging</h2>
        <p>Guests use your request link to send songs and messages while the event is Live.</p>
        <ul>
            <li>Keep your event status accurate so guests see the right message.</li>
            <li>Use the dashboard/event view to monitor incoming requests.</li>
            <li>Update statuses when songs are played to keep guests informed.</li>
        </ul>
    </div>

    <div class="howto-section">
        <h2>Message Statuses (Upcoming, Live, Ended)</h2>
        <p>
            Guests see a status banner based on your event state. You can view the default
            messages in <strong>Profile ▾ → Settings → Message Statuses</strong>.
        </p>
        <p class="howto-note">These defaults are fixed during Alpha and apply to all events.</p>
    </div>

    <div class="howto-section">
        <h2>Notifications</h2>
        <p>The bell icon shows recent activity such as bug reports, feedback, and broadcast messages.</p>
        <ul>
            <li>Click a notification to open the related page.</li>
            <li>Unread items are highlighted and clear automatically after you open them.</li>
        </ul>
    </div>

    <div class="howto-section">
        <h2>Bug Reports + Feedback</h2>
        <p>During Alpha testing, use these tools to track issues and ideas.</p>
        <ul>
            <li><strong>Bug Tracker</strong> is for logged-in DJs to report problems.</li>
            <li><strong>Feedback</strong> is available to the public and appears in the admin report.</li>
            <li>Admins can add comments, update status/priority, and merge duplicates.</li>
        </ul>
    </div>

    <div class="howto-section">
        <h2>Security Essentials</h2>
        <ul>
            <li>Share the QR/request link only for the event you want active.</li>
            <li>Do not reuse event links for unrelated gigs.</li>
            <li>Use Recovery Codes from your Account page in case you lose email access.</li>
        </ul>
    </div>

    <div class="howto-section">
        <h2>Patron Overview (So You Understand the Experience)</h2>
        <p>Guests see a simple request page where they can:</p>
        <ul>
            <li>Submit song requests while your event is Live.</li>
            <li>Send private messages and shoutout requests to the DJ.</li>
            <li>View your DJ profile and social links.</li>
            <li>Save your contact details to their phone with one tap.</li>
            <li>See your event status message (Upcoming/Live/Ended).</li>
            <li>Receive confirmations and updates based on your actions in the DJ panel.</li>
        </ul>
        <p class="howto-note">This section helps you explain the flow to venues and guests.</p>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
