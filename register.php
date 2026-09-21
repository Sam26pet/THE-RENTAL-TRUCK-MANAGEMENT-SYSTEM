<?php
$servername = "localhost";  
$username   = "root";        
$password   = "";            
$dbname     = "rental_truck"; 

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$error = ""; 

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $fullname         = trim($_POST['fullname']);
    $email            = trim($_POST['email']);
    $mobile           = trim($_POST['mobile']);
    $password         = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // Check if email already exists
    $check = $conn->prepare("SELECT email FROM register WHERE email = ?");
    $check->bind_param("s", $email);
    $check->execute();
    $check->store_result();
    if ($check->num_rows > 0) {
        $error = "❌ This email is already registered.";
    }
    $check->close();

    // Password validation
    if (empty($error)) {
        if (strlen($password) < 8) {
            $error = "❌ Password must be at least 8 characters long.";
        } elseif (!preg_match("/[A-Z]/", $password) || 
                  !preg_match("/[a-z]/", $password) || 
                  !preg_match("/[0-9]/", $password) || 
                  !preg_match("/[\W]/", $password)) {
            $error = "❌ Password must include uppercase, lowercase, number, and special character.";
        } elseif ($password !== $confirm_password) {
            $error = "❌ Passwords do not match.";
        }
    }

    // If no error, insert user
    if (empty($error)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $sql = "INSERT INTO register (fullname, email, mobileno, password) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssss", $fullname, $email, $mobile, $hashed_password);

        if ($stmt->execute()) {
            header("Location: login.php");
            exit();
        } else {
            $error = "❌ Error: " . $stmt->error;
        }
        $stmt->close();
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Register - Rental Truck</title>
  <style>
    *,
    *::before,
    *::after {
      box-sizing: border-box;
    }

    body {
      font-family: Georgia, 'Times New Roman', serif;
      background-color: #102b32;
      background-size: cover;
      background-position: center;
      background-repeat: no-repeat;
      transition: background-image 1s ease-in-out;
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      padding: 24px 16px;
      margin: 0;
    }
    .form-container {
      background: rgba(14, 42, 48, 0.96);
      padding: 34px 32px;
      border: 1px solid #b99a52;
      border-radius: 6px;
      box-shadow: 0 22px 55px rgba(0,0,0,0.45), inset 0 0 0 5px rgba(185,154,82,.08);
      width: min(100%, 390px);
      position: relative;
      overflow: hidden;
    }
    .form-container h2 {
      text-align: center;
      margin-bottom: 20px;
      color: #e5c979;
      font-weight: 600;
    }
    .error-message {
      background: #4b2528;
      color: #ffd0cc;
      padding: 10px;
      border-radius: 6px;
      margin-bottom: 15px;
      text-align: center;
      font-weight: bold;
      display: none; /* hidden by default */
    }
    .form-group {
      margin-bottom: 15px;
    }
    .form-group label {
      display: block;
      margin-bottom: 5px;
      font-weight: bold;
      color: #cbd8d4;
    }
    .form-group input {
      width: 100%;
      max-width: 100%;
      padding: 10px;
      border: 1px solid #557675;
      border-radius: 4px;
      background: #d9e2dc;
      color: #183438;
      font: inherit;
    }
    .password-wrapper {
      position: relative;
    }
    .password-wrapper input {
      padding-right: 44px;
    }
    .password-toggle {
      position: absolute;
      top: 50%;
      right: 10px;
      width: 30px;
      height: 30px;
      padding: 0;
      border: 0;
      background: transparent;
      color: #315b59;
      font-size: 18px;
      line-height: 1;
      cursor: pointer;
      transform: translateY(-50%);
    }
    .password-toggle:hover {
      color: #e5c979;
    }

    .password-toggle:focus-visible {
      outline: 2px solid #d1ae61;
      outline-offset: 2px;
    }
    .register-btn {
      width: 100%;
      padding: 12px;
      background: #b28d42;
      border: 1px solid #d1ae61;
      border-radius: 4px;
      color: #fff;
      font-size: 16px;
      cursor: pointer;
    }
    .register-btn:hover {
      background: #d1ae61;
    }
    .back-btn {
      display: block;
      margin-top: 10px;
      text-align: center;
      background: #315f60;
      color: #fff;
      padding: 10px;
      border-radius: 4px;
      text-decoration: none;
    }
    .back-btn:hover {
      background: #427b76;
    }

    @media (max-width: 420px) {
      .form-container {
        padding: 24px 20px;
      }
    }
  </style>
</head>
<body>
  <div class="form-container">
    <h2>Register</h2>
    <!-- Error popup -->
    <div id="errorPopup" class="error-message"><?php echo $error; ?></div>

    <form method="post" action="">
      <div class="form-group">
        <label for="fullname">Full Name</label>
        <input type="text" id="fullname" name="fullname" required>
      </div>

      <div class="form-group">
        <label for="email">Email Address</label>
        <input type="email" id="email" name="email" required>
      </div>

      <div class="form-group">
        <label for="mobile">Mobile No</label>
        <input type="tel" id="mobile" name="mobile" pattern="[0-9]{10}" placeholder="e.g. 0712345678" required>
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <div class="password-wrapper">
          <input type="password" id="password" name="password" required>
          <button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false" data-password-target="password">&#128065;</button>
        </div>
      </div>

      <div class="form-group">
        <label for="confirm_password">Confirm Password</label>
        <div class="password-wrapper">
          <input type="password" id="confirm_password" name="confirm_password" required>
          <button type="button" class="password-toggle" aria-label="Show confirm password" aria-pressed="false" data-password-target="confirm_password">&#128065;</button>
        </div>
      </div>

      <button type="submit" class="register-btn">Register</button>
      <a href="home.html" class="back-btn">Go Back </a>
    </form>
  </div>

  <!-- Background Slideshow -->
  <script>
    const images = [
      "image/volvo 3.jpg", "image/meat van3.jfif"
    ];
    let currentIndex = 0;
    function changeBackground() {
      document.body.style.backgroundImage = `url('${images[currentIndex]}')`;
      currentIndex = (currentIndex + 1) % images.length;
    }
    changeBackground();
    setInterval(changeBackground, 6000);

    document.querySelectorAll('.password-toggle').forEach(toggle => {
      toggle.addEventListener('click', () => {
        const passwordInput = document.getElementById(toggle.dataset.passwordTarget);
        const shouldShowPassword = passwordInput.type === 'password';
        passwordInput.type = shouldShowPassword ? 'text' : 'password';
        toggle.setAttribute('aria-label', shouldShowPassword ? 'Hide password' : 'Show password');
        toggle.setAttribute('aria-pressed', String(shouldShowPassword));
      });
    });

    // Show error popup if error exists
    const errorPopup = document.getElementById("errorPopup");
    if (errorPopup.innerText.trim() !== "") {
      errorPopup.style.display = "block";
      setTimeout(() => {
        errorPopup.style.display = "none";
      }, 4000); // hide after 4 seconds
    }
  </script>
</body>
</html>
