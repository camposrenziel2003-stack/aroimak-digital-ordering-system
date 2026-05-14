<?php 
session_start(); 
include "access_check.php";

unset($_SESSION['cart']); // Clear cart
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#ffffff">

<meta charset="UTF-8">
<title>Welcome</title>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@600&family=Sarabun:wght@500&family=Great+Vibes&display=swap" rel="stylesheet">
<style>
:root {
  --sys-bg1: #FFD54F;
  --sys-bg2: #FFA726;
  --sys-accent: #FF5722;
  --sys-orange: #FF9800;
  --sys-brown: #875a1c;
  --sys-yellow: #FFD600;
  --sys-white: #fffbe9;
  --sys-blue: #241d4f;
  --sys-red: #ed1c24;
  --sys-shadow: 0 8px 36px 0 rgba(0,0,0,0.22), 0 2px 12px #d35400aa;
}
body {
  margin: 0;
  min-height: 100vh;
  overflow-x: hidden;
  overflow-y: auto;
  cursor: pointer;
  font-family: 'Segoe UI', Tahoma, sans-serif;
  background: linear-gradient(160deg, var(--sys-orange), #ffb379ff, var(--sys-bg2));
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  position: relative;
  color: #fff;
}
body::before {
  content: "";
  position: absolute;
  inset: 0;
  background: url('images/main-bg.png') center/cover no-repeat;
  opacity: 0.35;
  z-index: 0;
  animation: bgMove 20s linear infinite alternate;
  filter: brightness(1.1) contrast(1.05);
}
body::after {
  content: "";
  position: absolute;
  inset: 0;
  background: linear-gradient(to bottom, rgba(255,140,0,0.15), rgba(0,0,0,0.25));
  z-index: 0;
}
.welcome-logo { z-index: 1; animation: pulseLogo 3s ease-in-out infinite; }
.welcome-logo img {
  width: 380px;
  height: auto; border-radius: 50%;
  border: 6px solid rgba(255, 193, 7, 0.9);
  box-shadow: 0 12px 32px rgba(255, 140, 0, 0.55);
  transition: transform 0.8s ease, opacity 0.8s ease;
}
.tap-text { z-index: 1; margin-top: 45px; text-align: center; animation: fadeInUp 2s ease; }
.tap-text .thai {
  font-family: 'Kanit', sans-serif;
  font-size: 40px;
  color: var(--sys-yellow);
  margin-bottom: 12px;
  letter-spacing: 1.8px;
  animation: shimmer 3s infinite;
  text-shadow:
    0 0 8px #fff,
    0 0 1px #fff,
    0 2px 6px #fff,
    2px 0 6px #fff,
    -2px 0 6px #fff,
    0 -2px 6px #fff,
    2px 2px 6px #fff,
    -2px -2px 6px #fff;
}
.tap-text .english { 
  font-size: 28px;
  color: #f2f2bbff;
  font-weight: 600;
  text-shadow: 2px 2px 8px rgba(0,0,0,0.75);
  animation: float 3s ease-in-out infinite;
}
@keyframes fadeInUp { from { opacity:0; transform:translateY(30px);} to{opacity:1; transform:translateY(0);} }
@keyframes shimmer { 0%{text-shadow:0 0 4px rgba(255,215,0,0.7);}50%{text-shadow:0 0 18px rgba(255,255,150,1);}100%{text-shadow:0 0 4px rgba(255,215,0,0.7);} }
@keyframes float {0%,100%{transform:translateY(0);opacity:1;}50%{transform:translateY(-10px);opacity:0.95;} }
@keyframes pulseLogo {0%,100%{transform:scale(1);}50%{transform:scale(1.05);} }
@keyframes bgMove {0%{background-position:center top;}100%{background-position:center bottom;} }
.error-message { position:absolute; bottom:110px; left:50%; transform:translateX(-50%); background: rgba(246,246,246,0.9); color: rgba(255,61,0,0.9); padding:10px 18px; border-radius:8px; font-size:15px; font-weight:500; box-shadow:0 4px 12px rgba(0,0,0,0.4); opacity:0; pointer-events:none; transition:opacity 0.4s ease; z-index:3; }
.error-message.show { opacity:1; }
.transition-overlay { position: fixed; inset:0; background:var(--sys-orange); opacity:0; pointer-events:none; z-index:9999; transition:opacity 0.8s ease; }
.transition-overlay.active { opacity:1; }

/* --- Creative Thailand Flag Transition --- */
.flag-overlay {
  position: fixed;
  inset: 0;
  z-index: 10000;
  background: transparent;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  pointer-events: none;
  opacity: 0;
  transition: opacity 0.55s cubic-bezier(.78,.11,.55,.99);
  overflow: hidden;
}
.flag-overlay.active {
  opacity: 1;
  background: radial-gradient(ellipse at center, #fffbe9cc 0%, #FFA726 100%);
  pointer-events: auto;
}
.flag-confetti {
  position: absolute;
  left: 0; top: 0;
  width: 100vw; height: 100vh;
  pointer-events: none;
  z-index: 0;
  overflow: hidden;
}
.confetti-piece {
  position: absolute;
  width: 22px; height: 22px;
  border-radius: 60% 40% 45% 55% / 55% 45% 40% 60%;
  background: var(--color);
  opacity: 0.95;
  animation: confettiFly 2.5s cubic-bezier(.55,.04,.91,.94) forwards;
  will-change: transform, opacity;
  box-shadow: 0 2px 8px #fff8;
  z-index: 1;
  transition: opacity 0.6s cubic-bezier(.42,.0,.58,1), transform 0.6s cubic-bezier(.42,.0,.58,1);
}

@keyframes confettiFly {
  0% { opacity: 0; transform: scale(0.7) translateY(0);}
  12% { opacity: 1; }
  28% { transform: scale(1.2) translateY(-14px);}
  70% { opacity: 1; }
  100% { opacity: 0; transform: scale(1.25) translateY(var(--dy)) translateX(var(--dx)) rotate(var(--rot));}
}
.flag-circle {
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  width: 210px;
  height: 210px;
  border-radius: 50%;
  box-shadow: 0 0 40px #fffad0, 0 0 0 10px #fffbe9;
  position: relative;
  overflow: hidden;
  background: #fffbe9;
   z-index: 10;
  animation: circleFlagIn 0.85s cubic-bezier(.44,1.3,.68,1) forwards;
  opacity: 0;
}
.flag-overlay.active .flag-circle {
  opacity: 1;
}
@keyframes circleFlagIn {
  0% { transform: scale(0.2) rotate(-40deg); opacity:0;}
  60% { transform: scale(1.07) rotate(14deg); opacity:1;}
  80% { transform: scale(1) rotate(-5deg);}
  100% { transform: scale(1) rotate(0deg);}
}
.flag-stripe-c {
  height: 36px;
  width: 100%;
  transition: background 0.6s;
  animation: stripeWave 1s cubic-bezier(.44,1.3,.68,1) forwards;
  opacity: 0.9;
}
.flag-stripe-c.red { background: var(--sys-red);}
.flag-stripe-c.white { background: var(--sys-white);}
.flag-stripe-c.blue { background: var(--sys-blue); height: 66px;}
@keyframes stripeWave {
  0% { opacity:0; transform:scaleX(0.5);}
  70% { opacity:1; transform:scaleX(1.1);}
  100% { opacity:1; transform:scaleX(1);}
}
.flag-emoji {
  position: absolute;
  left: 50%; top: 50%;
  transform: translate(-50%, -50%) scale(1.1);
  font-size: 54px;
  filter: drop-shadow(0 4px 8px #FFD54F88);
  z-index: 3;
  animation: emojiPop 1.1s cubic-bezier(.42,1.7,.46,1.12) forwards;
  opacity:0;
}
.flag-overlay.active .flag-emoji {
  opacity:1;
}
@keyframes emojiPop {
  0% { opacity:0; transform:translate(-50%,-50%) scale(0.7);}
  65% { opacity:1; transform: translate(-50%,-50%) scale(1.18);}
  100% { opacity:1; transform: translate(-50%,-50%) scale(1.1);}
}
.flag-textbox {
  margin-top: 24px;
  text-align: center;
  z-index: 10;
  position: relative;
}
.flag-textbox .text {
  font-family: 'Kanit', sans-serif;
  font-size: 2.6rem;
  color: #31270a; /* dark readable */
  text-shadow:
    0 2px 16px #fff, 0 1px 4px #fff8,
    0 0 8px #FFD54F,
    1px 1px 0 #fffbe9,
    0 0 0 #000,
    0 0 12px #fff;
  font-weight: bold;
  letter-spacing: 2px;
  opacity: 0;
  animation: textFlagIn 0.9s 0.1s cubic-bezier(.2,1.1,.4,1.08) forwards;
  background: linear-gradient(90deg, #fffbe9cc 80%, #fffbe9cc 100%);
  border-radius: 8px;
  padding: 4px 18px;
  box-shadow: 0 0 24px #fffbe9, 0 0 8px #FFD54F;
}


.flag-overlay.active .flag-textbox .text { opacity: 1; }
@keyframes textFlagIn {
  0% { opacity: 0; transform: translateY(16px) scale(1);}
  60% { opacity: 0.6; transform: translateY(-9px) scale(1.12);}
  100% { opacity: 1; transform: translateY(0) scale(1);}
}
.flag-textbox .subtitle {
  font-family: 'Sarabun', sans-serif;
  color: #31270a; /* dark readable */
  font-size: 1.35rem;
  margin-top: 10px;
  font-weight: 700;
  letter-spacing: 1.1px;
  opacity: 0;
  animation: subFlagIn 1.05s 0.3s cubic-bezier(.2,1.1,.4,1.08) forwards;
  background: linear-gradient(90deg, #fffbe9cc 80%, #fffbe9cc 100%);
  border-radius: 8px;
  padding: 4px 14px;
  box-shadow: 0 0 18px #fffbe9, 0 0 4px #FFD54F;
}
.flag-overlay.active .flag-textbox .subtitle { opacity: 1; }
@keyframes subFlagIn {
  0% { opacity: 0; transform: translateY(12px) scale(1);}
  60% { opacity: 0.6; transform: translateY(-5px) scale(1.08);}
  100% { opacity: 1; transform: translateY(0) scale(1);}
}
/* --- Particle Burst Animation --- */
.particle-container {
  position: fixed;
  left: 0; top: 0;
  width: 100vw;
  height: 100vh;
  pointer-events: none;
  z-index: 10001;
  overflow: hidden;
}
.particle {
  position: absolute;
  width: 14px; height: 14px;
  border-radius: 50%;
  opacity: 0.85;
  pointer-events: none;
  will-change: transform, opacity;
  box-shadow: 0 0 10px #fff8;
  animation: particleBurst 1.1s cubic-bezier(.3,1.3,.6,1) forwards;
}
@keyframes particleBurst {
  0% { transform: scale(0.7); opacity: 1;}
  40% { transform: scale(1.2);}
  70% { opacity: 1;}
  100% { transform: translate(var(--dx), var(--dy)) scale(1.15); opacity: 0;}
}
/* --- Text Morph Animation --- */
.tap-text.morphing .thai {
  animation: morphThai 1s cubic-bezier(.5,1.8,.5,1) forwards;
}
.tap-text.morphing .english {
  animation: morphEng 1s cubic-bezier(.5,1.8,.5,1) forwards;
}
@keyframes morphThai {
  0% { opacity: 1; transform: scale(1);}
  50% { opacity: 0; transform: scale(1.3) rotate(-3deg);}
  100% { opacity: 0; transform: scale(0.7);}
}
@keyframes morphEng {
  0% { opacity: 1; transform: scale(1);}
  50% { opacity: 0; transform: scale(1.3) rotate(3deg);}
  100% { opacity: 0; transform: scale(0.7);}
}
.tap-text.morphed .thai, .tap-text.morphed .english {
  display: none;
}
.tap-text.morphed .welcome-morph {
  display: block !important;
  animation: morphIn 0.7s cubic-bezier(.3,1.7,.3,1) forwards;
}
@keyframes morphIn {
  0% { opacity: 0; transform: scale(0.5) translateY(30px);}
  100% { opacity: 1; transform: scale(1) translateY(0);}
}
.welcome-morph {
  display: none;
  font-size: 46px;
  font-family: 'Kanit', cursive;
  color: #fffde6;
  text-shadow: 0 0 18px #ff9800, 2px 4px 15px #fff;
  letter-spacing: 2px;
  font-weight: bold;
}
.welcome-logo.animate img {
  animation: logoSpin 1.7s cubic-bezier(.4,2,.6,.7) forwards;
}
@keyframes logoSpin {
  0%   { transform: scale(1) rotate(0deg);}
  30%  { transform: scale(1.15) rotate(-24deg);}
  60%  { transform: scale(1.2) rotate(14deg);}
  100% { transform: scale(1.08) rotate(0deg);}
}
.modal {
  display: none;
  position: fixed;
  z-index: 1001;
  left:0; top:0;
  width:100%; height:100%;
  background-color: rgba(0,0,0,0.5);
  display:flex;
  justify-content:center;
  align-items:center;
}
.modal-content {
  background-color:#fff8e1;
  padding:20px 30px;
  border:2px solid #d35400;
  border-radius:12px;
  width:90vw;
  max-width:600px;
  box-sizing: border-box;
  position:relative;
  max-height: 90vh;
  overflow-y:auto;
}
.modal-content h2 {
  text-align: center;
  margin-bottom: 20px;
  color: #e65100;
  font-size: 2rem;
  font-family: 'Kanit', cursive;
  letter-spacing: 2px;
}
.modal-content ul {
  padding-left: 24px;
  margin-bottom: 16px;
  font-size: 1.06rem;
  color: #875a1c;
}
.modal-content li {
  margin: 12px 0;
  line-height: 1.65;
}
.modal-content .intro {
  font-size: 1.08rem;
  color: #d35400;
  margin-top: 12px;
  margin-bottom: 18px;
  text-align: center;
  font-weight: 600;
}
.modal-content .closing {
  font-size: 1.07rem;
  color: #d35400;
  margin-top: 18px;
  margin-bottom: 10px;
  text-align: center;
  font-weight: 500;
}
.close { color:#d35400; position:absolute; top:10px; right:20px; font-size:28px; font-weight:bold; cursor:pointer; }
.close:hover { color:#e67e22; }
.terms-box {
  position: absolute;
  bottom: 55px;
  text-align: center;
  width: 100%;
  z-index: 2;
  font-size: 16px;
  font-weight: 500;
  color: #fff3e0;
}
.checkbox-container { display:inline-flex; align-items:center; cursor:pointer; user-select:none; }
.checkbox-container input { display:none; }
.checkmark { width:22px; height:22px; border:2px solid var(--sys-yellow); border-radius:4px; margin-right:10px; position:relative; transition:all 0.3s ease; }
.checkbox-container input:checked ~ .checkmark { background-color:var(--sys-yellow); box-shadow:0 0 12px rgba(255,214,0,0.9); }
.checkmark::after { content:""; position:absolute; left:6px; top:2px; width:6px; height:12px; border:solid #000; border-width:0 3px 3px 0; opacity:0; transform:rotate(45deg) scale(0.8); transition:all 0.3s ease; }
.checkbox-container input:checked ~ .checkmark::after { opacity:1; transform:rotate(45deg) scale(1); }
.terms-box a { color:var(--sys-yellow); text-decoration:underline; font-weight:600; }
.terms-box a:hover { color:#ffeb3b; }

</style>
</head>
<body onclick="bodyTapped(event)">
  <!-- Logo -->
  <div class="welcome-logo">
    <img id="logo" src="images/logo.png" alt="App Logo">
  </div>
  <!-- Tap to Start -->
  <div class="tap-text">
    <div class="thai" style="color:#FFD600; font-weight:900; font-size:2.2em; letter-spacing:1px;">แตะเพื่อเริ่ม</div>
    <div class="english" style="font-size:1.35em; color:#fffbe9; font-weight:700;">Tap to start</div>
    <div class="welcome-morph">ยินดีต้อนรับ!</div>
  </div>
  <!-- Particle Burst Container (creative enhancement) -->
  <div id="particleContainer" class="particle-container"></div>
  <!-- Terms Checkbox with Modal -->
  <div class="terms-box">
    <label class="checkbox-container">
      <input type="checkbox" id="agreeTerms">
      <span class="checkmark"></span>
      <span style="color:#fff3e0;">I agree to the <a href="#" id="openTermsModal">Terms & Conditions</a></span>
    </label>
  </div>
  <!-- Error message -->
  <div id="errorMessage" class="error-message">
    Please agree to the Terms & Conditions before continuing.
  </div>
  <!-- Modal for Terms -->
  <div id="termsModal" class="modal" style="display:none;">
    <div class="modal-content">
      <span class="close">&times;</span>
      <h2>Terms & Conditions</h2>
      <div class="intro">
        Please read before using the kiosk. Thank you for helping us serve you better!
      </div>
      <ul>
      <li>You may be asked to provide your <strong>name</strong> to assist with order processing and service quality improvements.</li>
      <li>All orders are considered <strong>final</strong> once they have been confirmed.</li>
      <li><strong>Your orders will be served</strong> at your table. While you wait, you can track the status of your order real time.</li>
      <li>Please ensure that you <strong>complete payment at the counter</strong> after placing your order.</li>
      <li>Kindly inform our staff of any <strong>allergies or dietary restrictions</strong>. While we will do our best, we cannot guarantee allergen-free meals.</li>
      <li><strong>Preparation and serving times</strong> may vary depending on kitchen activity and overall order volume.</li>
      <li>In the event of a <strong>technical interruption</strong>, we kindly ask for your patience while service is restored.</li>
      <li>The restaurant reserves the right to <strong>refuse service</strong> to individuals who do not comply with these terms or who act inappropriately.</li>
      <li><strong> We ask that you use this kiosk responsibly. Any damage caused may result in penalties.</strong></li>
    </ul>
      <div class="closing">
        Thank you for your understanding and cooperation.<br>
        We appreciate your visit and look forward to serving you!
      </div>
    </div>
  </div>
  <!-- Thailand Flag Creative Transition Overlay -->
  <div id="flagOverlay" class="flag-overlay" style="display:none;">
    <div class="flag-confetti"></div>
    <div class="flag-circle">
      <div class="flag-stripe-c red"></div>
      <div class="flag-stripe-c white"></div>
      <div class="flag-stripe-c blue"></div>
      <div class="flag-stripe-c white"></div>
      <div class="flag-stripe-c red"></div>
      <span class="flag-emoji">🇹🇭</span>
    </div>
    <div class="flag-textbox">
      <div class="text">ยินดีต้อนรับ!</div>
      <div class="subtitle">Welcome to It's a Thai!</div>
    </div>
  </div>
  <div id="transitionOverlay" class="transition-overlay"></div>
<script>
// --- Particle Burst Effect ---
function createParticles(x, y) {
  const colors = ['#FFD54F', '#FF9800', '#875a1c', '#FF5722', '#FFD600', '#fffbe9', '#241d4f'];
  const container = document.getElementById('particleContainer');
  for (let i = 0; i < 22; i++) {
    const particle = document.createElement('div');
    particle.className = 'particle';
    const angle = (Math.PI * 2) * (i / 22);
    const radius = 160 + Math.random() * 60;
    const dx = Math.cos(angle) * radius;
    const dy = Math.sin(angle) * radius;
    particle.style.left = `${x-7}px`;
    particle.style.top = `${y-7}px`;
    particle.style.background = colors[Math.floor(Math.random()*colors.length)];
    particle.style.setProperty('--dx', dx + 'px');
    particle.style.setProperty('--dy', dy + 'px');
    container.appendChild(particle);

    setTimeout(() => { 
      particle.remove(); 
    }, 1100);
  }
  
}

function showFlagConfetti() {
  const confetti = document.querySelector('.flag-confetti');
  confetti.innerHTML = '';
  const pieces = 90;
  const colors = ['#FFD54F', '#FF9800', '#FF5722', '#FFD600', '#fffbe9', '#241d4f', '#ed1c24'];
  const flagCircle = document.querySelector('.flag-circle');
  const circleRect = flagCircle.getBoundingClientRect();
  const circleCenterX = circleRect.left + circleRect.width / 2;
  const circleCenterY = circleRect.top + circleRect.height / 2;
  const circleRadius = circleRect.width / 2;

  for (let i = 0; i < pieces; i++) {
    const piece = document.createElement('div');
    piece.className = 'confetti-piece';

    // Random starting position avoiding the flag circle
    let startX, startY;
    let avoidCircle = true;
    let attempts = 0;
    do {
      startX = Math.random() * window.innerWidth;
      startY = Math.random() * window.innerHeight;
      const dx = startX - circleCenterX;
      const dy = startY - circleCenterY;
      if (Math.sqrt(dx * dx + dy * dy) > circleRadius + 40) avoidCircle = false;
      attempts++;
    } while (avoidCircle && attempts < 5);

    piece.style.left = `${startX}px`;
    piece.style.top = `${startY}px`;

    // Random launch direction and distance
    const angle = Math.random() * Math.PI * 2;
    const radius = 200 + Math.random() * 250;
    const dx = Math.cos(angle) * radius + (Math.random() - 0.5) * 80;
    const dy = Math.sin(angle) * radius + (Math.random() - 0.5) * 80;
    piece.style.setProperty('--dx', dx + 'px');
    piece.style.setProperty('--dy', dy + 'px');
    piece.style.setProperty('--rot', ((Math.random() - 0.5) * 180) + 'deg');
    piece.style.setProperty('--color', colors[i % colors.length]);

    // Stagger animation by delay
    piece.style.animationDelay = (i * 0.012) + 's';

    confetti.appendChild(piece);

    // Smoothed fade-out removal
    setTimeout(() => {
      piece.style.opacity = '0';
      piece.style.transform += ' scale(1.08)';
      setTimeout(() => { piece.remove(); }, 600);
    }, 2200 + i * 12);
  }
}

let tapped = false;
window.onload = () => {
  document.getElementById("transitionOverlay").classList.remove("active");
  tapped = false;
  document.getElementById("agreeTerms").checked = false;
  hideFlagOverlayInstant();
  document.querySelector('.tap-text').classList.remove('morphing', 'morphed');
  document.querySelector('.welcome-logo').classList.remove('animate');
};

function bodyTapped(event) {
  // Ignore taps on modal or checkbox
  if (event.target.closest('.modal') || event.target.closest('.checkbox-container')) return;
  const agree = document.getElementById("agreeTerms");
  const errorMessage = document.getElementById("errorMessage");
  if (!agree.checked) {
    errorMessage.textContent = " Please agree to the Terms & Conditions.";
    errorMessage.style.background = "rgba(255, 245, 245, 0.95)";
    errorMessage.style.color = "rgba(244,67,54,0.95)";
    errorMessage.classList.add("show");
    setTimeout(() => errorMessage.classList.remove("show"), 2500);
    return;
  }
  if (!tapped) {
    tapped = true;
    document.querySelector('.welcome-logo').classList.add('animate');
    const tapText = document.querySelector('.tap-text');
    tapText.classList.add('morphing');
    // Particle burst at tap location
    let x = event.clientX, y = event.clientY;
    if (x === 0 && y === 0) { x = window.innerWidth/2; y = window.innerHeight/2; }
    createParticles(x, y);
    setTimeout(() => {
      tapText.classList.remove('morphing');
      tapText.classList.add('morphed');
      showFlagOverlay();
    }, 720);
  }
}

// --- Interactive Thailand Flag Overlay Transition ---
function showFlagOverlay() {
  const flagOverlay = document.getElementById("flagOverlay");
  flagOverlay.style.display = "flex";
  setTimeout(() => flagOverlay.classList.add("active"), 15);
  setTimeout(() => showFlagConfetti(), 110);

  // Animate the flag stripes in sequence for more lively effect
  let stripes = flagOverlay.querySelectorAll('.flag-stripe-c');
  stripes.forEach((stripe, i) => {
    setTimeout(() => {
      stripe.style.opacity = "1";
      stripe.style.transform = "scaleX(1.1)";
      setTimeout(() => stripe.style.transform = "scaleX(1)", 240);
    }, 120 + 70*i);
  });

  // Animate emoji bounce multiple times
  let emoji = flagOverlay.querySelector('.flag-emoji');
  setTimeout(() => {
    emoji.style.animation = "emojiPop 1.1s cubic-bezier(.42,1.7,.46,1.12) forwards";
    setTimeout(() => { emoji.style.animation = "emojiPop 0.7s cubic-bezier(.42,1.7,.46,1.12) forwards"; }, 900);
  }, 180);

  // Animate text with a gentle pulse after appearing
  let text = flagOverlay.querySelector('.flag-textbox .text');
  setTimeout(() => {
    text.style.animation = "textFlagIn 0.9s 0.1s cubic-bezier(.2,1.1,.4,1.08) forwards, flagPulse 1.2s 1.1s infinite alternate";
  }, 300);
  // Keyframes for text pulse
  let style = document.createElement('style');
  style.innerHTML = "@keyframes flagPulse { 0% {transform:scale(1);} 100% {transform:scale(1.03);} }";
  document.head.appendChild(style);

  // Smooth transition, redirect after main animation
  setTimeout(() => {
    flagOverlay.classList.remove("active");
    hideFlagOverlayInstant();
    document.getElementById("transitionOverlay").style.background = "#FFA726";
    document.getElementById("transitionOverlay").classList.add("active");
    window.location.href = "food_preference.php";
  }, 1400);
}
function hideFlagOverlayInstant() {
  const flagOverlay = document.getElementById("flagOverlay");
  flagOverlay.style.display = "none";
  flagOverlay.classList.remove("active");
}

// Modal functionality
const modal = document.getElementById("termsModal");
document.getElementById("openTermsModal").onclick = e => {
  e.preventDefault();
  modal.style.display = "flex";
};
document.querySelector(".modal .close").onclick = () => {
  modal.style.display = "none";
};
window.addEventListener('click', function(e) {
  if (e.target === modal) modal.style.display = "none";
});
document.querySelector(".modal-content").onclick = e => e.stopPropagation();
</script>
<script>
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('service-worker.js').catch(e => console.log('SW register failed', e));
}
</script>

</body>
</html>