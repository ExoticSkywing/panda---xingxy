<?php
/**
 * 星盟 - 商品发布权限扩展
 * 
 * 为合作方用户提供前台发布商品的权限检查
 * 
 * @package Xingxy
 * @subpackage StarAlliance
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 检查用户是否有发布商品的权限
 * 
 * 规则：已登录 + 角色为 author 及以上
 * 
 * @param int $user_id 用户 ID，默认当前用户
 * @return bool
 */
function xingxy_can_publish_product($user_id = 0) {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }
    if (!$user_id) {
        return false;
    }
    
    // 超级管理员始终有权限
    if (is_super_admin($user_id)) {
        return true;
    }
    
    // 检查用户角色：author / editor / administrator
    $user = get_userdata($user_id);
    if (!$user) {
        return false;
    }
    
    $allowed_roles = array('author', 'editor', 'administrator');
    $user_roles = (array) $user->roles;
    
    return !empty(array_intersect($allowed_roles, $user_roles));
}

/**
 * 检查用户是否有编辑指定商品的权限
 * 
 * @param int|WP_Post $post    商品 ID 或对象
 * @param int         $user_id 用户 ID，默认当前用户
 * @return bool
 */
function xingxy_can_edit_product($post, $user_id = 0) {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }
    if (!$user_id) {
        return false;
    }
    
    // 超级管理员始终有权限
    if (is_super_admin($user_id)) {
        return true;
    }
    
    $post = get_post($post);
    if (!$post || $post->post_type !== 'shop_product') {
        return false;
    }
    
    // 只能编辑自己的商品
    return (int) $post->post_author === $user_id;
}
