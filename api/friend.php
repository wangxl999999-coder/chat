<?php
require_once dirname(__DIR__) . '/includes/functions.php';

$currentUser = getCurrentUser();
if (!$currentUser) {
    jsonResponse(false, '请先登录');
}

$pdo = getDB();

switch ($action) {
    case 'list':
        // 获取好友列表
        $stmt = $pdo->prepare("
            SELECT f.id, f.user_id, f.friend_id, f.status, f.created_at,
                   u.user_number, u.nickname, u.avatar, u.gender, u.bio
            FROM friends f
            LEFT JOIN users u ON f.friend_id = u.id
            WHERE f.user_id = ? AND f.status = 1
            ORDER BY u.nickname ASC
        ");
        $stmt->execute([$currentUser['id']]);
        $friends = $stmt->fetchAll();
        
        jsonResponse(true, '获取成功', $friends);
        break;
        
    case 'requests':
        // 获取好友申请列表（待确认的）
        $type = isset($_GET['type']) ? $_GET['type'] : 'received'; // received/sent
        
        if ($type === 'sent') {
            // 我发送的申请
            $stmt = $pdo->prepare("
                SELECT f.id, f.user_id, f.friend_id, f.status, f.created_at,
                       u.user_number, u.nickname, u.avatar, u.gender
                FROM friends f
                LEFT JOIN users u ON f.friend_id = u.id
                WHERE f.user_id = ? AND f.status = 0
                ORDER BY f.created_at DESC
            ");
            $stmt->execute([$currentUser['id']]);
        } else {
            // 我收到的申请
            $stmt = $pdo->prepare("
                SELECT f.id, f.user_id, f.friend_id, f.status, f.created_at,
                       u.user_number, u.nickname, u.avatar, u.gender
                FROM friends f
                LEFT JOIN users u ON f.user_id = u.id
                WHERE f.friend_id = ? AND f.status = 0
                ORDER BY f.created_at DESC
            ");
            $stmt->execute([$currentUser['id']]);
        }
        
        $requests = $stmt->fetchAll();
        jsonResponse(true, '获取成功', $requests);
        break;
        
    case 'add':
        // 发送好友申请
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $friendId = intval($input['friend_id'] ?? 0);
        
        if ($friendId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        if ($friendId === $currentUser['id']) {
            jsonResponse(false, '不能添加自己为好友');
        }
        
        // 检查目标用户是否存在
        $stmt = $pdo->prepare("SELECT id, status FROM users WHERE id = ?");
        $stmt->execute([$friendId]);
        $targetUser = $stmt->fetch();
        
        if (!$targetUser) {
            jsonResponse(false, '用户不存在');
        }
        
        if ($targetUser['status'] != 1) {
            jsonResponse(false, '用户已被禁用');
        }
        
        try {
            $pdo->beginTransaction();
            
            // 检查是否已经是好友或已发送申请
            $stmt = $pdo->prepare("
                SELECT status FROM friends 
                WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)
                LIMIT 1
            ");
            $stmt->execute([$currentUser['id'], $friendId, $friendId, $currentUser['id']]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                if ($existing['status'] == 1) {
                    jsonResponse(false, '你们已经是好友了');
                } elseif ($existing['status'] == 0) {
                    jsonResponse(false, '已发送过好友申请，等待对方确认');
                }
            }
            
            // 插入好友申请（两条记录，便于双方查询）
            // 发送者侧：状态0（待确认）
            $stmt = $pdo->prepare("
                INSERT INTO friends (user_id, friend_id, status) VALUES (?, ?, 0)
            ");
            $stmt->execute([$currentUser['id'], $friendId]);
            
            // 接收者侧：状态0（待确认）
            $stmt = $pdo->prepare("
                INSERT INTO friends (user_id, friend_id, status) VALUES (?, ?, 0)
            ");
            $stmt->execute([$friendId, $currentUser['id']]);
            
            $pdo->commit();
            
            jsonResponse(true, '好友申请已发送');
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            jsonResponse(false, '发送失败: ' . $e->getMessage());
        }
        break;
        
    case 'accept':
        // 接受好友申请
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $friendId = intval($input['friend_id'] ?? 0);
        
        if ($friendId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        try {
            $pdo->beginTransaction();
            
            // 更新双方状态为已同意
            $stmt = $pdo->prepare("
                UPDATE friends SET status = 1 
                WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)
            ");
            $stmt->execute([$currentUser['id'], $friendId, $friendId, $currentUser['id']]);
            
            if ($stmt->rowCount() == 0) {
                $pdo->rollBack();
                jsonResponse(false, '好友申请不存在');
            }
            
            $pdo->commit();
            
            jsonResponse(true, '已同意好友申请');
            
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(false, '操作失败: ' . $e->getMessage());
        }
        break;
        
    case 'reject':
        // 拒绝好友申请
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $friendId = intval($input['friend_id'] ?? 0);
        
        if ($friendId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        try {
            $pdo->beginTransaction();
            
            // 删除双方的好友申请记录
            $stmt = $pdo->prepare("
                DELETE FROM friends 
                WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)
            ");
            $stmt->execute([$currentUser['id'], $friendId, $friendId, $currentUser['id']]);
            
            if ($stmt->rowCount() == 0) {
                $pdo->rollBack();
                jsonResponse(false, '好友申请不存在');
            }
            
            $pdo->commit();
            
            jsonResponse(true, '已拒绝好友申请');
            
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(false, '操作失败: ' . $e->getMessage());
        }
        break;
        
    case 'delete':
        // 删除好友
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $friendId = intval($input['friend_id'] ?? 0);
        
        if ($friendId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        try {
            $pdo->beginTransaction();
            
            // 删除双方的好友关系
            $stmt = $pdo->prepare("
                DELETE FROM friends 
                WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)
            ");
            $stmt->execute([$currentUser['id'], $friendId, $friendId, $currentUser['id']]);
            
            // 删除会话记录（可选）
            
            $pdo->commit();
            
            jsonResponse(true, '已删除好友');
            
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(false, '操作失败: ' . $e->getMessage());
        }
        break;
        
    default:
        jsonResponse(false, '接口不存在', null, 404);
}
