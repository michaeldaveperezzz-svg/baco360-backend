<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * ------------------------------------------------------------------
 * LavaLust - an opensource lightweight PHP MVC Framework
 * ------------------------------------------------------------------
 *
 * MIT License
 *
 * Copyright (c) 2020 Ronald M. Marasigan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package LavaLust
 * @author Ronald M. Marasigan <ronald.marasigan@yahoo.com>
 * @since Version 1
 * @link https://github.com/ronmarasigan/LavaLust
 * @license https://opensource.org/licenses/MIT MIT License
 */

/*
| -------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------
| Here is where you can register web routes for your application.
|
|
*/
/** @var object $router **/

$router->get('/', 'Welcome::index');

// Migration Routes
    $router->get('create-migration/{migration_class}', 'MigrationController::create_migration');
    $router->get('migrate', 'MigrationController::migrate');
    $router->get('rollback', 'MigrationController::rollback');
    $router->get('rollback-all', 'MigrationController::rollback_all');
    $router->get('refresh', 'MigrationController::refresh');
    $router->get('status', 'MigrationController::status');

    // Authentication and profile
    $router->post('api/auth/login', 'AuthController::login');
    $router->post('api/login', 'AuthController::login');
    $router->post('api/auth/register', 'AuthController::register');
    $router->post('api/auth/google', 'AuthController::googleSignIn');
    $router->post('api/auth/logout', 'AuthController::logout');
    $router->post('api/auth/refresh', 'AuthController::refresh');
    $router->get('api/user/me', 'AuthController::me');
    $router->get('api/user/login-history', 'AuthController::loginHistory');
    $router->get('api/user/sessions', 'AuthController::activeSessions');
    $router->delete('api/user/sessions/{sessionId}', 'AuthController::revokeSession');
    $router->put('api/user/profile', 'AuthController::updateProfile');
    $router->post('api/user/profile/contact-change', 'AuthController::requestContactChange');
    $router->post('api/user/profile/contact-change/verify', 'AuthController::verifyContactChange');
    $router->put('api/user/password', 'AuthController::changePassword');
    $router->delete('api/user/account', 'AuthController::deleteOwnAccount');
    $router->post('api/user/account/deactivate', 'AuthController::deactivateOwnAccount');
    $router->put('api/user/notification-settings', 'AuthController::updateNotificationSettings');
    $router->get('api/user/resident-profile', 'ProfilesController::residentProfile');
    $router->put('api/user/resident-profile', 'ProfilesController::updateResidentProfile');
    $router->post('api/user/profile-photo', 'ProfilesController::uploadProfilePhoto');
    $router->delete('api/user/profile-photo', 'ProfilesController::removeProfilePhoto');
    $router->get('api/user/profile-photo/{id}', 'ProfilesController::showProfilePhoto');
    $router->get('api/admin/resident-profiles', 'ProfilesController::verificationQueue');
    $router->patch('api/admin/resident-profiles/{id}/verification', 'ProfilesController::updateAddressVerification');

    // Reports
    $router->get('api/reports', 'ReportsController::index');
    $router->post('api/reports', 'ReportsController::store');
    $router->get('api/reports/{id}', 'ReportsController::show');
    $router->patch('api/reports/{id}', 'ReportsController::update');
    $router->delete('api/reports/{id}', 'ReportsController::destroy');
    $router->post('api/reports/{id}/comments', 'ReportsController::addComment');
    $router->post('api/reports/{id}/attachments', 'ReportsController::uploadAttachments');
    $router->get('api/reports/{id}/attachments/{attachmentId}', 'ReportsController::downloadAttachment');
    $router->post('api/reports/{id}/assign', 'ReportsController::assign');

    // Resolved community reports for residents
    $router->get('api/community/reports', 'CommunityReportsController::index');
    $router->get('api/community/reports/{id}/view', 'CommunityReportsController::viewPost');
    $router->get('api/community/reports/{id}/comments', 'CommunityReportsController::comments');
    $router->post('api/community/reports/{id}/comments', 'CommunityReportsController::addComment');
    $router->put('api/community/reports/{id}/heart', 'CommunityReportsController::setHeart');

    // Issue categories
    $router->get('api/categories', 'CategoriesController::index');
    $router->post('api/categories', 'CategoriesController::store');
    $router->get('api/categories/{id}', 'CategoriesController::show');
    $router->put('api/categories/{id}', 'CategoriesController::update');
    $router->put('api/categories/{id}/toggle', 'CategoriesController::toggle');
    $router->delete('api/categories/{id}', 'CategoriesController::destroy');

    // Administrator user management
    $router->get('api/admin/users', 'UsersController::index');
    $router->post('api/admin/users', 'UsersController::store');
    $router->get('api/admin/users/{id}', 'UsersController::show');
    $router->put('api/admin/users/{id}', 'UsersController::update');
    $router->delete('api/admin/users/{id}', 'UsersController::destroy');
    $router->get('api/admin/audit-logs', 'AdminController::auditLogs');
    $router->get('api/dashboard/stats', 'AdminController::dashboardStats');

    // Public website content and administrator content management
    $router->get('api/website-content', 'WebsiteContentController::index');
    $router->get('api/website-content/media/{fileName}', 'WebsiteContentController::media');
    $router->get('api/admin/website-content', 'WebsiteContentController::adminIndex');
    $router->put('api/admin/website-content/settings', 'WebsiteContentController::updateSettings');
    $router->post('api/admin/website-content/images', 'WebsiteContentController::uploadImage');
    $router->post('api/admin/website-content/{collection}', 'WebsiteContentController::collectionCreate');
    $router->put('api/admin/website-content/{collection}/{id}', 'WebsiteContentController::collectionUpdate');
    $router->delete('api/admin/website-content/{collection}/{id}', 'WebsiteContentController::collectionDelete');

    // Notifications
    $router->get('api/announcements', 'AnnouncementsController::index');
    $router->get('api/announcements/recipients', 'AnnouncementsController::recipients');
    $router->post('api/announcements', 'AnnouncementsController::publish');
    $router->put('api/announcements/{id}', 'AnnouncementsController::update');
    $router->delete('api/announcements/{id}', 'AnnouncementsController::destroy');
    $router->patch('api/announcements/{id}/archive', 'AnnouncementsController::archive');
    $router->patch('api/announcements/{id}/restore', 'AnnouncementsController::restore');
    $router->patch('api/announcements/{id}/read', 'AnnouncementsController::markRead');
    $router->get('api/emergency-contacts', 'EmergencyContactsController::index');
    $router->post('api/admin/emergency-contacts', 'EmergencyContactsController::store');
    $router->put('api/admin/emergency-contacts/{id}', 'EmergencyContactsController::update');
    $router->delete('api/admin/emergency-contacts/{id}', 'EmergencyContactsController::destroy');
    $router->get('api/community-events', 'CommunityEventsController::index');
    $router->post('api/community-events', 'CommunityEventsController::create');
    $router->delete('api/community-events/{id}', 'CommunityEventsController::destroy');
    $router->get('api/notifications', 'NotificationsController::index');
    $router->patch('api/notifications/{id}/read', 'NotificationsController::markRead');
    $router->patch('api/notifications/read-all', 'NotificationsController::markAllRead');
    $router->delete('api/notifications', 'NotificationsController::destroyAll');
    $router->delete('api/notifications/{id}', 'NotificationsController::destroy');
