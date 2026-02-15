<!-- ================================
     MOOD WIDGET (Enhanced Version)
=================================== -->

<style>
.mood-card {
    background: #121218;
    border-radius: 18px;
    padding: 22px;
    margin-top: 25px;
    margin-bottom: 25px;
    color: #fff;
    box-shadow: 0 0 20px rgba(255, 0, 150, 0.25);
}

.mood-title {
    text-align: center;
    font-size: 20px;
    font-weight: 600;
    margin-bottom: 18px;
    color: #ff2fd2;
}

.mood-buttons {
    display: flex;
    justify-content: center;
    gap: 12px;
    margin-bottom: 12px;
}

/* Base button style (neutral) */
.mood-btn {
    padding: 14px 20px;
    border-radius: 14px;
    font-size: 17px;
    border: none;
    cursor: pointer;
    min-width: 130px;
    transition: 0.2s;
    font-weight: 600;
    background: #2a2a2f;      /* neutral background */
    color: #eee;              /* neutral text */
}

/* Positive button ACTIVE state */
.mood-up.active {
    background: #2cc84b;
    color: #fff;
    box-shadow: 0 0 12px rgba(0, 255, 100, 0.6);
}

/* Negative button ACTIVE state */
.mood-down.active {
    background: #ff4343;
    color: #fff;
    box-shadow: 0 0 12px rgba(255, 50, 50, 0.6);
}


#moodScore {
    text-align: center;
    margin-top: 6px;
    font-size: 16px;
    color: #ddd;
}

/* Votes pill */
#moodVotes {
    display: inline-block;
    margin-top: 6px;
    background: rgba(255,255,255,0.12);
    padding: 4px 10px;
    border-radius: 50px;
    font-size: 13px;
    color: #bbb;
    text-align: center;
}

/* Mood bar container */
.mood-bar {
    width: 100%;
    height: 12px;
    background: #2a2a2f;
    border-radius: 6px;
    margin-top: 12px;
    overflow: hidden;
}

/* Animated inner bar */
#moodBarInner {
    height: 12px;
    width: 0%;
    background: #2cc84b; /* default green */
    border-radius: 6px;
    transition: width 0.6s ease, background-color 0.6s ease;
}
</style>

<div class="mood-card">
    <div class="mood-title">How’s the DJ going?</div>

    <div class="mood-buttons">
        <button class="mood-btn mood-up" data-mood="1">👍 Loving it</button>
        <button class="mood-btn mood-down" data-mood="-1">👎 Not my vibe</button>
    </div>

    <div id="moodScore">Loading…</div>
    <div id="moodVotes"></div>

    <div class="mood-bar">
        <div id="moodBarInner"></div>
    </div>
</div>


<script>
document.addEventListener("DOMContentLoaded", () => {
    const eventUuid     = <?php echo json_encode($uuid); ?>;
    const btnPos        = document.querySelector('.mood-btn.mood-up');
    const btnNeg        = document.querySelector('.mood-btn.mood-down');
    const moodScoreEl   = document.getElementById('moodScore');
    const moodVotesEl   = document.getElementById('moodVotes');
    const moodBarInner  = document.getElementById('moodBarInner');

    // Highlight the selected button
    function setActiveButton(mood) {
        btnPos.classList.remove("active");
        btnNeg.classList.remove("active");

        if (mood === 1) btnPos.classList.add("active");
        if (mood === -1) btnNeg.classList.add("active");
    }

    // Save vote
 async function sendMood(mood) {
    try {
        const patronName =
            localStorage.getItem("mdjr_guest_name") || "";

        const res = await fetch('/api/mood_save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                event: eventUuid,
                mood,
                patron_name: patronName
            })
        });

        const data = await res.json();
        if (data.ok) {
            setActiveButton(mood);
            fetchMoodStats();
        }
    } catch (e) {
        console.error("Mood save error", e);
    }
}

// ✅ ADD THESE
btnPos.addEventListener("click", () => sendMood(1));
btnNeg.addEventListener("click", () => sendMood(-1));

    // Fetch stats
let moodFetchInProgress = false;

window.fetchMoodStats = async function fetchMoodStats() {
    if (moodFetchInProgress) return;
    moodFetchInProgress = true;

    try {
        const res = await fetch(
            '/api/mood_stats.php?event=' + encodeURIComponent(eventUuid),
            { cache: 'no-store' }
        );

        const data = await res.json();
        if (!data.ok) return;

        const score    = data.score;
        const positive = data.positive || 0;
        const negative = data.negative || 0;
        const total    = data.total || 0;

        if (total === 0 || score === null) {
            moodScoreEl.textContent = "No votes yet. Be the first!";
            moodBarInner.style.width = "0%";
            moodVotesEl.style.display = "none";
        } else {
            moodScoreEl.innerHTML =
              'Crowd mood: <strong class="mood-percent">' +
              score +
              '% positive</strong><br>' +
              '👍 ' + positive + ' / 👎 ' + negative;

            moodVotesEl.style.display = "inline-block";
            moodVotesEl.textContent = total + " vote" + (total !== 1 ? "s" : "");

            moodBarInner.style.width = score + "%";

            if (score >= 70) {
                moodBarInner.style.backgroundColor = "#2cc84b";
            } else if (score >= 40) {
                moodBarInner.style.backgroundColor = "#f1c40f";
            } else {
                moodBarInner.style.backgroundColor = "#e74c3c";
            }
            
            
            const percentEl = moodScoreEl.querySelector(".mood-percent");

if (percentEl) {
    if (score >= 70) {
        percentEl.style.color = "#2cc84b";
    } else if (score >= 40) {
        percentEl.style.color = "#f1c40f";
    } else {
        percentEl.style.color = "#e74c3c";
    }
}
            
            
            
        }

        setActiveButton(data.guest_mood || 0);

    } catch (e) {
        console.warn("Mood stats error", e);
    } finally {
        moodFetchInProgress = false;
    }
}

// Load immediately on first render.
window.fetchMoodStats();

}); // ✅ CLOSE DOMContentLoaded
</script>