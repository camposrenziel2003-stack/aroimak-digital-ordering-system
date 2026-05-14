Google One subscription will be canceled … Update your payment method by Dec 2, 2025 to renew your storage subscription
<?php  
session_start();
include "config.php";
include "access_check.php";

// -------------------- INIT CART --------------------
if (!isset($_SESSION['cart'])) { 
    $_SESSION['cart'] = [];   
}  

if (isset($_GET['reset']) && $_GET['reset'] == 1) {
    $_SESSION['cart'] = [];
    header("Location: reco.php?ref=reco.php");
    exit;
}

// -------------------- CART TOTAL --------------------
$totalPrice = 0;
$cartCount = 0;
foreach ($_SESSION['cart'] as $c) {
    $dishId = (int) $c['id'];
    $quantity = (int) $c['quantity'];
    $cartCount += $quantity;
    $res = $conn->query("SELECT price FROM menu_items WHERE id = $dishId");
    if ($res && $row = $res->fetch_assoc()) {
        $totalPrice += $row['price'] * $quantity;
    }
}

// -------------------- GET DRINKS CATEGORY ID --------------------
$drinksCatId = null;
$drinksCatRes = $conn->query("SELECT id FROM categories WHERE name = 'Drinks' LIMIT 1");
if ($drinksCatRes && $drinksRow = $drinksCatRes->fetch_assoc()) {
    $drinksCatId = $drinksRow['id'];
}

// -------------------- GET TOP 10 POPULAR DISHES (EXCLUDING DRINKS) --------------------
$top10Dishes = [];
if ($drinksCatId !== null) {
    $popResult = $conn->query("
        SELECT order_items.item_name
        FROM order_items
        LEFT JOIN menu_items ON order_items.item_name = menu_items.name
        WHERE menu_items.category != $drinksCatId
        GROUP BY order_items.item_name
        ORDER BY SUM(order_items.quantity) DESC
        LIMIT 10
    ");
} else {
    $popResult = $conn->query("
        SELECT order_items.item_name
        FROM order_items
        GROUP BY order_items.item_name
        ORDER BY SUM(order_items.quantity) DESC
        LIMIT 10
    ");
}
while ($popRow = $popResult->fetch_assoc()) {
    $top10Dishes[] = $popRow['item_name'];
}

// -------------------- PREFERENCES HANDLING --------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && !empty($_POST['preferences'])) {
    $preferences = $_POST['preferences'];
    $_SESSION['preferences'] = $preferences;
    header("Location: reco.php");
    exit;
}

// Build query based on preferences
if (!empty($_SESSION['preferences'])) {
    $preferences = $_SESSION['preferences'];
    $conditions = [];
    foreach ($preferences as $pref) {
        $safePref = $conn->real_escape_string($pref);
        $conditions[] = "preferences LIKE '%$safePref%'";
    }
    $whereClause = implode(" OR ", $conditions);
    $sql = "
    SELECT mi.*, c.name AS category_name 
    FROM menu_items mi
    LEFT JOIN categories c ON mi.category = c.id
    WHERE $whereClause
";
    $result = $conn->query($sql);
} else {
    $result = $conn->query("
    SELECT mi.*, c.name AS category_name 
    FROM menu_items mi
    LEFT JOIN categories c ON mi.category = c.id
    LIMIT 8
");
}

// --- Split menus for display order ---
// 1. Available Popular
// 2. Available Others
// 3. Unavailable Popular
// 4. Unavailable Others
$availPopular = [];
$availOthers = [];
$unavailPopular = [];
$unavailOthers = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $isPopular = in_array($row['name'], $top10Dishes);
        if ($row['stock'] > 0) {
            if ($isPopular) {
                $availPopular[] = $row;
            } else {
                $availOthers[] = $row;
            }
        } else {
            if ($isPopular) {
                $unavailPopular[] = $row;
            } else {
                $unavailOthers[] = $row;
            }
        }
    }
}

// Always go back to food_preference.php and pass current preferences
$savedPrefsStr = !empty($_SESSION['preferences']) ? urlencode(json_encode($_SESSION['preferences'])) : '';
$ref = 'food_preference.php' . ($savedPrefsStr ? '?saved_prefs=' . $savedPrefsStr : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Recommended Dishes</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
  --bg1: #FFD54F;
  --bg2: #FFA726;
  --accent: #FF5722;
  --card-bg: #fff;
  --text-gray: #555;
  --unavailable: #ccc;
  --header-shadow: rgba(0,0,0,0.15);
  --btn-shadow: rgba(0,0,0,0.12);
}
* { box-sizing: border-box; }
body {
  font-family: 'Segoe UI', Tahoma, sans-serif;
  background: linear-gradient(to bottom, var(--bg1), var(--bg2));
  margin: 0; padding: 0;
}
header {
  position: fixed;
  top: 0; left: 0; width: 100%;
  z-index: 1000;
  background: linear-gradient(to right, var(--bg1), var(--bg2));
  padding: 15px 20px 15px 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  box-shadow: 0 3px 8px var(--header-shadow);
}
.header-bar-back {
  position: absolute;
  top: 20px;
  left: 24px;
  z-index: 1100;
}
.header-bar-back a,
.header-bar-back a:visited,
.header-bar-back a:active,
.header-bar-back a:focus {
  text-decoration: none !important;
  color: inherit !important;
}
.header-bar-back .back {
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
}
.header-bar-back .back:hover {
  background: linear-gradient(135deg, #FFD54F, #FF9800);
  transform: scale(1.07);
  box-shadow: 0 10px 28px rgba(255,152,0,0.22);
}
header img {
  height: 130px; width: 130px;
  border-radius: 50%;
  background: #fff8e1;
  padding: 10px;
  object-fit: cover;
  transition: transform 0.3s, box-shadow 0.3s;
  box-shadow: 0 8px 32px rgba(255,193,7,0.25), 0 2px 14px rgba(255,152,0,0.13);
  border: 5px solid #FF9800;
}
header img:hover { 
  transform: scale(1.11); 
  box-shadow: 0 12px 40px rgba(255,193,7,0.31), 0 8px 24px rgba(255,152,0,0.18);
  border-color: #FFD54F;
}
header h1 {
  font-size: 24px;
  font-weight: bold;
  color: #fff;
  margin: 0;
  font-style: italic;
  line-height: 1.3;
  text-shadow: 0 1px 3px rgba(0,0,0,0.4);
  text-align: center;
}
header form {
  position: absolute; right: 20px; top: 20px;
}
header .cart-icon {
  background: rgba(255,255,255,0.2);
  border: none; cursor: pointer;
  font-size: 27px; color: white;
  padding: 17px; border-radius: 40%;
  transition: all 0.3s ease;
  position: relative;
}
header .cart-icon:hover {
  background: rgba(255,255,255,0.4);
  transform: scale(1.1);
}
.cart-badge {
  position: absolute;
  top: 8px;
  right: 8px;
  background: #FF5722;
  color: white;
  font-size: 15px;
  font-weight: bold;
  border-radius: 50%;
  padding: 4px 8px;
  box-shadow: 0 1.5px 7px rgba(255,87,34,0.21);
  z-index: 2;
  min-width: 24px;
  height: 24px;
  display: flex;
  align-items: center;
  justify-content: center;
}
html, body {
  height: 100%;
  overflow: hidden;
}
.main-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 40px;
  height: 100%;
  padding-top: 150px;
  padding-bottom: 164px;
  overflow: hidden;
}
.menu-container {
  width: 100%;
  max-width: 1000px;
  flex: 1;
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
  padding: 0px 30px;
  margin-top: 40px;
  margin-bottom: 50px;
}
.menu-container::-webkit-scrollbar {
  width: 8px;
}
.menu-container::-webkit-scrollbar-track {
  background: transparent;
}
.menu-container::-webkit-scrollbar-thumb {
  background: rgba(0,0,0,0.2);
  border-radius: 4px;
}
.menu-container::-webkit-scrollbar-thumb:hover {
  background: rgba(0,0,0,0.35);
}
.menu-grid {
  display: grid;
  gap: 28px 30px;
  width: 100%;
  grid-template-columns: repeat(2, 1fr); /* Always 2 cards per row by default */
}

.menu-card {
  background: var(--card-bg);
  border-radius: 22px;
  box-shadow: 
    0 8px 32px rgba(255, 140, 0, 0.10),
    0 2px 4px rgba(0,0,0,0.09),
    0 1.5px 5px 0px rgba(255, 109, 0, 0.13);
  padding: 18px 14px 14px 14px;
  display: flex;
  flex-direction: column;
  transition: box-shadow 0.22s, transform 0.15s;
  text-decoration: none;
  min-height: 450px;
  max-width: 100%;
  overflow: hidden;
  /* make sure the menu card stands out */
}

.menu-card:hover, .menu-card:focus {
  transform: scale(1.045) translateY(-12px);
  box-shadow:
    0 20px 48px 0 rgba(255, 140, 0, 0.18),
    0 6px 30px rgba(255,186,58,0.24),
    0 0 0 4px #ff9800aa;
  z-index: 3;
  border: 2.5px solid #FF9800;
  background: linear-gradient(110deg, #fff9e6 85%, #ffd54f 100%);
}

/* Optionally, slightly enlarge the image on hover for effect */
.menu-card:hover img, .menu-card:focus img {
  transform: scale(1.04);
  transition: transform 0.23s;
  box-shadow: 0 15px 40px rgba(255,152,0,0.18);
}
.menu-card.unavailable { opacity: 0.6; pointer-events: none; }
.menu-card .imgwrap {
  width: 100%;
  height: 320px;
  background: none;
  border-top-left-radius: 22px;
  border-top-right-radius: 22px;
  overflow: hidden;
  display: flex;
  align-items: stretch;
  justify-content: stretch;
  padding: 0;
}
.menu-card img {
  width: 100%;
  height: 310px; /* large and appealing */
  border-radius: 20px;
  object-fit: cover;
  object-position: center center;
  box-shadow: 0 4px 16px rgba(255, 140, 0, 0.13);
  background: #fffce8;
  border: 2px solid #fff7e0;
  margin-bottom: 8px;
}

.menu-card h3 { margin: 0; font-size: 30px; font-weight: 700; color: #333; letter-spacing: 0.2px; display: flex; align-items: center; gap: 8px; }
.menu-card p.desc { margin: 0; font-size: 18px; color: var(--text-gray); min-height: 40px; }
.menu-card .price { font-weight: 700; font-size: 25px; color: var(--accent); margin-top: auto; }
.unavailable-text { font-size: 13px; font-weight: 600; color: red; }
.speaker-button {
  background: white;
  color: #FF9800;
  border: none;
  border-radius: 50%;
  font-size: 20px;
  width: 30px;
  height: 30px;
  cursor: pointer;
  margin-left: 6px;
  transition: all 0.2s ease;
  box-shadow: 0 1.5px 7px rgba(255,152,0,0.13);
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.speaker-button:hover {
  background: #FF9800;
  color: #fff;
  transform: scale(1.11) rotate(-7deg);
  box-shadow: 0 6px 14px rgba(255, 152, 0, 0.13);
}
.fire-icon {
  color: #ff5722;
  font-size: 1.11em;
  margin-left: 4px;
  vertical-align: middle;
  filter: drop-shadow(0 1px 3px #ff9800aa);
  animation: firePulse 1.2s infinite;
}
@keyframes firePulse {
  0% { transform: scale(1); filter: brightness(1.1);}
  50% { transform: scale(1.15); filter: brightness(1.22);}
  100% { transform: scale(1); filter: brightness(1.1);}
}
.button-container {
  position: fixed;
  bottom: 0;
  left: 0;
  width: 100%;
  background: linear-gradient(to right, var(--bg2), var(--bg1));
  padding: 17px 10px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  box-shadow: 0 -5px 15px rgba(0,0,0,0.13);
  z-index: 1000;
}
.main-buttons, .secondary-buttons {
  display: flex; gap: 15px; flex-wrap: wrap; justify-content: center;
}
.btn {
  padding: 16px 38px;
  border-radius: 16px;
  font-weight: 700;
  font-size: 20px;
  cursor: pointer;
  border: none;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  transition: all 0.2s ease;
  min-width: 150px;
  box-shadow: 0 4px 14px var(--btn-shadow);
}
.btn-confirm {
  background: linear-gradient(135deg, #875a1cff, #df8537ff);
  color: #fff;
  font-weight: 700;
  border-radius: 18px;
  box-shadow: 0 4px 10px rgba(255, 186, 58, 0.15);
  transition: all 0.3s ease;
}
.btn-confirm:hover {
  background: linear-gradient(135deg, #ffb74d, #ff9800);
  transform: scale(1.06) translateY(-2px);
  box-shadow: 0 6px 12px rgba(0,0,0,0.19);
}
.btn-small {
  background: white;
  border: 2px solid #FFA726;
  color: #FF5722;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 13px 30px;
  border-radius: 18px;
  font-weight: 600;
  font-size: 16px;
  box-shadow: 0 3px 9px rgba(0,0,0,0.09);
  transition: all 0.2s ease;
}
.btn-small i {
  font-size: 17px;
}
.btn-small:hover {
  background: linear-gradient(135deg, #f2e7c4ff, #FF9800);
  color: white;
  transform: scale(1.06) translateY(-2px);
  box-shadow: 0 6px 12px rgba(0,0,0,0.16);
}
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
  background: linear-gradient(90deg, #d68356ff 0%, #672b07ff 100%);
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
  background: linear-gradient(135deg, #ff6a19ff, #FFD54F);
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
  background: linear-gradient(135deg, #d9ff8cff 70%, #ff8046ff 100%);
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
  color: #fff;
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
/* For iPad Pro 12.9", iPad Air, and other tablets at 1024x1366 (portrait or landscape) */
@media (min-width: 1024px) and (max-width: 1100px) and (min-height: 1300px) {
  .menu-grid {
    grid-template-columns: repeat(2, 1fr) !important;
    max-width: 1000px; /* slightly wider for iPad Pro screens */
    gap: 35px 28px;
  }
  .menu-card {
    min-height: 500px;
    height: 630px;
  }
  .menu-card .imgwrap {
    width: 440px;
    height: 440px;
    min-height: 350px;
    max-height: 420px;
    margin-bottom: 20px;
  }
  .menu-card img {
    width: 100%;
    height: 100%;
    border-radius: 0%;
    object-fit: cover;
  }
  .menu-card p.desc { margin: 0; font-size: 20px; color: var(--text-gray); min-height: 40px; }
  header h1 {
    font-size: 1.4rem;
  }
  header img {
    height: 130px;
    width: 130px;
  }
  .btn, .btn-small {
    font-size: 16px;
    padding: 12px 28px;
  }
  .confirm-btn-area, .confirm-slider-bg, .slider-container {
    width: 280px;
    min-width: 150px;
    height: 55px;
    max-width: 98vw;
  }
  .confirm-slider {
    width: 60px;
    height: 60px;
    font-size: 22px;
  }
  .slider-label { font-size: 20px; margin-left: 12px; }
}
/* For tablets like 10.6" or 11" in landscape (800px-1200px width, landscape orientation) */
@media (min-width:1000px) and (max-width:1200px) and (orientation: landscape) {
  header h1 { font-size: 21px; }
  header img { height: 110px; width: 110px; padding: 8px; }
  .menu-container { padding: 0px 10px; }
  .menu-card {
    min-height:450px;
    height: 450px;
  }
  .menu-card .imgwrap {
    height: 300px;
  }
  .menu-card img {
    height: 100%;
  }
  .btn, .btn-small { padding: 11px 15px; font-size: 15px; min-width: 100px; }
  .menu-card .content { padding: 9px; }
  .main-container { padding-top: 115px; padding-bottom: 110px; }
  .confirm-btn-area, .confirm-slider-bg, .slider-container { width: 190px; min-width: 90px; height: 34px; max-width: 97vw; }
  .confirm-slider { width: 34px; height: 34px; font-size: 17px; }
  .slider-label { font-size: 13px; }
}

/* For smaller tablets and large phones in landscape/portrait (<=800px) */
@media (max-width:700px) {
  header h1 { font-size: 18px; }
  header img { height: 90px; width: 90px; padding: 7px; }
  .menu-card .content { padding: 12px; }
  .btn, .btn-small { padding: 12px 20px; font-size: 15px; min-width: 120px; }
  .menu-card {
    min-height: 340px;
    height: 340px;
  }
  .menu-card .imgwrap { height: 120px; }
  .confirm-btn-area, .confirm-slider-bg, .slider-container { width: 160px; min-width: 80px; height: 32px; max-width: 97vw; }
  .confirm-slider { width: 32px; height: 32px; font-size: 14px; }
  .slider-label { font-size: 12px; }
}

@media (min-width: 1024px) and (max-width: 1400px) and (orientation: landscape) {
  .menu-grid {
    grid-template-columns: repeat(2, 1fr) !important;
    gap: 28px 28px;
    max-width: 1400px;
    margin: 0 auto;
  }
  .menu-card {
    min-height: 420px;
    height: 600px;
    width: 450px;
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
    align-items: stretch;
  }
  .menu-card .imgwrap {
    width: 100%;
    height: 400px;
    overflow: hidden;
    border-radius: 20px 20px 0 0;
    background: #fff8e1;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .menu-card img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 20px 20px 0 0;
    background: #fffce8;
    border: 2px solid #fff7e0;
    transition: transform 0.23s;
  }
  .confirm-btn-area, .confirm-slider-bg, .slider-container {
    width: 320px;
    min-width: 180px;
    height: 62px;
    max-width: 97vw;
  }
  .confirm-slider {
    width: 62px;
    height: 62px;
    font-size: 32px;
  }
  .slider-label {
    font-size: 22px;
  }
}
/* Landscape responsiveness: height between 700px and 900px */
@media (min-width: 1024px) and (max-width: 1400px) 
  and (orientation: landscape) 
  and (min-height: 700px) 
  and (max-height: 900px) {

  .menu-grid {
    margin-bottom: 40px; /* adjust value as needed */
  }
}
.assistance-btn {
  background: linear-gradient(135deg, #ff8c4eff, #ff6e3eff);
  color: white;
  border-radius: 50%;
  width: 60px;
  height: 60px;
  margin-left: 11px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 27px;
  border: none;
  box-shadow: 0 6px 16px rgba(230,74,25,0.16), 0 2px 8px rgba(255,152,0,0.13);
  cursor: pointer;
  transition: background 0.22s, box-shadow 0.22s, transform 0.2s;
}
.assistance-btn:hover {
  background: linear-gradient(135deg, #FFD54F, #FF9800);
  color: #fff;
  transform: scale(1.08);
}
.button-container {
  /* already exists */
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
}

.assistance-modal .modal-content,
#assistSuccessModal .modal-content {
  border-radius: 17px;
  font-size: 18px;
  padding: 24px 32px;
}
/* rest are as you have... */
.modal {
  display: none;
  position: fixed;
  z-index: 10000;
  left: 0; top: 0;
  width: 100%;
  height: 100%;
  background-color: rgba(0,0,0,0.4);
  justify-content: center;
  align-items: center;
}
.assistance-btn {
  background: linear-gradient(135deg, #ff8c4eff, #ff6e3eff);
  color: white;
  border-radius: 50%;
  width: 60px;
  height: 60px;
  margin-left: 11px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 27px;
  border: none;
  box-shadow: 0 6px 16px rgba(230,74,25,0.16), 0 2px 8px rgba(255,152,0,0.13);
  cursor: pointer;
  transition: background 0.22s, box-shadow 0.22s, transform 0.2s;
}
.assistance-btn:hover {
  background: linear-gradient(135deg, #FFD54F, #FF9800);
  color: #fff;
  transform: scale(1.08);
}
.button-container {
  /* already exists */
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
}
.modal {
  display: none;
  position: fixed;
  z-index: 10000;
  left: 0; top: 0;
  width: 100%;
  height: 100%;
  background-color: rgba(0,0,0,0.4);
  justify-content: center;
  align-items: center;
}
.modal-content {
  background: #fff;
  color: #FF3D00;
  padding: 24px 36px;
  border-radius: 15px;
  font-size: 21px;
  font-weight: bold;
  text-align: center;
  box-shadow: 0 14px 32px rgba(255,152,0,0.22), 0 4px 14px rgba(255,193,7,0.15);
}
</style>
</head>
<body>
<!-- Success Modal -->
<div id="successModal" class="modal">
  <div class="modal-content">
    <h2>Added to Cart!</h2>
  </div>
</div>
<header>
  <div class="header-bar-back">
    <a href="<?= htmlspecialchars($ref) ?>">
      <button type="button" class="back">
        <i class="fa fa-arrow-left"></i> Back
      </button>
    </a>
  </div>
  <img src="images/logo.png" alt="Logo">
  <h1>Here are dishes we think you'll love today!</h1>
  <form action="review_order.php" method="get">
    <input type="hidden" name="ref" value="reco.php">
    <button type="submit" class="cart-icon">
      <i class="fas fa-shopping-cart"></i>
      <?php if ($cartCount > 0): ?>
        <span class="cart-badge"><?= $cartCount ?></span>
      <?php endif; ?>
    </button>
  </form>
</header>
<div class="main-container">
  <div class="menu-container">
    <div class="menu-grid">
      <?php
      function renderMenu($row, $isUnavailable, $top10Dishes, $drinksCatId) {
        ?>
        <a href="menu_item_detail.php?id=<?= $row['id'] ?>&ref=reco.php" class="menu-card<?= $isUnavailable ? ' unavailable' : '' ?>">
          <div class="imgwrap">
            <?php if (!empty($row['image'])): ?>
              <img src="/thai_digital/uploads/<?= rawurlencode($row['image']); ?>" alt="<?= htmlspecialchars($row['name']); ?>">
            <?php else: ?>
              <img src="assets/img/placeholder.png" alt="No image available">
            <?php endif; ?>
          </div>
          <div class="content">
            <h3>
              <?= htmlspecialchars($row['name']); ?>
              <?php
              // Only show fire icon for popular NON-drinks
              if (in_array($row['name'], $top10Dishes) && ($drinksCatId !== null && $row['category'] != $drinksCatId)): ?>
                <span class="fire-icon" title="Popular!"><i class="fa-solid fa-fire"></i></span>
              <?php endif; ?>
              <button type="button" class="speaker-button"
                      onclick="speakThai('<?= htmlspecialchars($row['name']); ?>')">
                <i class="fas fa-volume-up"></i>
              </button>
            </h3>
            <p class="desc"><?= htmlspecialchars(substr($row['description'] ?? '', 0, 100)); ?></p>
            <?php
            $priceDisplay = "";
            if (in_array(strtolower($row['category_name']), ['fried rice', 'noodles'])) {
                $parts = [];
                if (!empty($row['price_solo']) && $row['price_solo'] > 0) {
                    $parts[] = "₱" . number_format((float)$row['price_solo'], 2) . " (Solo)";
                }
                if (!empty($row['price_sharing']) && $row['price_sharing'] > 0) {
                    $parts[] = "₱" . number_format((float)$row['price_sharing'], 2) . " (Sharing)";
                }
                $priceDisplay = implode(" / ", $parts);
            } else {
                $priceDisplay = "₱" . number_format((float)$row['price'], 2);
            }
            ?>
            <div class="price">
              <?= $isUnavailable ? '<span class="unavailable-text">Unavailable at the moment</span>' : $priceDisplay ?>
            </div>
          </div>
        </a>
        <?php
      }

      foreach ($availPopular as $row) renderMenu($row, false, $top10Dishes, $drinksCatId);
      foreach ($availOthers as $row) renderMenu($row, false, $top10Dishes, $drinksCatId);
      foreach ($unavailPopular as $row) renderMenu($row, true, $top10Dishes, $drinksCatId);
      foreach ($unavailOthers as $row) renderMenu($row, true, $top10Dishes, $drinksCatId);

      if (
        count($availPopular) == 0 &&
        count($availOthers) == 0 &&
        count($unavailPopular) == 0 &&
        count($unavailOthers) == 0
      ):
      ?>
        <p style="grid-column:1/-1;text-align:center;color:#fff;">No dishes found.</p>
      <?php endif; ?>
    </div>
  </div>
 <div class="button-container">
    <div class="main-buttons">
      <div class="confirm-btn-area">
        <div class="confirm-slider-bg"></div>
        <div id="sliderContainer" class="slider-container">
          <span class="slider-label"><i class="fa fa-arrow-right" style="margin-right: 5px;"></i>Slide to confirm</span>
          <div id="confirmSlider" class="confirm-slider"><i class="fa fa-check"></i></div>
        </div>
      </div>
      <!-- ASSISTANCE BUTTON HERE (in button-container, so always on bottom)-->
      <button type="button" id="assistanceBtn" class="assistance-btn" title="Ask for Assistance">
        <i class="fa fa-hand-paper"></i>
      </button>
    </div>
    <div class="secondary-buttons"> 
      <form action="kiosk_home.php" method="get"> 
        <input type="hidden" name="ref" value="reco.php">
        <button type="submit" class="btn-small"><i class="fas fa-list"></i> View All Menu Categories</button> 
      </form>
      <form action="food_preference.php" method="get">
        <input type="hidden" name="ref" value="reco.php">
        <?php if (!empty($_SESSION['preferences'])): ?>
          <input type="hidden" name="saved_prefs" value="<?= htmlspecialchars(json_encode($_SESSION['preferences'])) ?>">
        <?php endif; ?>
        <button type="submit" class="btn-small"><i class="fas fa-sliders-h"></i> Change My Food Preferences</button>
      </form>
    </div>
  </div>
</div>

<!-- Assistance Modal (updated to match menu detail) -->
<div id="assistModal" class="modal">
  <div class="modal-content" style="color:#E64A19;">
    <p>Do you want to request staff assistance?</p>
    <button type="button" class="btn" id="confirmAssistance"
      style="background: linear-gradient(135deg, #FF9800, #FFD54F); color:white; margin-right: 12px; border-radius:18px; font-weight: bold; box-shadow: 0 8px 18px rgba(255,152,0,0.17), 0 4px 12px rgba(255,193,7,0.12);">Yes, Call Staff</button>
    <button type="button" class="btn" id="cancelAssistance"
      style="background:#aaa; color:white; font-weight:bold; border-radius:18px; box-shadow:0 4px 12px rgba(255,193,7,0.08);">Cancel</button>
  </div>
</div>
<!-- Success Modal -->
<div id="assistSuccessModal" class="modal">
  <div class="modal-content" style="color:green;">
    <p>Staff assistance requested! They'll arrive at your table soon.</p>
    <button type="button" class="btn" id="assistSuccessOK" style="background: linear-gradient(135deg, #FF9800, #FFD54F); color:white; border-radius:18px;">OK</button>
  </div>
</div>
<script>
window.onload = function() {
  const params = new URLSearchParams(window.location.search);
  if (params.get("added") === "1") {
    let modal = document.getElementById("successModal");
    modal.style.display = "flex";
    setTimeout(() => { modal.style.display = "none"; }, 1200);
  }
};
function speakThai(text) {
  const utter = new SpeechSynthesisUtterance(text);
  const voices = speechSynthesis.getVoices();
  const thaiVoice = voices.find(v => v.lang === "th-TH") || voices.find(v => v.lang.startsWith("th"));
  if (thaiVoice) {
    utter.voice = thaiVoice;
  }
  utter.lang = "th-TH";
  speechSynthesis.speak(utter);
}
if (typeof speechSynthesis !== "undefined") {
  speechSynthesis.onvoiceschanged = () => {};
}

// --- Slider Confirm Button Logic (food_preference.php style) ---
(function() {
  const sliderContainer = document.getElementById("sliderContainer");
  const slider = document.getElementById("confirmSlider");
  // Confirm action: go to kiosk_home.php
  function confirmAction() {
    sliderContainer.classList.add("confirmed");
    slider.style.left = (sliderContainer.offsetWidth - slider.offsetWidth - 2) + "px";
    setTimeout(() => {
      window.location.href = "kiosk_home.php?ref=reco.php";
    }, 340);
  }
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
      confirmed = true;
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

  slider.addEventListener("mousedown", onDragStart);
  window.addEventListener("mousemove", onDragMove);
  window.addEventListener("mouseup", onDragEnd);
  slider.addEventListener("touchstart", onDragStart, {passive:false});
  window.addEventListener("touchmove", onDragMove, {passive:false});
  window.addEventListener("touchend", onDragEnd);
})();
// Assistance Button JS (same logic/menu detail style)
document.addEventListener('DOMContentLoaded', function() {
  const assistanceBtn = document.getElementById("assistanceBtn");
  const assistModal = document.getElementById("assistModal");
  const confirmAssistance = document.getElementById("confirmAssistance");
  const cancelAssistance = document.getElementById("cancelAssistance");
  const assistSuccessModal = document.getElementById("assistSuccessModal");
  const assistSuccessOK = document.getElementById("assistSuccessOK");
  assistanceBtn.onclick = function() {
    assistModal.style.display = "flex";
  };
  cancelAssistance.onclick = function() {
    assistModal.style.display = "none";
  };
  confirmAssistance.onclick = function() {
    assistModal.style.display = "none";
    const payload = {
      order_group_id: "",
      table_number: "",
      customer_name: "",
      request_type: "Assistance",
      assistance_request: "Customer requested staff assistance via button (from reco.php)"
    };
    fetch("send_request.php", {
      method: "POST",
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
      assistSuccessModal.style.display = "flex";
    })
    .catch(() => {
      alert("Network error, could not submit assistance request.");
    });
  };
  assistSuccessOK.onclick = function() {
    assistSuccessModal.style.display = "none";
  };
  document.querySelectorAll('.modal').forEach(function(modalDiv) {
    modalDiv.addEventListener('mousedown', function(e) {
      if (e.target === modalDiv) {
        modalDiv.style.display = 'none';
      }
    });
  });
});
</script>
</body>
</html>