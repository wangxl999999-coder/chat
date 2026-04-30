<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';

// 启动会话
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 生成唯一用户号码（10位以内数字）
function generateUniqueUserNumber() {
    $pdo = getDB();
    $maxAttempts = 100;
    
    for ($i = 0; $i < $maxAttempts; $i++) {
        // 生成6-9位随机数字
        $length = rand(6, 9);
        $number = '';
        for ($j = 0; $j < $length; $j++) {
            $number .= rand($j == 0 ? 1 : 0, 9);
        }
        
        // 检查是否已存在
        $stmt = $pdo->prepare("SELECT id FROM users WHERE user_number = ?");
        $stmt->execute([$number]);
        
        if ($stmt->rowCount() == 0) {
            return $number;
        }
    }
    
    throw new Exception('生成用户号码失败，请重试');
}

// 密码加密
function encryptPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

// 密码验证
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// 生成验证码
function generateVerifyCode($length = 6) {
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= rand(0, 9);
    }
    return $code;
}

// 发送验证码（模拟）
function sendVerifyCode($phone, $code) {
    // 在实际生产环境中，这里应该调用短信服务提供商的API
    // 这里仅作演示，将验证码存储在会话中
    $_SESSION['verify_code'] = [
        'phone' => $phone,
        'code' => $code,
        'expire' => time() + 300 // 5分钟过期
    ];
    
    error_log("发送验证码到手机 {$phone}: {$code}");
    
    return true;
}

// 验证验证码
function verifyCode($phone, $code) {
    if (!isset($_SESSION['verify_code'])) {
        return false;
    }
    
    $vc = $_SESSION['verify_code'];
    
    if ($vc['phone'] != $phone || $vc['code'] != $code) {
        return false;
    }
    
    if (time() > $vc['expire']) {
        unset($_SESSION['verify_code']);
        return false;
    }
    
    // 验证成功后销毁验证码
    unset($_SESSION['verify_code']);
    return true;
}

// 检查用户是否登录
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// 获取当前登录用户信息
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id, user_number, nickname, avatar, phone, gender, bio, status, last_login FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    
    return $stmt->fetch();
}

// 登录用户
function loginUser($userId) {
    $_SESSION['user_id'] = $userId;
    
    // 更新最后登录时间
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
    $stmt->execute([$userId]);
}

// 退出登录
function logoutUser() {
    unset($_SESSION['user_id']);
    session_destroy();
}

// JSON响应
function jsonResponse($success, $message = '', $data = null) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 检查手机号格式
function isValidPhone($phone) {
    return preg_match('/^1[3-9]\d{9}$/', $phone);
}

// 检查昵称格式
function isValidNickname($nickname) {
    $len = mb_strlen($nickname, 'UTF-8');
    return $len >= 2 && $len <= 20;
}

// 检查密码强度
function isValidPassword($password) {
    $len = strlen($password);
    return $len >= 6 && $len <= 20;
}

// 上传图片
function uploadImage($file, $subDir = '') {
    // 检查文件是否有错误
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [
            'success' => false,
            'message' => '文件上传错误: ' . $file['error']
        ];
    }
    
    // 检查文件大小
    if ($file['size'] > MAX_IMAGE_SIZE) {
        return [
            'success' => false,
            'message' => '文件大小不能超过5MB'
        ];
    }
    
    // 检查文件类型
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, ALLOWED_IMAGE_TYPES)) {
        return [
            'success' => false,
            'message' => '不支持的文件类型'
        ];
    }
    
    // 生成文件名
    $extension = image_type_to_extension(exif_imagetype($file['tmp_name']));
    $filename = uniqid() . $extension;
    
    // 构建保存路径
    $uploadPath = UPLOAD_PATH . ltrim($subDir, '/');
    if (!is_dir($uploadPath)) {
        mkdir($uploadPath, 0755, true);
    }
    
    $filepath = $uploadPath . '/' . $filename;
    
    // 移动文件
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        $url = UPLOAD_URL . ltrim($subDir, '/') . '/' . $filename;
        return [
            'success' => true,
            'message' => '上传成功',
            'data' => [
                'filename' => $filename,
                'filepath' => $filepath,
                'url' => $url
            ]
        ];
    } else {
        return [
            'success' => false,
            'message' => '文件保存失败'
        ];
    }
}

// 处理消息内容
function processMessageContent($type, $content) {
    switch ($type) {
        case 1: // 文字
            return htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
        case 2: // 图片
            return $content; // URL
        case 3: // 表情
            return $content; // 表情代码
        default:
            return $content;
    }
}

// 获取或创建会话
function getOrCreateConversation($userId, $targetId) {
    $pdo = getDB();
    
    // 检查会话是否存在
    $stmt = $pdo->prepare("SELECT * FROM conversations WHERE user_id = ? AND target_id = ?");
    $stmt->execute([$userId, $targetId]);
    $conversation = $stmt->fetch();
    
    if (!$conversation) {
        // 创建两个方向的会话
        $pdo->beginTransaction();
        
        try {
            // 发送者侧会话
            $stmt = $pdo->prepare("INSERT INTO conversations (user_id, target_id) VALUES (?, ?)");
            $stmt->execute([$userId, $targetId]);
            $convId1 = $pdo->lastInsertId();
            
            // 接收者侧会话
            $stmt = $pdo->prepare("INSERT INTO conversations (user_id, target_id) VALUES (?, ?)");
            $stmt->execute([$targetId, $userId]);
            $convId2 = $pdo->lastInsertId();
            
            $pdo->commit();
            
            $stmt = $pdo->prepare("SELECT * FROM conversations WHERE id = ?");
            $stmt->execute([$convId1]);
            $conversation = $stmt->fetch();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    
    return $conversation;
}
