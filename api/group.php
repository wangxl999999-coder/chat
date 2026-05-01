<?php
require_once dirname(__DIR__) . '/includes/functions.php';

$currentUser = getCurrentUser();
if (!$currentUser) {
    jsonResponse(false, '请先登录');
}

$pdo = getDB();

switch ($action) {
    case 'create':
        // 创建群组
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $groupName = trim($input['group_name'] ?? '');
        $memberIds = $input['member_ids'] ?? [];
        
        if (empty($groupName)) {
            jsonResponse(false, '请输入群名称');
        }
        
        // 成员数必须至少1人（加上创建者共至少2人）
        if (!is_array($memberIds) || count($memberIds) < 1) {
            jsonResponse(false, '群组至少需要2人');
        }
        
        // 去重
        $memberIds = array_unique($memberIds);
        $memberIds = array_filter($memberIds, function($id) use ($currentUser) {
            return intval($id) !== intval($currentUser['id']);
        });
        
        if (count($memberIds) < 1) {
            jsonResponse(false, '群组至少需要2人');
        }
        
        // 验证所有成员都是好友
        foreach ($memberIds as $memberId) {
            $memberId = intval($memberId);
            $stmt = $pdo->prepare("
                SELECT status FROM friends 
                WHERE user_id = ? AND friend_id = ? AND status = 1
                LIMIT 1
            ");
            $stmt->execute([$currentUser['id'], $memberId]);
            
            if ($stmt->rowCount() == 0) {
                jsonResponse(false, '成员必须都是您的好友');
            }
        }
        
        try {
            $pdo->beginTransaction();
            
            // 创建群组
            $stmt = $pdo->prepare("
                INSERT INTO `groups` (group_name, creator_id)
                VALUES (?, ?)
            ");
            $stmt->execute([$groupName, $currentUser['id']]);
            $groupId = $pdo->lastInsertId();
            
            // 添加创建者为群主
            $stmt = $pdo->prepare("
                INSERT INTO group_members (group_id, user_id, nickname, role)
                VALUES (?, ?, ?, 2)
            ");
            $stmt->execute([$groupId, $currentUser['id'], $currentUser['nickname']]);
            
            // 添加其他成员
            foreach ($memberIds as $memberId) {
                $memberId = intval($memberId);
                
                // 获取成员昵称
                $stmt = $pdo->prepare("SELECT nickname FROM users WHERE id = ?");
                $stmt->execute([$memberId]);
                $member = $stmt->fetch();
                $memberNickname = $member ? $member['nickname'] : '';
                
                // 添加成员
                $stmt = $pdo->prepare("
                    INSERT IGNORE INTO group_members (group_id, user_id, nickname, role)
                    VALUES (?, ?, ?, 0)
                ");
                $stmt->execute([$groupId, $memberId, $memberNickname, 0]);
                
                // 为每个成员创建会话
                $stmt = $pdo->prepare("
                    INSERT IGNORE INTO conversations (user_id, target_id, type)
                    VALUES (?, ?, 2)
                ");
                $stmt->execute([$memberId, $groupId]);
            }
            
            // 为创建者创建会话
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO conversations (user_id, target_id, type)
                VALUES (?, ?, 2)
            ");
            $stmt->execute([$currentUser['id'], $groupId]);
            
            $pdo->commit();
            
            // 返回群组信息
            $stmt = $pdo->prepare("
                SELECT g.*, 
                       (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) as member_count
                FROM `groups` g
                WHERE g.id = ?
            ");
            $stmt->execute([$groupId]);
            $group = $stmt->fetch();
            
            // 获取成员列表
            $stmt = $pdo->prepare("
                SELECT gm.*, u.avatar, u.user_number
                FROM group_members gm
                LEFT JOIN users u ON gm.user_id = u.id
                WHERE gm.group_id = ?
                ORDER BY gm.role DESC, gm.join_time ASC
            ");
            $stmt->execute([$groupId]);
            $members = $stmt->fetchAll();
            
            $group['members'] = $members;
            
            jsonResponse(true, '群组创建成功', $group);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(false, '创建失败: ' . $e->getMessage());
        }
        break;
        
    case 'list':
        // 获取用户的群组列表
        $stmt = $pdo->prepare("
            SELECT g.*, 
                   (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) as member_count,
                   c.last_message,
                   c.last_message_time,
                   c.unread_count
            FROM `groups` g
            INNER JOIN group_members gm ON g.id = gm.group_id
            LEFT JOIN conversations c ON c.target_id = g.id AND c.user_id = gm.user_id AND c.type = 2
            WHERE gm.user_id = ?
            ORDER BY c.last_message_time DESC, g.created_at DESC
        ");
        $stmt->execute([$currentUser['id']]);
        $groups = $stmt->fetchAll();
        
        jsonResponse(true, '获取成功', $groups);
        break;
        
    case 'detail':
        // 获取群组详情
        $groupId = intval($_GET['group_id'] ?? 0);
        
        if ($groupId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        // 检查是否是群成员
        $stmt = $pdo->prepare("
            SELECT * FROM group_members 
            WHERE group_id = ? AND user_id = ?
        ");
        $stmt->execute([$groupId, $currentUser['id']]);
        
        if ($stmt->rowCount() == 0) {
            jsonResponse(false, '您不是该群成员');
        }
        
        // 获取群组信息
        $stmt = $pdo->prepare("
            SELECT g.*, 
                   (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) as member_count
            FROM `groups` g
            WHERE g.id = ?
        ");
        $stmt->execute([$groupId]);
        $group = $stmt->fetch();
        
        if (!$group) {
            jsonResponse(false, '群组不存在');
        }
        
        // 获取成员列表
        $stmt = $pdo->prepare("
            SELECT gm.*, u.avatar, u.user_number, u.nickname as user_nickname
            FROM group_members gm
            LEFT JOIN users u ON gm.user_id = u.id
            WHERE gm.group_id = ?
            ORDER BY gm.role DESC, gm.join_time ASC
        ");
        $stmt->execute([$groupId]);
        $members = $stmt->fetchAll();
        
        $group['members'] = $members;
        
        jsonResponse(true, '获取成功', $group);
        break;
        
    case 'update':
        // 修改群组信息
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $groupId = intval($input['group_id'] ?? 0);
        $groupName = trim($input['group_name'] ?? '');
        $groupAnnouncement = trim($input['group_announcement'] ?? '');
        
        if ($groupId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        // 检查权限（群主或管理员才能修改）
        $stmt = $pdo->prepare("
            SELECT role FROM group_members 
            WHERE group_id = ? AND user_id = ?
        ");
        $stmt->execute([$groupId, $currentUser['id']]);
        $member = $stmt->fetch();
        
        if (!$member) {
            jsonResponse(false, '您不是该群成员');
        }
        
        if ($member['role'] < 1) {
            jsonResponse(false, '无权限修改群组信息');
        }
        
        try {
            $pdo->beginTransaction();
            
            $updateFields = [];
            $updateParams = [];
            
            if (!empty($groupName)) {
                $updateFields[] = 'group_name = ?';
                $updateParams[] = $groupName;
            }
            
            if (isset($input['group_announcement'])) {
                $updateFields[] = 'group_announcement = ?';
                $updateParams[] = $groupAnnouncement;
            }
            
            if (empty($updateFields)) {
                jsonResponse(false, '没有需要修改的内容');
            }
            
            $updateParams[] = $groupId;
            
            $stmt = $pdo->prepare("
                UPDATE `groups` 
                SET " . implode(', ', $updateFields) . "
                WHERE id = ?
            ");
            $stmt->execute($updateParams);
            
            $pdo->commit();
            
            jsonResponse(true, '修改成功');
            
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(false, '修改失败: ' . $e->getMessage());
        }
        break;
        
    case 'update_nickname':
        // 修改群内昵称
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $groupId = intval($input['group_id'] ?? 0);
        $nickname = trim($input['nickname'] ?? '');
        
        if ($groupId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        // 检查是否是群成员
        $stmt = $pdo->prepare("
            SELECT * FROM group_members 
            WHERE group_id = ? AND user_id = ?
        ");
        $stmt->execute([$groupId, $currentUser['id']]);
        
        if ($stmt->rowCount() == 0) {
            jsonResponse(false, '您不是该群成员');
        }
        
        $stmt = $pdo->prepare("
            UPDATE group_members 
            SET nickname = ? 
            WHERE group_id = ? AND user_id = ?
        ");
        $stmt->execute([$nickname, $groupId, $currentUser['id']]);
        
        jsonResponse(true, '昵称修改成功');
        break;
        
    case 'quit':
        // 退出群组
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $groupId = intval($input['group_id'] ?? 0);
        
        if ($groupId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        // 检查是否是群成员
        $stmt = $pdo->prepare("
            SELECT role FROM group_members 
            WHERE group_id = ? AND user_id = ?
        ");
        $stmt->execute([$groupId, $currentUser['id']]);
        $member = $stmt->fetch();
        
        if (!$member) {
            jsonResponse(false, '您不是该群成员');
        }
        
        // 群主不能直接退出，需要先转让或解散
        if ($member['role'] == 2) {
            // 检查是否还有其他成员
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count FROM group_members 
                WHERE group_id = ?
            ");
            $stmt->execute([$groupId]);
            $countResult = $stmt->fetch();
            
            if ($countResult['count'] > 1) {
                jsonResponse(false, '群主需要先转让或解散群组');
            }
        }
        
        try {
            $pdo->beginTransaction();
            
            // 移除成员
            $stmt = $pdo->prepare("
                DELETE FROM group_members 
                WHERE group_id = ? AND user_id = ?
            ");
            $stmt->execute([$groupId, $currentUser['id']]);
            
            // 删除会话
            $stmt = $pdo->prepare("
                DELETE FROM conversations 
                WHERE user_id = ? AND target_id = ? AND type = 2
            ");
            $stmt->execute([$currentUser['id'], $groupId]);
            
            // 检查是否还有成员，没有则删除群组
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count FROM group_members 
                WHERE group_id = ?
            ");
            $stmt->execute([$groupId]);
            $countResult = $stmt->fetch();
            
            if ($countResult['count'] == 0) {
                // 删除群组
                $stmt = $pdo->prepare("DELETE FROM `groups` WHERE id = ?");
                $stmt->execute([$groupId]);
                
                // 删除所有相关会话
                $stmt = $pdo->prepare("DELETE FROM conversations WHERE target_id = ? AND type = 2");
                $stmt->execute([$groupId]);
                
                // 删除所有相关消息
                $stmt = $pdo->prepare("DELETE FROM messages WHERE group_id = ?");
                $stmt->execute([$groupId]);
            }
            
            $pdo->commit();
            
            jsonResponse(true, '已退出群组');
            
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(false, '退出失败: ' . $e->getMessage());
        }
        break;
        
    case 'add_members':
        // 添加群成员
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $groupId = intval($input['group_id'] ?? 0);
        $memberIds = $input['member_ids'] ?? [];
        
        if ($groupId <= 0 || !is_array($memberIds) || count($memberIds) == 0) {
            jsonResponse(false, '参数错误');
        }
        
        // 检查是否是群成员（只有成员才能邀请）
        $stmt = $pdo->prepare("
            SELECT * FROM group_members 
            WHERE group_id = ? AND user_id = ?
        ");
        $stmt->execute([$groupId, $currentUser['id']]);
        
        if ($stmt->rowCount() == 0) {
            jsonResponse(false, '您不是该群成员');
        }
        
        // 去重
        $memberIds = array_unique($memberIds);
        
        try {
            $pdo->beginTransaction();
            
            $addedCount = 0;
            
            foreach ($memberIds as $memberId) {
                $memberId = intval($memberId);
                
                // 不能添加自己
                if ($memberId == $currentUser['id']) {
                    continue;
                }
                
                // 检查是否已经是群成员
                $stmt = $pdo->prepare("
                    SELECT id FROM group_members 
                    WHERE group_id = ? AND user_id = ?
                ");
                $stmt->execute([$groupId, $memberId]);
                
                if ($stmt->rowCount() > 0) {
                    continue;
                }
                
                // 检查是否是好友
                $stmt = $pdo->prepare("
                    SELECT status FROM friends 
                    WHERE user_id = ? AND friend_id = ? AND status = 1
                    LIMIT 1
                ");
                $stmt->execute([$currentUser['id'], $memberId]);
                
                if ($stmt->rowCount() == 0) {
                    continue;
                }
                
                // 获取成员昵称
                $stmt = $pdo->prepare("SELECT nickname FROM users WHERE id = ?");
                $stmt->execute([$memberId]);
                $member = $stmt->fetch();
                $memberNickname = $member ? $member['nickname'] : '';
                
                // 添加成员
                $stmt = $pdo->prepare("
                    INSERT INTO group_members (group_id, user_id, nickname, role)
                    VALUES (?, ?, ?, 0)
                ");
                $stmt->execute([$groupId, $memberId, $memberNickname]);
                
                // 为成员创建会话
                $stmt = $pdo->prepare("
                    INSERT IGNORE INTO conversations (user_id, target_id, type)
                    VALUES (?, ?, 2)
                ");
                $stmt->execute([$memberId, $groupId]);
                
                $addedCount++;
            }
            
            $pdo->commit();
            
            jsonResponse(true, "成功添加 {$addedCount} 位成员", ['added_count' => $addedCount]);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(false, '添加失败: ' . $e->getMessage());
        }
        break;
        
    case 'remove_member':
        // 移除群成员（只有群主或管理员可以）
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $groupId = intval($input['group_id'] ?? 0);
        $memberId = intval($input['member_id'] ?? 0);
        
        if ($groupId <= 0 || $memberId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        // 不能移除自己
        if ($memberId == $currentUser['id']) {
            jsonResponse(false, '请使用退出群组功能');
        }
        
        // 检查权限
        $stmt = $pdo->prepare("
            SELECT role FROM group_members 
            WHERE group_id = ? AND user_id = ?
        ");
        $stmt->execute([$groupId, $currentUser['id']]);
        $operator = $stmt->fetch();
        
        if (!$operator || $operator['role'] < 1) {
            jsonResponse(false, '无权限移除成员');
        }
        
        // 检查目标成员
        $stmt = $pdo->prepare("
            SELECT role FROM group_members 
            WHERE group_id = ? AND user_id = ?
        ");
        $stmt->execute([$groupId, $memberId]);
        $target = $stmt->fetch();
        
        if (!$target) {
            jsonResponse(false, '目标用户不是群成员');
        }
        
        // 管理员不能移除管理员或群主
        if ($operator['role'] == 1 && $target['role'] >= 1) {
            jsonResponse(false, '无权限移除该成员');
        }
        
        try {
            $pdo->beginTransaction();
            
            // 移除成员
            $stmt = $pdo->prepare("
                DELETE FROM group_members 
                WHERE group_id = ? AND user_id = ?
            ");
            $stmt->execute([$groupId, $memberId]);
            
            // 删除会话
            $stmt = $pdo->prepare("
                DELETE FROM conversations 
                WHERE user_id = ? AND target_id = ? AND type = 2
            ");
            $stmt->execute([$memberId, $groupId]);
            
            $pdo->commit();
            
            jsonResponse(true, '已移除成员');
            
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(false, '移除失败: ' . $e->getMessage());
        }
        break;
        
    case 'transfer_owner':
        // 转让群主
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $groupId = intval($input['group_id'] ?? 0);
        $newOwnerId = intval($input['new_owner_id'] ?? 0);
        
        if ($groupId <= 0 || $newOwnerId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        // 检查是否是群主
        $stmt = $pdo->prepare("
            SELECT role FROM group_members 
            WHERE group_id = ? AND user_id = ?
        ");
        $stmt->execute([$groupId, $currentUser['id']]);
        $operator = $stmt->fetch();
        
        if (!$operator || $operator['role'] != 2) {
            jsonResponse(false, '只有群主可以转让');
        }
        
        // 不能转让给自己
        if ($newOwnerId == $currentUser['id']) {
            jsonResponse(false, '不能转让给自己');
        }
        
        // 检查新群主是否是群成员
        $stmt = $pdo->prepare("
            SELECT id FROM group_members 
            WHERE group_id = ? AND user_id = ?
        ");
        $stmt->execute([$groupId, $newOwnerId]);
        
        if ($stmt->rowCount() == 0) {
            jsonResponse(false, '新群主必须是群成员');
        }
        
        try {
            $pdo->beginTransaction();
            
            // 原群主变为普通成员
            $stmt = $pdo->prepare("
                UPDATE group_members 
                SET role = 0 
                WHERE group_id = ? AND user_id = ?
            ");
            $stmt->execute([$groupId, $currentUser['id']]);
            
            // 新群主
            $stmt = $pdo->prepare("
                UPDATE group_members 
                SET role = 2 
                WHERE group_id = ? AND user_id = ?
            ");
            $stmt->execute([$groupId, $newOwnerId]);
            
            // 更新群组创建者
            $stmt = $pdo->prepare("
                UPDATE `groups` 
                SET creator_id = ? 
                WHERE id = ?
            ");
            $stmt->execute([$newOwnerId, $groupId]);
            
            $pdo->commit();
            
            jsonResponse(true, '群主转让成功');
            
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(false, '转让失败: ' . $e->getMessage());
        }
        break;
        
    case 'set_admin':
        // 设置/取消管理员
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, '请求方法错误');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $groupId = intval($input['group_id'] ?? 0);
        $memberId = intval($input['member_id'] ?? 0);
        $isAdmin = isset($input['is_admin']) ? intval($input['is_admin']) : 1;
        
        if ($groupId <= 0 || $memberId <= 0) {
            jsonResponse(false, '参数错误');
        }
        
        // 检查是否是群主
        $stmt = $pdo->prepare("
            SELECT role FROM group_members 
            WHERE group_id = ? AND user_id = ?
        ");
        $stmt->execute([$groupId, $currentUser['id']]);
        $operator = $stmt->fetch();
        
        if (!$operator || $operator['role'] != 2) {
            jsonResponse(false, '只有群主可以设置管理员');
        }
        
        // 检查目标成员
        $stmt = $pdo->prepare("
            SELECT role FROM group_members 
            WHERE group_id = ? AND user_id = ?
        ");
        $stmt->execute([$groupId, $memberId]);
        $target = $stmt->fetch();
        
        if (!$target) {
            jsonResponse(false, '目标用户不是群成员');
        }
        
        // 不能设置群主为管理员
        if ($target['role'] == 2) {
            jsonResponse(false, '不能设置群主为管理员');
        }
        
        $newRole = $isAdmin ? 1 : 0;
        
        $stmt = $pdo->prepare("
            UPDATE group_members 
            SET role = ? 
            WHERE group_id = ? AND user_id = ?
        ");
        $stmt->execute([$newRole, $groupId, $memberId]);
        
        jsonResponse(true, $isAdmin ? '已设置为管理员' : '已取消管理员');
        break;
        
    default:
        jsonResponse(false, '接口不存在', null, 404);
}
