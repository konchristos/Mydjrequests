<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MyDJRequests – Video Test</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
html, body {
    margin: 0;
    padding: 0;
    height: 100%;
    background: #0d0d0f;
    font-family: system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
    color: #fff;
}

/* Container */
.video-section {
    position: relative;
    height: 100vh;
    width: 100%;
    overflow: hidden;
}

/* Background video */
.video-section video {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    z-index: 1;
}

/* Dark overlay for readability */
.video-overlay {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,0.55);
    z-index: 2;
}

/* Content */
.video-content {
    position: relative;
    z-index: 3;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 20px;
}

.video-content h1 {
    font-size: 48px;
    margin-bottom: 10px;
}

.video-content p {
    font-size: 18px;
    color: #ccc;
    max-width: 600px;
    margin: auto;
}
</style>
</head>

<body>

<section class="video-section">

<video
  autoplay
  muted
  loop
  playsinline
  preload="auto"
  style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover;"
>
  <!-- Chrome will choose this -->
  <source src="/assets/video/event-dj-loop.webm" type="video/webm">

  <!-- Safari will fall back to this -->
  <source src="/assets/video/event-dj-loop-chrome.mp4" type="video/mp4">
</video>

    <!-- <div class="video-overlay"></div>  -->

    <div class="video-content">
        <div>
            <h1>Video Background Test</h1>
            <p>If you can see motion behind this text, the video is loading and playing correctly.</p>
        </div>
    </div>

</section>

</body>
</html>