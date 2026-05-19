<?php
// JajaninId - API Endpoints
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/database.php';
$config = require __DIR__ . '/config.php';
$db = Database::getInstance()->getPdo();

$action = $_GET['action'] ?? '';

// ========================================
// CREATORS
// ========================================

// GET: List all creators
if ($action === 'creators') {
    $category = $_GET['category'] ?? '';
    $search = $_GET['search'] ?? '';

    $sql = "SELECT cp.*, u.avatar_url,
            (SELECT COUNT(DISTINCT fan_id) FROM gift_transactions WHERE creator_id=cp.id AND status='success') as supporters
            FROM creator_profiles cp
            JOIN users u ON u.id = cp.user_id WHERE 1=1";
    $params = [];

    if ($category && $category !== 'Semua') {
        $sql .= " AND cp.category = :cat";
        $params['cat'] = $category;
    }
    if ($search) {
        $sql .= " AND (cp.display_name LIKE :s OR cp.username LIKE :s2)";
        $params['s'] = "%$search%";
        $params['s2'] = "%$search%";
    }
    $sql .= " ORDER BY cp.total_earned DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['creators' => $stmt->fetchAll()]);
    exit;
}

// GET: Single creator profile
if ($action === 'creator') {
    $username = $_GET['username'] ?? '';
    $stmt = $db->prepare("SELECT cp.*, u.avatar_url, u.email,
        (SELECT COUNT(DISTINCT fan_id) FROM gift_transactions WHERE creator_id=cp.id AND status='success') as supporters
        FROM creator_profiles cp
        JOIN users u ON u.id = cp.user_id
        WHERE cp.username = :u");
    $stmt->execute(['u' => $username]);
    $creator = $stmt->fetch();

    if (!$creator) {
        http_response_code(404);
        echo json_encode(['error' => 'Creator not found']);
        exit;
    }

    // Recent supporters
    $stmt = $db->prepare("SELECT fan_display_name, gift_amount, message, created_at
        FROM gift_transactions WHERE creator_id = :cid AND status='success'
        ORDER BY created_at DESC LIMIT 10");
    $stmt->execute(['cid' => $creator['id']]);
    $supporters = $stmt->fetchAll();

    echo json_encode(['creator' => $creator, 'recent_supporters' => $supporters]);
    exit;
}

// ========================================
// GIFT TRANSACTIONS
// ========================================

// POST: Create gift transaction
if ($action === 'send_gift') {
    $data = json_decode(file_get_contents('php://input'), true);

    $creatorUsername = $data['creator_username'] ?? '';
    $giftAmount     = intval($data['gift_amount'] ?? 0);
    $message        = trim($data['message'] ?? '');
    $paymentMethod  = $data['payment_method'] ?? '';
    $fanName        = $data['fan_name'] ?? 'Anonymous';

    // Validate
    if ($giftAmount < $config['platform']['min_gift_amount']) {
        echo json_encode(['error' => "Minimum gift Rp " . number_format($config['platform']['min_gift_amount'])]);
        exit;
    }
    if ($giftAmount > $config['platform']['max_gift_amount']) {
        echo json_encode(['error' => 'Nominal terlalu besar']);
        exit;
    }
    if (!$paymentMethod) {
        echo json_encode(['error' => 'Pilih metode pembayaran']);
        exit;
    }

    // Get creator
    $stmt = $db->prepare("SELECT id FROM creator_profiles WHERE username = :u");
    $stmt->execute(['u' => $creatorUsername]);
    $creator = $stmt->fetch();
    if (!$creator) {
        echo json_encode(['error' => 'Creator not found']);
        exit;
    }

    // Calculate fee
    $platformFee = intval(ceil($giftAmount * $config['platform']['fee_percentage']));
    $totalPaid   = $giftAmount + $platformFee;
    $orderId     = 'GIFT-' . time() . '-' . bin2hex(random_bytes(4));

    // Get fan_id from session if logged in
    $fanId = $_SESSION['user_id'] ?? null;
    if ($fanId) {
        $stmt = $db->prepare("SELECT name FROM users WHERE id=:id");
        $stmt->execute(['id'=>$fanId]);
        $fan = $stmt->fetch();
        if ($fan) $fanName = $fan['name'];
    }

    // Insert transaction
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("INSERT INTO gift_transactions
            (order_id, fan_id, fan_display_name, creator_id, gift_amount, platform_fee, total_paid, message, status, payment_method)
            VALUES (:oid, :fid, :fname, :cid, :amount, :fee, :total, :msg, 'success', :method)");
        $stmt->execute([
            'oid'=>$orderId, 'fid'=>$fanId, 'fname'=>$fanName,
            'cid'=>$creator['id'], 'amount'=>$giftAmount,
            'fee'=>$platformFee, 'total'=>$totalPaid,
            'msg'=>$message, 'method'=>$paymentMethod,
        ]);

        // Update creator balance
        $stmt = $db->prepare("UPDATE creator_profiles SET
            total_earned = total_earned + :amount,
            available_balance = available_balance + :amount2
            WHERE id = :cid");
        $stmt->execute(['amount'=>$giftAmount, 'amount2'=>$giftAmount, 'cid'=>$creator['id']]);

        $db->commit();
        echo json_encode([
            'success'      => true,
            'order_id'     => $orderId,
            'gift_amount'  => $giftAmount,
            'platform_fee' => $platformFee,
            'total_paid'   => $totalPaid,
        ]);
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['error' => 'Transaksi gagal: ' . $e->getMessage()]);
    }
    exit;
}

// ========================================
// DASHBOARD DATA
// ========================================

// GET: Dashboard stats & transactions
if ($action === 'dashboard') {
    if (empty($_SESSION['logged_in']) || !in_array($_SESSION['user_role'], ['creator', 'admin'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $stmt = $db->prepare("SELECT * FROM creator_profiles WHERE user_id = :uid");
    $stmt->execute(['uid' => $_SESSION['user_id']]);
    $profile = $stmt->fetch();

    if (!$profile) {
        echo json_encode(['error' => 'No creator profile']);
        exit;
    }

    // Stats
    $stmt = $db->prepare("SELECT COUNT(*) as total_gifts, COUNT(DISTINCT fan_id) as unique_supporters
        FROM gift_transactions WHERE creator_id=:cid AND status='success'");
    $stmt->execute(['cid'=>$profile['id']]);
    $stats = $stmt->fetch();

    // Monthly count
    $stmt = $db->prepare("SELECT COUNT(*) as monthly FROM gift_transactions
        WHERE creator_id=:cid AND status='success'
        AND created_at >= date('now','start of month')");
    $stmt->execute(['cid'=>$profile['id']]);
    $monthly = $stmt->fetchColumn();

    // Recent transactions
    $stmt = $db->prepare("SELECT * FROM gift_transactions WHERE creator_id=:cid
        ORDER BY created_at DESC LIMIT 20");
    $stmt->execute(['cid'=>$profile['id']]);
    $transactions = $stmt->fetchAll();

    echo json_encode([
        'profile'      => $profile,
        'stats'        => $stats,
        'monthly_gifts'=> $monthly,
        'transactions' => $transactions,
    ]);
    exit;
}

// GET: All transactions (admin view)
if ($action === 'all_transactions') {
    $stmt = $db->query("SELECT gt.*, cp.display_name as creator_name
        FROM gift_transactions gt
        JOIN creator_profiles cp ON cp.id = gt.creator_id
        ORDER BY gt.created_at DESC LIMIT 50");
    echo json_encode(['transactions' => $stmt->fetchAll()]);
    exit;
}

// POST: Withdrawal request
if ($action === 'withdraw') {
    if (empty($_SESSION['logged_in'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $amount = intval($data['amount'] ?? 0);
    $method = $data['method'] ?? '';
    $account = $data['destination_account'] ?? '';

    $stmt = $db->prepare("SELECT * FROM creator_profiles WHERE user_id=:uid");
    $stmt->execute(['uid'=>$_SESSION['user_id']]);
    $profile = $stmt->fetch();

    if (!$profile) {
        echo json_encode(['error' => 'No creator profile']);
        exit;
    }
    if ($amount < 50000) {
        echo json_encode(['error' => 'Minimum pencairan Rp 50.000']);
        exit;
    }
    if ($amount > $profile['available_balance']) {
        echo json_encode(['error' => 'Saldo tidak cukup']);
        exit;
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("INSERT INTO withdrawals (creator_id, amount, method, destination_account, status) VALUES (:cid, :amt, :met, :acc, 'processing')");
        $stmt->execute(['cid'=>$profile['id'], 'amt'=>$amount, 'met'=>$method, 'acc'=>$account]);

        $stmt = $db->prepare("UPDATE creator_profiles SET available_balance = available_balance - :amt WHERE id=:cid");
        $stmt->execute(['amt'=>$amount, 'cid'=>$profile['id']]);

        $db->commit();
        echo json_encode(['success'=>true, 'message'=>'Pencairan sedang diproses']);
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['error'=>'Gagal memproses pencairan']);
    }
    exit;
}

// Default
echo json_encode(['error' => 'Invalid action', 'available_actions' => ['creators','creator','send_gift','dashboard','all_transactions','withdraw']]);
