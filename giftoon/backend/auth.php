<?php
// JajaninId - Google OAuth Authentication
session_start();

require_once __DIR__ . '/database.php';
$config = require __DIR__ . '/config.php';

$action = $_GET['action'] ?? '';
$db = Database::getInstance()->getPdo();

// ===== LOGIN: Login with email/password =====
if ($action === 'login_email') {
    header('Content-Type: application/json');

    $data = json_decode(file_get_contents('php://input'), true);
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';

    if (!$email || !$password) {
        echo json_encode(['error' => 'Email dan password wajib diisi']);
        exit;
    }

    $stmt = $db->prepare("SELECT * FROM users WHERE email = :email AND password_hash != ''");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        echo json_encode(['error' => 'Email atau password salah']);
        exit;
    }

    // Set session
    $_SESSION['user_id']     = $user['id'];
    $_SESSION['user_name']   = $user['name'];
    $_SESSION['user_email']  = $user['email'];
    $_SESSION['user_avatar'] = $user['avatar_url'];
    $_SESSION['user_role']   = $user['role'];
    $_SESSION['logged_in']   = true;

    // Determine redirect
    $redirect = $config['base_url'] . '/explore.html';
    if ($user['role'] === 'creator' || $user['role'] === 'admin') {
        $redirect = $config['base_url'] . '/dashboard.html';
    }

    echo json_encode([
        'success'  => true,
        'redirect' => $redirect,
        'user'     => [
            'id'    => $user['id'],
            'name'  => $user['name'],
            'email' => $user['email'],
            'role'  => $user['role'],
        ]
    ]);
    exit;
}

// ===== REGISTER: Native Email Registration =====
if ($action === 'register_email') {
    header('Content-Type: application/json');

    $data = json_decode(file_get_contents('php://input'), true);
    $name = trim($data['name'] ?? '');
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';

    if (!$name || !$email || !$password) {
        echo json_encode(['error' => 'Nama, email, dan password wajib diisi']);
        exit;
    }

    if (strlen($password) < 6) {
        echo json_encode(['error' => 'Password minimal 6 karakter']);
        exit;
    }

    // Check if user exists
    $stmt = $db->prepare("SELECT id FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    if ($stmt->fetch()) {
        echo json_encode(['error' => 'Email sudah terdaftar']);
        exit;
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("INSERT INTO users (email, name, role, password_hash) VALUES (:email, :name, 'fan', :password_hash)");
        $stmt->execute([
            'email' => $email,
            'name' => $name,
            'password_hash' => $hashedPassword
        ]);
        $userId = $db->lastInsertId();
        
        $db->commit();

        // Auto login
        $_SESSION['user_id']     = $userId;
        $_SESSION['user_name']   = $name;
        $_SESSION['user_email']  = $email;
        $_SESSION['user_avatar'] = '';
        $_SESSION['user_role']   = 'fan';
        $_SESSION['logged_in']   = true;

        echo json_encode([
            'success'  => true,
            'redirect' => $config['base_url'] . '/explore.html',
            'user'     => [
                'id'    => $userId,
                'name'  => $name,
                'email' => $email,
                'role'  => 'fan',
            ]
        ]);
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['error' => 'Terjadi kesalahan saat pendaftaran']);
    }
    exit;
}

// ===== LOGIN: Redirect to Google =====
if ($action === 'login') {
    $params = http_build_query([
        'client_id'     => $config['google']['client_id'],
        'redirect_uri'  => $config['google']['redirect_uri'],
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'access_type'   => 'online',
        'prompt'        => 'select_account',
        'state'         => bin2hex(random_bytes(16)),
    ]);
    $_SESSION['oauth_state'] = $_GET['state'] ?? '';
    header("Location: https://accounts.google.com/o/oauth2/v2/auth?$params");
    exit;
}

// ===== CALLBACK: Google redirects back =====
if ($action === 'callback') {
    if (isset($_GET['error'])) {
        header("Location: {$config['base_url']}/login.html?error=access_denied");
        exit;
    }

    $code = $_GET['code'] ?? '';
    if (!$code) {
        header("Location: {$config['base_url']}/login.html?error=no_code");
        exit;
    }

    // Exchange code for access token
    $tokenData = httpPost('https://oauth2.googleapis.com/token', [
        'code'          => $code,
        'client_id'     => $config['google']['client_id'],
        'client_secret' => $config['google']['client_secret'],
        'redirect_uri'  => $config['google']['redirect_uri'],
        'grant_type'    => 'authorization_code',
    ]);

    if (!isset($tokenData['access_token'])) {
        header("Location: {$config['base_url']}/login.html?error=token_failed");
        exit;
    }

    // Get user info from Google
    $userInfo = httpGet('https://www.googleapis.com/oauth2/v2/userinfo', $tokenData['access_token']);

    if (!isset($userInfo['id'])) {
        header("Location: {$config['base_url']}/login.html?error=userinfo_failed");
        exit;
    }

    // Check if user exists
    $stmt = $db->prepare("SELECT * FROM users WHERE google_id = :gid");
    $stmt->execute(['gid' => $userInfo['id']]);
    $user = $stmt->fetch();

    if (!$user) {
        // Register new user
        $stmt = $db->prepare("INSERT INTO users (google_id, email, name, avatar_url, role) VALUES (:gid, :email, :name, :avatar, 'fan')");
        $stmt->execute([
            'gid'    => $userInfo['id'],
            'email'  => $userInfo['email'],
            'name'   => $userInfo['name'],
            'avatar' => $userInfo['picture'] ?? '',
        ]);
        $userId = $db->lastInsertId();
        $user = ['id'=>$userId, 'google_id'=>$userInfo['id'], 'email'=>$userInfo['email'], 'name'=>$userInfo['name'], 'avatar_url'=>$userInfo['picture']??'', 'role'=>'fan'];
    }

    // Set session
    $_SESSION['user_id']     = $user['id'];
    $_SESSION['user_name']   = $user['name'];
    $_SESSION['user_email']  = $user['email'];
    $_SESSION['user_avatar'] = $user['avatar_url'];
    $_SESSION['user_role']   = $user['role'];
    $_SESSION['logged_in']   = true;

    // Redirect based on role
    if ($user['role'] === 'creator') {
        header("Location: {$config['base_url']}/dashboard.html");
    } else {
        header("Location: {$config['base_url']}/explore.html");
    }
    exit;
}

// ===== LOGOUT =====
if ($action === 'logout') {
    session_destroy();
    header("Location: {$config['base_url']}/index.html");
    exit;
}

// ===== GET SESSION (AJAX) =====
if ($action === 'session') {
    header('Content-Type: application/json');
    if (!empty($_SESSION['logged_in'])) {
        echo json_encode([
            'logged_in' => true,
            'user' => [
                'id'     => $_SESSION['user_id'],
                'name'   => $_SESSION['user_name'],
                'email'  => $_SESSION['user_email'],
                'avatar' => $_SESSION['user_avatar'],
                'role'   => $_SESSION['user_role'],
            ]
        ]);
    } else {
        echo json_encode(['logged_in' => false]);
    }
    exit;
}

// ===== UPGRADE TO CREATOR =====
if ($action === 'become_creator') {
    header('Content-Type: application/json');
    if (empty($_SESSION['logged_in'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Not logged in']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $username = preg_replace('/[^a-z0-9_]/', '', strtolower($data['username'] ?? ''));
    $displayName = trim($data['display_name'] ?? $_SESSION['user_name']);
    $bio = trim($data['bio'] ?? '');
    $category = trim($data['category'] ?? 'Other');

    if (strlen($username) < 3) {
        echo json_encode(['error' => 'Username minimal 3 karakter']);
        exit;
    }

    // Check if user already has a creator profile
    $stmt = $db->prepare("SELECT id FROM creator_profiles WHERE user_id = :uid");
    $stmt->execute(['uid' => $_SESSION['user_id']]);
    if ($stmt->fetch()) {
        echo json_encode(['error' => 'Satu akun hanya bisa memiliki maksimal 1 profil kreator.']);
        exit;
    }

    // Check username availability
    $stmt = $db->prepare("SELECT id FROM creator_profiles WHERE username = :u");
    $stmt->execute(['u' => $username]);
    if ($stmt->fetch()) {
        echo json_encode(['error' => 'Username sudah dipakai']);
        exit;
    }

    // Create profile & update role
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("INSERT INTO creator_profiles (user_id, username, display_name, bio, category) VALUES (:uid, :username, :name, :bio, :cat)");
        $stmt->execute(['uid'=>$_SESSION['user_id'], 'username'=>$username, 'name'=>$displayName, 'bio'=>$bio, 'cat'=>$category]);

        $stmt = $db->prepare("UPDATE users SET role='creator' WHERE id=:uid");
        $stmt->execute(['uid'=>$_SESSION['user_id']]);

        $_SESSION['user_role'] = 'creator';
        $db->commit();
        echo json_encode(['success' => true, 'username' => $username]);
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['error' => 'Gagal membuat profil kreator']);
    }
    exit;
}

// ===== HELPER FUNCTIONS =====
function httpPost($url, $data) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true) ?? [];
}

function httpGet($url, $token) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true) ?? [];
}
