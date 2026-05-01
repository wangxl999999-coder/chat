<?php
require_once dirname(__DIR__) . '/includes/functions.php';

$currentUser = getCurrentUser();
if (!$currentUser) {
    jsonResponse(false, '请先登录');
}

$pdo = getDB();

switch ($action) {
    case 'list':
        // 获取收藏列表
        $page = intval($_GET['page'] ?? 1);
        $pageSize = intval($_GET['page_size'] ?? 20);
        $offset = ($page - 1) * $pageSize;
        
        // 获取总数
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total FROM favorites WHERE user_id = ?
        ");
        $stmt->execute([$currentUser['id']]);
        $total = $stmt->fetch()['total'];
        
        // 获取收藏列表 - 关联消息和发送者信息
        $stmt = $pdo->prepare("
            SELECT f.id, f.msg_id, f.msg_type, f.content, f.sender_id, f.sender_nickname, f.created_at,
                   m.msg_type as msg_type_from_msg, m.content as message_content, m.created_at as msg_time,
                   u.nickname as sender_nickname_from_user, u.avatar as sender_avatar
            FROM favorites f
            LEFT JOIN messages m ON f.msg_id = m.id
            LEFT JOIN users u ON f.sender_id = u.id
            WHERE f.user_id = ?
            ORDER BY f.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$currentUser['id'], $pageSize, $offset]);
        $favorites = $stmt->fetchAll();
        
        // 处理收藏内容
        foreach ($favorites as &$favorite) {
            // 使用收藏时保存的内容，如果消息还存在且未焚毁，则使用最新消息内容
            $favorite['msg_type'] = $favorite['msg_type'] ?? $favorite['msg_type_from_msg'] ?? 1;
            
            if (!empty($favorite['message_content']) && $favorite['message_content'] != '该消息已焚毁') {
                $favorite['display_content'] = $favorite['message_content'];
                $favorite['msg_time'] = $favorite['msg_time'];
            } else {
                // 使用收藏时保存的内容
                $favorite['display_content'] = $favorite['content'] ?? '该消息已删除或焚毁';
                $favorite['msg_time'] = $favorite['created_at'];
            }
            
            // 使用收藏时保存的发送者信息
            if (empty($favorite['sender_nickname'])) {
                $favorite['sender_nickname'] = $favorite['sender_nickname_from_user'] ?? '未知用户';
            }
        }
        
        jsonResponse(true, '获取成功', [
            'list' => $favorites,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize
        ]);
        break;
        
    case 'add':
        // 添加收藏
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $msgId = intval($input['msg_id'] ?? 0);
        
        if ($msgId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        // 检查消息是否存在
        $stmt = $pdo->prepare("
            SELECT m.*, 
                   (SELECT f.id FROM favorites f WHERE f.user_id = ? AND f.msg_id = m.id) as favorite_id
            FROM messages m
            WHERE m.id = ?
        ");
        $stmt->execute([$currentUser['id'], $msgId]);
        $message = $stmt->fetch();
        
        if (!$message) {
            jsonResponse(false, '消息不存在');
        }
        
        // 检查是否已经收藏
        if ($message['favorite_id']) {
            jsonResponse(false, '已经收藏过了');
        }
        
        // 检查消息权限（确保是相关的消息）
        // 可以是单聊消息（发送者或接收者）或群聊消息（群成员）
        $hasAccess = false;
        
        if (!empty($message['group_id'])) {
            // 群聊消息 - 检查是否是群成员
            $stmt = $pdo->prepare("
                SELECT * FROM group_members 
                WHERE group_id = ? AND user_id = ?
            ");
            $stmt->execute([$message['group_id'], $currentUser['id']]);
            $hasAccess = ($stmt->rowCount() > 0);
        } else {
            // 单聊消息 - 检查是否是发送者或接收者
            $hasAccess = ($message['sender_id'] == $currentUser['id'] || $message['receiver_id'] == $currentUser['id']);
        }
        
        if (!$hasAccess) {
            jsonResponse(false, '无权收藏此消息');
        }
        
        // 添加收藏
        try {
            // 获取发送者昵称
            $stmt = $pdo->prepare("SELECT nickname, avatar FROM users WHERE id = ?");
            $stmt->execute([$message['sender_id']]);
            $sender = $stmt->fetch();
            $senderNickname = $sender ? $sender['nickname'] : '';
            
            $stmt = $pdo->prepare("
                INSERT INTO favorites (user_id, msg_id, msg_type, content, sender_id, sender_nickname)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $currentUser['id'], 
                $msgId, 
                $message['msg_type'],
                $message['content'],
                $message['sender_id'],
                $senderNickname
            ]);
            $favoriteId = $pdo->lastInsertId();
            
            jsonResponse(true, '收藏成功', [
                'favorite_id' => $favoriteId
            ]);
            
        } catch (Exception $e) {
            jsonResponse(false, '收藏失败: ' . $e->getMessage());
        }
        break;
        
    case 'remove':
        // 取消收藏
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $favoriteId = intval($input['favorite_id'] ?? 0);
        $msgId = intval($input['msg_id'] ?? 0);
        
        if ($favoriteId <= 0 && $msgId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        $deleteCondition = '';
        $deleteParams = [$currentUser['id']];
        
        if ($favoriteId > 0) {
            $deleteCondition = 'id = ?';
            $deleteParams[] = $favoriteId;
        } else {
            $deleteCondition = 'msg_id = ?';
            $deleteParams[] = $msgId;
        }
        
        try {
            $stmt = $pdo->prepare("
                DELETE FROM favorites 
                WHERE user_id = ? AND {$deleteCondition}
            ");
            $stmt->execute($deleteParams);
            
            if ($stmt->rowCount() > 0) {
                jsonResponse(true, '已取消收藏');
            } else {
                jsonResponse(false, '收藏不存在');
            }
            
        } catch (Exception $e) {
            jsonResponse(false, '操作失败: ' . $e->getMessage());
        }
        break;
        
    case 'check':
        // 检查消息是否已收藏
        $msgId = intval($_GET['msg_id'] ?? 0);
        
        if ($msgId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        $stmt = $pdo->prepare("
            SELECT id, created_at FROM favorites 
            WHERE user_id = ? AND msg_id = ?
        ");
        $stmt->execute([$currentUser['id'], $msgId]);
        $favorite = $stmt->fetch();
        
        jsonResponse(true, '获取成功', [
            'is_favorited' => ($favorite !== false),
            'favorite_id' => $favorite ? $favorite['id'] : null,
            'favorited_at' => $favorite ? $favorite['created_at'] : null
        ]);
        break;
        
    case 'batch_remove':
        // 批量取消收藏
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $favoriteIds = $input['favorite_ids'] ?? [];
        
        if (!is_array($favoriteIds) || empty($favoriteIds)) {
            jsonResponse(false, '参数错误');
        }
        
        // 过滤和处理ID
        $favoriteIds = array_map('intval', array_filter($favoriteIds, function($id) {
            return intval($id) > 0;
        }));
        
        if (empty($favoriteIds)) {
            jsonResponse(false, '没有有效的收藏ID');
        }
        
        try {
            $placeholders = implode(',', array_fill(0, count($favoriteIds), '?'));
            
            $stmt = $pdo->prepare("
                DELETE FROM favorites 
                WHERE user_id = ? AND id IN ({$placeholders})
            ");
            $params = array_merge([$currentUser['id']], $favoriteIds);
            $stmt->execute($params);
            
            $deletedCount = $stmt->rowCount();
            
            jsonResponse(true, '批量取消成功', [
                'deleted_count' => $deletedCount
            ]);
            
        } catch (Exception $e) {
            jsonResponse(false, '操作失败: ' . $e->getMessage());
        }
        break;
        
    default:
        jsonResponse(false, '接口不存在', null, 404);
}
