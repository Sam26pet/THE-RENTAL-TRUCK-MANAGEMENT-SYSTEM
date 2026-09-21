<?php
function startAdminSession(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function requireAdminSession(): void {
    startAdminSession();
    if (empty($_SESSION['admin_id'])) {
        $scriptPath = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
        if (strpos($scriptPath, '/api/') !== false) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Admin session expired. Please sign in again.']);
            exit;
        }
        header('Location: admin_access.php?mode=login');
        exit;
    }
}

function createAdminTable(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS admins (
        admin_id INT AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(120) NOT NULL,
        email VARCHAR(150) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

$isDirectAccess = basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'admin_access.php';
if (!$isDirectAccess) {
    return;
}

startAdminSession();
$mode = $_GET['mode'] ?? 'login';
if ($mode === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: admin_access.php?mode=login');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'rental_truck');
if ($conn->connect_error) {
    die('Unable to connect to the database.');
}
createAdminTable($conn);
$error = '';
$success = isset($_GET['registered']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($mode === 'register') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        if (!$fullName || !$email || !$password || !$confirmPassword) {
            $error = 'Please complete all fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[^a-zA-Z0-9]/', $password)) {
            $error = 'Password must be at least 8 characters and include uppercase, lowercase, number, and special character.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } else {
            $check = $conn->prepare('SELECT admin_id FROM admins WHERE email = ?');
            $check->bind_param('s', $email);
            $check->execute();
            $check->store_result();
            $exists = $check->num_rows > 0;
            $check->close();
            if ($exists) {
                $error = 'An admin account with this email already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare('INSERT INTO admins (full_name, email, password) VALUES (?, ?, ?)');
                $stmt->bind_param('sss', $fullName, $email, $hash);
                if ($stmt->execute()) {
                    header('Location: admin_access.php?mode=login&registered=1');
                    exit;
                }
                $error = 'The admin account could not be created. Please try again.';
                $stmt->close();
            }
        }
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $stmt = $conn->prepare('SELECT admin_id, full_name, password FROM admins WHERE email = ?');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows === 1) {
            $stmt->bind_result($adminId, $fullName, $hash);
            $stmt->fetch();
            if (password_verify($password, $hash)) {
                session_regenerate_id(true);
                $_SESSION['admin_id'] = $adminId;
                $_SESSION['admin_name'] = $fullName;
                header('Location: admin.php');
                exit;
            }
        }
        $error = 'Invalid admin email or password.';
        $stmt->close();
    }
}
$conn->close();
$isRegister = $mode === 'register';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isRegister ? 'Admin Registration' : 'Admin Login'; ?></title>
<style>
*{box-sizing:border-box}body{
margin:0;min-height:100vh;
display:grid;place-items:center;
padding:24px;font-family:Georgia,"Times New Roman",serif;
background-size:cover;background-position:center;
background-attachment:fixed;color:#d9e5e1;
transition:background-image 1s ease-in-out}body::before{content:"";position:fixed;inset:0;background:rgba(7,20,26,.62);z-index:-1}.card{width:min(100%,440px);
padding:34px;background:rgba(14,42,48,.96);
border:1px solid #b99a52;border-radius:6px;
box-shadow:0 24px 70px rgba(0,0,0,.45),inset 0 0 0 5px rgba(185,154,82,.08)}
h1{margin:0 0 10px;color:#e5c979;font-size:29px;font-weight:600}
p{color:#b8cbc5;font-size:16px;line-height:1.5}
.field{margin:20px 0}.field label{display:block;margin-bottom:8px;font-weight:700;color:#cbd8d4;font-size:15px}
.field input{width:100%;padding:14px 15px;border:1px solid #557675;
border-radius:4px;font-size:17px;background:#d9e2dc;color:#183438;font-family:inherit}.field input:focus{outline:0;border-color:#d1ae61;box-shadow:0 0 0 3px rgba(209,174,97,.18)}

button{
width:100%;padding:13px;border:0;
border-radius:10px;
background:#b28d42;
border:1px solid #d1ae61;
color:#17292d;
font-weight:700;
font-size:17px;cursor:pointer}

button:hover{background:#d1ae61}.error,.success{padding:12px;border-radius:4px;font-weight:600;transition:opacity .35s ease,transform .35s ease}
.error{background:#4b2528;color:#ffd0cc;font-size:15px;line-height:1.45}
.success{background:#1d5149;color:#c8f0d9;font-size:15px;line-height:1.45}
.message-hidden{opacity:0;transform:translateY(-8px);
pointer-events:none}
.links{text-align:center;font-size:15px;
margin-top:20px}.links a{color:#e5c979;font-weight:700;text-decoration:none}
</style>
</head>
<body>
    <main class="card">
        <h1><?php echo $isRegister ? 'Create Admin Account' : 'Admin Sign In'; ?></h1>
        <p><?php echo $isRegister ? 'Register an administrator before accessing the management panel.' : 'Sign in to manage trucks, bookings, and returned vehicles.'; ?></p>

        <?php if ($error): ?>
            <div class="error" id="authMessage"><?php echo htmlspecialchars($error); ?></div>
        <?php elseif ($success): ?>
            <div class="success" id="authMessage">Admin account created. You can sign in now.</div>
        <?php endif; ?>

        <form method="post">
            <?php if ($isRegister): ?>
                <div class="field">
                    <label for="full_name">Full Name</label>
                    <input id="full_name" name="full_name" required value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
                </div>
            <?php endif; ?>

            <div class="field">
                <label for="email">Admin Email</label>
                <input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>

            <?php if ($isRegister): ?>
                <div class="field">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>
            <?php endif; ?>

            <button type="submit"><?php echo $isRegister ? 'Create Admin Account' : 'Sign In'; ?></button>
        </form>

        <div class="links">
            <?php if ($isRegister): ?>
                Already registered? <a href="admin_access.php?mode=login">Sign in</a>
            <?php else: ?>
                Need an admin account? <a href="admin_access.php?mode=register">Register here</a>
            <?php endif; ?>
        </div>
    </main>

    <script>
        const adminBackgrounds = ['image/volvo 3.jpg', 'image/meat van3.jfif'];
        let adminBackgroundIndex = 0;

        function rotateAdminBackground() {
            document.body.style.backgroundImage = `url('${adminBackgrounds[adminBackgroundIndex]}')`;
            adminBackgroundIndex = (adminBackgroundIndex + 1) % adminBackgrounds.length;
        }

        rotateAdminBackground();
        setInterval(rotateAdminBackground, 6000);

        const authMessage = document.getElementById('authMessage');
        if (authMessage) {
            setTimeout(() => authMessage.classList.add('message-hidden'), 4000);
            setTimeout(() => authMessage.remove(), 4500);
        }
    </script>
</body>
</html>
