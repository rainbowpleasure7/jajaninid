<?php
// JajaninId - Database Setup (SQLite)

class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        $dbPath = __DIR__ . '/JajaninId.db';
        $this->pdo = new PDO("sqlite:$dbPath");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec("PRAGMA journal_mode=WAL");
        $this->pdo->exec("PRAGMA foreign_keys=ON");
        $this->createTables();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getPdo() {
        return $this->pdo;
    }

    private function createTables() {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                google_id TEXT UNIQUE,
                email TEXT UNIQUE NOT NULL,
                name TEXT NOT NULL,
                avatar_url TEXT DEFAULT '',
                role TEXT DEFAULT 'fan' CHECK(role IN ('fan','creator','admin')),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS creator_profiles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER UNIQUE NOT NULL,
                username TEXT UNIQUE NOT NULL,
                display_name TEXT NOT NULL,
                bio TEXT DEFAULT '',
                cover_image_url TEXT DEFAULT '',
                category TEXT DEFAULT 'Other',
                social_links TEXT DEFAULT '{}',
                min_gift_amount INTEGER DEFAULT 5000,
                total_earned INTEGER DEFAULT 0,
                available_balance INTEGER DEFAULT 0,
                is_verified INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            );

            CREATE TABLE IF NOT EXISTS gift_transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id TEXT UNIQUE NOT NULL,
                fan_id INTEGER,
                fan_display_name TEXT DEFAULT 'Anonymous',
                creator_id INTEGER NOT NULL,
                gift_amount INTEGER NOT NULL,
                platform_fee INTEGER NOT NULL,
                total_paid INTEGER NOT NULL,
                message TEXT DEFAULT '',
                status TEXT DEFAULT 'pending' CHECK(status IN ('pending','success','failed','expired')),
                payment_method TEXT DEFAULT '',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (fan_id) REFERENCES users(id),
                FOREIGN KEY (creator_id) REFERENCES creator_profiles(id)
            );

            CREATE TABLE IF NOT EXISTS withdrawals (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                creator_id INTEGER NOT NULL,
                amount INTEGER NOT NULL,
                method TEXT NOT NULL,
                destination_account TEXT NOT NULL,
                destination_name TEXT DEFAULT '',
                status TEXT DEFAULT 'pending' CHECK(status IN ('pending','processing','success','failed')),
                requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                processed_at DATETIME,
                FOREIGN KEY (creator_id) REFERENCES creator_profiles(id)
            );
        ");

        // Seed demo data if empty
        $count = $this->pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($count == 0) {
            $this->seedData();
        }
    }

    private function seedData() {
        // ===== SPECIAL ACCOUNTS =====
        // Developer Account (Admin/Creator) - untuk full access development
        $specialAccounts = [
            ['google_id'=>'dev_account','email'=>'developer@JajaninId.id','name'=>'Developer Admin','avatar_url'=>'','role'=>'admin','password_hash'=>password_hash('dev2026!', PASSWORD_DEFAULT)],
        ];

        // Add password_hash column if not exists
        try {
            $this->pdo->exec("ALTER TABLE users ADD COLUMN password_hash TEXT DEFAULT ''");
        } catch (Exception $e) { /* column already exists */ }

        $stmt = $this->pdo->prepare("INSERT INTO users (google_id,email,name,avatar_url,role,password_hash) VALUES (:google_id,:email,:name,:avatar_url,:role,:password_hash)");
        foreach ($specialAccounts as $sa) { $stmt->execute($sa); }

        // Demo creator users
        $users = [
            ['google_id'=>'g_101','email'=>'artstudio@gmail.com','name'=>'Art Studio ID','avatar_url'=>'','role'=>'creator'],
            ['google_id'=>'g_102','email'=>'gamingzone@gmail.com','name'=>'Gaming Zone','avatar_url'=>'','role'=>'creator'],
            ['google_id'=>'g_103','email'=>'chefnusantara@gmail.com','name'=>'Chef Nusantara','avatar_url'=>'','role'=>'creator'],
            ['google_id'=>'g_104','email'=>'musikindo@gmail.com','name'=>'Musik Indo','avatar_url'=>'','role'=>'creator'],
            ['google_id'=>'g_105','email'=>'techreview@gmail.com','name'=>'Tech Review ID','avatar_url'=>'','role'=>'creator'],
            ['google_id'=>'g_106','email'=>'comedynight@gmail.com','name'=>'Comedy Night','avatar_url'=>'','role'=>'creator'],
            ['google_id'=>'g_201','email'=>'andi@gmail.com','name'=>'Andi Gaming','avatar_url'=>'','role'=>'fan'],
            ['google_id'=>'g_202','email'=>'siti@gmail.com','name'=>'Siti Nurhaliza','avatar_url'=>'','role'=>'fan'],
            ['google_id'=>'g_203','email'=>'budi@gmail.com','name'=>'Budi Doremi','avatar_url'=>'','role'=>'fan'],
        ];
        $stmt = $this->pdo->prepare("INSERT INTO users (google_id,email,name,avatar_url,role) VALUES (:google_id,:email,:name,:avatar_url,:role)");
        foreach ($users as $u) { $stmt->execute($u); }

        // Creator profiles (user_ids shifted +2 because of 2 special accounts)
        // Developer account (user_id=2) gets a creator profile too
        $creators = [
            ['user_id'=>2,'username'=>'dev_admin','display_name'=>'Developer Admin','bio'=>'🛠️ Full access developer account — JajaninId Admin','category'=>'Tech','total_earned'=>99000000,'available_balance'=>15000000],
            ['user_id'=>3,'username'=>'artstudio_id','display_name'=>'Art Studio ID','bio'=>'Digital artist & illustrator 🎨','category'=>'Art','total_earned'=>15600000,'available_balance'=>2400000],
            ['user_id'=>4,'username'=>'gamingzone','display_name'=>'Gaming Zone','bio'=>'Pro gamer & streamer 🎮','category'=>'Gaming','total_earned'=>42000000,'available_balance'=>4850000],
            ['user_id'=>5,'username'=>'chef_nusantara','display_name'=>'Chef Nusantara','bio'=>'Resep masakan Indonesia 🍳','category'=>'Cooking','total_earned'=>8900000,'available_balance'=>900000],
            ['user_id'=>6,'username'=>'musikindo','display_name'=>'Musik Indo','bio'=>'Cover lagu & original music 🎵','category'=>'Music','total_earned'=>28000000,'available_balance'=>3200000],
            ['user_id'=>7,'username'=>'techreview_id','display_name'=>'Tech Review ID','bio'=>'Review gadget terbaru 📱','category'=>'Tech','total_earned'=>19500000,'available_balance'=>1500000],
            ['user_id'=>8,'username'=>'comedynight','display_name'=>'Comedy Night','bio'=>'Stand-up comedy & sketsa 😂','category'=>'Comedy','total_earned'=>55000000,'available_balance'=>6000000],
        ];
        $stmt = $this->pdo->prepare("INSERT INTO creator_profiles (user_id,username,display_name,bio,category,total_earned,available_balance) VALUES (:user_id,:username,:display_name,:bio,:category,:total_earned,:available_balance)");
        foreach ($creators as $c) { $stmt->execute($c); }

        // Demo transactions (fan_ids shifted +2, creator_ids shifted +1 for dev_admin)
        $txns = [
            ['order_id'=>'TRX-001','fan_id'=>9,'fan_display_name'=>'Andi Gaming','creator_id'=>3,'gift_amount'=>50000,'platform_fee'=>500,'total_paid'=>50500,'message'=>'Semangat terus bang!','status'=>'success','payment_method'=>'GoPay'],
            ['order_id'=>'TRX-002','fan_id'=>10,'fan_display_name'=>'Siti Nurhaliza','creator_id'=>3,'gift_amount'=>100000,'platform_fee'=>1000,'total_paid'=>101000,'message'=>'Terus berkarya ya kak 🔥','status'=>'success','payment_method'=>'DANA'],
            ['order_id'=>'TRX-003','fan_id'=>11,'fan_display_name'=>'Budi Doremi','creator_id'=>3,'gift_amount'=>25000,'platform_fee'=>250,'total_paid'=>25250,'message'=>'Suka banget!','status'=>'success','payment_method'=>'QRIS'],
            ['order_id'=>'TRX-004','fan_id'=>9,'fan_display_name'=>'Andi Gaming','creator_id'=>2,'gift_amount'=>10000,'platform_fee'=>100,'total_paid'=>10100,'message'=>'','status'=>'success','payment_method'=>'OVO'],
            ['order_id'=>'TRX-005','fan_id'=>10,'fan_display_name'=>'Siti Nurhaliza','creator_id'=>4,'gift_amount'=>50000,'platform_fee'=>500,'total_paid'=>50500,'message'=>'Resepnya enak!','status'=>'success','payment_method'=>'GoPay'],
        ];
        $stmt = $this->pdo->prepare("INSERT INTO gift_transactions (order_id,fan_id,fan_display_name,creator_id,gift_amount,platform_fee,total_paid,message,status,payment_method) VALUES (:order_id,:fan_id,:fan_display_name,:creator_id,:gift_amount,:platform_fee,:total_paid,:message,:status,:payment_method)");
        foreach ($txns as $t) { $stmt->execute($t); }
    }
}
