<?php
// 基础配置
define('SITE_NAME', '阅后即焚');
define('SITE_URL', 'http://biaozhu.com');
define('UPLOAD_PATH', dirname(__DIR__) . '/uploads/');
define('UPLOAD_URL', SITE_URL . '/uploads/');

// 会话配置
define('SESSION_LIFETIME', 86400 * 7); // 7天

// 文件上传配置
define('MAX_IMAGE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

// 阅后即焚配置
define('BURN_DELAY_SECONDS', 30); // 消息阅读后30秒焚毁

// 错误报告设置
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', dirname(__DIR__) . '/logs/error.log');

// 时区设置
date_default_timezone_set('Asia/Shanghai');

// 自动创建必要目录
$directories = [
    dirname(__DIR__) . '/uploads/avatars',
    dirname(__DIR__) . '/uploads/images',
    dirname(__DIR__) . '/logs'
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}
