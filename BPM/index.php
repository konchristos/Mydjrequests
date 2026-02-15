<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>BPM Import – Rekordbox XML</title>
<style>
body { font-family: sans-serif; max-width: 700px; margin: 40px auto; }
.box { border: 1px solid #ccc; padding: 20px; }
.error { color: #b00020; }
.info { color: #444; font-size: 14px; }
</style>
</head>
<body>

<h2>Import BPM from Rekordbox</h2>

<div class="box">
<form method="post" action="upload.php" enctype="multipart/form-data">

<label>
<strong>Rekordbox Playlist XML</strong><br>

<input
  type="file"
  name="xml"
  accept=".txt,.xml"
  required
>

</label>

<p class="info">
• Export a <strong>playlist</strong> from Rekordbox (not full library)<br>
• Recommended under <strong>10,000 tracks</strong><br>
• Max file size: <strong>15 MB</strong>
</p>

<button type="submit">Upload & Preview</button>

</form>
</div>

</body>
</html>