<?php
require_once dirname(__DIR__) . '/includes/functions.php';

switch ($action) {
    case 'send_code':
        // 发送验证码
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['phone'])) {
            jsonResponse(false, '请输入手机号');
        }
        
        $phone = trim($input['phone']);
        
        if (!isValidPhone($phone)) {
            jsonResponse(false, '手机号格式错误');
        }
        
        // 检查手机号是否已注册
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ?");
        $stmt->execute([$phone]);
        
        // 如果是注册接口，需要检查手机号是否已存在
        // 这里同时支持注册和登录的验证码
        $code = generateVerifyCode();
        if (sendVerifyCode($phone, $code)) {
            jsonResponse(true, '验证码已发送', ['phone' => $phone, 'code' => $code]); // 实际生产环境不要返回code
        } else {
            jsonResponse(false, '验证码发送失败');
        }
        break;
        
    case 'register':
        // 用户注册
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        // 验证必填字段
        $required = ['nickname', 'phone', 'code', 'password'];
        foreach ($required as $field) {
            if (!isset($input[$field]) || empty(trim($input[$field]))) {
                jsonResponse(false, "请填写完整信息: {$field}");
            }
        }
        
        $nickname = trim($input['nickname']);
        $phone = trim($input['phone']);
        $code = trim($input['code']);
        $password = $input['password'];
        $gender = isset($input['gender']) ? intval($input['gender']) : 0;
        $avatar = isset($input['avatar']) ? trim($input['avatar']) : '';
        
        // 验证格式
        if (!isValidNickname($nickname)) {
            jsonResponse(false, '昵称长度应在2-20个字符之间');
        }
        
        if (!isValidPhone($phone)) {
            jsonResponse(false, '手机号格式错误');
        }
        
        if (!isValidPassword($password)) {
            jsonResponse(false, '密码长度应在6-20个字符之间');
        }
        
        // 验证验证码
        if (!verifyCode($phone, $code)) {
            jsonResponse(false, '验证码错误或已过期');
        }
        
        $pdo = getDB();
        
        // 检查手机号是否已注册
        $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ?");
        $stmt->execute([$phone]);
        if ($stmt->rowCount() > 0) {
            jsonResponse(false, '该手机号已注册');
        }
        
        try {
            $pdo->beginTransaction();
            
            // 生成唯一用户号码
            $userNumber = generateUniqueUserNumber();
            
            // 密码加密
            $passwordHash = encryptPassword($password);
            
            // 如果有头像，保存 base64 图片到文件
            if (!empty($avatar)) {
                $uploadResult = uploadImageFromBase64($avatar, 'avatars');
                if ($uploadResult['success']) {
                    $avatar = $uploadResult['data']['url'];
                } else {
                    $avatar = '';
                }
            }
            
            // 插入用户数据
            $stmt = $pdo->prepare("
                INSERT INTO users (user_number, nickname, avatar, phone, password, gender)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userNumber, $nickname, $avatar, $phone, $passwordHash, $gender]);
            
            $userId = $pdo->lastInsertId();
            
            $pdo->commit();
            
            // 自动登录
            loginUser($userId);
            
            // 获取用户信息
            $stmt = $pdo->prepare("
                SELECT id, user_number, nickname, avatar, phone, gender, bio, created_at
                FROM users WHERE id = ?
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            jsonResponse(true, '注册成功', $user);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(false, '注册失败: ' . $e->getMessage());
        }
        break;
        
    case 'login':
        // 用户登录
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        // 支持两种登录方式：手机号+密码 或 手机号+验证码
        $phone = trim($input['phone'] ?? '');
        $password = $input['password'] ?? '';
        $code = trim($input['code'] ?? '');
        
        if (empty($phone)) {
            jsonResponse(false, '请输入手机号');
        }
        
        if (!isValidPhone($phone)) {
            jsonResponse(false, '手机号格式错误');
        }
        
        $pdo = getDB();
        
        // 查找用户
        $stmt = $pdo->prepare("SELECT * FROM users WHERE phone = ?");
        $stmt->execute([$phone]);
        $user = $stmt->fetch();
        
        if (!$user) {
            jsonResponse(false, '该手机号未注册');
        }
        
        if ($user['status'] != 1) {
            jsonResponse(false, '账号已被禁用');
        }
        
        // 验证登录方式
        if (!empty($password)) {
            // 密码登录
            if (!verifyPassword($password, $user['password'])) {
                jsonResponse(false, '密码错误');
            }
        } elseif (!empty($code)) {
            // 验证码登录
            if (!verifyCode($phone, $code)) {
                jsonResponse(false, '验证码错误或已过期');
            }
        } else {
            jsonResponse(false, '请输入密码或验证码');
        }
        
        // 登录成功
        loginUser($user['id']);
        
        // 返回用户信息（不包含密码）
        unset($user['password']);
        
        jsonResponse(true, '登录成功', $user);
        break;
        
    case 'logout':
        // 退出登录
        logoutUser();
        jsonResponse(true, '已退出登录');
        break;
        
    case 'check_login':
        // 检查登录状态
        if (isLoggedIn()) {
            $user = getCurrentUser();
            if ($user && $user['status'] == 1) {
                jsonResponse(true, '已登录', $user);
            } else {
                logoutUser();
                jsonResponse(false, '登录状态已失效');
            }
        } else {
            jsonResponse(false, '未登录');
        }
        break;
        
    default:
        jsonResponse(false, '接口不存在', null, 404);
}
