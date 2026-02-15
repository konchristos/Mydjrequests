<?php
//dj/layout.php
require_once __DIR__ . '/../app/bootstrap.php';
require_dj_login(); // DJ must be logged in

$pageTitle = $pageTitle ?? "MyDJRequests - DJ Panel";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    
<meta charset="UTF-8">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<title><?php echo e($pageTitle); ?></title>

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />
<link rel="icon" type="image/svg+xml" href="/favicon.svg" />
<link rel="shortcut icon" href="/favicon-v2.ico" />
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
<link rel="manifest" href="/site.webmanifest" />

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">


<style>
    body {
        margin: 0;
        background: #0d0d0f;
        color: #fff;
        font-family: 'Inter', sans-serif;
    }

    /* --- NAVBAR --- */
    .navbar {
        position: sticky;
        top: 0;
        z-index: 1000;
        background: rgba(0, 0, 0, 0.6);
        backdrop-filter: blur(8px);
        border-bottom: 1px solid #18181c;
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 14px 20px;
    }

    .navbar .logo {
        font-size: 22px;
        font-weight: 700;
        color: #ff2fd2;
        letter-spacing: 0.5px;
    }

    .hamburger {
        font-size: 26px;
        cursor: pointer;
        color: #fff;
        display: none;
    }

    /* Desktop Menu */
    .nav-links {
        display: flex;
        gap: 25px;
    }

    .nav-links a {
        text-decoration: none;
        color: #cfcfcf;
        font-size: 15px;
        transition: 0.2s;
    }

    .nav-links a:hover {
        color: #ff2fd2;
    }

    /* Mobile Menu (hidden by default) */
    .mobile-menu {
        display: none;
        flex-direction: column;
        background: #111116;
        border-bottom: 1px solid #18181c;
        padding: 15px 20px;
    }

    .mobile-menu a {
        color: #fff;
        padding: 10px 0;
        text-decoration: none;
        border-bottom: 1px solid #1f1f29;
    }

    .mobile-menu a:last-child {
        border-bottom: none;
    }

    /* Main content */
    .content {
        padding: 25px;
        max-width: 900px;
        margin: auto;
    }
    
    



    /* Mobile Responsive */
    @media (max-width: 768px) {
        .nav-links {
            display: none;
        }
        .hamburger {
            display: block;
        }
    }
    
    
    /* PROFILE DROPDOWN */
.profile-dropdown {
    position: relative;
    display: inline-block;
}

.profile-dropdown-toggle {
    cursor: pointer;
    color: #cfcfcf;
    font-size: 15px;
}

.profile-dropdown-toggle:hover {
    color: #ff2fd2;
}

.profile-dropdown-menu {
    display: none;
    position: absolute;
    top: 32px;
    right: 0;          /* OPEN MENU TO THE LEFT */
    left: auto;        /* ensure left is not used */
    background: #111116;
    border: 1px solid #18181c;
    border-radius: 8px;
    padding: 0;
    min-width: 180px;
    z-index: 2000;

}

.profile-dropdown-menu a {
    display: block;
    padding: 10px 14px;
    color: #cfcfcf;
    text-decoration: none;
    border-bottom: 1px solid #1f1f29;
}

.profile-dropdown-menu a:hover {
    background: #1f1f29;
    color: #ff2fd2;
}

.profile-dropdown-menu a:last-child {
    border-bottom: none;
}
   
    



/* Notifications */
.notif-wrap { position: relative; margin-right: 10px; }
.notif-bell { color:#cfcfcf; cursor:pointer; position: relative; }
.notif-bell:hover { color:#ff2fd2; }
.notif-count {
    position:absolute; top:-6px; right:-8px;
    background:#ff2fd2; color:#fff; font-size:11px; font-weight:700;
    padding:2px 6px; border-radius:999px;
}
.notif-menu {
    display:none; position:absolute; right:0; top:28px;
    background:#111116; border:1px solid #18181c; border-radius:8px;
    min-width:320px; max-width:360px; z-index:3000;
}
.notif-menu a { display:block; padding:10px 12px; color:#cfcfcf; text-decoration:none; border-bottom:1px solid #1f1f29; }
.notif-menu a:hover { background:#1f1f29; color:#ff2fd2; }
.notif-menu a:last-child { border-bottom:none; }
.notif-item-title { font-weight:600; color:#fff; }
.notif-item-body { font-size:12px; color:#aaa; margin-top:4px; }
.notif-item-time { font-size:11px; color:#888; margin-top:4px; }
.notif-unread { background: rgba(255,47,210,0.08); }

</style>

<?php if (is_admin()): ?>
<link rel="stylesheet" href="/admin/css/admin.css?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'].'/admin/css/admin.css'); ?>">
<?php endif; ?>


<script>
// Toggle mobile menu
function toggleMenu() {
    const menu = document.getElementById("mobileMenu");
    menu.style.display = (menu.style.display === "flex") ? "none" : "flex";
}
</script>


<script>
document.addEventListener("DOMContentLoaded", () => {
    const toggle = document.querySelector(".profile-dropdown-toggle");
    const menu = document.querySelector(".profile-dropdown-menu");

    if (toggle) {
        toggle.addEventListener("click", () => {
            menu.style.display = (menu.style.display === "block") ? "none" : "block";
        });
    }

    // Click outside to close
    document.addEventListener("click", (e) => {
        if (!menu.contains(e.target) && !toggle.contains(e.target)) {
            menu.style.display = "none";
        }
    });
});
</script>



<script>
document.addEventListener("DOMContentLoaded", () => {
    const bell = document.querySelector(".notif-bell");
    const menu = document.querySelector(".notif-menu");

    if (bell && menu) {
        bell.addEventListener("click", (e) => {
            e.stopPropagation();
            menu.style.display = (menu.style.display === "block") ? "none" : "block";
        });

        document.addEventListener("click", (e) => {
            if (!menu.contains(e.target) && !bell.contains(e.target)) {
                menu.style.display = "none";
            }
        });
    }
});
</script>


<script>
function formatLocalDateTime(ts) {
  if (!ts) return "";
  const d = new Date(ts.replace(" ", "T") + "Z");
  const date = d.toLocaleDateString([], { weekday: "short", day: "numeric", month: "short" });
  const time = d.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
  return `${date}, ${time}`;
}
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.js-local-time').forEach(el => {
    const ts = el.dataset.utc || '';
    el.textContent = formatLocalDateTime(ts);
  });
});
</script>

</head>
<body class="<?php echo $pageBodyClass ?? ''; ?>">

<!-- NAVBAR -->

<?php
$notifUnread = 0;
$notifItems = [];
if (!empty($_SESSION['dj_id'])) {
    $notifUnread = notifications_get_unread_count((int)$_SESSION['dj_id']);
    $notifItems = notifications_get_recent((int)$_SESSION['dj_id'], 10);
}
?>

<div class="navbar">
    <div class="logo">
    <a href="<?php echo e(url('dj/dashboard.php')); ?>" style="display:flex;align-items:center;">
        <img
            src="/assets/logo/MYDJRequests_Logo-white.png"
            alt="MyDJRequests"
            style="height:32px; width:auto;"
        >
    </a>
</div>
<div class="nav-links">

    <a href="<?php echo e(url('dj/dashboard.php')); ?>">Dashboard</a>
    <a href="<?php echo e(url('dj/events.php')); ?>">My Events</a>
    <a href="<?php echo e(url('dj/how_to.php')); ?>">How To</a>
    <a href="<?php echo e(url('dj/terms.php')); ?>">Terms</a>

    
    <?php if (is_admin()): ?>
    <a href="<?php echo e(url('admin/dashboard.php')); ?>" style="color:#ff2fd2;">
        Admin
    </a>
<?php endif; ?>


    <div class="notif-wrap">
        <span class="notif-bell" title="Notifications">
            <i class="fa-solid fa-bell"></i>
            <?php if (!empty($notifUnread)): ?>
                <span class="notif-count"><?php echo (int)$notifUnread; ?></span>
            <?php endif; ?>
        </span>
        <div class="notif-menu">
            <?php if (empty($notifItems)): ?>
                <a href="/dj/dashboard.php">No notifications</a>
            <?php else: ?>
                <?php foreach ($notifItems as $n): ?>
                    <?php
                        $fallback = '/dj/notifications.php';
                        if (($n['type'] ?? '') === 'broadcast') {
                            $target = '/dj/broadcasts.php';
                        } else {
                            if (($n['type'] ?? '') === 'feedback') {
                                $fallback = '/admin/feedback.php';
                            }
                            $target = !empty($n['url']) ? $n['url'] : $fallback;
                        }
                    ?>
                    <a class="<?php echo ($n['is_read'] ? '' : 'notif-unread'); ?>"
                       href="/dj/notification_read.php?id=<?php echo (int)$n['id']; ?>&redirect=<?php echo urlencode($target); ?>">
                        <div class="notif-item-title"><?php echo e($n['title']); ?></div>
                        <?php if (!empty($n['body'])): ?>
                            <div class="notif-item-body"><?php echo e($n['body']); ?></div>
                        <?php endif; ?>
                        <div class="notif-item-time"><span class="js-local-time" data-utc="<?php echo e($n['created_at']); ?>"></span></div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="profile-dropdown">
        <span class="profile-dropdown-toggle">
    <i class="fa-solid fa-user"></i> Profile ▾
</span>

        <div class="profile-dropdown-menu">
    <a href="<?php echo e(url('account/')); ?>">Account</a>
    <a href="<?php echo e(url('dj/dj_profile_edit.php')); ?>">Public Profile</a>
    <a href="<?php echo e(url('dj/message_statuses.php')); ?>">Message Statuses</a>
    <a href="<?php echo e(url('dj/broadcasts.php')); ?>">Broadcast Messages</a>
    <a href="<?php echo e(url('dj/bugs.php')); ?>">Bug Tracker</a>
    <a href="<?php echo e(url('dj/feedback.php')); ?>">My Feedback</a>
    <hr>
    <a href="<?php echo e(url('dj/logout.php')); ?>">Logout</a>
</div>
    </div>
</div>

    <div class="hamburger" onclick="toggleMenu()">☰</div>
</div>

<!-- MOBILE MENU -->
<div id="mobileMenu" class="mobile-menu">
    <a href="<?php echo e(url('dj/dashboard.php')); ?>">Dashboard</a>
    <a href="<?php echo e(url('dj/events.php')); ?>">My Events</a>
    <a href="<?php echo e(url('dj/how_to.php')); ?>">How To</a>
    <a href="<?php echo e(url('dj/bugs.php')); ?>">Bug Tracker</a>
    <a href="<?php echo e(url('dj/feedback.php')); ?>">My Feedback</a>
    
    <?php if (is_admin()): ?>
    <a href="<?php echo e(url('admin/dashboard.php')); ?>">
        Admin Dashboard
    </a>
<?php endif; ?>
    
    <a href="<?php echo e(url('dj/account_settings.php')); ?>">Account Settings</a>
    <a href="<?php echo e(url('dj/dj_profile_edit.php')); ?>">Public Profile</a>
    <a href="<?php echo e(url('dj/logout.php')); ?>">Logout</a>
</div>


<!-- MAIN CONTENT WRAPPER -->
<div class="content">
    
