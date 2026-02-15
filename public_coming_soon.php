<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>MyDJRequests – Coming Soon</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            background: #0d0d0f;
            color: #fff;
            text-align: center;
        }
        .hero {
            padding: 160px 20px;
            background: radial-gradient(circle at center, #550066 0%, #0d0d0f 70%);
        }
        h1 {
            font-size: 52px;
            color: #ff2fd2;
        }
        p {
            font-size: 20px;
            color: #d8d8d8;
            max-width: 600px;
            margin: 0 auto 40px;
        }
        .footer {
            margin-top: 100px;
            color: #888;
            font-size: 14px;
        }
        
        
        
        .signup-box {
    margin-top: 40px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 14px;
}

.signup-box input {
    width: 100%;
    max-width: 360px;
    padding: 14px 16px;
    border-radius: 14px;
    border: 1px solid rgba(255,255,255,0.15);
    background: #0d0d12;
    color: #fff;
    font-size: 16px;
}

.signup-box input::placeholder {
    color: #888;
}

.signup-box button {
    padding: 14px 22px;
    border-radius: 14px;
    border: none;
    background: linear-gradient(135deg, #ff2fd2, #6ae3ff);
    color: #000;
    font-weight: 700;
    font-size: 16px;
    cursor: pointer;
    box-shadow: 0 0 14px rgba(255,47,210,0.4);
}

.signup-box button:hover {
    transform: translateY(-1px);
}

.status {
    font-size: 14px;
    color: #aaa;
}


.hero {
    position: relative;
    overflow: hidden;
    padding: 160px 20px;
    background: radial-gradient(circle at center, #550066 0%, #0d0d0f 70%);
}

.dashboard-tease {
    position: absolute;

    /* center it visually */
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%) rotate(-6deg);

    width: 1200px;
    height: 700px;

    background-image: url('/assets/images/dj-dashboard-tease.png');
    background-size: cover;
    background-position: center;

    opacity: 0.12;          /* more noticeable */
    filter: blur(2px);      /* still soft, but readable */

    pointer-events: none;

    /* soft vignette fade */
    mask-image: radial-gradient(
        circle at center,
        black 60%,
        transparent 78%
    );
}


.hero:hover .dashboard-tease {
    transform: rotate(-7deg) translateY(-6px);
    transition: transform 18s ease;
}


.dashboard-tease::after {
    content: "";
    position: absolute;
    inset: 0;
    background: radial-gradient(
        circle at center,
        rgba(255,47,210,0.08),
        transparent 70%
    );
    pointer-events: none;
}



.hero-logo {
    width: 100%;
    max-width: 420px;   /* controls desktop size */
    height: auto;
    margin: 0 auto 20px;
    display: block;
}


/*.hero-logo {
    filter: drop-shadow(0 0 18px rgba(255,47,210,0.45))
            drop-shadow(0 0 30px rgba(47,216,255,0.25));
}*/

/* Mobile tweak */
@media (max-width: 480px) {
    .hero-logo {
        max-width: 300px;
        margin-bottom: 16px;
    }
}
        
        
    </style>
</head>

<body>
<div class="hero">
    <div class="dashboard-tease"></div>

<img
  src="/assets/logo/MYDJRequests_Logo-white.png"
  alt="MyDJRequests"
  class="hero-logo"
/>
    <p>We're building something amazing. Please check back soon.</p>



<div class="signup-box">
    <input
        type="email"
        id="notify_email"
        placeholder="Enter your email"
        autocomplete="email"
    >
    <button id="notify_btn">Notify me</button>
    <div id="notify_status" class="status"></div>
</div>
    <p class="footer">&copy; <?php echo date('Y'); ?> MyDJRequests</p>
</div>

<script>
document.getElementById("notify_btn").addEventListener("click", async () => {
    const emailInput = document.getElementById("notify_email");
    const statusEl   = document.getElementById("notify_status");
    const email      = emailInput.value.trim();

    if (!email || !email.includes("@")) {
        statusEl.textContent = "Please enter a valid email address.";
        return;
    }

    statusEl.textContent = "Saving…";

    try {
        const res = await fetch("/api/public/notify_signup.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ email })
        });

        const data = await res.json();

        if (data.ok) {
            statusEl.textContent = "You're on the list 🚀";
            emailInput.value = "";
        } else {
            statusEl.textContent = data.error || "Something went wrong.";
        }
    } catch {
        statusEl.textContent = "Network error. Try again.";
    }
});
</script>

</body>
</html>