<?php
// food_preference.php
session_start();
include "access_check.php";

// --- Save previous preferences if provided via GET ---
$savedPrefs = [];
if (isset($_GET['saved_prefs'])) {
    $saved = json_decode($_GET['saved_prefs'], true);
    if (is_array($saved)) {
        $savedPrefs = $saved;
    }
}

// ✅ Handle form submission (POST → Redirect to reco.php)
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST['preferences'])) {
    $_SESSION['preferences'] = $_POST['preferences'];
    header("Location: reco.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Food Preferences</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    body {
      margin: 0;
      font-family: 'Segoe UI', Tahoma, sans-serif;
      min-height: 100vh;
      background: linear-gradient(135deg, #FFD54F, #FF9800);
      display: flex;
      justify-content: center;
      align-items: center;
      position: relative;
      overflow-x: hidden;
      /* ... existing styles ... */
    }

@media (orientation: landscape) {
  html, body {
    scrollbar-width: thin;
    scrollbar-color: transparent transparent; /* for Firefox */
  }
  html::-webkit-scrollbar,
  body::-webkit-scrollbar {
    width: 12px;
    background: transparent !important;
  }
  html::-webkit-scrollbar-thumb,
  body::-webkit-scrollbar-thumb {
    background: rgba(0,0,0,0) !important; /* fully transparent */
    border: none !important;
    box-shadow: none !important;
  }
  html::-webkit-scrollbar-track,
  body::-webkit-scrollbar-track {
    background: transparent !important;
  }
}

    .floating-decor {
      position: fixed;
      opacity: 0.85;
      pointer-events: none;
      z-index: 0;
      transition: opacity 0.4s;
      animation: floatAnim 9s ease-in-out infinite;
    }
    .decor-1 { top: 30px; left: 35px; width: 100px; animation-delay: 0s; }
    .decor-2 { top: 80px; right: 40px; width: 80px; animation-delay: 2s; }
    .decor-3 { bottom: 60px; left: 75px; width: 90px; animation-delay: 1s; }
    .decor-4 { bottom: 110px; right: 65px; width: 105px; animation-delay: 2.5s;}
    @keyframes floatAnim {
      0%,100% { transform: translateY(0); }
      50%     { transform: translateY(-13px) scale(1.04); }
    }
    .container {
      width: 90%;
      max-width: 1000px;
      text-align: center;
      color: white;
      padding: 44px 20px 20px 20px;
      display: flex;
      flex-direction: column;
      align-items: center;
      z-index: 1;
      box-shadow: 0 8px 32px rgba(255,140,0,0.10), 0 1.5px 14px rgba(255,193,7,0.07);
      border-radius: 30px;
      background: linear-gradient(110deg, #fff8e1 80%, #ffd54f 100%);
      margin-top: 20px;
    }
    .logo img {
      width: 140px;
      height: auto;
      margin-bottom: 20px;
      border-radius: 50%;
      box-shadow: 0 4px 12px rgba(0,0,0,0.10);
      border: 4px solid #FFD54F;
      transition: transform 0.7s;
    }
    .logo img:hover {
      transform: scale(1.07) rotate(-8deg);
      box-shadow: 0 8px 24px rgba(255,140,0,0.17);
    }
    h1 {
      font-size: clamp(28px, 6vw, 48px);
      font-weight: 900;
      margin: 15px 0 10px 0;
      color: #FF9800;
      letter-spacing: -0.5px;
      text-shadow: 0 3px 6px rgba(255,152,0,0.12);
    }
    h2 {
      font-size: clamp(18px, 4vw, 30px);
      font-style: italic;
      color: #212121;
      margin-bottom: 36px;
      font-weight: 500;
    }
    .grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 22px 18px;
      margin-bottom: 40px;
      width: 100%;
      padding: 10px 0 18px 0;
      background: rgba(255,255,255,0.07);
      border-radius: 22px;
      box-shadow: 0 2px 10px rgba(255,152,0,0.03);
      justify-items: center;
      align-items: stretch;
    }
    .grid > div {
      width: 100%;
      height: 100%;
      display: flex;
      align-items: stretch;
      justify-content: stretch;
    }
    .preference-btn {
      width: 100%;
      height: 100%;
      min-width: 0;
      border: 2px solid #FF9800;
      border-radius: 20px;
      padding: 18px 10px 18px 10px;
      font-size: 28px;
      font-weight: 700;
      cursor: pointer;
      background: linear-gradient(120deg, #fffbe9 60%, #ffe082 100%);
      color: #333;
      transition: box-shadow 0.16s, background 0.16s, color 0.16s, transform 0.12s;
      box-shadow: 0 1.5px 4px rgba(255,152,0,0.07);
      outline: none;
      display: flex;
      flex-direction: row;
      align-items: center;
      justify-content: center;
      gap: 10px;
      letter-spacing: 0.5px;
      margin: 0;
    }
    .preference-btn .fa {
      margin-right: 7px;
      font-size: 1.38em;
      color: #FF9800;
      vertical-align: middle;
      transition: color 0.2s;
    }
    .preference-btn:hover, .preference-btn:focus {
      transform: scale(1.03);
      box-shadow: 0 2.5px 7px rgba(255,152,0,0.10);
      background: linear-gradient(120deg, #ffecb3 75%, #ffd54f 100%);
      border: 2px solid #FF9800;
      color: #bf6210;
    }
    .preference-btn.selected {
      background: linear-gradient(120deg, #ff9800 80%, #ffd54f 100%);
      color: #fff;
      box-shadow: 0 2.5px 7px rgba(255,140,0,0.13);
      border: 2px solid #FF9800;
      transform: scale(1.045);
      letter-spacing: 1px;
    }
    .preference-btn.selected .fa {
      color: #fff;
    }
    /* Interactive sliding confirm button */
    .confirm-btn-area {
      text-align: center;
      position: relative;
      min-height: 70px;
      height: 70px;
      overflow: visible;
    }
    .confirm-slider-bg {
      position: absolute;
      left: 50%;
      top: 50%;
      transform: translate(-50%,-50%);
      width: 320px;
      max-width: 80vw;
      height: 55px;
      background: linear-gradient(90deg, #ffd54f 0%, #ff9800 100%);
      border-radius: 28px;
      box-shadow: 0 6px 12px rgba(255,152,0,0.12);
      z-index: 1;
      opacity: 0.13;
      pointer-events: none;
    }
    .slider-container {
      position: relative;
      width: 320px;
      max-width: 80vw;
      height: 55px;
      margin: auto;
      z-index: 2;
      user-select: none;
      touch-action: pan-x;
    }
    .confirm-slider {
      position: absolute;
      left: 0;
      top: 0;
      height: 55px;
      width: 55px;
      background: linear-gradient(135deg, #FF9800, #FFD54F);
      border-radius: 50%;
      box-shadow: 0 6px 12px rgba(255,152,0,0.28);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 28px;
      color: #fff;
      cursor: grab;
      transition: background 0.2s, box-shadow 0.2s, left 0.22s cubic-bezier(.7,1.6,.8,1);
      z-index: 3;
      will-change: left;
    }
    .confirm-slider:active {
      cursor: grabbing;
      background: linear-gradient(135deg, #ff9800 70%, #ffd54f 100%);
      box-shadow: 0 12px 16px rgba(255,152,0,0.19);
    }
    .slider-label {
      position: absolute;
      top: 0;
      left: 0;
      height: 55px;
      width: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: clamp(18px,2.8vw,22px);
      font-weight: bold;
      color: #FF9800;
      z-index: 2;
      pointer-events: none;
      transition: opacity 0.18s;
      opacity: 1;
      user-select: none;
    }
    .slider-container.confirmed .slider-label {
      opacity: 0;
    }
    .slider-container.confirmed .confirm-slider {
      background: linear-gradient(135deg,#43e97b 0%,#38f9d7 100%);
      color: #fff;
      box-shadow: 0 12px 32px rgba(67,233,123,0.21);
    }
    .slider-container.disabled {
      opacity: 0.6;
      pointer-events: none;
      filter: grayscale(0.18);
    }

    @media (max-width:600px) {
      .confirm-btn-area,
      .confirm-slider-bg,
      .slider-container {
        width: 180px;
        min-width: 100px;
        height: 40px;
        max-width: 99vw;
      }
      .confirm-slider {
        width: 40px;
        height: 40px;
        font-size: 19px;
      }
      .slider-label {
        font-size: 14px;
      }
    }
    .skip-section {
      margin-top: 10px;
      padding-top: 18px;
      border-top: 1px solid #FFD54F;
    }
    .skip-section p {
      font-size: 18px;
      font-weight: bold;
      color: #FF9800;
      margin-bottom: 8px;
      margin-top: 0;
    }
    .skip-btn {
      background: transparent;
      border: 2px solid #FF9800;
      border-radius: 30px;
      padding: 10px 24px;
      font-size: 16px;
      font-weight: bold;
      color: #FF9800;
      cursor: pointer;
      transition: all 0.3s ease;
      margin-bottom: 10px;
    }
    .skip-btn:hover, .skip-btn:focus {
      background: #FF9800;
      color: #fff;
      box-shadow: 0 2px 10px rgba(255,152,0,0.08);
      transform: scale(1.06);
    }
    .selection-counter {
      margin: 18px 0 0 0;
      font-size: 18px;
      color: #FF9800;
      font-weight: 600;
      letter-spacing: 0.5px;
      background: #fffde7;
      padding: 9px 30px;
      border-radius: 14px;
      display: inline-block;
      box-shadow: 0 2px 8px rgba(255,152,0,0.06);
    }
    .popup-message {
      display: none;
      position: fixed;
      left: 50%;
      top: 18%;
      transform: translate(-50%, 0);
      background: #fff8e1;
      color: #FF9800;
      border: 2px solid #FF9800;
      border-radius: 16px;
      padding: 22px 28px;
      font-size: 21px;
      font-weight: 700;
      z-index: 2000;
      text-align: center;
      animation: popupFadeIn 0.4s;
    }
    @keyframes popupFadeIn {
      from { opacity: 0; transform: scale(0.95) translate(-50%, 0);}
      to   { opacity: 1; transform: scale(1)   translate(-50%, 0);}
    }

    body, .container, .grid {
      scrollbar-width: thin;
      scrollbar-color: #FAE9D1 #FAE9D1;
    }
    @media (orientation: landscape) and (max-width: 1100px) {
      .container {
        max-width: 800px;
        width: 66vw;
        padding: 22px 12px 18px 12px;
        margin-top: 12px;
        overflow-y: auto;
      }
    }
    @media (orientation: landscape) and (max-width: 1100px) {
      body {
        align-items: flex-start;
        padding-top: 20px;
      }
      .container {
        max-width: 1000px;
        width: 80vw;
        padding: 22px 12px 18px 12px;
        margin-top: 12px;
      }
      .grid {
        grid-template-columns: repeat(6, 1fr);
        gap: 10px 12px;
        padding: 6px 0 12px 0;
      }
      .logo img {
        width: 100px;
      }
      .preference-btn {
        font-size: 15px;
        padding: 8px 2px 8px 2px;
      }
      h1 {
        font-size: 20px;
      }
      h2 {
        font-size: 15px;
      }
    }
    @media (max-width: 900px) {
      .container {
        max-width: 99vw;
        padding: 30px 6px;
      }
      .grid {
        grid-template-columns: repeat(4, 1fr);
        gap: 14px 10px;
      }
      .grid > div {
        min-width: 0;
      }
      .preference-btn {
        font-size: 16px;
        padding: 13px 5px 13px 5px;
      }
      .selection-counter {
        font-size: 15px;
        padding: 6px 12px;
      }
      .popup-message {
        font-size: 17px;
        padding: 18px 16px;
      }
    }
    @media (max-width: 600px) {
      .container {
        max-width: 99vw;
        padding: 10px 2px;
        border-radius: 16px;
      }
      .grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 8px 5px;
      }
      .grid > div {
        min-width: 0;
      }
      .preference-btn {
        font-size: 14px;
        padding: 9px 2px 9px 2px;
      }
      h1 {
        font-size: 23px;
      }
      h2 {
        font-size: 15px;
      }
      .popup-message {
        font-size: 15px;
        padding: 13px 9px;
        top: 8%;
      }
    }

   .back-btn {
      background: linear-gradient(135deg, #ff8c4eff, #ff6e3eff);
      color: #fff;
      border: none;
      border-radius: 16px;
      font-size: 18px;
      padding: 13px 18px;
      display: flex;
      align-items: center;
      gap: 7px;
      box-shadow: 0 6px 22px rgba(230,74,25,0.18), 0 2px 12px rgba(255,152,0,0.13);
      font-weight: 700;
      transition: background 0.22s, box-shadow 0.22s, transform 0.2s;
      text-decoration: none;
      cursor: pointer;
    }
    .back-btn:hover {
      background: linear-gradient(135deg, #FFD54F, #FF9800);
      transform: scale(1.07);
      box-shadow: 0 10px 28px rgba(255,152,0,0.22);
    }

  </style>
</head>
<body>
    <!-- Back button at the very top -->
  <div style="position: absolute; top: 25px; left: 25px; z-index: 10;">
    <button type="button" onclick="history.back();" class="back-btn">
      <i class="fa fa-arrow-left" style="margin-right:8px;"></i> Back
    </button>
  </div>
  
  <!-- Floating Decorations -->
  <img src="images/decor-noodles.png" class="floating-decor decor-1" alt="Decorative Noodles">
  <img src="images/decor-dessert.png" class="floating-decor decor-2" alt="Decorative Dessert">
  <img src="images/decor-noodles.png" class="floating-decor decor-3" alt="Decorative Noodles">
  <img src="images/decor-dessert.png" class="floating-decor decor-4" alt="Decorative Dessert">

  <!-- Popup message -->
  <div id="popupMessage" class="popup-message">
    You can select up to <span style="color:#d35400;">5</span> preferences only.
  </div>

  <div class="container">
    <div class="logo">
      <img src="images/logo.png" alt="App Logo">
    </div>
    <h1>What do you feel like eating today?</h1>
    <h2>(Select up to 5 food preferences)</h2>
    <span class="selection-counter" id="counterDisplay">No preference selected</span>
    <form id="preferencesForm" method="post" action="" style="width:100%; flex-grow:1;">
      <div class="grid">
        <?php
        $preferenceIcons = [
          'Hot'    => 'fa-pepper-hot',
          'Noodles'  => 'fa-bowl-food',
          'Soup'     => 'fa-mug-hot',
          'Salad'    => 'fa-leaf',
          'Seafood'  => 'fa-fish',
          'Drinks'   => 'fa-glass-martini-alt',
          'Veggie'   => 'fa-carrot',
          'Curry'    => 'fa-utensil-spoon',
          'Pork'     => 'fa-bacon',
          'Dessert'    => 'fa-ice-cream',
          'Chicken'  => 'fa-drumstick-bite',
          'Rice'     => 'fa-bowl-rice'
        ];
        $preferences = array_keys($preferenceIcons);
        foreach ($preferences as $pref) {
          $icon = $preferenceIcons[$pref];
          $selected = in_array($pref, $savedPrefs) ? 'selected' : '';
          $inputDisabled = in_array($pref, $savedPrefs) ? '' : 'disabled';
          echo "<div>";
          echo "<button type='button' class='preference-btn $selected' onclick='togglePreference(this)'><i class=\"fa $icon\"></i> $pref</button>";
          echo "<input type='hidden' name='preferences[]' value='$pref' $inputDisabled>";
          echo "</div>";
        }
        ?>
      </div>
      <div class="confirm-btn-area">
        <div class="confirm-slider-bg"></div>
        <div id="sliderContainer" class="slider-container disabled">
          <span class="slider-label"><i class="fa fa-arrow-right"></i> Slide to confirm</span>
          <div id="confirmSlider" class="confirm-slider"><i class="fa fa-check"></i></div>
        </div>
      </div>
    </form>
    <div class="skip-section">
      <p>Don’t feel like choosing?</p>
      <button class="skip-btn" onclick="window.location.href='kiosk_home.php'">Skip</button>
    </div>
  </div>

  <script>
    const maxSelection = 5;
    let selectedCount = <?php echo count($savedPrefs); ?>;

    function updateConfirmBtnState() {
      const sliderContainer = document.getElementById("sliderContainer");
      sliderContainer.classList.toggle("disabled", selectedCount === 0);
    }

    function togglePreference(btn) {
      const hiddenInput = btn.nextElementSibling;
      if (btn.classList.contains("selected")) {
        btn.classList.remove("selected");
        hiddenInput.disabled = true;
        selectedCount--;
      } else {
        if (selectedCount >= maxSelection) {
          showPopup();
          return;
        }
        btn.classList.add("selected");
        hiddenInput.disabled = false;
        selectedCount++;
      }
      updateConfirmBtnState();
      const counterDisplay = document.getElementById("counterDisplay");
      if (selectedCount === 0) {
        counterDisplay.textContent = "No preference selected";
      } else if (selectedCount === 1) {
        counterDisplay.textContent = "1 preference selected";
      } else {
        counterDisplay.textContent = selectedCount + " preferences selected";
      }
    }
    window.onload = function() {
      updateConfirmBtnState();
      const counterDisplay = document.getElementById("counterDisplay");
      if (selectedCount === 0) {
        counterDisplay.textContent = "No preference selected";
      } else if (selectedCount === 1) {
        counterDisplay.textContent = "1 preference selected";
      } else {
        counterDisplay.textContent = selectedCount + " preferences selected";
      }
    };
    function showPopup() {
      const popup = document.getElementById('popupMessage');
      popup.style.display = 'block';
      setTimeout(() => { popup.style.display = 'none'; }, 1000);
    }
    window.addEventListener('pageshow', function(event) {
      if (event.persisted) {
        window.location.reload();
      }
    });

    // Interactive slider confirm button
    (function() {
      const sliderContainer = document.getElementById("sliderContainer");
      const slider = document.getElementById("confirmSlider");
      const form = document.getElementById("preferencesForm");
      let isDragging = false, startX = 0, currentX = 0, sliderLeft = 0;
      let maxSlide = sliderContainer.offsetWidth - slider.offsetWidth - 2;
      let confirmed = false;

      function updateMaxSlide() {
        maxSlide = sliderContainer.offsetWidth - slider.offsetWidth - 2;
      }
      window.addEventListener('resize', updateMaxSlide);

      function onDragStart(e) {
        if (sliderContainer.classList.contains("disabled") || confirmed) return;
        isDragging = true;
        slider.classList.add("dragging");
        startX = (e.touches ? e.touches[0].clientX : e.clientX);
        sliderLeft = parseInt(slider.style.left || 0);
        e.preventDefault();
      }
      function onDragMove(e) {
        if (!isDragging) return;
        currentX = (e.touches ? e.touches[0].clientX : e.clientX) - startX;
        let newLeft = Math.min(Math.max(sliderLeft + currentX, 0), maxSlide);
        slider.style.left = newLeft + "px";
        if (newLeft > maxSlide * 0.96) {
          confirmAction();
        }
      }
      function onDragEnd(e) {
        if (!isDragging) return;
        isDragging = false;
        slider.classList.remove("dragging");
        let leftPx = parseInt(slider.style.left || 0);
        if (!confirmed) {
          slider.style.left = "0px";
        }
      }
      function confirmAction() {
        confirmed = true;
        sliderContainer.classList.add("confirmed");
        slider.style.left = maxSlide + "px";
        setTimeout(() => {
          form.submit();
        }, 340);
      }

      // Mouse events
      slider.addEventListener("mousedown", onDragStart);
      window.addEventListener("mousemove", onDragMove);
      window.addEventListener("mouseup", onDragEnd);

      // Touch events
      slider.addEventListener("touchstart", onDragStart, {passive:false});
      window.addEventListener("touchmove", onDragMove, {passive:false});
      window.addEventListener("touchend", onDragEnd);

      // Reset slider if selection count changes
      function resetSlider() {
        confirmed = false;
        sliderContainer.classList.remove("confirmed");
        slider.style.left = "0px";
      }
      window.updateConfirmBtnState = function() {
        sliderContainer.classList.toggle("disabled", selectedCount === 0);
        resetSlider();
      };
    })();
  </script>
</body>
</html>