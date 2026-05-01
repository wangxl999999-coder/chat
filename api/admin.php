<?php
require_once dirname(__DIR__) . '/includes/functions.php';

// 除了登录和检查登录状态，其他接口都需要管理员登录
$publicActions = ['login', 'check_login'];
if (!in_array($action, $publicActions) && !isAdminLoggedIn()) {
    jsonResponse(false, '请先登录');
}

switch ($action) {
    case 'login':
        // 管理员登录
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            jsonResponse(false, '请输入用户名和密码');
        }
        
        $pdo = getDB();
        
        // 查找管理员
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
        
        if (!$admin) {
            jsonResponse(false, '用户名或密码错误');
        }
        
        // 验证密码
        if (!verifyPassword($password, $admin['password'])) {
            jsonResponse(false, '用户名或密码错误');
        }
        
        // 登录成功
        loginAdmin($admin['id']);
        
        // 返回管理员信息（不包含密码）
        unset($admin['password']);
        
        jsonResponse(true, '登录成功', $admin);
        break;
        
    case 'logout':
        // 管理员退出登录
        logoutAdmin();
        jsonResponse(true, '已退出登录');
        break;
        
    case 'check_login':
        // 检查登录状态
        if (isAdminLoggedIn()) {
            $admin = getCurrentAdmin();
            if ($admin) {
                jsonResponse(true, '已登录', $admin);
            } else {
                logoutAdmin();
                jsonResponse(false, '登录状态已失效');
            }
        } else {
            jsonResponse(false, '未登录');
        }
        break;
        
    case 'dashboard':
        // 获取仪表盘数据
        $pdo = getDB();
        
        // 统计用户数
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM users");
        $totalUsers = $stmt->fetch()['total'];
        
        // 统计今日新增用户
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE DATE(created_at) = CURDATE()");
        $todayNewUsers = $stmt->fetch()['total'];
        
        // 统计消息数
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM messages");
        $totalMessages = $stmt->fetch()['total'];
        
        // 统计今日消息
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM messages WHERE DATE(created_at) = CURDATE()");
        $todayMessages = $stmt->fetch()['total'];
        
        // 统计会话数
        $stmt = $pdo->query("SELECT COUNT(DISTINCT user_id, target_id) as total FROM conversations");
        $totalConversations = $stmt->fetch()['total'] / 2; // 每个会话有两条记录
        
        // 统计好友关系数
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM friends WHERE status = 1");
        $totalFriends = $stmt->fetch()['total'] / 2; // 每条好友关系有两条记录
        
        jsonResponse(true, '获取成功', [
            'total_users' => intval($totalUsers),
            'today_new_users' => intval($todayNewUsers),
            'total_messages' => intval($totalMessages),
            'today_messages' => intval($todayMessages),
            'total_conversations' => intval($totalConversations),
            'total_friends' => intval($totalFriends)
        ]);
        break;
        
    case 'users':
        // 获取用户列表（支持分页、搜索、筛选）
        $pdo = getDB();
        
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $pageSize = isset($_GET['page_size']) ? min(100, max(1, intval($_GET['page_size']))) : 10;
        $keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
        $status = isset($_GET['status']) ? intval($_GET['status']) : -1;
        
        $offset = ($page - 1) * $pageSize;
        
        // 构建查询条件
        $whereConditions = [];
        $params = [];
        
        if (!empty($keyword)) {
            $whereConditions[] = "(user_number LIKE ? OR nickname LIKE ? OR phone LIKE ?)";
            $keywordParam = "%{$keyword}%";
            $params[] = $keywordParam;
            $params[] = $keywordParam;
            $params[] = $keywordParam;
        }
        
        if ($status >= 0) {
            $whereConditions[] = "status = ?";
            $params[] = $status;
        }
        
        $whereSql = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        
        // 统计总数
        $countSql = "SELECT COUNT(*) as total FROM users {$whereSql}";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $total = $stmt->fetch()['total'];
        
        // 查询用户列表
        $sql = "SELECT id, user_number, nickname, avatar, phone, gender, bio, status, last_login, created_at 
                FROM users {$whereSql} 
                ORDER BY created_at DESC 
                LIMIT {$offset}, {$pageSize}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll();
        
        jsonResponse(true, '获取成功', [
            'list' => $users,
            'users' => $users,
            'total' => intval($total),
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => ceil($total / $pageSize)
        ]);
        break;
        
    case 'user_detail':
        // 获取用户详情
        $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
        
        if ($userId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        $pdo = getDB();
        
        // 获取用户基本信息
        $stmt = $pdo->prepare("
            SELECT id, user_number, nickname, avatar, phone, gender, bio, status, last_login, created_at 
            FROM users WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            jsonResponse(false, '用户不存在');
        }
        
        // 获取用户统计信息
        // 好友数量
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM friends WHERE user_id = ? AND status = 1");
        $stmt->execute([$userId]);
        $friendCount = $stmt->fetch()['total'];
        
        // 发送的消息数
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM messages WHERE sender_id = ?");
        $stmt->execute([$userId]);
        $sentCount = $stmt->fetch()['total'];
        
        // 接收的消息数
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM messages WHERE receiver_id = ?");
        $stmt->execute([$userId]);
        $receivedCount = $stmt->fetch()['total'];
        
        // 最近登录时间
        $stmt = $pdo->prepare("
            SELECT id, sender_id, receiver_id, msg_type, content, is_read, created_at 
            FROM messages 
            WHERE (sender_id = ? OR receiver_id = ?) 
            ORDER BY created_at DESC 
            LIMIT 10
        ");
        $stmt->execute([$userId, $userId]);
        $recentMessages = $stmt->fetchAll();
        
        $user['friend_count'] = intval($friendCount);
        $user['sent_messages'] = intval($sentCount);
        $user['received_messages'] = intval($receivedCount);
        $user['recent_messages'] = $recentMessages;
        
        jsonResponse(true, '获取成功', $user);
        break;
        
    case 'update_user_status':
        // 更新用户状态（启用/禁用）
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $userId = intval($input['user_id'] ?? 0);
        $status = intval($input['status'] ?? 0);
        
        if ($userId <= 0 || !in_array($status, [0, 1])) {
            jsonResponse(false, '参数错误');
        }
        
        $pdo = getDB();
        
        // 检查用户是否存在
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        if ($stmt->rowCount() == 0) {
            jsonResponse(false, '用户不存在');
        }
        
        // 更新状态
        $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->execute([$status, $userId]);
        
        jsonResponse(true, $status == 1 ? '用户已启用' : '用户已禁用');
        break;
        
    case 'reset_user_password':
        // 重置用户密码（超级管理员权限）
        if (!isSuperAdmin()) {
            jsonResponse(false, '无权限执行此操作');
        }
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $userId = intval($input['user_id'] ?? 0);
        $newPassword = $input['new_password'] ?? '';
        
        if ($userId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        if (empty($newPassword) || !isValidPassword($newPassword)) {
            jsonResponse(false, '密码长度应在6-20个字符之间');
        }
        
        $pdo = getDB();
        
        // 检查用户是否存在
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        if ($stmt->rowCount() == 0) {
            jsonResponse(false, '用户不存在');
        }
        
        // 重置密码
        $passwordHash = encryptPassword($newPassword);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$passwordHash, $userId]);
        
        jsonResponse(true, '密码已重置');
        break;
        
    case 'admins':
        // 获取管理员列表（仅超级管理员）
        if (!isSuperAdmin()) {
            jsonResponse(false, '无权限查看管理员列表');
        }
        
        $pdo = getDB();
        
        $stmt = $pdo->query("
            SELECT id, username, role, last_login, created_at 
            FROM admins 
            ORDER BY created_at DESC
        ");
        $admins = $stmt->fetchAll();
        
        // 角色转换为文字描述
        foreach ($admins as &$admin) {
            $admin['role_text'] = $admin['role'] == 2 ? '超级管理员' : '普通管理员';
        }
        
        // 前端期望 res.data 直接是数组，同时为了兼容性保留对象格式的信息
        // 使用数组作为主返回，附加信息在数组中
        jsonResponse(true, '获取成功', $admins);
        break;
        
    case 'add_admin':
        // 添加管理员（仅超级管理员）
        if (!isSuperAdmin()) {
            jsonResponse(false, '无权限执行此操作');
        }
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        $role = intval($input['role'] ?? 1);
        
        if (empty($username) || mb_strlen($username) < 2 || mb_strlen($username) > 20) {
            jsonResponse(false, '用户名长度应在2-20个字符之间');
        }
        
        if (empty($password) || !isValidPassword($password)) {
            jsonResponse(false, '密码长度应在6-20个字符之间');
        }
        
        if (!in_array($role, [1, 2])) {
            jsonResponse(false, '角色参数错误');
        }
        
        $pdo = getDB();
        
        // 检查用户名是否已存在
        $stmt = $pdo->prepare("SELECT id FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->rowCount() > 0) {
            jsonResponse(false, '用户名已存在');
        }
        
        // 插入新管理员
        $passwordHash = encryptPassword($password);
        $stmt = $pdo->prepare("INSERT INTO admins (username, password, role) VALUES (?, ?, ?)");
        $stmt->execute([$username, $passwordHash, $role]);
        
        $newAdminId = $pdo->lastInsertId();
        
        jsonResponse(true, '管理员添加成功', [
            'id' => $newAdminId,
            'username' => $username,
            'role' => $role
        ]);
        break;
        
    case 'delete_admin':
        // 删除管理员（仅超级管理员，且不能删除自己）
        if (!isSuperAdmin()) {
            jsonResponse(false, '无权限执行此操作');
        }
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $adminId = intval($input['admin_id'] ?? 0);
        
        if ($adminId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        // 不能删除自己
        $currentAdmin = getCurrentAdmin();
        if ($adminId == $currentAdmin['id']) {
            jsonResponse(false, '不能删除自己的账号');
        }
        
        $pdo = getDB();
        
        // 检查管理员是否存在
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
        $stmt->execute([$adminId]);
        $admin = $stmt->fetch();
        
        if (!$admin) {
            jsonResponse(false, '管理员不存在');
        }
        
        // 删除管理员
        $stmt = $pdo->prepare("DELETE FROM admins WHERE id = ?");
        $stmt->execute([$adminId]);
        
        jsonResponse(true, '管理员已删除');
        break;
        
    default:
        jsonResponse(false, '接口不存在', null, 404);
}
