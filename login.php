<?php
session_start();

$host = "localhost";
$user = "root";
$pass = "";
$db   = "rental_truck";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$error = ""; // variable to hold error messages

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email    = trim($_POST['email']);
    $password = trim($_POST['password']);

    $stmt = $conn->prepare("SELECT Rid, fullname, email, mobileno, password FROM register WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->bind_result($id, $fullname, $accountEmail, $mobile, $hashed_password);
        $stmt->fetch();

        if (password_verify($password, $hashed_password)) {
            $_SESSION['user_id'] = $id;
            $_SESSION['user_fullname'] = $fullname;
            $_SESSION['user_email'] = $accountEmail;
            $_SESSION['user_mobile'] = $mobile;
            header("Location: truck_register.php");
            exit();
        } else {
            $error = "❌ Invalid password. Please try again.";
        }
    } else {
        $error = "❌ No account found with that email.";
    }

    $stmt->close();
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Login - Rental Truck</title>
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
      height: 100vh;
      margin: 0;
    }
    .login-container {
      background: rgba(14, 42, 48, 0.96);
      padding: 38px 34px 32px;
      border: 1px solid #b99a52;
      border-radius: 8px;
      box-shadow: 0 22px 55px rgba(0,0,0,0.45), inset 0 0 0 5px rgba(185,154,82,.08);
      width: min(100%, 390px);
      position: relative;
    }
    .login-container::before,
    .login-container::after {
      content: '';
      position: absolute;
      left: 12%;
      right: 12%;
      height: 1px;
      background: #6f5c35;
    }
    .login-container::before { top: 16px; }
    .login-container::after { bottom: 16px; }
    .login-container h2 {
      text-align: center;
      margin-bottom: 8px;
      color: #e5c979;
      font-family: Georgia, 'Times New Roman', serif;
      font-size: 30px;
      font-weight: 600;
    }
    .login-intro {
      margin: 0 0 26px;
      text-align: center;
      color: #a9c7c4;
      font-size: 14px;
    }
    .login-container p {
      color: #b8cbc5;
      font-size: 15px;
      line-height: 1.5;
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
      position: absolute;
      top: -60px;
      left: 0;
      right: 0;
    }
    .form-group {
      margin: 0 0 20px;
  
    }
    .form-group label {
      display: block;
      margin-bottom: 8px;
      font-weight: 700;
      color: #cbd8d4;
      font-size: 14px;
      letter-spacing: .02em;
    }
    .form-group input {
      width: 100%;
      height: 48px;
      padding: 13px 14px;
      border: 1px solid #557675;
      border-radius: 4px;
      font-size: 16px;
      font-weight: 400;
      line-height: 20px;
      background: #d9e2dc;
      color: #183438;
      font-family: inherit;
      transition: border-color .2s ease, box-shadow .2s ease;
    }
    .form-group input:focus {
      outline: 0;
      border-color: #d1ae61;
      box-shadow: 0 0 0 3px rgba(209,174,97,.18);
    }
    .login-btn {
      width: 100%;
      padding: 10px;
      background: #b28d42;
      border: 1px solid #d1ae61;
      border-radius: 10px;
      color: #17292d;
      font-weight: 700;
      font-size: 17px;
      cursor: pointer;
      transition: background .2s ease, transform .2s ease;
    }
    .login-btn:hover {
      background: #d1ae61;
    }
    .login-container a {
      color: #e5c979;
      font-weight: 700;
    }
    .login-container a:hover {
      color: #f2d995;
    }
    .login-footer {
      margin: 24px 0 0;
      text-align: center;
      color: #a9c7c4;
    }
  </style>
</head>
<body>
  <div class="login-container">
    <h2>Login</h2>
    <p class="login-intro">Sign in to continue your truck booking</p>
    <!-- Error popup -->
    <div id="errorPopup" class="error-message"><?php echo $error; ?></div>

    <form method="post" action="">
      <div class="form-group">
        <label for="email">Email Address</label>
        <input type="email" id="email" name="email" required>
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
      </div>

      <button type="submit" class="login-btn">Login</button>
      <p class="login-footer">New customer? <a href="register.php">Create an account</a></p>
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
