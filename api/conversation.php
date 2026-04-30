<?php
require_once dirname(__DIR__) . '/includes/functions.php';

$currentUser = getCurrentUser();
if (!$currentUser) {
    jsonResponse(false, '请先登录');
}

$pdo = getDB();

switch ($action) {
    case 'list':
        // 获取会话列表
        $stmt = $pdo->prepare("
            SELECT c.id, c.user_id, c.target_id, c.last_message, c.last_message_time, 
                   c.unread_count, c.created_at, c.updated_at,
                   u.user_number, u.nickname, u.avatar, u.gender, u.status
            FROM conversations c
            LEFT JOIN users u ON c.target_id = u.id
            WHERE c.user_id = ?
            ORDER BY c.last_message_time DESC
        ");
        $stmt->execute([$currentUser['id']]);
        $conversations = $stmt->fetchAll();
        
        // 统计总未读数
        $totalUnread = 0;
        foreach ($conversations as $conv) {
            $totalUnread += intval($conv['unread_count']);
        }
        
        jsonResponse(true, '获取成功', [
            'total_unread' => $totalUnread,
            'conversations' => $conversations
        ]);
        break;
        
    case 'delete':
        // 删除会话
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $convId = intval($input['conversation_id'] ?? 0);
        
        if ($convId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        try {
            $pdo->beginTransaction();
            
            // 检查会话是否属于当前用户
            $stmt = $pdo->prepare("
                SELECT id FROM conversations WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$convId, $currentUser['id']]);
            
            if ($stmt->rowCount() == 0) {
                jsonResponse(false, '会话不存在');
            }
            
            // 删除会话（消息不删除，保留在数据库中）
            $stmt = $pdo->prepare("DELETE FROM conversations WHERE id = ?");
            $stmt->execute([$convId]);
            
            $pdo->commit();
            
            jsonResponse(true, '会话已删除');
            
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(false, '删除失败: ' . $e->getMessage());
        }
        break;
        
    case 'clear_unread':
        // 清空会话未读数
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $convId = intval($input['conversation_id'] ?? 0);
        
        if ($convId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        $stmt = $pdo->prepare("
            UPDATE conversations 
            SET unread_count = 0 
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$convId, $currentUser['id']]);
        
        jsonResponse(true, '已清空未读数');
        break;
        
    default:
        jsonResponse(false, '接口不存在', null, 404);
}
