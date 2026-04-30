<?php
require_once dirname(__DIR__) . '/includes/functions.php';

$currentUser = getCurrentUser();
if (!$currentUser) {
    jsonResponse(false, '请先登录');
}

switch ($action) {
    case 'profile':
        // 获取个人资料
        jsonResponse(true, '获取成功', $currentUser);
        break;
        
    case 'update':
        // 更新个人资料
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $pdo = getDB();
        
        // 允许更新的字段
        $allowedFields = ['nickname', 'avatar', 'gender', 'bio'];
        $updateFields = [];
        $updateValues = [];
        
        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                // 验证昵称
                if ($field === 'nickname') {
                    if (!isValidNickname($input[$field])) {
                        jsonResponse(false, '昵称长度应在2-20个字符之间');
                    }
                }
                
                // 验证性别
                if ($field === 'gender') {
                    $input[$field] = intval($input[$field]);
                    if (!in_array($input[$field], [0, 1, 2])) {
                        jsonResponse(false, '性别参数错误');
                    }
                }
                
                // 验证简介长度
                if ($field === 'bio') {
                    if (mb_strlen($input[$field], 'UTF-8') > 200) {
                        jsonResponse(false, '简介不能超过200个字符');
                    }
                }
                
                $updateFields[] = "{$field} = ?";
                $updateValues[] = $input[$field];
            }
        }
        
        if (empty($updateFields)) {
            jsonResponse(false, '没有需要更新的内容');
        }
        
        $updateValues[] = $currentUser['id'];
        
        try {
            $stmt = $pdo->prepare("UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?");
            $stmt->execute($updateValues);
            
            // 获取更新后的用户信息
            $stmt = $pdo->prepare("
                SELECT id, user_number, nickname, avatar, phone, gender, bio, status, last_login
                FROM users WHERE id = ?
            ");
            $stmt->execute([$currentUser['id']]);
            $updatedUser = $stmt->fetch();
            
            jsonResponse(true, '更新成功', $updatedUser);
            
        } catch (Exception $e) {
            jsonResponse(false, '更新失败: ' . $e->getMessage());
        }
        break;
        
    case 'update_password':
        // 修改密码
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $oldPassword = $input['old_password'] ?? '';
        $newPassword = $input['new_password'] ?? '';
        
        if (empty($oldPassword) || empty($newPassword)) {
            jsonResponse(false, '请填写完整信息');
        }
        
        if (!isValidPassword($newPassword)) {
            jsonResponse(false, '新密码长度应在6-20个字符之间');
        }
        
        $pdo = getDB();
        
        // 获取原密码
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$currentUser['id']]);
        $user = $stmt->fetch();
        
        if (!verifyPassword($oldPassword, $user['password'])) {
            jsonResponse(false, '原密码错误');
        }
        
        try {
            $newPasswordHash = encryptPassword($newPassword);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$newPasswordHash, $currentUser['id']]);
            
            jsonResponse(true, '密码修改成功');
            
        } catch (Exception $e) {
            jsonResponse(false, '密码修改失败: ' . $e->getMessage());
        }
        break;
        
    case 'upload_avatar':
        // 上传头像
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        if (!isset($_FILES['avatar'])) {
            jsonResponse(false, '请选择头像文件');
        }
        
        $result = uploadImage($_FILES['avatar'], 'avatars');
        
        if ($result['success']) {
            // 更新用户头像
            $pdo = getDB();
            $stmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
            $stmt->execute([$result['data']['url'], $currentUser['id']]);
            
            jsonResponse(true, '头像上传成功', [
                'url' => $result['data']['url']
            ]);
        } else {
            jsonResponse(false, $result['message']);
        }
        break;
        
    case 'search':
        // 搜索用户（按号码或昵称）
        $keyword = trim($_GET['keyword'] ?? '');
        
        if (empty($keyword)) {
            jsonResponse(false, '请输入搜索关键词');
        }
        
        $pdo = getDB();
        
        // 优先精确匹配号码
        $stmt = $pdo->prepare("
            SELECT id, user_number, nickname, avatar, gender, bio
            FROM users 
            WHERE user_number = ? AND status = 1
            LIMIT 1
        ");
        $stmt->execute([$keyword]);
        $user = $stmt->fetch();
        
        if ($user) {
            // 检查是否是好友
            $stmt = $pdo->prepare("
                SELECT status FROM friends 
                WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)
                LIMIT 1
            ");
            $stmt->execute([$currentUser['id'], $user['id'], $user['id'], $currentUser['id']]);
            $friend = $stmt->fetch();
            
            $user['friend_status'] = $friend ? $friend['status'] : -1; // -1: 不是好友
            
            jsonResponse(true, '查询成功', $user);
        }
        
        // 模糊匹配昵称
        $stmt = $pdo->prepare("
            SELECT id, user_number, nickname, avatar, gender, bio
            FROM users 
            WHERE nickname LIKE ? AND status = 1
            LIMIT 20
        ");
        $stmt->execute(['%' . $keyword . '%']);
        $users = $stmt->fetchAll();
        
        foreach ($users as &$u) {
            $stmt = $pdo->prepare("
                SELECT status FROM friends 
                WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)
                LIMIT 1
            ");
            $stmt->execute([$currentUser['id'], $u['id'], $u['id'], $currentUser['id']]);
            $friend = $stmt->fetch();
            $u['friend_status'] = $friend ? $friend['status'] : -1;
        }
        
        if (empty($users)) {
            jsonResponse(false, '未找到用户');
        }
        
        jsonResponse(true, '查询成功', $users);
        break;
        
    case 'info':
        // 获取指定用户信息
        $userId = intval($_GET['user_id'] ?? 0);
        
        if ($userId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        $pdo = getDB();
        $stmt = $pdo->prepare("
            SELECT id, user_number, nickname, avatar, gender, bio, status
            FROM users WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            jsonResponse(false, '用户不存在');
        }
        
        if ($user['status'] != 1) {
            jsonResponse(false, '用户已被禁用');
        }
        
        // 检查是否是好友
        $stmt = $pdo->prepare("
            SELECT status FROM friends 
            WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)
            LIMIT 1
        ");
        $stmt->execute([$currentUser['id'], $user['id'], $user['id'], $currentUser['id']]);
        $friend = $stmt->fetch();
        
        $user['friend_status'] = $friend ? $friend['status'] : -1;
        
        jsonResponse(true, '获取成功', $user);
        break;
        
    default:
        jsonResponse(false, '接口不存在', null, 404);
}
