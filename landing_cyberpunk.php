<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>MyDJRequests – Song Requests Made Easy</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<!-- Favicons -->
<link rel="icon" type="image/png" sizes="96x96" href="/favicon-96x96.png">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="shortcut icon" href="/favicon-v2.ico">
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
<link rel="manifest" href="/site.webmanifest">

<!-- Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            background: #0d0d0f;
            color: #fff;
        }

        header {
            padding: 20px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(0,0,0,0.4);
            position: sticky;
            top: 0;
            z-index: 9999;          /* 🔒 always above videos & sections */
            isolation: isolate;    /* 🔑 prevents bleed-through */
            will-change: transform;/* 🛡 stabilises scroll compositing */
            backdrop-filter: blur(6px);
        }

        header nav a {
            margin-left: 25px;
            color: #cfcfcf;
            text-decoration: none;
        }
        header nav a:hover {
            color: #ffffff;
        }

        .hero {
            padding: 120px 20px;
            text-align: center;
            background: radial-gradient(circle at center, #550066 0%, #0d0d0f 70%);
        }

        .hero h1 {
            font-size: 48px;
            font-weight: 700;
            margin-bottom: 20px;
            color: #ff2fd2;
        }

        .hero p {
            font-size: 20px;
            color: #e4e4e4;
            max-width: 620px;
            margin: 0 auto 30px;
        }

        .btn-primary {
            padding: 15px 28px;
            font-size: 18px;
            background: #ff2fd2;
            color: #fff;
            border-radius: 6px;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary:hover { background: #ff4ae0; }

        .btn-secondary {
            padding: 12px 20px;
            font-size: 16px;
            background: #292933;
            color: #fff;
            text-decoration: none;
            border-radius: 6px;
            display: inline-block;
        }
        .btn-secondary:hover { background: #333340; }

        /* Reassurance strip */
        .reassure {
            padding: 30px 20px;
            text-align: center;
            font-size: 14px;
            color: #aaa;
            background: #0b0b10;
            border-top: 1px solid #1f1f29;
            border-bottom: 1px solid #1f1f29;
        }

        /* Persona section */
        .persona-section {
            padding: 70px 20px 20px;
            text-align: center;
        }

        .persona-grid {
            display: grid;
            gap: 24px;
            max-width: 900px;
            margin: auto;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        }

        .persona-card {
            background: #1a1a1f;
            border: 1px solid #292933;
            border-radius: 14px;
            padding: 28px;
            text-align: left;
            transition: 0.25s ease;
        }



        .persona-card h3 {
            color: #ff2fd2;
            margin-bottom: 10px;
        }

        .persona-card ul {
            padding-left: 18px;
            color: #d0d0d0;
        }

        /* How it works */
        .how-it-works {
            padding: 80px 20px;
            text-align: center;
        }

.how-grid {
    display: grid;
    gap: 30px;
    max-width: 900px;
    margin: auto;
    grid-template-columns: repeat(2, 1fr);
}

        /* Features */
        .features {
            padding: 80px 20px;
            max-width: 1000px;
            margin: auto;
        }

        .feature-grid {
            display: grid;
            gap: 30px;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        }

        .feature {
            background: #1a1a1f;
            border: 1px solid #292933;
            border-radius: 8px;
            padding: 25px;
            text-align: center;
        }

        footer {
            padding: 30px;
            text-align: center;
            color: #8a8a8a;
            border-top: 1px solid #292933;
        }
        
        
/* =========================
   Tile hover animation (shared)
========================= */
.tile-hover {
    transition:
        transform 0.25s ease,
        box-shadow 0.25s ease,
        border-color 0.25s ease;
}

.tile-hover:hover {
    transform: translateY(-6px);
    border-color: #ff2fd2;
    box-shadow:
        0 12px 30px rgba(0,0,0,0.6),
        0 0 22px rgba(255,47,210,0.25);
}
       
       
 

/* =========================
   Persona video hover
========================= */
.has-video {
    position: relative;
    overflow: hidden;
}

.card-video {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    opacity: 0;
    transition: opacity 0.35s ease;
    filter: blur(0.4px) brightness(0.65);
    pointer-events: none;
}

.has-video:hover .card-video {
    opacity: 0.22; /* subtle, premium */
}

.card-content {
    position: relative;
    z-index: 2;
}

@media (hover: none) {
  .card-video {
    filter: blur(0.5px) brightness(0.85);
    opacity: 0.35; /* still subtle, but readable */
  }
}

/* MOBILE: make video clear and readable */
@media (hover: none), (max-width: 768px) {
  .card-video {
    filter: none !important;
    opacity: 0.45 !important;
  }
}


/* =========================
   Persona section BG video
========================= */

.has-section-video {
  position: relative;
  overflow: hidden;
}

.section-video-bg {
  position: absolute;
  inset: 0;
  z-index: 0;
  pointer-events: none;
}

.bg-video {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  opacity: 0;
  transition: opacity 0.5s ease;
  filter: blur(0.4px) brightness(0.7);
}

/* Keep CONTENT above, but NOT the video layer */
.persona-section > :not(.section-video-bg) {
  position: relative;
  z-index: 2;
}

/* Desktop only */
@media (hover: hover) and (pointer: fine) {
  .bg-video.active {
    opacity: 1;
  }
}

/* Mobile: disable section BG video entirely */
/* Disable persona BG video ONLY on phones */
@media (max-width: 767px) {
  .section-video-bg {
    display: none;
  }
}


.persona-section {
  background: transparent;
}

.has-section-video {
  min-height: 600px;
}

@media (hover: hover) and (pointer: fine) {
  .has-section-video .persona-card {
    background: rgba(26,26,31,0.92);
  }

 .has-section-video .persona-card:hover {
  background: rgba(26,26,31,0.75);
  backdrop-filter: blur(2px);
}


/* DESKTOP: disable tile-level video, use section background only */
@media (hover: hover) and (pointer: fine) {
  .has-section-video .card-video {
    display: none;
  }
}



.how-step {
    background:#1a1a1f;
    border:1px solid #292933;
    border-radius:14px;
    padding:28px;
    text-align:left;
    position:relative;
}

.step-num {
    font-size:20px;
    letter-spacing:1px;
    color:#ff2fd2;
    font-weight:700;
    margin-bottom:10px;
}

.how-step h3 {
    margin:0 0 10px;
    font-size:20px;
}

.how-step p {
    color:#d0d0d0;
    font-size:15px;
    line-height:1.5;
}

.step-note {
    margin-top:12px;
    font-size:13px;
    color:#999;
}


/* Tablet */
@media (max-width: 900px) {
    .how-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

/* Mobile */
@media (max-width: 600px) {
    .how-grid {
        grid-template-columns: 1fr;
    }
}


.spotify-tile {
    box-shadow:
        0 14px 34px rgba(0,0,0,0.65),
        0 0 26px rgba(255,47,210,0.22);
}

/* =========================
   Live, refreshable DJ playlists
========================= */

.prepare-section {
    padding: 100px 20px;
    background: #0b0b10;
    border-top: 1px solid #292933;
    border-bottom: 1px solid #292933;
}

.prepare-grid {
    max-width: 1100px;
    margin: auto;
    display: grid;
    gap: 50px;
    grid-template-columns: 1.1fr 0.9fr;
    align-items: center;
}

.prepare-title {
    color: #ff2fd2;
    margin-bottom: 20px;
}

.prepare-intro {
    color: #ccc;
    font-size: 17px;
    max-width: 520px;
}

.prepare-detail {
    color: #aaa;
    font-size: 15px;
    margin-top: 14px;
}

.prepare-list {
    margin-top: 22px;
    color: #d0d0d0;
    font-size: 15px;
    line-height: 1.6;
    padding-left: 18px;
}

.prepare-footnote {
    margin-top: 20px;
    color: #888;
    font-size: 14px;
}

/* Spotify tile */
.spotify-tile {
    background: #1a1a1f;
    border: 1px solid #292933;
    border-radius: 14px;
    padding: 30px;
    box-shadow:
        0 14px 34px rgba(0,0,0,0.65),
        0 0 26px rgba(255,47,210,0.22);
}

.spotify-tile h3 {
    color: #ff2fd2;
    margin-bottom: 12px;
}

.spotify-tile p {
    color: #d0d0d0;
    font-size: 15px;
}

.spotify-sub {
    margin-top: 12px;
    color: #aaa;
    font-size: 14px;
}

.spotify-legal {
    margin-top: 8px;
    color: #777;
    font-size: 12px;
}

.spotify-tagline {
    margin-top: 10px;
    color: #999;
    font-size: 13px;
}

/* =========================
   Mobile behavior
========================= */

@media (max-width: 768px) {
    .prepare-grid {
        grid-template-columns: 1fr;
        gap: 30px;
    }

    .prepare-title {
        
        line-height: 1.1;
    }

    .prepare-intro {
        font-size: 16px;
        line-height: 1.5;
    }

    .spotify-tile {
        margin-top: 10px;
    }
}

@media (max-width: 768px) {
    .prepare-section {
        padding: 60px 20px;
    }
}


@media (max-width: 768px) {
    .spotify-tile {
        background: linear-gradient(
            180deg,
            rgba(26,26,31,0.95),
            rgba(20,20,25,0.95)
        );
        border: 1px solid rgba(255,47,210,0.4);
        border-radius: 16px;
        box-shadow:
            0 18px 40px rgba(0,0,0,0.75),
            0 0 28px rgba(255,47,210,0.35);
    }
}


@media (max-width: 768px) {
    .prepare-detail {
        margin-top: 10px;
    }

    .prepare-list {
        margin-top: 16px;
    }

    .prepare-footnote {
        margin-top: 14px;
    }
}



@media (max-width: 768px) {
    .spotify-tile h3 {
        font-size: 20px;
        line-height: 1.2;
    }
}

@media (max-width: 768px) {
    .spotify-tile {
        margin-top: 36px;
    }
}


@media (max-width: 768px) {
    .spotify-tile p {
        font-size: 14.5px;
        line-height: 1.45;
    }

    .spotify-sub {
        margin-top: 10px;
    }

    .spotify-legal {
        margin-top: 6px;
    }
}


/* =========================
   GLOBAL TILE SYSTEM
========================= */

.tile-hover {
    background: #1a1a1f;
    border: 1px solid #292933;
    border-radius: 14px;
    position: relative;
}



@media (max-width: 768px) {

    /* All tiles must visually detach from the page */
    .tile-hover {
        background: linear-gradient(
            180deg,
            rgba(26,26,31,0.96),
            rgba(20,20,26,0.96)
        );
        border: 1px solid rgba(255,47,210,0.35);
        border-radius: 16px;
        box-shadow:
            0 18px 42px rgba(0,0,0,0.75),
            0 0 28px rgba(255,47,210,0.25);
    }

    /* Tiles must breathe */
    .tile-hover + .tile-hover {
        margin-top: 26px;
    }
}



/* =========================
   HEADING COLOR SYSTEM
========================= */

/* Section headings (desktop + mobile) */
section > h2,
.tech-bg h2,
.how-it-works h2 {
    color: #ff2fd2;
}

/* Tile headings */
.tile-hover h3 {
    color: #ff2fd2;
}

/* Body copy headings inside sections (mobile safety) */
@media (max-width: 768px) {
    section > h2 {
        font-size: 32px;
        line-height: 1.15;
    }

    .tile-hover h3 {
        font-size: 20px;
        line-height: 1.2;
    }
}


.qr-preview {
    margin-top: 18px;
    display: flex;
    align-items: center;
    gap: 16px;
}

.qr-box {
    width: 90px;
    height: 90px;
    background: #fff;
    border-radius: 10px;
    padding: 8px;
    box-shadow:
        0 8px 20px rgba(0,0,0,0.4),
        0 0 18px rgba(255,47,210,0.15);
}

.qr-box img {
    width: 100%;
    height: 100%;
}

.qr-caption {
    font-size: 13px;
    color: #aaa;
    max-width: 160px;
}




.reveal {
    opacity: 0;
    transform: translateY(16px);
    transition: opacity 0.6s ease, transform 0.6s ease;
}

.reveal.is-visible {
    opacity: 1;
    transform: none;
}

/* =========================
   HOW IT WORKS MODAL
========================= */

.how-modal {
  position: fixed;
  inset: 0;
  z-index: 9999;
  display: none;
}

.how-modal[aria-hidden="false"] {
  display: block;
}

.how-modal-backdrop {
  position: absolute;
  inset: 0;
  background: rgba(0,0,0,0.75);
  backdrop-filter: blur(6px);
}

.how-modal-panel {
  position: relative;
  max-width: 920px;
  margin: 6vh auto;
  background: #121217;
  border: 1px solid #292933;
  border-radius: 18px;
  padding: 26px;
  box-shadow:
    0 40px 80px rgba(0,0,0,0.85),
    0 0 40px rgba(255,47,210,0.2);
}

.how-modal-close {
  position: absolute;
  top: 16px;
  right: 16px;
  background: none;
  border: none;
  color: #aaa;
  font-size: 18px;
  cursor: pointer;
}

.how-modal-title {
  color: #ff2fd2;
  font-size: 22px;
  margin-bottom: 8px;
}

.how-modal-caption {
  color: #aaa;
  font-size: 15px;
  max-width: 640px;
  margin-bottom: 18px;
}

.how-modal-media {
  display: flex;
  justify-content: center;
  align-items: center;
  margin-top: 14px;
}

.how-modal-media img {
  max-width: 100%;
  max-height: 62vh;           /* KEY: controls size */
  width: auto;
  height: auto;
  object-fit: contain;
  border-radius: 16px;
  border: 1px solid #292933;
  background: #0b0b10;
  padding: 6px;
  box-shadow:
    0 18px 40px rgba(0,0,0,0.75),
    0 0 26px rgba(255,47,210,0.18);
}

/* Mobile */
@media (max-width: 768px) {
  .how-modal-panel {
    margin: 4vh 12px;
    padding: 18px;
  }
}


@media (max-width: 768px) {
  .how-modal-media img {
    max-height: 52vh;
    border-radius: 14px;
    padding: 5px;
  }
}


.how-modal-media img.is-portrait {
  max-height: 58vh;
}







@media (max-width: 600px) {
    .how-step {
        padding: 20px;
    }

    .how-step h3 {
        font-size: 18px;
        line-height: 1.25;
    }

    .how-step p {
        font-size: 14.5px;
        line-height: 1.45;
    }

    .step-note {
        font-size: 12.5px;
        margin-top: 10px;
    }
}



@media (max-width: 600px) {
    .qr-preview {
        margin-top: 14px;
        align-items: center;
    }
}

    .qr-box {
        width: 80px;
        height: 80px;
    }

    .qr-caption {
        max-width: 100%;
        font-size: 12.5px;
    }
}


@media (max-width: 768px) {
    .how-modal-title {
        font-size: 20px;
    }

    .how-modal-caption {
        font-size: 14px;
        line-height: 1.45;
        margin-bottom: 14px;
    }
}


/* Absolute safety net for QR images */
.qr-box img {
    display: block;
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}


@media (max-width: 600px) {
    .qr-box {
        width: 72px;
        height: 72px;
        padding: 6px;
    }
}

@media (max-width: 480px) {
    .qr-preview {
        opacity: 0.85;
    }
}

@media (max-width: 768px) {
    .how-grid {
        grid-template-columns: 1fr !important;
    }
}



/* =========================
   HOW IT WORKS — MOBILE COLOR EMPHASIS
========================= */
@media (max-width: 768px) {
    
     /* Primary section titles */
    section > h2 {
        color: #ff2fd2;
        font-weight: 700;
    }

    /* Persona section title */
    .persona-section h2 {
        color: #ff2fd2;
    }

    /* How it works title */
    .how-it-works h2 {
        color: #ff2fd2;
    }

    /* Prepare section title */
    .prepare-title {
        color: #ff2fd2;
    }

    /* Spotify tile heading */
    .spotify-tile h3 {
        color: #ff2fd2;
    }

    /* Features section title */
    .features h2 {
        color: #ff2fd2;
    }
    
    
    
    .how-step .step-num {
        color: #ff2fd2;
        font-weight: 700;
    }

    .how-step h3 {
        color: #ff2fd2;
        font-weight: 700;
    }
}



@media (max-width: 768px) {
    .how-step {
        padding-top: 24px;
    }

    .how-step:not(:last-child)::after {
        content: "";
        display: block;
        margin-top: 22px;
        height: 1px;
        background: linear-gradient(
            90deg,
            transparent,
            rgba(255,47,210,0.25),
            transparent
        );
    }
}


/* =========================
   GLOBAL TYPE SCALE (FINAL)
========================= */

/* Desktop */
h1 {
    font-size: 48px;
    line-height: 1.1;
    font-weight: 700;
}

h2 {
    font-size: 36px;
    line-height: 1.2;
    font-weight: 700;
    margin-bottom: 18px;
}

h3 {
    font-size: 20px;
    line-height: 1.25;
    font-weight: 600;
    margin-bottom: 10px;
}

/* Mobile */
@media (max-width: 768px) {
    h1 {
        font-size: 36px;
    }

    h2 {
        font-size: 26px;
        line-height: 1.25;
    }

    h3 {
        font-size: 18px;
        line-height: 1.3;
    }
}



/* =========================
   HEADING COLOR SYSTEM
========================= */

/* Section headings */
section > h2,
.persona-section h2,
.how-it-works h2,
.prepare-title,
.features h2 {
    color: #ff2fd2;
}

/* Tile headings */
.tile-hover h3 {
    color: #ff2fd2;
}

/* =========================
   PREPARE SECTION — MOBILE FIX
========================= */
@media (max-width: 768px) {

  .prepare-copy {
    background: linear-gradient(
      180deg,
      rgba(26,26,31,0.96),
      rgba(20,20,26,0.96)
    );
    border: 1px solid rgba(255,47,210,0.35);
    border-radius: 16px;
    padding: 24px 20px;
    box-shadow:
      0 18px 42px rgba(0,0,0,0.75),
      0 0 28px rgba(255,47,210,0.25);
  }

}


@media (max-width: 768px) {

  .spotify-tile {
    margin-top: 28px;
    padding: 26px 22px;
  }

}


@media (max-width: 768px) {
  .spotify-tile {
    border: 1px solid rgba(255,47,210,0.45);
  }
}


.section-separator {
  position: relative;
  height: 80px;
  display: flex;
  align-items: center;
  justify-content: center;

  background: linear-gradient(
    180deg,
    rgba(13,13,15,0.0),
    rgba(13,13,15,0.75),
    rgba(13,13,15,1)
  );
}

.section-separator span {
  font-size: 12px;
  letter-spacing: 2px;
  color: rgba(255,47,210,0.6);
  text-transform: uppercase;
  z-index: 2;
}



..section-divider.cue {
  position: relative;
  z-index: 10;               /* above fixed bg video */
  height: 110px;

  display: flex;
  align-items: center;
  justify-content: center;

  background-color: #0d0d0f; /* HARD BLACK — no gradients */
  
  isolation: isolate;        /* 🔑 creates a new compositing layer */
}

.section-divider.cue span {
  color: rgba(255,47,210,0.75);
  font-size: 13px;
  letter-spacing: 0.18em;
  text-transform: uppercase;

  text-shadow:
    0 0 12px rgba(255,47,210,0.35);

  pointer-events: none;
}


/* HARD STOP between sections */
.section-divider,
.section-separator {
  background-color: #0d0d0f;
}


/* =========================
   CYBERPUNK — GLOBAL VIEWPORT VIDEO
========================= */

.cyberpunk-bg {
  position: fixed;
  inset: 0;
  z-index: -1;
  pointer-events: none;
  opacity: 0;
  transition: opacity 0.15s linear;
}

.cyberpunk-bg video {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transform: scale(1.02);
  filter: brightness(0.75) saturate(1.05);
}

.cyberpunk-bg.is-active {
  opacity: 1;
}

/* Sections only act as VISIBILITY GATES */
.has-cyberpunk-bg {
  position: relative;
  background: transparent;
}

/* Phones only */
@media (max-width: 767px) {
  .cyberpunk-bg,
  .cyberpunk-section-bg {
    display: none;
  }
}





.hero {
  background:
    radial-gradient(circle at center, rgba(85,0,102,0.9), rgba(13,13,15,0.95));
}

@media (max-width: 768px) {
  .site-bg-video {
    display: none;
  }
}


.section-separator {
  position: relative;
  z-index: 3;
  background: #0d0d0f;
}


.has-cyberpunk-bg {
  position: relative;
  z-index: 1;
}

/* =========================
   COMPATIBILITY LOGO STRIP
========================= */
.compat-strip {
  margin: 40px auto 0;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 14px;
  color: #aaa;
  font-size: 14px;
  flex-wrap: wrap;
}

.compat-logo svg {
  width: 28px;
  height: 28px;
  fill: #1DB954; /* Spotify green */
  filter: drop-shadow(0 0 10px rgba(29,185,84,0.35));
}

.compat-text {
  margin: 0;
  line-height: 1.4;
}

.compat-text span {
  color: #888;
  font-size: 13px;
}

/* Mobile polish */
@media (max-width: 600px) {
  .compat-strip {
    gap: 10px;
    font-size: 13px;
  }

  .compat-logo svg {
    width: 24px;
    height: 24px;
  }
}

.spotify-legal strong {
  color: #bbb;
  font-weight: 600;
}

/* Mobile tuning */
@media (max-width: 600px) {
  .compatibility-logos img {
    height: 22px;
  }

  .compatibility-logos {
    gap: 20px;
  }
}


/* =========================
   TABLET FIX — FORCE SOLID TILES
========================= */
@media (pointer: coarse) and (min-width: 768px) {

  .how-it-works .how-step {
    background: #1a1a1f !important;
    border: 1px solid #292933;
    box-shadow:
      0 16px 40px rgba(0,0,0,0.75),
      0 0 22px rgba(255,47,210,0.22);
  }

  /* Kill any glass effects */
  .how-it-works .how-step:hover {
    backdrop-filter: none !important;
  }
}


/* =========================
   CLICKABLE HOW-STEP TILES
========================= */

.how-step {
  cursor: pointer;
  border-radius: 18px; /* slightly more premium */
  transition:
    transform 0.25s ease,
    box-shadow 0.25s ease,
    border-color 0.25s ease;
}

/* Hover / focus feedback */
@media (hover: hover) and (pointer: fine) {
  .how-step:hover {
    transform: translateY(-6px);
    border-color: #ff2fd2;
    box-shadow:
      0 18px 42px rgba(0,0,0,0.75),
      0 0 26px rgba(255,47,210,0.35);
  }
}

.how-step {
  cursor: pointer;
}

.how-step:focus-visible {
  outline: 2px solid rgba(255,47,210,0.8);
  outline-offset: 4px;
}


.how-step a {
  pointer-events: none;
}


/* Hide "Tap any step…" helper on touch devices */
@media (pointer: coarse) {
  .how-it-works p.tap-hint {
    display: none;
  }
}

/* =========================
   TABLET — HOW IT WORKS TILE POLISH
========================= */
@media (pointer: coarse) and (min-width: 768px) {

  .how-it-works .how-step {
    padding: 34px 30px;        /* more breathing room */
    text-align: left;          /* left-align content */
  }

  .how-it-works .how-step p,
  .how-it-works .how-step h3,
  .how-it-works .step-note {
    text-align: left;
  }

}

/* =========================
   TABLET — FIX STEP NUMBER COLOR
========================= */
@media (pointer: coarse) and (min-width: 768px) {

  .how-it-works .step-num {
    color: #ff2fd2 !important; /* electric pink */
    font-weight: 700;
  }

}

/* =========================
   TABLET — FIX QR SIZE (STEP 2)
========================= */
@media (pointer: coarse) and (min-width: 768px) {

  .how-it-works .qr-preview {
    align-items: center;
    gap: 14px;
  }

  .how-it-works .qr-box {
    width: 80px;
    height: 80px;
    padding: 6px;
    flex-shrink: 0;
  }

  .how-it-works .qr-box img {
    width: 100%;
    height: 100%;
    object-fit: contain;
  }

  .how-it-works .qr-caption {
    font-size: 12.5px;
    max-width: 160px;
    text-align: left;
  }

}

/* =========================
   TABLET — PREPARE SECTION = DESKTOP MODE
========================= */
@media (pointer: coarse) and (min-width: 768px) {

  .prepare-section {
    background: #0b0b10 !important;
    border-top: 1px solid #292933;
    border-bottom: 1px solid #292933;
  }

}


@media (pointer: coarse) and (min-width: 768px) {

  .prepare-grid {
    grid-template-columns: 1.1fr 0.9fr;
    gap: 50px;
    align-items: center;
  }

}



@media (pointer: coarse) and (min-width: 768px) {

  .prepare-copy,
  .spotify-tile {
    background: #1a1a1f !important;
    border: 1px solid #292933;
    box-shadow:
      0 14px 34px rgba(0,0,0,0.65),
      0 0 26px rgba(255,47,210,0.22);
    backdrop-filter: none !important;
  }

}


@media (pointer: coarse) and (min-width: 768px) {

  .prepare-copy,
  .spotify-tile {
    text-align: left;
  }

}

/* =========================
   TABLET — PAD PREPARE TEXT ONLY
========================= */
@media (pointer: coarse) and (min-width: 768px) {

  .prepare-copy {
    padding-left: 32px;
    padding-right: 12px; /* optional, subtle */
  }

}


/* =========================
   TABLET — PAD & LEFT-ALIGN PREPARE TILE ONLY
========================= */
@media (pointer: coarse) and (min-width: 768px) {

  .prepare-section .spotify-tile {
    padding-left: 32px;
    padding-right: 32px;
    text-align: left;
  }

  .prepare-section .spotify-tile h3,
  .prepare-section .spotify-tile p {
    text-align: left;
  }

}


/* =========================
   MOBILE — LEFT ALIGN TILE TEXT
========================= */
@media (max-width: 600px) {

  .how-it-works .how-step {
    text-align: left;
  }

  .how-it-works .how-step p,
  .how-it-works .how-step h3,
  .how-it-works .step-note {
    text-align: left;
  }

}

/* =========================
   TABLET - PERSONA VIDEOS
========================= */


/* Desktop only: section background videos */
@media (hover: hover) and (pointer: fine) {
  .section-video-bg {
    display: block;
  }
}

/* Tablet + Mobile: disable section background video */
@media (pointer: coarse) {
  .section-video-bg {
    display: none !important;
  }
}


/* Tablet + Mobile: use tile-level videos */
@media (pointer: coarse) {
  .persona-card .card-video {
    display: block;
    opacity: 0.45;
    filter: none;
  }
}

/* HARD STOP: Persona section must block global cyberpunk bg */
.persona-section.has-section-video {
  background-color: #0d0d0f;
  position: relative;
  z-index: 2;
}

</style>
</head>

<body>

<div class="cyberpunk-bg" id="cyberpunkBg" aria-hidden="true">
  <video muted loop playsinline preload="auto">
    <source src="/assets/video/cyberpunk_night_city_loop.webm" type="video/webm">
    <source src="/assets/video/cyberpunk_night_city_loop.mp4" type="video/mp4">
  </video>
</div>


<?php
$loggedIn = function_exists('is_dj_logged_in') ? is_dj_logged_in() : false;
$adminUser = function_exists('is_admin') ? is_admin() : false;
?>

<header>
    <a href="/">
        <img src="/assets/logo/MYDJRequests_Logo-white.png" alt="MyDJRequests" style="height:32px;">
    </a>
    <nav>
        <?php if ($loggedIn): ?>
            <a href="/dj/dashboard.php">Dashboard</a>
            <a href="/dj/events.php">My Events</a>
            <a href="/dj/terms.php">Terms</a>
            <?php if ($adminUser): ?>
                <a href="/admin/dashboard.php">Admin</a>
            <?php endif; ?>
            <a href="/dj/logout.php">Logout</a>
        <?php else: ?>
            <a href="<?php echo e(mdjr_url('dj/login.php')); ?>">DJ Login</a>
        <?php endif; ?>
    </nav>
</header>

<section class="hero">
    <h1>Song Requests. Simplified.</h1>
    <p>
        The cleanest way for DJs to receive song requests, tips and messages —
        whether you’re playing live events or streaming online.
    </p>
    <p style="margin-top:40px;color:#aaa;font-size:15px;">
        Scroll to see how DJs use MyDJRequests ↓
    </p>
</section>

<div class="section-divider cue"></div>

<section class="persona-section has-section-video">
    
    
    <div class="section-video-bg">

<video
  class="bg-video bg-event active"
  muted loop playsinline preload="auto"
  autoplay
>
    <source src="/assets/video/event-dj-loop.webm" type="video/webm">
    <source src="/assets/video/event-dj-loop.mp4" type="video/mp4">
  </video>

  <video
    class="bg-video bg-stream"
    muted loop playsinline preload="none"
  >
    <source src="/assets/video/live-stream-loop.webm" type="video/webm">
    <source src="/assets/video/live-stream-loop.mp4" type="video/mp4">
  </video>
  
  
    <video
    class="bg-video bg-both"
    muted loop playsinline preload="none"
  >
    <source src="/assets/video/both.webm" type="video/webm">
    <source src="/assets/video/both.mp4" type="video/mp4">
  </video>
  

</div>
    
    
    <h2>How do you usually DJ?</h2>
    <p style="color:#aaa;max-width:600px;margin:0 auto 40px;">
        Whether you play live events, stream online, or do both —
        MyDJRequests works exactly the same.
    </p>

<div class="persona-grid">

    <!-- Mobile / Event DJ -->
    <div class="persona-card tile-hover has-video" data-bg="event">
<video
  class="card-video"
  muted
  loop
  playsinline
  autoplay
  preload="auto"
>
  <source src="/assets/video/event-dj-loop.webm" type="video/webm">
  <source src="/assets/video/event-dj-loop.mp4" type="video/mp4">
</video>    
            
            <div class="card-content">
            <h3>🎧 Mobile / Event DJ</h3>
            <p style="color:#999;font-size:14px;">Perfect for weddings, parties & venues</p>
            <ul>
                <li>QR code song requests</li>
                <li>No crowding the DJ booth</li>
                <li>Requests grouped by popularity</li>
            </ul>
        </div>
    </div>

    <!-- Live Streamer -->
    <div class="persona-card tile-hover has-video" data-bg="stream">
<video
  class="card-video"
  muted
  loop
  playsinline
  autoplay
  preload="auto"
>
  <source src="/assets/video/live-stream-loop.webm" type="video/webm">
  <source src="/assets/video/live-stream-loop.mp4" type="video/mp4">
</video>

        <div class="card-content">
            <h3>📡 Live Streamer</h3>
            <p style="color:#999;font-size:14px;">Perfect for Twitch & Mixcloud</p>
            <ul>
                <li>Clean request links for chat</li>
                <li>No spam or song shouting</li>
                <li>Tablet-friendly dashboard</li>
            </ul>
        </div>
    </div>

    <!-- I Do Both -->
    <div class="persona-card tile-hover has-video" data-bg="both">
<video
  class="card-video"
  muted
  loop
  playsinline
  autoplay
  preload="auto"
>
  <source src="/assets/video/both.webm" type="video/webm">
  <source src="/assets/video/both.mp4" type="video/mp4">
</video>

        <div class="card-content">
            <h3>🔥 I Do Both</h3>
            <p style="color:#999;font-size:14px;">One system for every audience</p>
            <ul>
                <li>Live gigs and streams</li>
                <li>Same workflow everywhere</li>
                <li>No switching modes</li>
            </ul>
        </div>
    </div>

</div>


</section>


<div class="section-separator"></div>



<section class="how-it-works has-cyberpunk-bg">
    <h2>How it works</h2>
    <p style="
        color:#aaa;
        max-width:620px;
        margin:0 auto 50px;
        font-size:17px;
    ">
        A clean, controlled request flow — built for real DJs,
        not noisy crowds.
    </p>
    
    
    <p class="tap-hint" style="color:#777;font-size:13px;margin-bottom:30px;">
      Tap any step to preview the experience
    </p>

    <div class="how-grid">

        <!-- Step 1 -->
<div
  class="how-step tile-hover reveal"
  role="button"
  tabindex="0"
  onclick="event.preventDefault(); event.stopPropagation(); openHowModal({
    title: 'Create your event & QR code',
    caption: 'Every event instantly generates a unique request link and QR code. Share it ahead of time so guests can collaboratively add songs before the night begins.',
    image: '/assets/marketing/how-step-1-event.png'
  })"
  onkeydown="if (event.key === 'Enter' || event.key === ' ') {
    event.preventDefault();
    openHowModal({
      title: 'Create your event & QR code',
      caption: 'Every event instantly generates a unique request link and QR code. Share it ahead of time so guests can collaboratively add songs before the night begins.',
      image: '/assets/marketing/how-step-1-event.png'
    });
  }"
>
            <div class="step-num">01</div>
            <h3>Create your event — start collecting requests early</h3>
            <p>
                Set the event name, date, and visibility.
                Your request link and QR code are generated instantly.
            </p>
            
                <p>
                    Share it ahead of time so hosts, friends, and guests can
                    collaboratively add tracks and help set the tone before the event.
                </p>
            
                <p class="step-note">
                    Arrive prepared, informed, and aligned — with no setup, apps, or patron accounts required.
                </p>
        </div>

<!-- Step 2 -->
<div class="how-step tile-hover reveal"
onclick="openHowModal({
  title: 'Guests request from their phones',
  caption: 'Guests scan the QR code or open your link to request songs and leave messages — no apps, no accounts, no friction.',
  image: '/assets/marketing/how-step-2-patron-mobile.png'
})">
    <div class="step-num">02</div>
    <h3>Guests request effortlessly</h3>
    <p>
        Guests scan the QR code or open your link on their phone
        to submit song requests and optional messages.
    </p>
    
    
    <div class="qr-preview">
<div class="qr-box">
    <img src="/assets/qr/qr-demo.png" alt="Scan to request a song">
</div>
    <p class="qr-caption">
        Guests scan — requests open instantly.
    </p>
</div>
    
    
    
    
    <p class="step-note">
        Quick for guests, unobtrusive for DJs.
    </p>
</div>

<!-- Step 3 -->
<div class="how-step tile-hover reveal"
onclick="openHowModal({
  title: 'You curate the set in real time',
  caption: 'All requests appear in your DJ dashboard where you approve, skip, or sort by popularity. Nothing plays automatically.',
  image: '/assets/marketing/how-step-3-dj-dashboard.png'
})">
    <div class="step-num">03</div>
    <h3>You curate the set</h3>
    <p>
        Requests appear live in your dashboard.
        You approve, filter, or skip tracks as they come in —
        nothing plays automatically.
    </p>
    <p class="step-note">
        Your taste, your timing, your call.
    </p>
</div>

<!-- Step 4 -->
<div class="how-step tile-hover reveal">
    <div class="step-num">04</div>
    <h3>Finish on a professional note</h3>
    <p>
        Requests close automatically when the event ends,
        keeping the experience clean for both you and your guests.
    </p>
    <p class="step-note">
        Polished from setup to shutdown.
    </p>
</div>

    </div>

<p style="
    margin-top:60px;
    color:#ff2fd2;
    font-size:18px;
    font-weight:600;
">
    MyDJRequests doesn’t tell you what to play —
    it gives you a live playlist that adapts as the event unfolds.
</p>

<p style="
    color:#888;
    font-size:14px;
    margin-top:10px;
">
    Designed to complement your Spotify playlists — without changing how you DJ.
</p>


</section>

<section class="tech-bg" style="
    padding:80px 20px;
    background:#0b0b10;
    border-top:1px solid #292933;
    border-bottom:1px solid #292933;
">
    <h2 style="text-align:center;color:#ff2fd2;margin-bottom:10px;">
        Your DJ Presence & Preparation, Handled
    </h2>

    <p style="
        text-align:center;
        color:#aaa;
        max-width:720px;
        margin:0 auto 50px;
        font-size:17px;
    ">
        MyDJRequests gives you a professional public presence —
        without needing your own website, business cards, or link-in-bio tools.
    </p>

    <div style="
        max-width:1000px;
        margin:auto;
        display:grid;
        gap:30px;
        grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));
    ">

        <!-- Public Profile -->
<div class="tile-hover" style="
    background:#1a1a1f;
    border:1px solid #292933;
    border-radius:10px;
    padding:26px;
">
            <h3 style="color:#ff2fd2;margin-bottom:10px;">
                🎧 Event Request Page
            </h3>
            <p style="color:#d0d0d0;font-size:15px;">
                Every event gets a clean, mobile-friendly request page for your patrons.
                </p>
                
                <p style="color:#d0d0d0;font-size:15px;">
                Guests can submit song requests, send messages, and learn a bit about you — without crowding the DJ booth.
            </p>
        </div>

        <!-- Save to Contact -->
<div class="tile-hover" style="
    background:#1a1a1f;
    border:1px solid #292933;
    border-radius:10px;
    padding:26px;
">
            <h3 style="color:#ff2fd2;margin-bottom:10px;">
                📇 Save to Contacts
            </h3>
            <p style="color:#d0d0d0;font-size:15px;">
                Let patrons save your DJ details directly to their phone — from the event page or your public profile.
                </p>
                <p style="color:#d0d0d0;font-size:15px;">
                No business cards. No scanning socials later.
            </p>
        </div>

        <!-- Personal URL -->
<div class="tile-hover" style="
    background:#1a1a1f;
    border:1px solid #292933;
    border-radius:10px;
    padding:26px;
">
    <h3 style="color:#ff2fd2;margin-bottom:10px;">
        🌐 Public DJ Profile (Included)
    </h3>

    <p style="color:#d0d0d0;font-size:15px;">
        Every MyDJRequests subscription includes a public DJ profile with your own unique URL.
    </p>

    <p style="color:#d0d0d0;font-size:15px;margin-bottom:8px;">
        Use it to:
    </p>

    <ul style="
        margin: 0 0 14px 18px;
        padding: 0;
        color:#d0d0d0;
        font-size:15px;
    ">
        <li>Share all your social media in one place</li>
        <li>Promote your DJ identity online or offline</li>
        <li>Give people a clean, professional way to find you</li>
    </ul>

    <p style="color:#d0d0d0;font-size:15px;">
        Your profile stays live while your subscription is active.
        Cancel anytime — reactivate whenever you’re ready.
    </p>
</div>
        
        
        <!-- Availability Calendar
        <div style="
            background:#1a1a1f;
            border:1px solid #292933;
            border-radius:10px;
            padding:26px;
        ">
            <h3 style="color:#ff2fd2;margin-bottom:10px;">
                📅 Availability Calendar
            </h3>
            <p style="color:#d0d0d0;font-size:15px;">
                Manage your public availability by blocking out gig dates.
                Let promoters and clients see when you’re available.
            </p>
            <p style="margin-top:10px;color:#777;font-size:13px;">
                Coming soon
            </p>
        </div> -->

    </div>
</section>


<section class="prepare-section">
    <div class="prepare-grid">

        <!-- LEFT: Copy -->
        <div class="prepare-copy">
            <h2 class="prepare-title">
                Live, refreshable DJ playlists
            </h2>

            <p class="prepare-intro">
                Shape a playlist that evolves with your event —
                built from real guest requests and controlled entirely by you.
            </p>
            
            <p class="prepare-detail">
                As soon as your event is created, approved requests are reflected
                in a clean, DJ-ready playlist — already filtered to match your taste
                and the crowd’s expectations.
            </p>

            <ul class="prepare-list">
                <li>Collect requests in advance to understand the crowd</li>
                <li>Approve, filter, or skip tracks before they hit your playlist</li>
                <li>Load a clean, legal playlist into compatible DJ software</li>
            </ul>
            
            <p class="prepare-detail">
                As requests change during the event, your playlist updates too —
                simply refresh it in compatible DJ software to stay in sync.
            </p>
            

            <p class="prepare-footnote">
                Designed to complement Spotify workflows —
                without changing how you DJ.
            </p>
            
            
<div class="compat-strip">
  <div class="compat-logo">
    <!-- Spotify SVG -->
    <svg role="img" viewBox="0 0 24 24" aria-label="Spotify">
      <path d="M12 0C5.372 0 0 5.373 0 12c0 6.628 5.372 12 12 12s12-5.372 12-12C24 5.373 18.628 0 12 0zm5.482 17.31c-.217.357-.684.469-1.04.252-2.848-1.74-6.436-2.134-10.66-1.17-.408.093-.815-.163-.908-.571-.093-.408.162-.815.57-.908 4.63-1.06 8.59-.61 11.78 1.33.357.217.469.684.252 1.04zm1.486-3.307c-.274.445-.856.586-1.301.312-3.26-2.003-8.23-2.585-12.084-1.416-.5.152-1.03-.13-1.182-.63-.152-.5.13-1.03.63-1.182 4.402-1.337 9.873-.69 13.63 1.64.445.274.586.856.312 1.301zm.128-3.443C15.2 8.31 8.67 8.02 4.93 9.14c-.592.18-1.218-.154-1.398-.746-.18-.592.154-1.218.746-1.398 4.29-1.285 11.43-1.036 15.94 1.64.537.319.713 1.012.394 1.549-.319.537-1.012.713-1.549.394z"/>
    </svg>
  </div>

<p class="spotify-legal">
  Compatible with Spotify-supported DJ software
  (e.g. rekordbox, Serato DJ, Algoriddim djay)
  <strong>for Spotify Premium users</strong>.
</p>
</div>
            
        </div>

        <!-- RIGHT: Feature tile -->
        <div class="tile-hover spotify-tile reveal">
            <h3>
                🎵 Spotify-ready, DJ-controlled playlists
            </h3>

            <p>
                Create a Spotify-compatible playlist the moment your event is created.
                As you approve or skip requests, the playlist reflects only the tracks
                you’ve chosen.
            </p>

            <p class="spotify-sub">
                Reload your playlist in Spotify-supported DJ software at any time —
                changes made during the event are reflected instantly.
            </p>

            <p class="spotify-legal">
                Compatible with Spotify-supported DJ software
                (e.g. rekordbox, Serato DJ, Algoriddim djay) for Premium users.
            </p>

            <p class="spotify-tagline">
                No cleanup required. Just load and play.
            </p>
        </div>

    </div>
</section>


<section class="features has-cyberpunk-bg">
    <h2 style="text-align:center;">Why DJs Love MyDJRequests</h2>
    <p style="text-align:center;color:#aaa;margin-bottom:40px;">
        Built by DJs, for DJs — tested at real gigs and live streams.
    </p>

    <div class="feature-grid">
        <div class="feature tile-hover reveal"><h3>🎧 Easy Requests</h3><p>QR & link-based requests — no shouting, no crowding the booth.</p></div>
        <div class="feature tile-hover reveal"><h3>💬 Guest Messages</h3><p>Notes, dedications & tips — without interrupting your flow.</p></div>
        <div class="feature tile-hover reveal"><h3>📈 Popularity Tracking</h3><p>See what the crowd wants — at a glance, not by guessing.</p></div>
        <div class="feature tile-hover reveal"><h3>💵 Tips</h3><p>Optional tipping — discreet, digital, and DJ-controlled.</p></div>
        <div class="feature tile-hover reveal"><h3>🔍 Track Metadata</h3><p>See useful Spotify track data before deciding what hits the deck.</p></div>
        <div class="feature tile-hover reveal"><h3>📊 Analytics</h3><p>Understand your crowd — before, during, and after the event.</p></div>
    </div>
    
    <p style="text-align:center;color:#888;font-size:14px;margin-top:30px;">
  Designed to make you look organised, professional, and in control — even at peak moments.
</p>
    
</section>


<section class="reassure">
    ✔ 30-day free trial &nbsp; • &nbsp; ✔ No lock-in contracts &nbsp; • &nbsp; ✔ Cancel anytime
</section>


<section style="padding:80px 20px;text-align:center;background:#0f0f14;border-top:1px solid #292933;">
    <h2 style="color:#ff2fd2;">Ready to try MyDJRequests?</h2>
    <p style="color:#ccc;max-width:600px;margin:0 auto 30px;">
        Start your 30-day free trial and take requests at your next gig or stream.
    </p>

    <a href="<?php echo e(mdjr_url('dj/register.php')); ?>" class="btn-primary">
        Start 30-Day Free Trial
    </a>

    <p style="margin-top:12px;color:#666;font-size:13px;">
        No credit card required • Cancel anytime
    </p>

    <br><br>

    <a href="<?php echo e(mdjr_url('dj/login.php')); ?>" class="btn-secondary">
        Already using MyDJRequests? Log in
    </a>

    <p style="margin-top:30px;color:#666;font-size:13px;max-width:600px;margin-left:auto;margin-right:auto;">
        After your free trial, a subscription is required to continue.
        You’ll be reminded before your trial ends.
    </p>
</section>

<footer>
    &copy; <?php echo date('Y'); ?> MyDJRequests — All Rights Reserved.
</footer>

<script>
const section = document.querySelector('.has-section-video');
const defaultBg = section.querySelector('.bg-event');
const IS_MOBILE = window.matchMedia('(max-width: 768px)').matches;
const IS_PHONE = window.matchMedia('(max-width: 600px)').matches;

document.querySelectorAll('.persona-card').forEach(card => {
  const bgType = card.dataset.bg;
  const bgVideo = section.querySelector(`.bg-${bgType}`);

  card.addEventListener('mouseenter', () => {
    if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
      section.querySelectorAll('.bg-video').forEach(v => {
        v.pause();
        v.classList.remove('active');
      });

      if (bgVideo) {
        bgVideo.currentTime = 0;
        bgVideo.classList.add('active');
        bgVideo.play().catch(() => {});
      }
    }
  });

  card.addEventListener('mouseleave', () => {
    if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
      section.querySelectorAll('.bg-video').forEach(v => {
        v.pause();
        v.classList.remove('active');
      });

      defaultBg.classList.add('active');
      defaultBg.play().catch(() => {});
    }
  });
});
</script>

<script>
if (IS_PHONE) {
  document.querySelectorAll('.card-video').forEach(video => {
    video.play().catch(() => {});
  });
}
</script>

<script>
const revealEls = document.querySelectorAll('.reveal');

const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
        }
    });
}, { threshold: 0.15 });

revealEls.forEach(el => observer.observe(el));
</script>




<!-- HOW IT WORKS MODAL -->
<div class="how-modal" id="howModal" aria-hidden="true">
  <div class="how-modal-backdrop" onclick="closeHowModal()"></div>

  <div class="how-modal-panel">
    <button class="how-modal-close" onclick="closeHowModal()">✕</button>

    <h3 class="how-modal-title" id="howModalTitle"></h3>
    <p class="how-modal-caption" id="howModalCaption"></p>

    <div class="how-modal-media">
      <img id="howModalImage" src="" alt="" />
    </div>
  </div>
</div>

<script>
/* ===========================
   HOW IT WORKS MODAL (FIXED)
=========================== */

let howModalScrollY = 0;

function openHowModal(payload) {
  // ⛔ Disable ONLY on phones
  if (IS_TOUCH_PHONE) return;

  howModalScrollY = window.scrollY;

  document.body.style.position = 'fixed';
  document.body.style.top = `-${howModalScrollY}px`;
  document.body.style.width = '100%';

  const img = document.getElementById('howModalImage');
  img.onload = () => {
    img.classList.toggle(
      'is-portrait',
      img.naturalHeight > img.naturalWidth
    );
  };

  document.getElementById('howModalTitle').textContent = payload.title;
  document.getElementById('howModalCaption').textContent = payload.caption;
  img.src = payload.image;

  document
    .getElementById('howModal')
    .setAttribute('aria-hidden', 'false');
}

function closeHowModal() {
  // Unlock scroll
  document.body.style.position = '';
  document.body.style.top = '';
  document.body.style.width = '';

  // ✅ Restore exact scroll position
  window.scrollTo(0, howModalScrollY);

  document
    .getElementById('howModal')
    .setAttribute('aria-hidden', 'true');
}

/* ESC to close (desktop only) */
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closeHowModal();
});
</script>

<script>
const cyberpunkBg = document.getElementById('cyberpunkBg');
const cyberpunkSections = document.querySelectorAll('.has-cyberpunk-bg');

function updateCyberpunkVisibility() {
  let active = false;

  cyberpunkSections.forEach(section => {
    const rect = section.getBoundingClientRect();
    const vh = window.innerHeight || document.documentElement.clientHeight;

    // ✅ Active the moment ANY part of the section is onscreen
    if (rect.top < vh && rect.bottom > 0) {
      active = true;
    }
  });

  cyberpunkBg.classList.toggle('is-active', active);

  const video = cyberpunkBg.querySelector('video');
  if (active) {
    video.play().catch(() => {});
  } else {
    video.pause();
  }
}

window.addEventListener('scroll', updateCyberpunkVisibility, { passive: true });
window.addEventListener('resize', updateCyberpunkVisibility);
updateCyberpunkVisibility();
</script>


<script>
const IS_TOUCH = matchMedia('(pointer: coarse)').matches;
const IS_TABLET = IS_TOUCH && window.innerWidth >= 768;
const IS_DESKTOP = !IS_TOUCH;

document.documentElement.classList.toggle('is-desktop', IS_DESKTOP);
document.documentElement.classList.toggle('is-tablet', IS_TABLET);
document.documentElement.classList.toggle('is-touch', IS_TOUCH);
</script>


</body>
</html>