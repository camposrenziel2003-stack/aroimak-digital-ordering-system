<!-- Header -->
<header class="header">
    <div class="header-left">
    </div>
    <div class="header-right">
        <div class="profile-menu" onclick="toggleMenu()">
            <img id="headerProfilePic" src="uploads/<?= htmlspecialchars($_SESSION['profile_pic']) ?>" 
             alt="Admin" class="profile-pic">
            <div class="menu-content" id="menuContent">
                <a href="#" onclick="openProfileModal(); return false;">
                    <i class="fa-solid fa-pen"></i> Edit Profile
                </a>
                <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
            </div>
        </div>
        <a href="cashier/index.php" class="btn cashier-btn">
            <i class="fa-solid fa-cash-register"></i> Cashier View
        </a>
    </div>
</header>

<!-- Floating Profile Modal -->
<div id="profileOverlay" class="profile-overlay" onclick="closeProfile(event)" style="display:none;">
  <div class="profile-card" onclick="event.stopPropagation()">
    <button class="close-btn" onclick="closeProfile()">✖</button>
    <div id="profileContent">
      <!-- Profile content will load here -->
      <p style="text-align:center;">Loading...</p>
    </div>
  </div>
</div>
<script>
//Profile
/* Initialize modal fragment state & attach form handlers */
function initProfileModal() {
  const form = document.getElementById("usernameForm");
  const display = document.getElementById("usernameDisplay");
  const editIcon = document.getElementById("editIcon");

  if (form) form.classList.add("hidden");
  if (display) display.style.display = "";
  if (editIcon) editIcon.style.display = "inline-block";

  attachProfileFormHandlers();
}

/* Attach handlers to forms inside modal; submits are done via fetch to profile.php */
function attachProfileFormHandlers() {
  const content = document.getElementById("profileContent");
  if (!content) return;

  // For every form in the modal, remove previous listeners (clone to remove)
  content.querySelectorAll('form').forEach(form => {
    const cloned = form.cloneNode(true);
    form.parentNode.replaceChild(cloned, form);

    cloned.addEventListener('submit', async function(e) {
      e.preventDefault();
      const fd = new FormData(cloned);

      // disable submit button(s)
      cloned.querySelectorAll('button[type="submit"]').forEach(b => b.disabled = true);

      try {
        const res = await fetch('profile.php', {
          method: 'POST',
          body: fd,
          credentials: 'same-origin'
        });

        if (!res.ok) throw new Error('Network response not ok');

        const text = await res.text();

        // replace modal content
        content.innerHTML = text;

        // re-init (attach handlers for newly injected forms)
        initProfileModal();

        // Update header picture and username from the returned fragment
        const parser = new DOMParser();
        const doc = parser.parseFromString(text, 'text/html');

        const newImg = doc.querySelector('.profile-avatar');
        if (newImg) {
          const headerPic = document.getElementById('headerProfilePic');
          if (headerPic) {
            // Replace header image src and cache-bust
            headerPic.src = newImg.getAttribute('src').split('?')[0] + '?v=' + Date.now();
          }
        }

        const usernameEl = doc.querySelector('#usernameDisplay');
        if (usernameEl) {
          const headerUsername = document.getElementById('headerUsername');
          if (headerUsername) headerUsername.textContent = usernameEl.textContent;
        }

      } catch (err) {
        console.error('Profile form submission failed:', err);
        alert('Failed to submit profile form. See console for details.');
      } finally {
        cloned.querySelectorAll('button[type="submit"]').forEach(b => b.disabled = false);
      }
    });
  });
}

/* Open modal and load profile.php into it */
function openProfileModal() {
  const overlay = document.getElementById("profileOverlay");
  const content = document.getElementById("profileContent");
  overlay.style.display = "flex";
  content.innerHTML = '<p style="text-align:center;">Loading...</p>';

  fetch('profile.php', { credentials: 'same-origin' })
    .then(res => {
      if (!res.ok) throw new Error('Network response not ok');
      return res.text();
    })
    .then(html => {
      content.innerHTML = html;
      initProfileModal();
    })
    .catch(err => {
      console.error('Failed to load profile.php:', err);
      content.innerHTML = '<p style="color:red;text-align:center;">Failed to load profile.</p>';
    });
}

/* Close modal (background click or close btn) */
function closeProfile(e) {
  if (!e || e.target.id === 'profileOverlay') {
    document.getElementById('profileOverlay').style.display = 'none';
  }
}

/* toggle edit (username) - kept global for inline onclick */
function toggleEdit() {
  const display = document.getElementById("usernameDisplay");
  const editIcon = document.getElementById("editIcon");
  const form = document.getElementById("usernameForm");
  if (!form || !display || !editIcon) return;

  if (form.classList.contains("hidden")) {
    display.style.display = "none";
    editIcon.style.display = "none";
    form.classList.remove("hidden");
  } else {
    display.style.display = "";
    editIcon.style.display = "inline-block";
    form.classList.add("hidden");
  }
}

</script>