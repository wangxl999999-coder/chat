<?php
// 数据库配置 - 自动生成
define('DB_HOST', 'localhost');
define('DB_NAME', 'chat_app');
define('DB_USER', 'root');
define('DB_PASS', '123123');
define('DB_CHARSET', 'utf8mb4');

// 创建数据库连接
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die('数据库连接失败: ' . $e->getMessage());
        }
    }
    return $pdo;
}

// 初始化数据库
function initDB() {
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // 创建数据库
        $pdo->exec("CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE " . DB_NAME);
        
        // 用户表
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_number VARCHAR(10) NOT NULL UNIQUE COMMENT '10位以内唯一数字号码',
                nickname VARCHAR(50) NOT NULL COMMENT '昵称',
                avatar VARCHAR(255) DEFAULT '' COMMENT '头像路径',
                phone VARCHAR(11) NOT NULL UNIQUE COMMENT '手机号',
                password VARCHAR(255) NOT NULL COMMENT '密码（加密）',
                gender TINYINT DEFAULT 0 COMMENT '性别: 0未知 1男 2女',
                bio VARCHAR(200) DEFAULT '' COMMENT '简介',
                status TINYINT DEFAULT 1 COMMENT '状态: 0禁用 1正常',
                last_login DATETIME DEFAULT NULL COMMENT '最后登录时间',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user_number (user_number),
                INDEX idx_phone (phone)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户表'
        ");
        
        // 好友关系表
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS friends (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL COMMENT '用户ID',
                friend_id INT NOT NULL COMMENT '好友ID',
                status TINYINT DEFAULT 0 COMMENT '状态: 0待确认 1已同意 2已拒绝',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_friend (user_id, friend_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (friend_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_id (user_id),
                INDEX idx_friend_id (friend_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='好友关系表'
        ");
        
        // 会话表
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS conversations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL COMMENT '用户ID',
                target_id INT NOT NULL COMMENT '目标用户ID',
                last_message TEXT COMMENT '最后一条消息预览',
                last_message_time DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '最后消息时间',
                unread_count INT DEFAULT 0 COMMENT '未读消息数',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_conversation (user_id, target_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (target_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会话表'
        ");
        
        // 消息表
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                conversation_id INT NOT NULL COMMENT '会话ID',
                sender_id INT NOT NULL COMMENT '发送者ID',
                receiver_id INT NOT NULL COMMENT '接收者ID',
                msg_type TINYINT DEFAULT 1 COMMENT '消息类型: 1文字 2图片 3表情',
                content TEXT NOT NULL COMMENT '消息内容',
                is_read TINYINT DEFAULT 0 COMMENT '是否已读: 0未读 1已读',
                is_burned TINYINT DEFAULT 0 COMMENT '是否已焚: 0未焚 1已焚',
                burn_time DATETIME DEFAULT NULL COMMENT '阅读后焚时间',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
                FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_conversation_id (conversation_id),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='消息表'
        ");
        
        // 管理员表
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS admins (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL UNIQUE COMMENT '管理员用户名',
                password VARCHAR(255) NOT NULL COMMENT '密码（加密）',
                role TINYINT DEFAULT 1 COMMENT '角色: 1普通管理员 2超级管理员',
                last_login DATETIME DEFAULT NULL COMMENT '最后登录时间',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员表'
        ");
        
        // 朋友圈表
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS moments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL COMMENT '发布者ID',
                content TEXT COMMENT '文字内容',
                images TEXT COMMENT '图片URL，JSON数组',
                is_private TINYINT DEFAULT 0 COMMENT '是否私有: 0公开 1私有',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_id (user_id),
                INDEX idx_created_at (created_at),
                INDEX idx_is_private (is_private)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='朋友圈表'
        ");
        
        // 评论表
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS comments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                moment_id INT NOT NULL COMMENT '朋友圈ID',
                user_id INT NOT NULL COMMENT '评论者ID',
                reply_to_id INT DEFAULT NULL COMMENT '回复的评论ID，NULL表示直接评论',
                reply_to_user_id INT DEFAULT NULL COMMENT '回复的用户ID',
                content TEXT NOT NULL COMMENT '评论内容',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (moment_id) REFERENCES moments(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (reply_to_id) REFERENCES comments(id) ON DELETE CASCADE,
                FOREIGN KEY (reply_to_user_id) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_moment_id (moment_id),
                INDEX idx_user_id (user_id),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='评论表'
        ");
        
        // 点赞表
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS likes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                moment_id INT NOT NULL COMMENT '朋友圈ID',
                user_id INT NOT NULL COMMENT '点赞者ID',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_like (moment_id, user_id),
                FOREIGN KEY (moment_id) REFERENCES moments(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_moment_id (moment_id),
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='点赞表'
        ");
        
        // 插入默认管理员（用户名: admin, 密码: admin123）
        $defaultAdmin = $pdo->prepare("INSERT IGNORE INTO admins (username, password, role) VALUES (?, ?, 2)");
        $defaultAdmin->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT)]);
        
        return true;
    } catch (PDOException $e) {
        die('数据库初始化失败: ' . $e->getMessage());
    }
}
