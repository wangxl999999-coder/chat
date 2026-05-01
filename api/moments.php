<?php
require_once dirname(__DIR__) . '/includes/functions.php';

$currentUser = getCurrentUser();
if (!$currentUser) {
    jsonResponse(false, '请先登录');
}

$pdo = getDB();

switch ($action) {
    case 'list':
        // 获取朋友圈列表（自己和好友的公开朋友圈，按时间倒序）
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $pageSize = isset($_GET['page_size']) ? min(50, max(1, intval($_GET['page_size']))) : 20;
        $offset = ($page - 1) * $pageSize;
        
        // 获取好友ID列表
        $stmt = $pdo->prepare("
            SELECT friend_id FROM friends 
            WHERE user_id = ? AND status = 1
        ");
        $stmt->execute([$currentUser['id']]);
        $friends = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        
        // 构建查询条件：
        // 1. 自己的所有朋友圈（包括私有）
        // 2. 好友的公开朋友圈（is_private = 0）
        if (empty($friends)) {
            // 没有好友，只查自己的所有朋友圈
            $whereCondition = "m.user_id = ?";
            $params = [$currentUser['id']];
        } else {
            $friendPlaceholders = implode(',', array_fill(0, count($friends), '?'));
            $whereCondition = "m.user_id = ? OR (m.is_private = 0 AND m.user_id IN ($friendPlaceholders))";
            $params = array_merge([$currentUser['id']], $friends);
        }
        
        // 查询总数
        $countStmt = $pdo->prepare("
            SELECT COUNT(*) FROM moments m
            WHERE $whereCondition
        ");
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();
        
        // 查询数据（添加is_liked参数到查询开头）
        $stmt = $pdo->prepare("
            SELECT m.*, 
                   u.nickname, u.avatar, u.user_number,
                   (SELECT COUNT(*) FROM likes WHERE moment_id = m.id) as like_count,
                   (SELECT COUNT(*) FROM likes WHERE moment_id = m.id AND user_id = ?) as is_liked,
                   (SELECT COUNT(*) FROM comments WHERE moment_id = m.id) as comment_count
            FROM moments m
            LEFT JOIN users u ON m.user_id = u.id
            WHERE $whereCondition
            ORDER BY m.created_at DESC
            LIMIT $offset, $pageSize
        ");
        
        // 添加当前用户ID到参数开头（用于is_liked子查询）
        $queryParams = array_merge([$currentUser['id']], $params);
        $stmt->execute($queryParams);
        $moments = $stmt->fetchAll();
        
        // 处理图片和获取点赞、评论详情
        foreach ($moments as &$moment) {
            $moment['images'] = $moment['images'] ? json_decode($moment['images'], true) : [];
            
            // 获取点赞用户
            $likeStmt = $pdo->prepare("
                SELECT l.user_id, u.nickname 
                FROM likes l 
                LEFT JOIN users u ON l.user_id = u.id 
                WHERE l.moment_id = ?
                ORDER BY l.created_at ASC
            ");
            $likeStmt->execute([$moment['id']]);
            $moment['likes'] = $likeStmt->fetchAll();
            
            // 获取评论（包含回复）
            $commentStmt = $pdo->prepare("
                SELECT c.*, 
                       u.nickname as user_nickname, u.avatar as user_avatar,
                       ru.nickname as reply_to_nickname
                FROM comments c
                LEFT JOIN users u ON c.user_id = u.id
                LEFT JOIN users ru ON c.reply_to_user_id = ru.id
                WHERE c.moment_id = ?
                ORDER BY c.created_at ASC
            ");
            $commentStmt->execute([$moment['id']]);
            $moment['comments'] = $commentStmt->fetchAll();
        }
        
        jsonResponse(true, '获取成功', [
            'list' => $moments,
            'total' => intval($total),
            'page' => $page,
            'page_size' => $pageSize
        ]);
        break;
        
    case 'my':
        // 获取自己的朋友圈
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $pageSize = isset($_GET['page_size']) ? min(50, max(1, intval($_GET['page_size']))) : 20;
        $offset = ($page - 1) * $pageSize;
        
        // 查询总数
        $countStmt = $pdo->prepare("
            SELECT COUNT(*) FROM moments WHERE user_id = ?
        ");
        $countStmt->execute([$currentUser['id']]);
        $total = $countStmt->fetchColumn();
        
        // 查询数据
        $stmt = $pdo->prepare("
            SELECT m.*, 
                   u.nickname, u.avatar, u.user_number,
                   (SELECT COUNT(*) FROM likes WHERE moment_id = m.id) as like_count,
                   (SELECT COUNT(*) FROM likes WHERE moment_id = m.id AND user_id = ?) as is_liked,
                   (SELECT COUNT(*) FROM comments WHERE moment_id = m.id) as comment_count
            FROM moments m
            LEFT JOIN users u ON m.user_id = u.id
            WHERE m.user_id = ?
            ORDER BY m.created_at DESC
            LIMIT $offset, $pageSize
        ");
        $stmt->execute([$currentUser['id'], $currentUser['id']]);
        $moments = $stmt->fetchAll();
        
        // 处理图片和获取点赞、评论详情
        foreach ($moments as &$moment) {
            $moment['images'] = $moment['images'] ? json_decode($moment['images'], true) : [];
            
            // 获取点赞用户
            $likeStmt = $pdo->prepare("
                SELECT l.user_id, u.nickname 
                FROM likes l 
                LEFT JOIN users u ON l.user_id = u.id 
                WHERE l.moment_id = ?
                ORDER BY l.created_at ASC
            ");
            $likeStmt->execute([$moment['id']]);
            $moment['likes'] = $likeStmt->fetchAll();
            
            // 获取评论（包含回复）
            $commentStmt = $pdo->prepare("
                SELECT c.*, 
                       u.nickname as user_nickname, u.avatar as user_avatar,
                       ru.nickname as reply_to_nickname
                FROM comments c
                LEFT JOIN users u ON c.user_id = u.id
                LEFT JOIN users ru ON c.reply_to_user_id = ru.id
                WHERE c.moment_id = ?
                ORDER BY c.created_at ASC
            ");
            $commentStmt->execute([$moment['id']]);
            $moment['comments'] = $commentStmt->fetchAll();
        }
        
        jsonResponse(true, '获取成功', [
            'list' => $moments,
            'total' => intval($total),
            'page' => $page,
            'page_size' => $pageSize
        ]);
        break;
        
    case 'publish':
        // 发布朋友圈
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $content = trim($input['content'] ?? '');
        $images = $input['images'] ?? [];
        $isPrivate = isset($input['is_private']) ? intval($input['is_private']) : 0;
        
        if (empty($content) && empty($images)) {
            jsonResponse(false, '请输入内容或上传图片');
        }
        
        // 验证图片数组
        if (!is_array($images)) {
            $images = [];
        }
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO moments (user_id, content, images, is_private) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $currentUser['id'],
                $content,
                json_encode($images, JSON_UNESCAPED_UNICODE),
                $isPrivate
            ]);
            
            $momentId = $pdo->lastInsertId();
            
            // 返回完整的朋友圈数据
            $stmt = $pdo->prepare("
                SELECT m.*, 
                       u.nickname, u.avatar, u.user_number,
                       0 as like_count, 0 as is_liked, 0 as comment_count
                FROM moments m
                LEFT JOIN users u ON m.user_id = u.id
                WHERE m.id = ?
            ");
            $stmt->execute([$momentId]);
            $moment = $stmt->fetch();
            $moment['images'] = $moment['images'] ? json_decode($moment['images'], true) : [];
            $moment['likes'] = [];
            $moment['comments'] = [];
            
            jsonResponse(true, '发布成功', $moment);
            
        } catch (Exception $e) {
            jsonResponse(false, '发布失败: ' . $e->getMessage());
        }
        break;
        
    case 'delete':
        // 删除朋友圈
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $momentId = intval($input['moment_id'] ?? 0);
        
        if ($momentId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        // 检查是否是自己的朋友圈
        $stmt = $pdo->prepare("SELECT id, user_id FROM moments WHERE id = ?");
        $stmt->execute([$momentId]);
        $moment = $stmt->fetch();
        
        if (!$moment) {
            jsonResponse(false, '朋友圈不存在');
        }
        
        if ($moment['user_id'] != $currentUser['id']) {
            jsonResponse(false, '只能删除自己的朋友圈');
        }
        
        try {
            $pdo->beginTransaction();
            
            // 删除点赞
            $stmt = $pdo->prepare("DELETE FROM likes WHERE moment_id = ?");
            $stmt->execute([$momentId]);
            
            // 删除评论
            $stmt = $pdo->prepare("DELETE FROM comments WHERE moment_id = ?");
            $stmt->execute([$momentId]);
            
            // 删除朋友圈
            $stmt = $pdo->prepare("DELETE FROM moments WHERE id = ?");
            $stmt->execute([$momentId]);
            
            $pdo->commit();
            
            jsonResponse(true, '删除成功');
            
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(false, '删除失败: ' . $e->getMessage());
        }
        break;
        
    case 'like':
        // 点赞
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $momentId = intval($input['moment_id'] ?? 0);
        
        if ($momentId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        // 检查朋友圈是否存在且有权限查看
        $stmt = $pdo->prepare("
            SELECT m.*, u.nickname 
            FROM moments m 
            LEFT JOIN users u ON m.user_id = u.id
            WHERE m.id = ?
        ");
        $stmt->execute([$momentId]);
        $moment = $stmt->fetch();
        
        if (!$moment) {
            jsonResponse(false, '朋友圈不存在');
        }
        
        // 检查是否有权限查看（自己或好友的公开朋友圈）
        if ($moment['user_id'] != $currentUser['id']) {
            if ($moment['is_private'] == 1) {
                jsonResponse(false, '无法查看私有朋友圈');
            }
            
            // 检查是否是好友
            $stmt = $pdo->prepare("
                SELECT status FROM friends 
                WHERE user_id = ? AND friend_id = ? AND status = 1
                LIMIT 1
            ");
            $stmt->execute([$currentUser['id'], $moment['user_id']]);
            if (!$stmt->fetch()) {
                jsonResponse(false, '只能查看好友的朋友圈');
            }
        }
        
        try {
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO likes (moment_id, user_id) VALUES (?, ?)
            ");
            $stmt->execute([$momentId, $currentUser['id']]);
            
            if ($stmt->rowCount() > 0) {
                jsonResponse(true, '点赞成功', [
                    'user_id' => $currentUser['id'],
                    'nickname' => $currentUser['nickname']
                ]);
            } else {
                jsonResponse(false, '已经点赞过了');
            }
            
        } catch (Exception $e) {
            jsonResponse(false, '点赞失败: ' . $e->getMessage());
        }
        break;
        
    case 'unlike':
        // 取消点赞
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $momentId = intval($input['moment_id'] ?? 0);
        
        if ($momentId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        try {
            $stmt = $pdo->prepare("
                DELETE FROM likes WHERE moment_id = ? AND user_id = ?
            ");
            $stmt->execute([$momentId, $currentUser['id']]);
            
            if ($stmt->rowCount() > 0) {
                jsonResponse(true, '已取消点赞');
            } else {
                jsonResponse(false, '尚未点赞');
            }
            
        } catch (Exception $e) {
            jsonResponse(false, '操作失败: ' . $e->getMessage());
        }
        break;
        
    case 'comment':
        // 评论或回复
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $momentId = intval($input['moment_id'] ?? 0);
        $content = trim($input['content'] ?? '');
        $replyToId = isset($input['reply_to_id']) ? intval($input['reply_to_id']) : null;
        $replyToUserId = isset($input['reply_to_user_id']) ? intval($input['reply_to_user_id']) : null;
        
        if ($momentId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        if (empty($content)) {
            jsonResponse(false, '请输入评论内容');
        }
        
        if (mb_strlen($content, 'UTF-8') > 500) {
            jsonResponse(false, '评论内容不能超过500个字符');
        }
        
        // 检查朋友圈是否存在且有权限查看
        $stmt = $pdo->prepare("
            SELECT m.*, u.nickname 
            FROM moments m 
            LEFT JOIN users u ON m.user_id = u.id
            WHERE m.id = ?
        ");
        $stmt->execute([$momentId]);
        $moment = $stmt->fetch();
        
        if (!$moment) {
            jsonResponse(false, '朋友圈不存在');
        }
        
        // 检查是否有权限查看
        if ($moment['user_id'] != $currentUser['id']) {
            if ($moment['is_private'] == 1) {
                jsonResponse(false, '无法查看私有朋友圈');
            }
            
            // 检查是否是好友
            $stmt = $pdo->prepare("
                SELECT status FROM friends 
                WHERE user_id = ? AND friend_id = ? AND status = 1
                LIMIT 1
            ");
            $stmt->execute([$currentUser['id'], $moment['user_id']]);
            if (!$stmt->fetch()) {
                jsonResponse(false, '只能评论好友的朋友圈');
            }
        }
        
        // 如果是回复，检查被回复的评论是否存在
        if ($replyToId) {
            $stmt = $pdo->prepare("
                SELECT c.user_id, u.nickname as reply_to_nickname
                FROM comments c
                LEFT JOIN users u ON c.user_id = u.id
                WHERE c.id = ? AND c.moment_id = ?
            ");
            $stmt->execute([$replyToId, $momentId]);
            $replyComment = $stmt->fetch();
            
            if (!$replyComment) {
                jsonResponse(false, '被回复的评论不存在');
            }
            
            $replyToUserId = $replyComment['user_id'];
        }
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO comments (moment_id, user_id, reply_to_id, reply_to_user_id, content) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $momentId,
                $currentUser['id'],
                $replyToId ?: null,
                $replyToUserId ?: null,
                $content
            ]);
            
            $commentId = $pdo->lastInsertId();
            
            // 返回完整的评论数据
            $stmt = $pdo->prepare("
                SELECT c.*, 
                       u.nickname as user_nickname, u.avatar as user_avatar,
                       ru.nickname as reply_to_nickname
                FROM comments c
                LEFT JOIN users u ON c.user_id = u.id
                LEFT JOIN users ru ON c.reply_to_user_id = ru.id
                WHERE c.id = ?
            ");
            $stmt->execute([$commentId]);
            $comment = $stmt->fetch();
            
            jsonResponse(true, '评论成功', $comment);
            
        } catch (Exception $e) {
            jsonResponse(false, '评论失败: ' . $e->getMessage());
        }
        break;
        
    case 'delete_comment':
        // 删除评论
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $commentId = intval($input['comment_id'] ?? 0);
        
        if ($commentId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        // 检查评论是否存在
        $stmt = $pdo->prepare("
            SELECT c.*, m.user_id as moment_owner_id
            FROM comments c
            LEFT JOIN moments m ON c.moment_id = m.id
            WHERE c.id = ?
        ");
        $stmt->execute([$commentId]);
        $comment = $stmt->fetch();
        
        if (!$comment) {
            jsonResponse(false, '评论不存在');
        }
        
        // 只能删除自己的评论或自己朋友圈下的评论
        if ($comment['user_id'] != $currentUser['id'] && $comment['moment_owner_id'] != $currentUser['id']) {
            jsonResponse(false, '只能删除自己的评论或自己朋友圈下的评论');
        }
        
        try {
            // 删除评论及其回复
            $pdo->beginTransaction();
            
            // 先删除所有回复
            $stmt = $pdo->prepare("
                DELETE FROM comments WHERE reply_to_id = ?
            ");
            $stmt->execute([$commentId]);
            
            // 再删除该评论
            $stmt = $pdo->prepare("
                DELETE FROM comments WHERE id = ?
            ");
            $stmt->execute([$commentId]);
            
            $pdo->commit();
            
            jsonResponse(true, '删除成功');
            
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(false, '删除失败: ' . $e->getMessage());
        }
        break;
        
    case 'upload_image':
        // 上传朋友圈图片
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        if (!isset($_FILES['image'])) {
            jsonResponse(false, '请选择图片文件');
        }
        
        $result = uploadImage($_FILES['image'], 'moments');
        
        if ($result['success']) {
            jsonResponse(true, '上传成功', [
                'url' => $result['data']['url']
            ]);
        } else {
            jsonResponse(false, $result['message']);
        }
        break;
        
    case 'detail':
        // 获取单条朋友圈详情
        $momentId = intval($_GET['moment_id'] ?? 0);
        
        if ($momentId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        // 检查朋友圈是否存在且有权限查看
        $stmt = $pdo->prepare("
            SELECT m.*, 
                   u.nickname, u.avatar, u.user_number,
                   (SELECT COUNT(*) FROM likes WHERE moment_id = m.id) as like_count,
                   (SELECT COUNT(*) FROM likes WHERE moment_id = m.id AND user_id = ?) as is_liked,
                   (SELECT COUNT(*) FROM comments WHERE moment_id = m.id) as comment_count
            FROM moments m
            LEFT JOIN users u ON m.user_id = u.id
            WHERE m.id = ?
        ");
        $stmt->execute([$currentUser['id'], $momentId]);
        $moment = $stmt->fetch();
        
        if (!$moment) {
            jsonResponse(false, '朋友圈不存在');
        }
        
        // 检查是否有权限查看
        if ($moment['user_id'] != $currentUser['id']) {
            if ($moment['is_private'] == 1) {
                jsonResponse(false, '无法查看私有朋友圈');
            }
            
            // 检查是否是好友
            $stmt = $pdo->prepare("
                SELECT status FROM friends 
                WHERE user_id = ? AND friend_id = ? AND status = 1
                LIMIT 1
            ");
            $stmt->execute([$currentUser['id'], $moment['user_id']]);
            if (!$stmt->fetch()) {
                jsonResponse(false, '只能查看好友的朋友圈');
            }
        }
        
        // 处理图片
        $moment['images'] = $moment['images'] ? json_decode($moment['images'], true) : [];
        
        // 获取点赞用户
        $likeStmt = $pdo->prepare("
            SELECT l.user_id, u.nickname 
            FROM likes l 
            LEFT JOIN users u ON l.user_id = u.id 
            WHERE l.moment_id = ?
            ORDER BY l.created_at ASC
        ");
        $likeStmt->execute([$momentId]);
        $moment['likes'] = $likeStmt->fetchAll();
        
        // 获取评论（包含回复）
        $commentStmt = $pdo->prepare("
            SELECT c.*, 
                   u.nickname as user_nickname, u.avatar as user_avatar,
                   ru.nickname as reply_to_nickname
            FROM comments c
            LEFT JOIN users u ON c.user_id = u.id
            LEFT JOIN users ru ON c.reply_to_user_id = ru.id
            WHERE c.moment_id = ?
            ORDER BY c.created_at ASC
        ");
        $commentStmt->execute([$momentId]);
        $moment['comments'] = $commentStmt->fetchAll();
        
        jsonResponse(true, '获取成功', $moment);
        break;
        
    default:
        jsonResponse(false, '接口不存在', null, 404);
}
