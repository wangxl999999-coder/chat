<?php
/**
 * 数据库更新脚本 - 添加朋友圈相关表
 * 运行此脚本以确保moments, comments, likes表存在
 */

require_once __DIR__ . '/config/database.php';

try {
    $pdo = getDB();
    $errors = [];
    $successes = [];
    
    // 1. 创建朋友圈表
    try {
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
        $successes[] = "moments表创建成功（或已存在）";
    } catch (PDOException $e) {
        $errors[] = "moments表创建失败: " . $e->getMessage();
    }
    
    // 2. 创建评论表
    try {
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
        $successes[] = "comments表创建成功（或已存在）";
    } catch (PDOException $e) {
        $errors[] = "comments表创建失败: " . $e->getMessage();
    }
    
    // 3. 创建点赞表
    try {
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
        $successes[] = "likes表创建成功（或已存在）";
    } catch (PDOException $e) {
        $errors[] = "likes表创建失败: " . $e->getMessage();
    }
    
    // 输出结果
    echo "<!DOCTYPE html>
<html lang='zh-CN'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>数据库更新结果</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; padding: 40px; background: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        h1 { color: #333; margin-bottom: 20px; font-size: 20px; }
        .success { color: #059669; background: #ecfdf5; padding: 10px 14px; border-radius: 6px; margin: 8px 0; }
        .error { color: #dc2626; background: #fef2f2; padding: 10px 14px; border-radius: 6px; margin: 8px 0; }
        .hint { margin-top: 20px; padding: 12px; background: #dbeafe; color: #1e40af; border-radius: 6px; font-size: 14px; }
        a { color: #6366f1; text-decoration: none; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>数据库更新结果</h1>";
    
    if (!empty($successes)) {
        echo "<h3 style='color: #059669; margin-top: 20px;'>成功</h3>";
        foreach ($successes as $msg) {
            echo "<div class='success'>✓ $msg</div>";
        }
    }
    
    if (!empty($errors)) {
        echo "<h3 style='color: #dc2626; margin-top: 20px;'>错误</h3>";
        foreach ($errors as $msg) {
            echo "<div class='error'>✗ $msg</div>";
        }
    }
    
    if (empty($errors)) {
        echo "<div class='hint'>
            <strong>更新完成！</strong><br>
            朋友圈相关表已成功创建。<br><br>
            <a href='moments.html'>点击这里访问朋友圈页面</a><br>
            <br>
            <strong>安全提示：</strong>请在使用后删除此文件。
        </div>";
    } else {
        echo "<div class='hint' style='background: #fef3c7; color: #92400e;'>
            <strong>出现错误</strong><br>
            请检查数据库连接配置和权限，确保users表已存在。
        </div>";
    }
    
    echo "
    </div>
</body>
</html>";
    
} catch (PDOException $e) {
    die("数据库连接失败: " . $e->getMessage());
}
