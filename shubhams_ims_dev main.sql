-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Nov 20, 2025 at 04:27 PM
-- Server version: 10.6.22-MariaDB-cll-lve
-- PHP Version: 8.3.15

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `shubhams_ims_dev`
--

-- --------------------------------------------------------

--
-- Table structure for table `acl_permissions`
--

CREATE TABLE `acl_permissions` (
  `id` int(11) NOT NULL,
  `permission_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `acl_permissions`
--

INSERT INTO `acl_permissions` (`id`, `permission_name`, `description`, `category`, `created_at`) VALUES
(1, 'auth.login', 'Basic login access', 'authentication', '2025-07-24 00:05:50'),
(2, 'auth.logout', 'Logout access', 'authentication', '2025-07-24 00:05:50'),
(3, 'auth.change_password', 'Change own password', 'authentication', '2025-07-24 00:05:50'),
(4, 'users.view', 'View user list and details', 'user_management', '2025-07-24 00:05:50'),
(5, 'users.create', 'Create new user accounts', 'user_management', '2025-07-24 00:05:50'),
(6, 'users.edit', 'Edit user account details', 'user_management', '2025-07-24 00:05:50'),
(7, 'users.delete', 'Delete user accounts', 'user_management', '2025-07-24 00:05:50'),
(8, 'users.manage_roles', 'Assign/remove roles from users', 'user_management', '2025-07-24 00:05:50'),
(9, 'roles.view', 'View available roles', 'role_management', '2025-07-24 00:05:50'),
(10, 'roles.create', 'Create new roles', 'role_management', '2025-07-24 00:05:50'),
(11, 'roles.edit', 'Edit role details and permissions', 'role_management', '2025-07-24 00:05:50'),
(12, 'roles.delete', 'Delete custom roles', 'role_management', '2025-07-24 00:05:50'),
(13, 'cpu.view', 'View CPU inventory', 'inventory', '2025-07-24 00:05:50'),
(14, 'cpu.create', 'Add new CPU components', 'inventory', '2025-07-24 00:05:50'),
(15, 'cpu.edit', 'Edit CPU component details', 'inventory', '2025-07-24 00:05:50'),
(16, 'cpu.delete', 'Delete CPU components', 'inventory', '2025-07-24 00:05:50'),
(17, 'ram.view', 'View RAM inventory', 'inventory', '2025-07-24 00:05:50'),
(18, 'ram.create', 'Add new RAM components', 'inventory', '2025-07-24 00:05:50'),
(19, 'ram.edit', 'Edit RAM component details', 'inventory', '2025-07-24 00:05:50'),
(20, 'ram.delete', 'Delete RAM components', 'inventory', '2025-07-24 00:05:50'),
(21, 'storage.view', 'View storage inventory', 'inventory', '2025-07-24 00:05:50'),
(22, 'storage.create', 'Add new storage components', 'inventory', '2025-07-24 00:05:50'),
(23, 'storage.edit', 'Edit storage component details', 'inventory', '2025-07-24 00:05:50'),
(24, 'storage.delete', 'Delete storage components', 'inventory', '2025-07-24 00:05:50'),
(25, 'motherboard.view', 'View motherboard inventory', 'inventory', '2025-07-24 00:05:50'),
(26, 'motherboard.create', 'Add new motherboard components', 'inventory', '2025-07-24 00:05:50'),
(27, 'motherboard.edit', 'Edit motherboard component details', 'inventory', '2025-07-24 00:05:50'),
(28, 'motherboard.delete', 'Delete motherboard components', 'inventory', '2025-07-24 00:05:50'),
(29, 'nic.view', 'View NIC inventory', 'inventory', '2025-07-24 00:05:50'),
(30, 'nic.create', 'Add new NIC components', 'inventory', '2025-07-24 00:05:50'),
(31, 'nic.edit', 'Edit NIC component details', 'inventory', '2025-07-24 00:05:50'),
(32, 'nic.delete', 'Delete NIC components', 'inventory', '2025-07-24 00:05:50'),
(33, 'caddy.view', 'View caddy inventory', 'inventory', '2025-07-24 00:05:50'),
(34, 'caddy.create', 'Add new caddy components', 'inventory', '2025-07-24 00:05:50'),
(35, 'caddy.edit', 'Edit caddy component details', 'inventory', '2025-07-24 00:05:50'),
(36, 'caddy.delete', 'Delete caddy components', 'inventory', '2025-07-24 00:05:50'),
(37, 'dashboard.view', 'Access main dashboard', 'dashboard', '2025-07-24 00:05:50'),
(38, 'reports.view', 'View inventory reports', 'reports', '2025-07-24 00:05:50'),
(39, 'reports.export', 'Export inventory data', 'reports', '2025-07-24 00:05:50'),
(40, 'search.global', 'Search across all components', 'utilities', '2025-07-24 00:05:50'),
(41, 'search.advanced', 'Advanced search capabilities', 'utilities', '2025-07-24 00:05:50'),
(42, 'system.view_logs', 'View system activity logs', 'system', '2025-07-24 00:05:50'),
(43, 'system.manage_settings', 'Manage system settings', 'system', '2025-07-24 00:05:50'),
(44, 'system.backup', 'Create system backups', 'system', '2025-07-24 00:05:50'),
(45, 'system.maintenance', 'Perform system maintenance', 'system', '2025-07-24 00:05:50'),
(46, 'dashboard.admin', NULL, 'dashboard', '2025-07-25 01:29:56'),
(47, 'roles.assign', NULL, 'user_management', '2025-07-25 01:29:56'),
(48, 'system.settings', NULL, 'system', '2025-07-25 01:29:56'),
(49, 'system.logs', NULL, 'system', '2025-07-25 01:29:56'),
(50, 'server.view', 'View server configuration details', 'server_management', '2025-08-02 15:10:03'),
(51, 'server.create', 'Create new server configurations', 'server_management', '2025-08-02 15:10:03'),
(52, 'server.edit', 'Modify existing server configurations', 'server_management', '2025-08-02 15:10:03'),
(53, 'server.delete', 'Delete server configurations', 'server_management', '2025-08-02 15:10:03'),
(54, 'server.view_all', 'View server configurations created by other users', 'server_management', '2025-08-02 15:10:03'),
(55, 'server.delete_all', 'Delete server configurations created by other users', 'server_management', '2025-08-02 15:10:03'),
(56, 'server.view_statistics', 'View server configuration statistics and reports', 'server_management', '2025-08-02 15:10:03'),
(57, 'compatibility.check', 'Run compatibility checks between components', 'compatibility', '2025-08-02 15:10:03'),
(58, 'compatibility.view_statistics', 'View compatibility check statistics', 'compatibility', '2025-08-02 15:10:03'),
(59, 'compatibility.manage_rules', 'Create and modify compatibility rules', 'compatibility', '2025-08-02 15:10:03'),
(60, 'server.edit_all', 'Edit all users server configurations', 'server', '2025-08-20 09:40:51'),
(61, 'permissions.get_all', 'View all system permissions', 'role_management', '2025-08-20 09:40:51'),
(62, 'permissions.manage', 'Manage system permissions', 'role_management', '2025-08-20 09:40:51'),
(63, 'roles.update_permissions', 'Update permissions for roles', 'role_management', '2025-08-20 09:40:51'),
(64, 'reports.create', 'Create new reports', 'reports', '2025-08-20 09:40:51'),
(65, 'reports.schedule', 'Schedule report generation', 'reports', '2025-08-20 09:40:51'),
(66, 'chassis.view', 'View chassis inventory', 'inventory', '2025-10-22 14:33:58'),
(67, 'chassis.create', 'Add new chassis components', 'inventory', '2025-10-22 14:33:58'),
(68, 'chassis.edit', 'Edit chassis component details', 'inventory', '2025-10-22 14:33:58'),
(69, 'chassis.delete', 'Delete chassis components', 'inventory', '2025-10-22 14:33:58'),
(70, 'pciecard.view', 'View PCIe card inventory', 'inventory', '2025-10-22 14:33:58'),
(71, 'pciecard.create', 'Add new PCIe card components', 'inventory', '2025-10-22 14:33:58'),
(72, 'pciecard.edit', 'Edit PCIe card component details', 'inventory', '2025-10-22 14:33:58'),
(73, 'pciecard.delete', 'Delete PCIe card components', 'inventory', '2025-10-22 14:33:58'),
(74, 'hbacard.view', 'View HBA Card inventory', 'inventory', '2025-10-24 08:21:15'),
(75, 'hbacard.create', 'Add new HBA Card components', 'inventory', '2025-10-24 08:21:15'),
(76, 'hbacard.edit', 'Edit HBA Card component details', 'inventory', '2025-10-24 08:21:15'),
(77, 'hbacard.delete', 'Delete HBA Card components', 'inventory', '2025-10-24 08:21:15'),
(88, 'sfp.view', 'View SFP module inventory and details', 'inventory', '2025-11-15 09:24:45'),
(89, 'sfp.create', 'Add new SFP modules to inventory', 'inventory', '2025-11-15 09:24:45'),
(90, 'sfp.edit', 'Edit SFP module details and assignments', 'inventory', '2025-11-15 09:24:45'),
(91, 'sfp.delete', 'Delete SFP modules from inventory', 'inventory', '2025-11-15 09:24:45'),
(97, 'ticket.create', 'Create new tickets and submit for approval', 'ticket', '2025-11-18 20:51:49'),
(98, 'ticket.view_own', 'View own tickets', 'ticket', '2025-11-18 20:51:49'),
(99, 'ticket.edit_own', 'Edit own draft tickets', 'ticket', '2025-11-18 20:51:49'),
(100, 'ticket.view_all', 'View all tickets in system', 'ticket', '2025-11-18 20:51:49'),
(101, 'ticket.view_assigned', 'View tickets assigned to user', 'ticket', '2025-11-18 20:51:49'),
(102, 'ticket.approve', 'Approve pending tickets', 'ticket', '2025-11-18 20:51:49'),
(103, 'ticket.reject', 'Reject pending tickets', 'ticket', '2025-11-18 20:51:49'),
(104, 'ticket.assign', 'Assign tickets to users', 'ticket', '2025-11-18 20:51:49'),
(105, 'ticket.deploy', 'Deploy approved changes and mark as deployed', 'ticket', '2025-11-18 20:51:49'),
(106, 'ticket.complete', 'Mark deployed tickets as complete', 'ticket', '2025-11-18 20:51:49'),
(107, 'ticket.cancel', 'Cancel tickets at any stage', 'ticket', '2025-11-18 20:51:49'),
(108, 'ticket.delete', 'Delete tickets permanently (admin only)', 'ticket', '2025-11-18 20:51:49'),
(109, 'ticket.manage', 'Bypass all ticket restrictions (superuser)', 'ticket', '2025-11-18 20:51:49');

-- --------------------------------------------------------

--
-- Table structure for table `acl_roles`
--

CREATE TABLE `acl_roles` (
  `id` int(11) NOT NULL,
  `role_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `acl_roles`
--

INSERT INTO `acl_roles` (`id`, `role_name`, `description`, `created_at`) VALUES
(1, 'super_admin', 'Full system access with all permissions', '2025-07-24 00:05:50'),
(3, 'manager', 'Management level access for inventory operations', '2025-07-24 00:05:50'),
(4, 'technician', 'Technical staff with component management access', '2025-07-24 00:05:50'),
(5, 'viewer', 'Read-only access to inventory data', '2025-07-24 00:05:50'),
(6, 'media_manager', '', '2025-07-29 03:54:58'),
(9, 'admin', 'Administrator with full access', '2025-08-20 10:01:01');

-- --------------------------------------------------------

--
-- Table structure for table `auth_tokens`
--

CREATE TABLE `auth_tokens` (
  `id` int(11) NOT NULL,
  `user_id` int(6) UNSIGNED NOT NULL,
  `token` varchar(128) NOT NULL,
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `auth_tokens`
--

INSERT INTO `auth_tokens` (`id`, `user_id`, `token`, `created_at`, `expires_at`, `last_used_at`) VALUES
(3, 3, '7445badd338c5c63f3a2ce982acc1f8cc4ef26d5b4abbe37c2d1e5d762444943', '2025-04-09 12:46:48', '2025-04-16 12:46:48', NULL),
(4, 4, 'f9b50bb0e26411acb70bb633f704c18b16c069d70cd8cd0324b2661a5d688761', '2025-04-09 16:24:16', '2025-04-16 16:24:16', NULL),
(5, 3, '3d427aca75b42626268391237f21d5d19e03bec1dc63946bc4f984227e1d13fb', '2025-04-09 16:42:18', '2025-04-16 16:42:18', NULL),
(6, 37, '6233831cf4aa883114fb92f9c326bc8f232a385acf2b8abbbf409a3cedc9e60d', '2025-07-23 21:03:30', '2025-08-22 21:03:30', '2025-07-25 07:08:05'),
(7, 37, 'da09f665c313f56af05f611ee962946d9228ce77bbf36ee0c76bb55511219d68', '2025-07-23 21:08:08', '2025-08-22 21:08:08', '2025-07-25 07:08:05'),
(8, 37, 'f9ee7438a75bbbb617e4b96c0910cc6b0030943284a2f71d749454a36b166cc0', '2025-07-25 01:29:56', '2025-08-24 01:29:56', '2025-07-25 07:08:05'),
(9, 37, '96e349c89a9c70de802796e7e26e5689eb67d4aa0e7308e406e164c657eb58be', '2025-07-25 01:43:06', '2025-08-24 01:43:06', '2025-07-25 07:08:05'),
(10, 37, 'bd9e4734d0f83a64bd880e8baba5691ac2098d2c91e766980a1e79d5050f4bc7', '2025-07-25 01:45:19', '2025-08-24 01:45:19', '2025-07-25 07:08:05'),
(11, 37, 'ba84e78dfd2651e5568659fc8060e1187adcd3bb659df19f0121630f878ee9b8', '2025-07-25 02:41:20', '2025-08-24 02:41:20', '2025-07-25 07:08:05'),
(12, 37, 'adfbb667ea9b2cbde651bf0e1f99cb6c97c8c7a1c690fd13183b0f23cc068991', '2025-07-25 03:57:32', '2025-08-24 03:57:32', '2025-07-25 07:08:05'),
(13, 37, '5d6bbbf5e86d0b7034ddb2308c6d159a6bfcd9b206233e58147b515345ce546d', '2025-07-25 07:00:57', '2025-08-24 07:00:57', '2025-07-25 07:08:05'),
(17, 25, 'dfa2ff74c0b9c1a93970f365d9fc689262047710bfcd72599cee8d8d4689f2e4', '2025-07-25 14:39:09', '2025-08-24 14:39:09', NULL),
(18, 25, 'ddad6f90f21deeab493e116c8da65d854d3016be141edc67075abc6f8258c5d0', '2025-07-25 14:39:38', '2025-08-24 14:39:38', NULL),
(19, 25, '803bd0dc379ad57ef817281686ddf9282f2a64625d99096fab2a292590786e71', '2025-07-25 14:40:09', '2025-08-24 14:40:09', NULL),
(268, 25, '1bb955d054f2e490fc1604708e8a6d1f86c8c4c4b12207ffa8a9d6ff1ffbcebc', '2025-10-17 08:31:24', '2025-10-24 08:31:24', NULL),
(372, 38, '5082aa09abea095f6fa46a1cc8618425850d9fbc9e465616bebc4664c834f54d', '2025-11-07 07:35:58', '2025-11-14 07:35:58', '2025-11-20 16:25:05'),
(373, 38, '90544e628eafba0023c51333e377537d7fbb7434ba222997b677672797e51fbd', '2025-11-07 07:43:44', '2025-11-14 07:43:44', '2025-11-20 16:25:05'),
(374, 38, '0a8c70776fa4f42ba5729bf5e699e34af5006eeb4193a88b5d4dce14ce435f84', '2025-11-07 07:52:49', '2025-11-14 07:52:49', '2025-11-20 16:25:05'),
(375, 38, 'fa67ed9a10d460b507a0f41068967030ebb6d622e0da7070bf061d413eedce7a', '2025-11-07 08:05:28', '2025-11-14 08:05:28', '2025-11-20 16:25:05'),
(376, 38, 'bb439545d57966e9fef04c28343ea1ba923a4e04e955a10d0f367d67d6ab774e', '2025-11-07 11:44:13', '2025-11-14 11:44:13', '2025-11-20 16:25:05'),
(377, 38, '91fa5b59db54f0f09dbe17ae8a82a6149641d13a3bba9612b7245cdff33e0dce', '2025-11-08 05:23:03', '2025-11-15 05:23:03', '2025-11-20 16:25:05'),
(378, 38, '65a48432186d5be7d0cc7958fe2dc3949dc97d45a294fd9c010a9e815bbed591', '2025-11-08 05:28:21', '2025-11-15 05:28:21', '2025-11-20 16:25:05'),
(379, 38, 'e1e74cda995bdd2a6fe6a1616e3d8efb4c0fe93c1db0b12f11fbed22764cbaa2', '2025-11-08 05:29:12', '2025-11-15 05:29:12', '2025-11-20 16:25:05'),
(380, 38, '50a7804f38ce455c1c119ec219d3ffb131145c454c0e5f6e90f93847d1d5e8a9', '2025-11-08 05:29:29', '2025-11-15 05:29:29', '2025-11-20 16:25:05'),
(381, 38, '8e04b8a8cb2a9a1acbefd6e6d9ccd1f19fe6c519a34e9ee77c1710e82ba4865d', '2025-11-08 05:31:00', '2025-11-15 05:31:00', '2025-11-20 16:25:05'),
(382, 38, '59dbde5715562374f463817b1624a40d843f76244876a3d45324d62c1d6f9ba7', '2025-11-09 15:33:14', '2025-11-16 15:33:14', '2025-11-20 16:25:05'),
(383, 38, 'de470fe4c8b491a7b7fc499c60448b6aa2e39dad72519ada4effe19b8296301b', '2025-11-10 15:41:10', '2025-11-17 15:41:10', '2025-11-20 16:25:05'),
(384, 38, '7d8ca5b6d428ffbebab79afcbe9829a0f670276e23342b83d174ef2f6c464cfe', '2025-11-10 15:43:17', '2025-11-17 15:43:17', '2025-11-20 16:25:05'),
(385, 38, '1e4696943066f1d96bb6ec73f82302d0db16cabd8e91d3bc55b519f6ae0e5bb2', '2025-11-12 07:52:41', '2025-11-19 07:52:41', '2025-11-20 16:25:05'),
(386, 5, 'd9d6dbe5f764f758423bdb3f2174f104bdeb14d4e120ee8c79c560af33856ae2', '2025-11-12 19:44:02', '2025-11-19 19:44:02', '2025-11-12 20:03:06'),
(387, 5, 'cf83b0857a11dc1022e7be491356568a8b791f7a991817052dcf0ad17f961962', '2025-11-12 19:47:37', '2025-11-19 19:47:37', '2025-11-12 20:03:06'),
(388, 5, '24a884be7ca0904b19e735afad8315fcd1b7958af8e736927d36711f238ff76c', '2025-11-12 19:56:34', '2025-11-19 19:56:34', '2025-11-12 20:03:06'),
(389, 5, '4d693be5b2428691a6a6e31697ef89312d812bba99413fa282b33e34b2caddc7', '2025-11-12 19:58:10', '2025-11-19 19:58:10', '2025-11-12 20:03:06'),
(390, 38, '1ee770df43f9ff84c940f4b6bb7e6ea017ef68de8790513efdc120a818140c03', '2025-11-13 08:14:59', '2025-11-20 08:14:59', '2025-11-20 16:25:05'),
(391, 38, '2cbf15d891f884af44cdc95a79c0ffeabdffb5ff53fba2bce1e60e63418993b7', '2025-11-13 10:01:20', '2025-11-20 10:01:20', '2025-11-20 16:25:05'),
(392, 38, '4d101353a729bd5cee2ad35aacd80226602cc286c1dfc58e24a4f7e60ab67904', '2025-11-14 14:21:17', '2025-11-21 14:21:17', '2025-11-20 16:25:05'),
(393, 38, 'c597977af826d979aad178bd68f0cb380d8b5fd5b04486f0c40f3bcb5b6f97ff', '2025-11-16 07:46:26', '2025-11-23 07:46:26', '2025-11-20 16:25:05'),
(394, 38, '226c7889cf9a2cd3d69acbf64fed6146ffa03f34b4ec4cab5c576a815694ed35', '2025-11-18 09:16:56', '2025-11-25 09:16:56', '2025-11-20 16:25:05'),
(395, 38, 'fad86795654839691ce9a258fccbd8466e08e182644497d0cd234f1a890b2f0b', '2025-11-18 14:05:32', '2025-11-25 14:05:32', '2025-11-20 16:25:05'),
(396, 38, 'ae4d6d5d29de8a3899d3145b78dc197f1ed0ec2b07d52ad7a46e3eed2faf382b', '2025-11-19 19:53:30', '2025-11-26 19:53:30', '2025-11-20 16:25:05'),
(397, 38, 'f638f6aa192b79d1c7b043d03ce1674276b18ee2f7ffeb31e6b083daa6187468', '2025-11-19 21:19:02', '2025-11-26 21:19:02', '2025-11-20 16:25:05'),
(398, 38, '1c0482ba2cbd8043a1f436bf3424786ac67753a4cd944e9a0b4acf255efdf84b', '2025-11-19 21:20:16', '2025-11-26 21:20:16', '2025-11-20 16:25:05'),
(399, 38, '4c1874a774d03049a8875d22950d9506c810745787fe9fc738f8899f463c1b5e', '2025-11-19 21:21:48', '2025-11-26 21:21:48', '2025-11-20 16:25:05'),
(400, 38, '4df56222e1c79e33413cfc57721e7c1d9c6fddb6acef9da88733f50ccadbd700', '2025-11-19 21:23:08', '2025-11-26 21:23:08', '2025-11-20 16:25:05'),
(401, 38, 'd31e3039a0dde2679e1ba014e87f9578291249d92b42d6f6139a624639f1fd55', '2025-11-19 21:24:40', '2025-11-26 21:24:40', '2025-11-20 16:25:05'),
(402, 38, '7439138e1f18f57eccf24e2e9e0863a73436c2350722d839973dbb49bb9bf578', '2025-11-19 21:26:07', '2025-11-26 21:26:07', '2025-11-20 16:25:05'),
(403, 38, '3f17cb59d7240de71e19d4742b58345f315f5512860d1fe4178fec093bde32dc', '2025-11-19 21:27:06', '2025-11-26 21:27:06', '2025-11-20 16:25:05'),
(404, 38, '19f49dea281463ace62521c1b5c3c1227834c4b90744f14e8c78badf1277b9a5', '2025-11-19 21:28:44', '2025-11-26 21:28:44', '2025-11-20 16:25:05'),
(405, 38, '294029e42afb7c0cf78b5902fcb62a6a0bd0fa89ddbb695d96c619742bf0f407', '2025-11-19 21:29:36', '2025-11-26 21:29:36', '2025-11-20 16:25:05'),
(406, 38, 'baf80e6edd53b6ffa7a1b02c80e71a75e0b4a225fb3e80ae4c5dac4d3801c38d', '2025-11-19 21:31:10', '2025-11-26 21:31:10', '2025-11-20 16:25:05'),
(407, 38, '367b152ed66e77cf3fcff5b48a596b68ce7792955ce764498fa358b508ef702b', '2025-11-19 21:31:38', '2025-11-26 21:31:38', '2025-11-20 16:25:05'),
(408, 38, '2681777d10a9104427d2c226e8e31b112375438b801db0675ac95063b19b8ec1', '2025-11-19 21:34:32', '2025-11-26 21:34:32', '2025-11-20 16:25:05'),
(409, 38, '4ba1f198f0790590324a85216adf8b01cfcff1fee8f3b4556be8ab0641fd65a1', '2025-11-19 21:54:52', '2025-11-26 21:54:52', '2025-11-20 16:25:05'),
(410, 38, 'a01e4ff74b1f15006026a42c456b85c756e32324c02874a3f8ea3ac48a590637', '2025-11-19 21:56:08', '2025-11-26 21:56:08', '2025-11-20 16:25:05'),
(411, 38, '9564374fbb0c009698442c8e2a9f6c86bd41d63f0abc597af85e9ff8116e6000', '2025-11-19 21:57:21', '2025-11-26 21:57:21', '2025-11-20 16:25:05'),
(412, 38, 'aadabf3055978d84252caac114ebc63ce4d64a7d8857b4d22d95e9ae7e38e059', '2025-11-19 21:58:54', '2025-11-26 21:58:54', '2025-11-20 16:25:05');

-- --------------------------------------------------------

--
-- Table structure for table `caddyinventory`
--

CREATE TABLE `caddyinventory` (
  `ID` int(11) NOT NULL,
  `UUID` varchar(50) NOT NULL COMMENT 'Links to detailed specs in JSON',
  `SerialNumber` varchar(50) DEFAULT NULL COMMENT 'Manufacturer serial number',
  `Status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=Failed/Decommissioned, 1=Available, 2=In Use',
  `ServerUUID` varchar(36) DEFAULT NULL COMMENT 'UUID of server where caddy is installed, if any',
  `Location` varchar(100) DEFAULT NULL COMMENT 'Physical location like datacenter, warehouse',
  `RackPosition` varchar(20) DEFAULT NULL COMMENT 'Specific rack/shelf position',
  `PurchaseDate` date DEFAULT NULL,
  `InstallationDate` date DEFAULT NULL COMMENT 'When installed in current server',
  `WarrantyEndDate` date DEFAULT NULL,
  `Flag` varchar(50) DEFAULT NULL COMMENT 'Quick status flag or category',
  `Notes` text DEFAULT NULL COMMENT 'Any additional info or history',
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `caddyinventory`
--

INSERT INTO `caddyinventory` (`ID`, `UUID`, `SerialNumber`, `Status`, `ServerUUID`, `Location`, `RackPosition`, `PurchaseDate`, `InstallationDate`, `WarrantyEndDate`, `Flag`, `Notes`, `CreatedAt`, `UpdatedAt`) VALUES
(1, '4a8a2c05-e993-4b00-acae-9f036617091c', 'CDY123456', 2, '214100e3-c7cd-4f01-8c27-eca2310b0bbb', '', '', '2023-05-12', '2025-11-06', '2026-05-12', 'Production', 'Dell 2.5\" SAS Drive Caddy', '2025-05-11 11:42:52', '2025-11-06 12:52:58'),
(2, 'bcdce745-47ce-4deb-984d-8c3ba4b767ca', 'CDY789012', 1, NULL, 'Mumbai', NULL, '2022-03-10', NULL, '2025-03-10', 'Damaged', 'HP 3.5\" SATA Drive Caddy - Damaged locking mechanism', '2025-05-11 11:42:52', '2025-11-06 09:43:06'),
(3, 'bf3192bf-810e-47ea-81c6-946533aad2ca', 'CDY456789', 1, NULL, '', NULL, '2024-03-01', NULL, '2027-03-01', 'New', 'SuperMicro 3.5\" SAS/SATA Drive Tray', '2025-05-11 11:42:52', '2025-11-06 12:47:21'),
(4, '505c1ec9-35cc-4da9-b555-b7d15c0d9d06', 'CDY789082', 1, NULL, 'Mumbai', NULL, '2025-07-29', NULL, '2025-07-17', 'Critical', 'Type: 3.5 Inch\n\nAdditional Notes: new caddy', '2025-07-27 14:11:44', '2025-11-06 10:48:32'),
(5, 'a8d6f3c1-4b2e-4c89-9d13-72f5b9d0e6f7', 'CAD999999', 1, NULL, 'Mumbai', NULL, '2025-08-30', NULL, '2028-10-30', 'Backup', 'Caddy - Universal 2.5-inch HDD/SSD Caddy', '2025-08-30 12:14:12', '2025-11-06 09:43:14'),
(6, 'd7f1a3b5-8e4c-4f9d-9a2b-3c5d6e7f8a9b', 'CDY11111', 1, NULL, 'Mumbai', NULL, '2024-01-31', NULL, '2026-01-31', 'Backup', '2.5 inch caddy ', '2025-10-15 08:58:45', '2025-11-06 10:48:32'),
(12, 'invalid-test-uuid-999', 'CDY99999', 1, NULL, 'Test', 'Test', '2024-01-01', NULL, '2026-01-01', 'Test', 'Testing invalid UUID', '2025-10-15 11:16:03', '2025-10-15 11:16:03'),
(14, '00000000-0000-0000-0000-000000000000', 'TEST12345', 1, NULL, 'Test', 'A1', '2024-01-01', NULL, '2026-01-01', 'Test', 'Invalid UUID test', '2025-10-15 11:25:11', '2025-10-15 11:25:11'),
(16, 'd7f1a3b5-8e4c-4f9d-9a2b-3c5d6e7f8a9', 'CDY00989', 1, NULL, '', NULL, '2024-01-31', NULL, '2026-01-31', 'Backup', '3.5 inch caddy ', '2025-10-15 11:45:15', '2025-10-27 20:04:24'),
(17, 'ffffffff-ffff-ffff-ffff-ffffffffffff', 'TESTINVALID001', 1, NULL, 'TestLocation', 'TestRack', '2024-01-01', NULL, '2026-01-01', 'TestFlag', 'Testing invalid UUID blocking', '2025-10-15 11:52:10', '2025-10-15 11:52:10'),
(18, '99999999-9999-9999-9999-999999999999', 'SHOULDBLOCK001', 1, NULL, 'TestBlocking', 'TestRack', '2024-01-01', NULL, '2026-01-01', 'TestBlock', 'This UUID does NOT exist in caddy JSON - should be BLOCKED', '2025-10-15 11:54:03', '2025-10-15 11:54:03'),
(19, 'Universal 2.5-inch HDD/SSD Caddy', 'after-fix-caddy', 2, 'null', 'Indore', 'null', '2025-10-25', NULL, '2025-10-08', 'Backup', 'Universal 2.5-inch HDD/SSD Caddy', '2025-10-25 07:58:44', '2025-10-25 07:58:44');

-- --------------------------------------------------------

--
-- Table structure for table `chassisinventory`
--

CREATE TABLE `chassisinventory` (
  `ID` int(11) NOT NULL,
  `UUID` varchar(50) NOT NULL COMMENT 'Links to detailed specs in JSON',
  `SerialNumber` varchar(50) DEFAULT NULL,
  `Status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=Failed, 1=Available, 2=In Use',
  `ServerUUID` varchar(36) DEFAULT NULL COMMENT 'Server where chassis is installed',
  `Location` varchar(100) DEFAULT NULL,
  `RackPosition` varchar(20) DEFAULT NULL,
  `PurchaseDate` date DEFAULT NULL,
  `InstallationDate` date DEFAULT NULL,
  `WarrantyEndDate` date DEFAULT NULL,
  `Flag` varchar(50) DEFAULT NULL,
  `Notes` text DEFAULT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `chassisinventory`
--

INSERT INTO `chassisinventory` (`ID`, `UUID`, `SerialNumber`, `Status`, `ServerUUID`, `Location`, `RackPosition`, `PurchaseDate`, `InstallationDate`, `WarrantyEndDate`, `Flag`, `Notes`, `CreatedAt`, `UpdatedAt`) VALUES
(1, 'sm-sc846be1c-r90jbod-001', 'SUP-SC846B-001234', 1, NULL, '', NULL, '2024-03-15', NULL, '2027-03-15', 'Available', '{\"brand\": \"Supermicro\", \"model\": \"SC846BE1C-R90JBOD\", \"series\": \"SuperChassis 4U\", \"form_factor\": \"4U\", \"chassis_type\": \"Storage Server\", \"drive_bays\": 24, \"backplane_interface\": \"SAS3\"}', '2025-09-17 05:46:06', '2025-11-06 12:47:21'),
(2, 'dell-r740-chassis-001', 'DEL-R740CH-001237', 2, '214100e3-c7cd-4f01-8c27-eca2310b0bbb', '', '', '2024-01-10', '2025-11-06', '2027-01-10', 'In Use', '{\"brand\": \"Dell\", \"model\": \"PowerEdge R740\", \"series\": \"PowerEdge R-Series\", \"form_factor\": \"2U\", \"chassis_type\": \"Rack Server\", \"drive_bays\": 16, \"backplane_interface\": \"SAS3\"}', '2025-09-17 05:46:06', '2025-11-06 12:52:49'),
(3, 'hpe-dl380-gen10-001', 'HPE-DL380G-001240', 1, NULL, '', NULL, '2024-06-01', NULL, '2027-06-01', 'Ready', '{\"brand\": \"HPE\", \"model\": \"ProLiant DL380 Gen10\", \"series\": \"ProLiant DL-Series\", \"form_factor\": \"2U\", \"chassis_type\": \"Rack Server\", \"drive_bays\": 12, \"backplane_interface\": \"SAS3\"}', '2025-09-17 05:46:06', '2025-10-27 20:04:24'),
(4, '6fa0bd31-bb99-4d1c-9191-e3cd04a85da4', 'after-fix-chassis', 2, 'null', 'Indore', 'null', '2025-10-25', NULL, '2025-10-20', 'Backup', 'chassis', '2025-10-25 07:59:16', '2025-10-25 07:59:16'),
(5, 'abaa2c58-c08c-46f0-abcf-2242400e907c', NULL, 1, 'null', NULL, 'null', NULL, NULL, NULL, 'Backup', 'chassis', '2025-10-25 21:32:29', '2025-10-25 21:32:29'),
(6, 'f0bb3152-1157-40d4-9547-5a1654cd29dd', NULL, 1, 'null', NULL, 'null', NULL, NULL, NULL, 'Backup', 'chassis', '2025-10-25 21:32:29', '2025-10-25 21:32:29'),
(7, '291647ea-b780-47e6-a8b9-3086f814129e', 'Shubham-Test-Server', 2, 'null', 'Indore', 'null', '2025-10-26', NULL, '0000-00-00', 'Backup', 'chassis', '2025-10-26 05:37:02', '2025-10-26 05:37:02'),
(8, '037bd515-19a7-47ab-8832-40fe388f65aa', 'Shubham-Test-Server', 2, 'null', 'Indore', 'null', '2025-10-26', NULL, '0000-00-00', 'Backup', 'chassis', '2025-10-26 05:37:02', '2025-10-26 05:37:02'),
(9, 'sm-sc113tq-r700cb-001', 'Shubham-Test-Server-Full-Done', 2, 'null', 'Indore', 'null', '2025-10-26', NULL, '2025-10-01', 'Backup', 'chassis', '2025-10-26 06:01:55', '2025-10-26 06:01:55');

-- --------------------------------------------------------

--
-- Table structure for table `compatibility_log`
--

CREATE TABLE `compatibility_log` (
  `id` int(11) NOT NULL,
  `session_id` varchar(64) DEFAULT NULL COMMENT 'Session identifier for grouping related operations',
  `operation_type` varchar(50) NOT NULL COMMENT 'Type of operation (check, validate, build, etc.)',
  `component_type_1` varchar(50) DEFAULT NULL,
  `component_uuid_1` varchar(36) DEFAULT NULL,
  `component_type_2` varchar(50) DEFAULT NULL,
  `component_uuid_2` varchar(36) DEFAULT NULL,
  `compatibility_result` tinyint(1) DEFAULT NULL COMMENT 'Result of compatibility check',
  `compatibility_score` decimal(3,2) DEFAULT NULL COMMENT 'Compatibility score result',
  `applied_rules` longtext DEFAULT NULL COMMENT 'JSON array of rules that were applied',
  `execution_time_ms` int(11) DEFAULT NULL COMMENT 'Execution time in milliseconds',
  `user_id` int(6) UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Audit log for compatibility operations';

--
-- Dumping data for table `compatibility_log`
--

INSERT INTO `compatibility_log` (`id`, `session_id`, `operation_type`, `component_type_1`, `component_uuid_1`, `component_type_2`, `component_uuid_2`, `compatibility_result`, `compatibility_score`, `applied_rules`, `execution_time_ms`, `user_id`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, NULL, 'status_change', NULL, 'ebfcf9d1-0913-47db-a935-2c854093f63a', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-08-24 23:01:53'),
(2, NULL, 'status_change', NULL, '9be5cfff-066d-4adf-82a6-2d6f93585ae5', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-10-14 22:09:54'),
(3, NULL, 'status_change', NULL, '9be5cfff-066d-4adf-82a6-2d6f93585ae5', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-10-15 08:35:08'),
(4, NULL, 'status_change', NULL, 'ebfcf9d1-0913-47db-a935-2c854093f63a', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-10-15 08:39:32'),
(5, NULL, 'status_change', NULL, 'ebfcf9d1-0913-47db-a935-2c854093f63a', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-10-15 08:39:36'),
(6, NULL, 'status_change', NULL, 'f2cadb5f-e31d-42f5-9cc6-449666d96e5a', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-11-04 17:57:25'),
(7, NULL, 'status_change', NULL, '3e943748-cb56-4c35-8cd1-be1e7ff07893', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-11-06 11:04:06'),
(8, NULL, 'status_change', NULL, '8225144d-b847-4722-bc2b-aa4f784ba02b', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-11-06 11:29:03'),
(9, NULL, 'status_change', NULL, '214100e3-c7cd-4f01-8c27-eca2310b0bbb', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-11-06 12:55:07');

-- --------------------------------------------------------

--
-- Table structure for table `compatibility_rules`
--

CREATE TABLE `compatibility_rules` (
  `id` int(11) NOT NULL,
  `rule_name` varchar(100) NOT NULL COMMENT 'Human-readable rule name',
  `rule_type` varchar(50) NOT NULL COMMENT 'Type of rule (socket, interface, power, etc.)',
  `component_types` varchar(255) NOT NULL COMMENT 'Comma-separated list of component types this rule applies to',
  `rule_definition` longtext NOT NULL COMMENT 'JSON rule definition',
  `rule_priority` int(11) NOT NULL DEFAULT 100 COMMENT 'Rule priority (lower = higher priority)',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Whether the rule is active',
  `is_override_allowed` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Whether admin can override this rule',
  `failure_message` text DEFAULT NULL COMMENT 'Message to show when rule fails',
  `created_by` int(6) UNSIGNED DEFAULT NULL,
  `updated_by` int(6) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Business rules for component compatibility';

--
-- Dumping data for table `compatibility_rules`
--

INSERT INTO `compatibility_rules` (`id`, `rule_name`, `rule_type`, `component_types`, `rule_definition`, `rule_priority`, `is_active`, `is_override_allowed`, `failure_message`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, 'CPU-Motherboard Socket Match', 'socket', 'cpu,motherboard', '{\"rule\": \"socket_match\", \"description\": \"CPU and motherboard must have matching socket types\", \"validation\": {\"method\": \"json_path_match\", \"cpu_field\": \"socket.type\", \"motherboard_field\": \"socket.type\"}}', 1, 1, 0, 'CPU socket type must match motherboard socket type for compatibility', NULL, NULL, '2025-08-04 12:55:37', '2025-08-06 02:38:05'),
(2, 'Memory Type Compatibility', 'memory', 'motherboard,ram', '{\"rule\": \"memory_type_support\", \"description\": \"RAM type must be supported by motherboard\", \"validation\": {\"method\": \"array_contains\", \"motherboard_field\": \"memory.supported_types\", \"ram_field\": \"type\"}}', 2, 1, 0, 'Memory type not supported by this motherboard', NULL, NULL, '2025-08-04 12:55:37', '2025-08-06 02:38:26'),
(3, 'Memory Speed Compatibility', 'memory', 'cpu,motherboard,ram', '{\"rule\": \"memory_speed_check\", \"description\": \"Memory speed must be supported by CPU and motherboard\", \"json_path\": {\"motherboard\": \"memory.max_frequency_MHz\", \"ram\": \"frequency_mhz\"}}', 3, 1, 0, 'Memory speed exceeds CPU or motherboard limits', NULL, NULL, '2025-08-04 12:55:37', '2025-08-04 12:55:37'),
(4, 'Storage Interface Compatibility', 'interface', 'motherboard,storage', '{\"rule\": \"storage_interface_check\", \"description\": \"Storage interface must be available on motherboard\", \"json_path\": {\"motherboard\": \"storage\", \"storage\": \"interface\"}}', 4, 1, 0, 'Storage interface not available on motherboard', NULL, NULL, '2025-08-04 12:55:37', '2025-08-04 12:55:37'),
(5, 'PCIe Slot Availability', 'interface', 'motherboard,nic', '{\"rule\": \"pcie_slot_check\", \"description\": \"Sufficient PCIe slots must be available\", \"json_path\": {\"motherboard\": \"expansion_slots.pcie_slots\", \"nic\": \"pcie_requirements\"}}', 5, 1, 0, 'Insufficient PCIe slots available on motherboard', NULL, NULL, '2025-08-04 12:55:37', '2025-08-04 12:55:37'),
(6, 'Power Consumption Check', 'power', 'cpu,motherboard,ram,storage,nic', '{\"rule\": \"power_budget_check\", \"description\": \"Total power consumption within limits\", \"json_path\": {\"cpu\": \"tdp_watts\", \"ram\": \"power_consumption\", \"storage\": \"power_consumption_watts\"}}', 10, 1, 0, 'Total power consumption exceeds system limits', NULL, NULL, '2025-08-04 12:55:37', '2025-08-04 12:55:37');

-- --------------------------------------------------------

--
-- Table structure for table `component_compatibility`
--

CREATE TABLE `component_compatibility` (
  `id` int(11) NOT NULL,
  `component_type_1` varchar(50) NOT NULL COMMENT 'First component type (cpu, motherboard, ram, etc.)',
  `component_uuid_1` varchar(36) NOT NULL COMMENT 'UUID of first component',
  `component_type_2` varchar(50) NOT NULL COMMENT 'Second component type',
  `component_uuid_2` varchar(36) NOT NULL COMMENT 'UUID of second component',
  `compatibility_status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=Compatible, 0=Incompatible',
  `compatibility_score` decimal(3,2) DEFAULT 1.00 COMMENT 'Compatibility score (0.00-1.00)',
  `compatibility_notes` text DEFAULT NULL COMMENT 'Additional compatibility information',
  `validation_rules` longtext DEFAULT NULL COMMENT 'JSON rules that determine compatibility',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Master compatibility matrix for all components';

-- --------------------------------------------------------

--
-- Table structure for table `component_specifications`
--

CREATE TABLE `component_specifications` (
  `id` int(11) NOT NULL,
  `component_uuid` varchar(36) NOT NULL,
  `component_type` varchar(20) NOT NULL,
  `specification_key` varchar(100) NOT NULL COMMENT 'socket_type, memory_type, form_factor, etc.',
  `specification_value` text NOT NULL,
  `data_type` varchar(20) NOT NULL DEFAULT 'string' COMMENT 'string, integer, decimal, boolean, json',
  `is_searchable` tinyint(1) NOT NULL DEFAULT 1,
  `is_comparable` tinyint(1) NOT NULL DEFAULT 1,
  `unit` varchar(20) DEFAULT NULL COMMENT 'MHz, GB, W, etc.',
  `source` varchar(100) DEFAULT NULL COMMENT 'manufacturer, manual, specification_sheet, etc.',
  `confidence_level` tinyint(1) NOT NULL DEFAULT 5 COMMENT '1-10 confidence in accuracy',
  `last_verified` timestamp NULL DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `component_usage_tracking`
--

CREATE TABLE `component_usage_tracking` (
  `id` int(11) NOT NULL,
  `component_uuid` varchar(36) NOT NULL,
  `component_type` varchar(20) NOT NULL,
  `config_uuid` varchar(36) DEFAULT NULL COMMENT 'NULL if not assigned to configuration',
  `deployment_uuid` varchar(36) DEFAULT NULL COMMENT 'NULL if not deployed',
  `usage_status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0=Available, 1=Reserved, 2=In Use, 3=Maintenance, 4=Failed, 5=Retired',
  `assigned_at` timestamp NULL DEFAULT NULL,
  `released_at` timestamp NULL DEFAULT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `released_by` int(11) DEFAULT NULL,
  `usage_purpose` varchar(255) DEFAULT NULL,
  `expected_duration` int(11) DEFAULT NULL COMMENT 'Expected usage duration in days',
  `actual_duration` int(11) DEFAULT NULL COMMENT 'Actual usage duration in days',
  `performance_notes` text DEFAULT NULL,
  `failure_reason` text DEFAULT NULL,
  `maintenance_required` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cpuinventory`
--

CREATE TABLE `cpuinventory` (
  `ID` int(11) NOT NULL,
  `UUID` varchar(50) NOT NULL COMMENT 'Links to detailed specs in JSON',
  `SerialNumber` varchar(50) DEFAULT NULL COMMENT 'Manufacturer serial number',
  `Status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=Failed/Decommissioned, 1=Available, 2=In Use',
  `ServerUUID` varchar(36) DEFAULT NULL COMMENT 'UUID of server where CPU is installed, if any',
  `Location` varchar(100) DEFAULT NULL COMMENT 'Physical location like datacenter, warehouse',
  `RackPosition` varchar(20) DEFAULT NULL COMMENT 'Specific rack/shelf position',
  `PurchaseDate` date DEFAULT NULL,
  `InstallationDate` date DEFAULT NULL COMMENT 'When installed in current server',
  `WarrantyEndDate` date DEFAULT NULL,
  `Flag` varchar(50) DEFAULT NULL COMMENT 'Quick status flag or category',
  `Notes` text DEFAULT NULL COMMENT 'Any additional info or history',
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cpuinventory`
--

INSERT INTO `cpuinventory` (`ID`, `UUID`, `SerialNumber`, `Status`, `ServerUUID`, `Location`, `RackPosition`, `PurchaseDate`, `InstallationDate`, `WarrantyEndDate`, `Flag`, `Notes`, `CreatedAt`, `UpdatedAt`) VALUES
(1, 'd93a4790-959d-4cd4-8e95-bb1b9c85b9fd', 'CPU123456', 1, NULL, 'Datacenter North', 'Rack A3-12', '2023-05-15', '2023-06-01', '2026-05-15', 'Production', 'Intel Xeon 8-core 3.2GHz', '2025-05-11 11:42:52', '2025-10-15 08:32:47'),
(2, '41849749-8d19-4366-b41a-afda6fa46b58', 'CPU789012', 1, NULL, 'Warehouse East', NULL, '2024-01-10', NULL, '2027-01-10', 'Backup', 'AMD EPYC 16-core 2.9GHz', '2025-05-11 11:42:52', '2025-10-15 08:40:32'),
(24, '545e143b-57b3-419e-86e5-1df6f7aa8fd3', 'CPU999999', 2, '214100e3-c7cd-4f01-8c27-eca2310b0bbb', '', '', '2025-08-29', '2025-11-07', '2030-12-20', 'Backup', 'Cpu - Platinum 8480+', '2025-08-29 13:59:05', '2025-11-07 13:18:57'),
(26, '545e143b-57b3-419e-86e5-1df6f7aa8fxx', 'CPU111111', 2, '214100e3-c7cd-4f01-8c27-eca2310b0bbb', '', '', '2024-01-31', '2025-11-07', '2026-01-31', 'Backup', 'Intel 8470', '2025-09-04 23:33:55', '2025-11-07 11:47:31'),
(27, 'd3b5f1c2-9f4e-4c2a-8e6b-7a9f3e2d1c4b', 'CPU000000', 1, NULL, '', NULL, '2024-01-31', NULL, '2026-01-31', 'Backup', 'AMD EPYC 9374F', '2025-09-04 23:41:35', '2025-11-18 14:05:53'),
(28, '80aeb1cd-dd2d-4f86-86b7-04237b56376f', 'Shubham-Test-Server', 2, 'null', 'Indore', 'null', '2025-10-24', NULL, '2025-11-12', 'Backup', 'TESt - Platinum 8470', '2025-10-24 17:38:46', '2025-10-24 17:38:46'),
(30, '067737c6-4786-487e-9127-c75fc030c408', 'after-fix-cpu', 2, 'null', 'Indore', 'null', '2025-10-25', NULL, '2025-10-21', 'Backup', 'Test - Platinum 8480+', '2025-10-25 07:48:09', '2025-10-25 07:48:09'),
(36, 'd3b5f1c2-9f4e-4c2a-8e6b-7a9f3e2d1c4b', 'CPU1009854', 2, '4841e506-6e58-46d2-ab77-6fa91996561c', '', '', '2025-11-06', '2025-11-13', '2028-11-08', 'null', 'Brand: AMD, Series: EPYC, Model: EPYC 9374F', '2025-11-06 13:58:32', '2025-11-13 13:15:31'),
(38, 'd3b5f1c2-9f4e-4c2a-8e6b-7a9f3e2d1c4b', 'AMD13241551', 2, '4841e506-6e58-46d2-ab77-6fa91996561c', '', '', '2025-11-07', '2025-11-13', '2028-10-16', 'null', 'Brand: AMD, Series: EPYC, Model: EPYC 9374F', '2025-11-07 07:54:55', '2025-11-13 13:15:46');

-- --------------------------------------------------------

--
-- Table structure for table `hbacardinventory`
--

CREATE TABLE `hbacardinventory` (
  `ID` int(11) NOT NULL,
  `UUID` varchar(50) NOT NULL COMMENT 'Links to detailed specs in JSON',
  `SerialNumber` varchar(50) DEFAULT NULL COMMENT 'Manufacturer serial number',
  `Status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=Failed/Decommissioned, 1=Available, 2=In Use',
  `ServerUUID` varchar(36) DEFAULT NULL COMMENT 'UUID of server where HBA card is installed, if any',
  `Location` varchar(100) DEFAULT NULL COMMENT 'Physical location like datacenter, warehouse',
  `RackPosition` varchar(20) DEFAULT NULL COMMENT 'Specific rack/shelf position',
  `PurchaseDate` date DEFAULT NULL,
  `InstallationDate` date DEFAULT NULL COMMENT 'When installed in current server',
  `WarrantyEndDate` date DEFAULT NULL,
  `Flag` varchar(50) DEFAULT NULL COMMENT 'Quick status flag or category',
  `Notes` text DEFAULT NULL COMMENT 'Any additional info or history',
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `hbacardinventory`
--

INSERT INTO `hbacardinventory` (`ID`, `UUID`, `SerialNumber`, `Status`, `ServerUUID`, `Location`, `RackPosition`, `PurchaseDate`, `InstallationDate`, `WarrantyEndDate`, `Flag`, `Notes`, `CreatedAt`, `UpdatedAt`) VALUES
(1, 'hba-9400-16i-001-abcd1234', NULL, 1, NULL, '', NULL, '2024-03-15', NULL, '2027-03-15', NULL, 'Broadcom HBA 9400-16i, 16-port, PCIe 3.0 x8', '2025-09-27 15:39:02', '2025-10-15 16:51:49'),
(2, 'hba-9400-8i-001-efgh5678', NULL, 1, NULL, 'Mumbai', NULL, '2024-03-20', NULL, '2027-03-20', NULL, 'Broadcom HBA 9400-8i, 8-port, PCIe 3.0 x8', '2025-09-27 15:39:02', '2025-11-04 08:24:18'),
(19, 'hba-9400-16e-001-ijkl9012', 'BC9400-16E-001', 1, NULL, 'Datacenter B, Rack A3', 'Shelf 12', '2024-02-10', NULL, '2027-02-10', NULL, 'Broadcom HBA 9400-16e, 16-port external, PCIe 3.0 x8, 12Gb/s SAS', '2025-10-22 06:27:09', '2025-10-22 06:27:09'),
(20, 'hba-9400-8e-001-mnop3456', 'BC9400-8E-003', 1, NULL, 'Datacenter B, Rack D1', 'Storage Unit 3', '2024-04-05', NULL, '2027-04-05', NULL, 'Broadcom HBA 9400-8e, 8-port external, PCIe 3.0 x8, 12Gb/s SAS', '2025-10-22 06:27:09', '2025-10-22 06:27:09'),
(21, 'hba-9500-16i-001-qrst7890', 'BC9500-16I-001', 2, '214100e3-c7cd-4f01-8c27-eca2310b0bbb', '', '', '2024-05-12', '2025-11-06', '2027-05-12', NULL, 'Broadcom HBA 9500-16i Tri-Mode, 16-port, PCIe 4.0 x8, SAS/SATA/NVMe', '2025-10-22 06:27:09', '2025-11-06 12:53:18'),
(22, 'hba-9500-8i-001-uvwx4567', 'BC9500-8I-002', 1, NULL, 'Datacenter C, Rack B2', NULL, '2024-06-18', NULL, '2027-06-18', NULL, 'Broadcom HBA 9500-8i Tri-Mode, 8-port, PCIe 4.0 x8, SAS/SATA/NVMe', '2025-10-22 06:27:09', '2025-11-06 10:51:52'),
(23, 'hba-9300-16i-001-yzab8901', 'BC9300-16I-004', 1, NULL, 'Datacenter A, Rack C1', 'Storage Unit 7', '2023-11-22', NULL, '2026-11-22', NULL, 'Broadcom HBA 9300-16i, 16-port internal, PCIe 3.0 x8, 12Gb/s SAS', '2025-10-22 06:27:09', '2025-10-22 06:27:09'),
(24, 'hba-9300-8i-001-cdef2345', 'BC9300-8I-005', 1, NULL, 'Datacenter B, Rack B3', 'Server Bay 2', '2023-12-05', NULL, '2026-12-05', NULL, 'Broadcom HBA 9300-8i, 8-port internal, PCIe 3.0 x8, 12Gb/s SAS', '2025-10-22 06:27:09', '2025-10-22 06:27:09'),
(25, 'hba-9300-16e-001-ghij6789', 'BC9300-16E-002', 0, NULL, 'Warehouse A', 'Bin 45', '2023-09-14', NULL, '2026-09-14', 'RMA', 'Broadcom HBA 9300-16e, 16-port external - Failed, awaiting RMA', '2025-10-22 06:27:09', '2025-10-22 06:27:09'),
(26, 'hba-9300-8e-001-klmn0123', 'BC9300-8E-006', 1, NULL, 'Datacenter C, Rack A2', 'Storage Unit 11', '2023-10-30', NULL, '2026-10-30', NULL, 'Broadcom HBA 9300-8e, 8-port external, PCIe 3.0 x8, 12Gb/s SAS', '2025-10-22 06:27:09', '2025-10-22 06:27:09'),
(27, 'smarthba-2100-8i-001-opqr4567', 'MC2100-8I-001', 1, NULL, 'Datacenter A, Rack D2', 'Server Bay 6', '2024-02-28', NULL, '2027-02-28', NULL, 'Microchip SmartHBA 2100-8i, 8-port internal, PCIe 3.0 x8, SAS/SATA', '2025-10-22 06:27:09', '2025-10-22 06:27:09'),
(28, 'smarthba-2100-4i-001-stuv8901', 'MC2100-4I-003', 1, NULL, 'Datacenter B, Rack C1', 'Shelf 5', '2024-03-15', NULL, '2027-03-15', NULL, 'Microchip SmartHBA 2100-4i, 4-port internal, PCIe 3.0 x8, SAS/SATA', '2025-10-22 06:27:09', '2025-10-22 06:27:09'),
(29, 'hba-9600-16i-001-wxyz2345', 'BC9600-16I-001', 1, NULL, 'Datacenter C, Rack A1', 'Server Bay 1', '2024-07-10', NULL, '2027-07-10', NULL, 'Broadcom HBA 9600-16i Tri-Mode, 16-port, PCIe 4.0 x16, 24Gb/s SAS', '2025-10-22 06:27:09', '2025-10-22 06:27:09'),
(30, 'hba-9600-8i-001-abcd6789', 'BC9600-8I-002', 1, NULL, 'Datacenter A, Rack B4', 'Storage Unit 14', '2024-08-22', NULL, '2027-08-22', NULL, 'Broadcom HBA 9600-8i Tri-Mode, 8-port, PCIe 4.0 x8, 24Gb/s SAS', '2025-10-22 06:27:09', '2025-10-22 06:27:09'),
(31, 'atto-h1280-001-efgh0123', 'ATTO-H1280-001', 1, NULL, 'Datacenter B, Rack D3', 'Server Bay 8', '2024-04-20', NULL, '2027-04-20', NULL, 'ATTO ExpressSAS H1280, 16-port internal, PCIe 4.0 x8, 12Gb/s SAS/SATA', '2025-10-22 06:27:09', '2025-10-22 06:27:09'),
(32, 'atto-h680-001-ijkl4567', 'ATTO-H680-002', 1, '', 'Datacenter C, Rack C2', 'Shelf 9', '2024-05-30', '0000-00-00', '2027-05-30', '', 'ATTO ExpressSAS H680, 8-port internal, PCIe 3.0 x8, 12Gb/s SAS/SATA', '2025-10-22 06:27:09', '2025-10-26 15:14:42');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_log`
--

CREATE TABLE `inventory_log` (
  `id` int(11) NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `component_type` varchar(50) DEFAULT NULL,
  `component_id` int(11) DEFAULT NULL,
  `action` varchar(100) DEFAULT NULL,
  `old_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_data`)),
  `new_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_data`)),
  `notes` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `inventory_log`
--

INSERT INTO `inventory_log` (`id`, `user_id`, `component_type`, `component_id`, `action`, `old_data`, `new_data`, `notes`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 37, 'auth', 37, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-25 01:29:56'),
(2, 37, 'auth', 37, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-25 01:43:06'),
(3, 37, 'auth', 37, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-25 01:45:19'),
(4, 37, 'auth', 37, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-25 02:41:20'),
(5, 37, 'auth', 37, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-25 03:57:32'),
(6, 37, 'auth', 37, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-25 07:00:57'),
(7, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-25 07:06:47'),
(8, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-25 07:12:06'),
(9, 38, 'user_management', 37, 'Role assigned', NULL, NULL, 'Assigned role 2 to user 37', '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-25 07:18:52'),
(10, 38, 'cpu', 16, 'Component created', NULL, '{\"UUID\":\"450437dc-af82-4d56-879c-6f341373a8b9\",\"SerialNumber\":\"CPU789089\",\"Status\":\"1\",\"ServerUUID\":\"\",\"Location\":\" Warehouse East\",\"RackPosition\":\"\",\"PurchaseDate\":null,\"WarrantyEndDate\":null,\"Flag\":\"Backup\",\"Notes\":\"\"}', 'Created new cpu component', '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-25 07:49:41'),
(11, 38, 'cpu', 16, 'Component deleted', '{\"ID\":16,\"UUID\":\"450437dc-af82-4d56-879c-6f341373a8b9\",\"SerialNumber\":\"CPU789089\",\"Status\":1,\"ServerUUID\":\"\",\"Location\":\" Warehouse East\",\"RackPosition\":\"\",\"PurchaseDate\":null,\"InstallationDate\":null,\"WarrantyEndDate\":null,\"Flag\":\"Backup\",\"Notes\":\"\",\"CreatedAt\":\"2025-07-25 07:49:41\",\"UpdatedAt\":\"2025-07-25 07:49:41\"}', NULL, 'Deleted cpu component', '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-25 07:50:31'),
(12, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Mobile Safari/537.36', '2025-07-25 08:08:35'),
(13, 25, 'auth', 25, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-25 14:39:09'),
(14, 25, 'auth', 25, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Mobile Safari/537.36', '2025-07-25 14:39:38'),
(15, 25, 'auth', 25, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Mobile Safari/537.36', '2025-07-25 14:40:09'),
(16, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Mobile Safari/537.36', '2025-07-25 17:35:05'),
(17, 38, 'auth', 38, 'User logout', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-25 17:53:36'),
(18, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-25 17:53:52'),
(19, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-25 19:44:23'),
(20, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-25 19:51:05'),
(21, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-25 19:53:18'),
(22, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-26 07:19:14'),
(23, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-26 07:26:34'),
(24, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-26 08:44:47'),
(25, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-26 18:02:09'),
(26, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-26 18:06:16'),
(27, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-26 18:06:30'),
(28, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-26 18:15:16'),
(29, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-26 18:16:18'),
(30, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-26 18:16:57'),
(31, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-26 18:22:43'),
(32, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-26 18:26:19'),
(33, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-26 18:34:34'),
(34, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-26 18:37:25'),
(35, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-26 23:25:45'),
(36, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-26 23:29:31'),
(37, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-26 23:42:11'),
(38, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 01:06:06'),
(39, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 02:47:43'),
(40, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-27 02:49:31'),
(41, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 03:03:24'),
(42, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 03:35:58'),
(43, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 03:36:59'),
(44, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 03:39:39'),
(45, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 03:42:38'),
(46, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 03:48:21'),
(47, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 03:49:14'),
(48, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 03:50:22'),
(49, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 03:57:12'),
(50, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 04:05:54'),
(51, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 04:39:56'),
(52, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 05:06:46'),
(53, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 05:14:34'),
(54, 38, 'cpu', 15, 'Component deleted', '{\"ID\":15,\"UUID\":\"545e143b-57b3-419e-86e5-1df6f7aa8fy9\",\"SerialNumber\":\"CPU789032\",\"Status\":2,\"ServerUUID\":\"\",\"Location\":\" Warehouse East\",\"RackPosition\":\"Shelf B4\",\"PurchaseDate\":\"2024-01-31\",\"InstallationDate\":null,\"WarrantyEndDate\":\"2026-01-31\",\"Flag\":\"Backup\",\"Notes\":\"AMD EPYC 64-core 2.9GHz\",\"CreatedAt\":\"2025-07-22 19:50:05\",\"UpdatedAt\":\"2025-07-22 19:50:05\"}', NULL, 'Deleted cpu component', '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 05:36:24'),
(55, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 07:02:23'),
(56, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 08:03:02'),
(57, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 08:05:39'),
(58, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 08:37:10'),
(59, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 08:55:53'),
(60, 38, 'cpu', 17, 'Component created', NULL, '{\"UUID\":\"139e9bcd-ac86-44e9-8e9b-3178e3be1fb8\",\"SerialNumber\":\"CPU789060\",\"Status\":\"1\",\"ServerUUID\":\"null\",\"Location\":\"New Delhi, Delhi\",\"RackPosition\":\"Rack Z10\",\"PurchaseDate\":\"2025-07-28\",\"WarrantyEndDate\":\"2027-12-02\",\"Flag\":\"Backup\",\"Notes\":\"EPYC 9534 AMD CPU\"}', 'Created new cpu component', '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 08:57:02'),
(61, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 13:51:06'),
(62, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 14:05:07'),
(63, 38, 'storage', 4, 'Component created', NULL, '{\"UUID\":\"43e1ad0d-cf4a-49c9-a750-b50f73e773f7\",\"SerialNumber\":\"HDD789098\",\"Status\":\"1\",\"ServerUUID\":\"null\",\"Location\":\"New Delhi, Delhi\",\"RackPosition\":\"Rack Z9\",\"PurchaseDate\":\"2025-07-30\",\"WarrantyEndDate\":\"2029-10-25\",\"Flag\":\"Backup\",\"Notes\":\"Type: HDD, Capacity: 960GB\\n\\nAdditional Notes: crucial nvme gen 4 \"}', 'Created new storage component', '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 14:06:01'),
(64, 38, 'motherboard', 4, 'Component created', NULL, '{\"UUID\":\"18527f82-7f18-4148-9cb8-7449b1e3cadf\",\"SerialNumber\":\"MOT2323882\",\"Status\":\"1\",\"ServerUUID\":\"null\",\"Location\":\"New Delhi India\",\"RackPosition\":\"Rack Z10\",\"PurchaseDate\":\"2025-07-29\",\"WarrantyEndDate\":\"2029-11-15\",\"Flag\":\"Backup\",\"Notes\":\"Brand: GIGABYTE, Series: MZ, Model: MZ93-FS0\\n\\nAdditional Notes: gigabyte motherboard z790 godlike\"}', 'Created new motherboard component', '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 14:08:05'),
(65, 38, 'motherboard', 5, 'Component created', NULL, '{\"UUID\":\"67c845a3-d827-47d1-8441-0639ef10391b\",\"SerialNumber\":\"MB345688\",\"Status\":\"1\",\"ServerUUID\":\"null\",\"Location\":\"Banglore\",\"RackPosition\":\"Rack Z10\",\"PurchaseDate\":\"2025-07-28\",\"WarrantyEndDate\":\"2025-08-02\",\"Flag\":\"Backup\",\"Notes\":\"Brand: Supermicro, Series: X13, Model: X13DRi-N\\n\\nAdditional Notes: gigabyte godlike z790 \"}', 'Created new motherboard component', '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 14:09:43'),
(66, 38, 'caddy', 4, 'Component created', NULL, '{\"UUID\":\"505c1ec9-35cc-4da9-b555-b7d15c0d9d06\",\"SerialNumber\":\"CDY789082\",\"Status\":\"1\",\"ServerUUID\":\"null\",\"Location\":\"Himachal\",\"RackPosition\":\"Rack Z5\",\"PurchaseDate\":\"2025-07-29\",\"WarrantyEndDate\":\"2025-07-17\",\"Flag\":\"Critical\",\"Notes\":\"Type: 3.5 Inch\\n\\nAdditional Notes: new caddy\"}', 'Created new caddy component', '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 14:11:44'),
(67, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 15:34:32'),
(68, 38, 'auth', 38, 'User logout', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 15:50:36'),
(69, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 15:53:03'),
(70, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-27 16:00:05'),
(71, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 20:06:04'),
(72, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 00:41:56'),
(73, 38, 'auth', 38, 'User logout', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 00:47:35'),
(74, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 00:47:42'),
(75, 38, 'cpu', 17, 'Component updated', '{\"Status\":1,\"ServerUUID\":\"null\",\"Location\":\"New Delhi, Delhi\",\"RackPosition\":\"Rack Z10\",\"Flag\":\"Backup\",\"Notes\":\"EPYC 9534 AMD CPU\"}', '{\"Status\":\"2\",\"Notes\":\"EPYC 9534 AMD CPUsss\",\"Location\":\"New Delhi, Delhi\",\"RackPosition\":\"Rack Z10\",\"Flag\":\"Backup\",\"ServerUUID\":\"null\"}', 'Updated cpu component', '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 00:48:05'),
(76, 38, 'motherboard', 5, 'Component updated', '{\"Status\":1,\"ServerUUID\":\"null\",\"Location\":\"Banglore\",\"RackPosition\":\"Rack Z10\",\"Flag\":\"Backup\",\"Notes\":\"Brand: Supermicro, Series: X13, Model: X13DRi-N\\n\\nAdditional Notes: gigabyte godlike z790 \"}', '{\"Status\":\"2\",\"Notes\":\"Brand: Supermicro, Series: X13, Model: X13DRi-N good motherboard\\r\\n\\r\\nAdditional Notes: gigabyte godlike z790 \",\"Location\":\"Banglore\",\"RackPosition\":\"Rack Z10\",\"Flag\":\"Backup\",\"ServerUUID\":\"null\"}', 'Updated motherboard component', '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 00:48:55'),
(77, 38, 'auth', 38, 'User logout', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 01:22:01'),
(78, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 01:23:43'),
(79, 38, 'auth', 38, 'User logout', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 01:23:51'),
(80, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 01:24:58'),
(81, 38, 'auth', 38, 'User logout', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 01:25:05'),
(82, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 01:26:27'),
(83, 38, 'auth', 38, 'User logout', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 01:26:35'),
(84, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 04:19:25'),
(85, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 04:42:33'),
(86, 38, 'auth', 38, 'User logout', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 04:42:54'),
(87, 38, 'auth', 38, 'User logout', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 04:44:20'),
(88, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 04:44:32'),
(89, 38, 'cpu', 17, 'Component updated', '{\"Status\":2,\"ServerUUID\":\"null\",\"Location\":\"New Delhi, Delhi\",\"RackPosition\":\"Rack Z10\",\"Flag\":\"Backup\",\"Notes\":\"EPYC 9534 AMD CPUsss\"}', '{\"Status\":\"1\",\"Notes\":\"EPYC 9534 AMD CPUsss\",\"Location\":\"New Delhi, Delhi\",\"RackPosition\":\"Rack Z10\",\"Flag\":\"Backup\",\"ServerUUID\":\"null\"}', 'Updated cpu component', '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 04:44:43'),
(90, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '27.97.101.219', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 06:51:11'),
(91, 38, 'cpu', 18, 'Component created', NULL, '{\"UUID\":\"5f67a7a1-842d-4137-b1b6-fde29f5d49e7\",\"SerialNumber\":\"fhdjfhskjfhsjkdf\",\"Status\":\"2\",\"ServerUUID\":\"In Use\",\"Location\":\"Noida\",\"RackPosition\":\"Rack B4\",\"PurchaseDate\":\"2025-07-16\",\"WarrantyEndDate\":\"2025-08-08\",\"Flag\":\"Backup\",\"Notes\":\"Brand: Intel, Series: Xeon Scalable, Model: Platinum 8480+\"}', 'Created new cpu component', '27.97.101.219', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 06:59:25'),
(92, 38, 'cpu', 18, 'Component deleted', '{\"ID\":18,\"UUID\":\"5f67a7a1-842d-4137-b1b6-fde29f5d49e7\",\"SerialNumber\":\"fhdjfhskjfhsjkdf\",\"Status\":2,\"ServerUUID\":\"In Use\",\"Location\":\"Noida\",\"RackPosition\":\"Rack B4\",\"PurchaseDate\":\"2025-07-16\",\"InstallationDate\":null,\"WarrantyEndDate\":\"2025-08-08\",\"Flag\":\"Backup\",\"Notes\":\"Brand: Intel, Series: Xeon Scalable, Model: Platinum 8480+\",\"CreatedAt\":\"2025-07-28 06:59:25\",\"UpdatedAt\":\"2025-07-28 06:59:25\"}', NULL, 'Deleted cpu component', '27.97.101.219', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 06:59:35'),
(93, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.161.226', 'PostmanRuntime/7.44.1', '2025-07-29 03:21:02'),
(94, 38, 'user_management', 37, 'Role assigned', NULL, NULL, 'Assigned role 2 to user 37', '106.215.161.226', 'PostmanRuntime/7.44.1', '2025-07-29 03:23:32'),
(95, 38, 'user_management', 39, 'User created', NULL, NULL, 'Created new user: Shubham', '106.215.161.226', 'PostmanRuntime/7.44.1', '2025-07-29 03:38:57'),
(96, 38, 'role', 3806, 'create', NULL, NULL, 'Created role: Media Manager', '106.215.161.226', 'PostmanRuntime/7.44.1', '2025-07-29 03:54:58'),
(97, 38, 'role', 3806, 'update_permissions', NULL, NULL, 'Updated permissions for role: media_manager', '106.215.161.226', 'PostmanRuntime/7.44.1', '2025-07-29 04:06:17'),
(98, 38, 'user_management', 39, 'Role assigned', NULL, NULL, 'Assigned role 3806 to user 39', '106.215.161.226', 'PostmanRuntime/7.44.1', '2025-07-29 04:17:03'),
(99, 38, 'user_management', 39, 'Role assigned', NULL, NULL, 'Assigned role 3806 to user 39', '106.215.161.226', 'PostmanRuntime/7.44.1', '2025-07-29 04:18:36'),
(100, 38, 'user_management', 39, 'Role removed', NULL, NULL, 'Removed role 3806 from user 39', '106.215.161.226', 'PostmanRuntime/7.44.1', '2025-07-29 04:18:48'),
(101, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.161.226', 'PostmanRuntime/7.44.1', '2025-07-29 04:21:32'),
(102, 38, 'role', 3806, 'update', NULL, NULL, 'Updated role: Media Managers', '106.215.161.226', 'PostmanRuntime/7.44.1', '2025-07-29 04:27:40'),
(103, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.167.9', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-29 13:09:12'),
(104, 38, 'auth', 38, 'User logout', NULL, NULL, NULL, '106.215.167.9', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-29 13:23:40'),
(105, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.167.9', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-30 00:39:53'),
(106, 38, 'auth', 38, 'User logout', NULL, NULL, NULL, '106.215.167.9', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-30 01:12:47'),
(107, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.167.9', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-30 01:13:00'),
(108, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.167.9', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-31 16:32:55'),
(109, 38, 'auth', 38, 'User logout', NULL, NULL, NULL, '106.215.167.9', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-31 17:00:19');

-- --------------------------------------------------------

--
-- Table structure for table `jwt_blacklist`
--

CREATE TABLE `jwt_blacklist` (
  `id` int(11) NOT NULL,
  `jti` varchar(255) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `reason` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `motherboardinventory`
--

CREATE TABLE `motherboardinventory` (
  `ID` int(11) NOT NULL,
  `UUID` varchar(50) NOT NULL COMMENT 'Links to detailed specs in JSON',
  `SerialNumber` varchar(50) DEFAULT NULL COMMENT 'Manufacturer serial number',
  `Status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=Failed/Decommissioned, 1=Available, 2=In Use',
  `ServerUUID` varchar(36) DEFAULT NULL COMMENT 'UUID of server where motherboard is installed, if any',
  `Location` varchar(100) DEFAULT NULL COMMENT 'Physical location like datacenter, warehouse',
  `RackPosition` varchar(20) DEFAULT NULL COMMENT 'Specific rack/shelf position',
  `PurchaseDate` date DEFAULT NULL,
  `InstallationDate` date DEFAULT NULL COMMENT 'When installed in current server',
  `WarrantyEndDate` date DEFAULT NULL,
  `Flag` varchar(50) DEFAULT NULL COMMENT 'Quick status flag or category',
  `Notes` text DEFAULT NULL COMMENT 'Any additional info or history',
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `motherboardinventory`
--

INSERT INTO `motherboardinventory` (`ID`, `UUID`, `SerialNumber`, `Status`, `ServerUUID`, `Location`, `RackPosition`, `PurchaseDate`, `InstallationDate`, `WarrantyEndDate`, `Flag`, `Notes`, `CreatedAt`, `UpdatedAt`) VALUES
(1, '79b1f3a2-c248-48ae-bec0-71bdfd170849', 'MB123456', 1, NULL, 'Datacenter North', 'Rack A3-12', '2023-05-10', '2023-06-01', '2026-05-10', 'Production', 'Supermicro X12DPi-NT6', '2025-05-11 11:42:52', '2025-10-15 08:36:32'),
(2, '92d2d69d-9101-4f15-a507-ab9effd93b6b', 'MB789012', 1, NULL, 'Repair Center', 'Bench 3', '2023-02-20', '2023-03-01', '2026-02-20', 'Repair', 'ASUS WS C621E SAGE - Under repair for BIOS issues', '2025-05-11 11:42:52', '2025-10-15 08:36:36'),
(3, 'fa410f1c-ab12-46c5-add9-201fcc4985c7', 'MB345678', 1, NULL, 'Mumbai', NULL, '2024-02-10', NULL, '2027-02-10', 'Spare', 'MSI PRO B650-P WiFi', '2025-05-11 11:42:52', '2025-10-15 08:39:47'),
(4, '18527f82-7f18-4148-9cb8-7449b1e3cadf', 'MOT2323882', 1, NULL, 'New Delhi India', NULL, '2025-07-29', NULL, '2029-11-15', 'Backup', 'Brand: GIGABYTE, Series: MZ, Model: MZ93-FS0\n\nAdditional Notes: gigabyte motherboard z790 godlike', '2025-07-27 14:08:05', '2025-10-15 08:40:32'),
(5, '67c845a3-d827-47d1-8441-0639ef10391b', 'MB345688', 1, 'null', 'Banglore', 'Rack Z10', '2025-07-28', NULL, '2025-08-02', 'Backup', 'Brand: Supermicro, Series: X13, Model: X13DRi-N good motherboard\r\n\r\nAdditional Notes: gigabyte godlike z790 ', '2025-07-27 14:09:43', '2025-08-28 13:15:45'),
(6, '7a3b9c8d-2f1a-4b7e-8c6d-5a9f2b3e8c7d', 'MOT2323999', 1, NULL, 'Mumbai', NULL, '2025-08-30', NULL, '2029-10-30', 'Backup', 'Motherboard - X13DRi-N', '2025-08-30 09:39:49', '2025-11-06 10:48:33'),
(9, '9d2e4f6a-7b8c-4d9e-8f1a-6c3d5e7f9a2b', 'SMC-1029U-001', 1, NULL, 'Mumbai', NULL, '2024-09-10', NULL, '2027-09-10', 'Available', 'Supermicro SYS-1029U-TR4, 1U form factor, LGA 4189 dual socket, Intel C741 chipset, 16x DDR5 slots (4TB max), Expansion: 2x PCIe 5.0 x16 Riser + 2x PCIe 5.0 x8 Riser (riser cards required), Storage: 4x SATA, 2x M.2 NVMe, 4x U.2 NVMe, Networking: Dual 10GbE SFP+, IPMI 2.0, 1200W redundant PSU recommended. Use cases: Dense Rack Deployment, Edge Computing, Virtualization Host', '2025-10-22 21:28:24', '2025-11-06 09:11:26'),
(10, '5a7c9e2b-4d6f-8a1c-3e5b-7f9d2a4c6e8b', 'DELL-R760-001', 1, NULL, 'Mumbai', NULL, '2024-10-05', NULL, '2027-10-05', 'Available', 'Dell PowerEdge R760, 2U Rack form factor, LGA 4189 dual socket, Intel C741 chipset, 32x DDR5 slots (8TB max), Expansion: 3x PCIe 5.0 x16 + 2x PCIe 5.0 x8 + 3x PCIe 5.0 x16 Riser (Dell Riser Config 1A/2A required), Storage: 8x SATA, 8x SAS (Broadcom 3916), 2x M.2 NVMe, 8x U.2 NVMe, Networking: Quad 10GbE RJ45 (Broadcom BCM57416), iDRAC 9 management, 1600W redundant PSU recommended. Use cases: Enterprise Data Center, Virtualization, Database Workloads, AI/ML Training', '2025-10-22 21:28:24', '2025-10-29 06:40:31'),
(11, '3f8d6b2e-9a4c-7e1f-5b3d-8a2c6f4e9d7b', 'HPE-DL385-001', 1, NULL, 'Datacenter C, Rack A2', 'Server Bay 12', '2024-11-15', NULL, '2027-11-15', 'Available', 'HPE ProLiant DL385 Gen11, 2U Rack form factor, SP5 dual socket (AMD EPYC 9004), AMD SP5 Integrated chipset, 24x DDR5 slots (6TB max), Expansion: 4x PCIe 5.0 x16 Riser + 2x PCIe 5.0 x8 Riser (HPE PCIe Riser Kit 1/2/3 required), Storage: 8x SATA, 8x SAS (Broadcom 3916), 2x M.2 NVMe, 10x U.2 NVMe, Networking: Dual 25GbE SFP28 (Broadcom BCM57508), iLO 6 management, 1600W redundant PSU recommended. Use cases: Virtualization, Software Defined Storage, HPC Workloads, Database Consolidation', '2025-10-22 21:28:24', '2025-10-22 21:28:24'),
(12, '8c5f2b87-1e5b-4e8c-a1d2-0b1a5e3f4d6c', NULL, 2, '214100e3-c7cd-4f01-8c27-eca2310b0bbb', '', '', NULL, '2025-11-07', NULL, 'New Stock', 'Brand: Supermicro, Series: X13, Model: X13DRG-H, Form Factor: EATX, Chipset: Intel C741, Socket: LGA 4189 (2 sockets), Max Risers: 4, Slot Spacing: 20.32mm, Mounting Length: 280mm', '2025-10-23 09:11:47', '2025-11-07 07:49:44'),
(14, '6e4c2a5b-3a8e-4f7d-8b2c-9d1a4e5b6f7c', NULL, 2, 'b8754c5b-f071-4446-bae1-f0fd5afd640f', '', '', NULL, '2025-11-12', NULL, 'New Stock', 'Brand: GIGABYTE, Series: MZ, Model: MZ93-FS0, Form Factor: EATX, Chipset: Intel C741, Socket: LGA 4189 (2 sockets), Riser Slots Available', '2025-10-23 09:11:47', '2025-11-12 13:49:41'),
(15, '4f8e6c3d-2b7a-4c9e-8d1b-5e6f7a3d9c8b', NULL, 2, '4841e506-6e58-46d2-ab77-6fa91996561c', '', '', NULL, '2025-11-18', NULL, 'New Stock', 'Brand: ASRock Rack, Series: ROMED, Model: ROMED8-9001, Form Factor: EATX, Chipset: AMD SP5 Integrated, Socket: SP5 (2 sockets), Riser Slots Available', '2025-10-23 09:11:47', '2025-11-18 17:50:18');

-- --------------------------------------------------------

--
-- Table structure for table `nicinventory`
--

CREATE TABLE `nicinventory` (
  `ID` int(11) NOT NULL,
  `UUID` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `SerialNumber` varchar(50) DEFAULT NULL COMMENT 'Manufacturer serial number',
  `Status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=Failed/Decommissioned, 1=Available, 2=In Use',
  `ServerUUID` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Location` varchar(100) DEFAULT NULL COMMENT 'Physical location like datacenter, warehouse',
  `RackPosition` varchar(20) DEFAULT NULL COMMENT 'Specific rack/shelf position',
  `PurchaseDate` date DEFAULT NULL,
  `InstallationDate` date DEFAULT NULL COMMENT 'When installed in current server',
  `WarrantyEndDate` date DEFAULT NULL,
  `Flag` varchar(50) DEFAULT NULL COMMENT 'Quick status flag or category',
  `Notes` text DEFAULT NULL COMMENT 'Any additional info or history',
  `SourceType` varchar(20) DEFAULT 'component' COMMENT 'component=physical inventory, onboard=motherboard integrated',
  `ParentComponentUUID` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `OnboardNICIndex` int(11) DEFAULT NULL COMMENT 'Index of onboard NIC from motherboard specs (1, 2, etc.)',
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `nicinventory`
--

INSERT INTO `nicinventory` (`ID`, `UUID`, `SerialNumber`, `Status`, `ServerUUID`, `Location`, `RackPosition`, `PurchaseDate`, `InstallationDate`, `WarrantyEndDate`, `Flag`, `Notes`, `SourceType`, `ParentComponentUUID`, `OnboardNICIndex`, `CreatedAt`, `UpdatedAt`) VALUES
(6, 'i350-t4-1234-5678-90ab-cdef01234567', 'I350-T4', 1, NULL, 'Mumbai', NULL, NULL, NULL, NULL, 'Intel I350 Series', 'Intel I350-T4 4xRJ45 PCIe 2.1 x4 1GbE SR-IOV VMDq IEEE1588', 'component', NULL, NULL, '2025-10-10 10:05:57', '2025-11-05 04:07:42'),
(7, 'i350-t2-2345-6789-01bc-def012345678', 'I350-T2', 1, NULL, 'Mumbai', NULL, NULL, NULL, NULL, 'Intel I350 Series', 'Intel I350-T2 2xRJ45 PCIe 2.1 x4 1GbE SR-IOV VMDq IEEE1588', 'component', NULL, NULL, '2025-10-10 10:05:57', '2025-11-05 22:12:00'),
(8, 'x520-da2-3456-789a-bcde-f01234567890', 'X520-DA2', 1, NULL, 'Mumbai', NULL, NULL, NULL, NULL, 'Intel X520 Series', 'Intel X520-DA2 2xSFP+ PCIe 2.0 x8 10GbE SR-IOV DCB FCoE', 'component', NULL, NULL, '2025-10-10 10:05:57', '2025-11-06 10:51:52'),
(9, 'x710-da4-5678-9abc-def0-123456789012', 'X710-DA4', 1, NULL, NULL, NULL, NULL, NULL, NULL, 'Intel X710 Series', 'Intel X710-DA4 4xSFP+ PCIe 3.0 x8 10GbE SR-IOV VXLAN NVGRE RDMA', 'component', NULL, NULL, '2025-10-10 10:05:57', '2025-10-10 10:05:57'),
(10, 'e810-cqda2-abcd-ef01-2345-678901234567', 'E810-CQDA2', 1, NULL, NULL, NULL, NULL, NULL, NULL, 'Intel E810 Series', 'Intel E810-CQDA2 2xQSFP28 PCIe 4.0 x16 100GbE SR-IOV ADQ RoCE DDP', 'component', NULL, NULL, '2025-10-10 10:05:57', '2025-11-01 16:35:33'),
(11, 'bcm57508-100g-1234-5678-9abc-345678901234', 'BCM57508-P2100G', 1, NULL, '', NULL, NULL, NULL, NULL, 'Broadcom NetXtreme-E', 'Broadcom BCM57508 2xQSFP28 PCIe 4.0 x16 100GbE SR-IOV RoCE TruFlow', 'component', NULL, NULL, '2025-10-10 10:05:57', '2025-11-01 16:35:33'),
(12, 'mcx516a-ccat-5678-9abc-def0-789012345678', 'MCX516A-CCAT', 1, NULL, 'Mumbai', NULL, NULL, NULL, NULL, 'Mellanox ConnectX-5', 'Mellanox ConnectX-5 2xQSFP28 PCIe 3.0 x16 100GbE SR-IOV RoCEv2 GPUDirect TLS Offload', 'component', NULL, NULL, '2025-10-10 10:05:57', '2025-11-06 09:42:32'),
(13, 'mcx653106a-hdat-789a-bcde-f012-901234567890', 'MCX653106A-HDAT', 1, NULL, NULL, NULL, NULL, NULL, NULL, 'Mellanox ConnectX-6', 'Mellanox ConnectX-6 1xQSFP56 PCIe 4.0 x16 200GbE SR-IOV RoCEv2 SHARP GPUDirect', 'component', NULL, NULL, '2025-10-10 10:05:57', '2025-11-01 16:35:33'),
(14, 't62100-lp-ef01-2345-6789-678901234567', 'T62100-LP-CR', 1, NULL, NULL, NULL, NULL, NULL, NULL, 'Chelsio T6 Series', 'Chelsio T62100-LP-CR 2xQSFP28 PCIe 3.0 x16 100GbE iWARP RDMA TLS NVMe-oF', 'component', NULL, NULL, '2025-10-10 10:05:57', '2025-11-01 16:35:33'),
(15, 'ql45212h-lc-1234-5678-9abc-901234567890', 'QL45212H-LC', 1, NULL, NULL, NULL, NULL, NULL, NULL, 'Marvell FastLinQ QL45000', 'Marvell QL45212H-LC 2xQSFP28 PCIe 4.0 x16 100GbE SR-IOV RoCEv2 VXLAN', 'component', NULL, NULL, '2025-10-10 10:05:57', '2025-11-01 16:35:33'),
(16, '64992d14-c775-4002-8ed5-419404909d75', NULL, 1, NULL, 'Mumbai', NULL, NULL, NULL, NULL, 'Backup', 'nic', 'component', NULL, NULL, '2025-10-24 20:33:11', '2025-11-06 10:48:33'),
(17, 'f85c377e-f4a2-46eb-9f2e-a466581d1ee4', NULL, 1, NULL, 'Mumbai', NULL, NULL, NULL, NULL, 'Backup', 'nic', 'component', NULL, NULL, '2025-10-24 20:33:11', '2025-11-06 07:06:59'),
(18, '346d55c6-8058-424a-ae88-010385c57785', 'Shubham-Test-Server', 2, 'null', 'Indore', 'null', '2025-10-24', NULL, '2025-10-22', 'Backup', 'nic', 'component', NULL, NULL, '2025-10-24 21:22:25', '2025-10-24 21:22:25'),
(30, '5a63e63f-9a08-4c60-956b-f57d857ca25b', 'fhdjfhskjfhsjkdf', 1, 'null', 'Indore', 'null', '2025-10-25', NULL, '2025-10-15', 'Backup', 'nic', 'component', NULL, NULL, '2025-10-25 06:29:07', '2025-10-25 06:29:07'),
(32, 'a9089773-4aa9-42bd-af82-fbca810df093', 'after-fix', 0, 'null', 'Indore', 'null', '2025-10-25', NULL, '2025-10-09', 'Backup', 'Test - nic', 'component', NULL, NULL, '2025-10-25 07:44:38', '2025-10-25 07:44:38'),
(34, 'bbad8dd7-bc61-4297-9067-9155c35ef89b', 'after-fix-NIC', 1, 'null', 'Indore', 'null', '2025-10-25', NULL, '2025-10-15', 'Backup', 'nic', 'component', NULL, NULL, '2025-10-25 07:55:39', '2025-10-25 07:55:39'),
(35, 'onboard-nic-5a7c9e2b-4d6f-8a1c-3e5b-', 'ONBOARD', 1, NULL, NULL, NULL, NULL, NULL, NULL, 'Onboard', 'Onboard: Broadcom BCM57416 4-port 10GbE RJ45', 'onboard', '5a7c9e2b-4d6f-8a1c-3e5b-7f9d2a4c6e8b', 1, '2025-10-28 23:37:05', '2025-10-29 06:41:07'),
(175, 'onboard-8c5f2b87-1', 'ONBOARD-8c5f2b87-1', 2, '214100e3-c7cd-4f01-8c27-eca2310b0bbb', NULL, NULL, NULL, NULL, NULL, 'Onboard', 'Onboard: Intel X710 2-port 10GbE SFP+', 'onboard', '8c5f2b87-1e5b-4e8c-a1d2-0b1a5e3f4d6c', 1, '2025-11-07 07:49:44', '2025-11-07 07:49:44'),
(176, 'onboard-6e4c2a5b-1', 'ONBOARD-6e4c2a5b-1', 2, 'b8754c5b-f071-4446-bae1-f0fd5afd640f', NULL, NULL, NULL, NULL, NULL, 'Onboard', 'Onboard: Intel X710 2-port 10GbE SFP+', 'onboard', '6e4c2a5b-3a8e-4f7d-8b2c-9d1a4e5b6f7c', 1, '2025-11-12 13:49:41', '2025-11-12 13:49:41'),
(177, 'onboard-4f8e6c3d-1', 'ONBOARD-4f8e6c3d-1', 2, '4841e506-6e58-46d2-ab77-6fa91996561c', NULL, NULL, NULL, NULL, NULL, 'Onboard', 'Onboard: Broadcom BCM57414 2-port 10GbE SFP+', 'onboard', '4f8e6c3d-2b7a-4c9e-8d1b-5e6f7a3d9c8b', 1, '2025-11-18 17:50:18', '2025-11-18 17:50:18');

-- --------------------------------------------------------

--
-- Table structure for table `pciecardinventory`
--

CREATE TABLE `pciecardinventory` (
  `ID` int(11) NOT NULL,
  `UUID` varchar(50) NOT NULL COMMENT 'Links to detailed specs in JSON',
  `SerialNumber` varchar(50) DEFAULT NULL COMMENT 'Manufacturer serial number',
  `Status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=Failed/Decommissioned, 1=Available, 2=In Use',
  `ServerUUID` varchar(36) DEFAULT NULL COMMENT 'UUID of server where PCIe card is installed, if any',
  `Location` varchar(100) DEFAULT NULL COMMENT 'Physical location like datacenter, warehouse',
  `RackPosition` varchar(20) DEFAULT NULL COMMENT 'Specific rack/shelf position',
  `PurchaseDate` date DEFAULT NULL,
  `InstallationDate` date DEFAULT NULL COMMENT 'When installed in current server',
  `WarrantyEndDate` date DEFAULT NULL,
  `Flag` varchar(50) DEFAULT NULL COMMENT 'Quick status flag or category',
  `Notes` text DEFAULT NULL COMMENT 'Any additional info or history',
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pciecardinventory`
--

INSERT INTO `pciecardinventory` (`ID`, `UUID`, `SerialNumber`, `Status`, `ServerUUID`, `Location`, `RackPosition`, `PurchaseDate`, `InstallationDate`, `WarrantyEndDate`, `Flag`, `Notes`, `CreatedAt`, `UpdatedAt`) VALUES
(3, 'nvme-adaptor-4m2p-001-ijkl9012', NULL, 1, NULL, '', NULL, '2024-04-10', NULL, '2027-04-10', NULL, 'Supermicro Quad M.2 NVMe Adapter AOM-SNG-4M2P, PCIe 3.0 x16', '2025-09-27 15:39:02', '2025-11-06 12:47:21'),
(4, 'nvme-adaptor-2m2p-001-mnop3456', NULL, 2, '214100e3-c7cd-4f01-8c27-eca2310b0bbb', '', '', '2024-04-05', '2025-11-06', '2027-04-05', NULL, 'Supermicro Dual M.2 NVMe Adapter AOM-SNG-2M2P, PCIe 3.0 x8', '2025-09-27 15:39:02', '2025-11-06 12:53:07'),
(5, 'riser-1u-e8r-001-qrst7890', NULL, 1, NULL, 'Mumbai', NULL, '2024-05-01', NULL, '2027-05-01', NULL, 'Supermicro 1U PCIe Riser Card RSC-R1UU-E8R, PCIe 3.0/4.0 x8', '2025-09-27 15:39:02', '2025-11-06 10:51:52'),
(6, 'riser-1u-2e8r-001-uvwx4567', NULL, 1, NULL, 'Mumbai', NULL, '2024-05-05', NULL, '2027-05-05', NULL, 'Supermicro 1U PCIe Riser Card RSC-R1UU-2E8R, PCIe 3.0/4.0 dual x8', '2025-09-27 15:39:02', '2025-11-06 10:48:33'),
(7, 'riser-2u-e16r-001-yzab8901', NULL, 1, NULL, 'Mumbai', NULL, '2024-05-10', NULL, '2027-05-10', NULL, 'Supermicro 2U PCIe Riser Card RSC-R2UU-E16R, PCIe 3.0/4.0 x16', '2025-09-27 15:39:02', '2025-11-06 10:48:33'),
(9, 'a7baedef-ced2-4f5a-bee3-15927d018b6c', 'after-fix-PCIe', 2, 'null', 'Indore', 'null', '2025-10-25', NULL, '2025-10-22', 'Backup', 'pciecard', '2025-10-25 08:01:06', '2025-10-25 08:01:06'),
(10, 'e6032fcb-4768-4eb1-9aee-44172d07dbc7', 'Shubham-Test-Server-nil', 2, 'null', 'Indore', 'null', '2025-10-26', NULL, '0000-00-00', 'Backup', 'pciecard', '2025-10-26 06:48:00', '2025-10-26 06:48:00');

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `display_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(50) DEFAULT 'general',
  `is_basic` tinyint(1) DEFAULT 0 COMMENT '1 = basic permission given to new roles',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `name`, `display_name`, `description`, `category`, `is_basic`, `created_at`) VALUES
(1, 'auth.login', 'Login to System', 'Basic login access', 'authentication', 1, '2025-07-24 00:05:50'),
(2, 'auth.logout', 'Logout from System', 'Logout access', 'authentication', 1, '2025-07-24 00:05:50'),
(3, 'auth.change_password', 'Change Own Password', 'Change own password', 'authentication', 1, '2025-07-24 00:05:50'),
(4, 'users.view', 'View Users', 'View user list and details', 'user_management', 0, '2025-07-24 00:05:50'),
(5, 'users.create', 'Create Users', 'Create new user accounts', 'user_management', 0, '2025-07-24 00:05:50'),
(6, 'users.edit', 'Edit Users', 'Edit user account details', 'user_management', 0, '2025-07-24 00:05:50'),
(7, 'users.delete', 'Delete Users', 'Delete user accounts', 'user_management', 0, '2025-07-24 00:05:50'),
(8, 'users.manage_roles', 'Manage User Roles', 'Assign/remove roles from users', 'user_management', 0, '2025-07-24 00:05:50'),
(9, 'roles.view', 'View Roles', 'View available roles', 'role_management', 0, '2025-07-24 00:05:50'),
(10, 'roles.create', 'Create Roles', 'Create new roles', 'role_management', 0, '2025-07-24 00:05:50'),
(11, 'roles.edit', 'Edit Roles', 'Edit role details and permissions', 'role_management', 0, '2025-07-24 00:05:50'),
(12, 'roles.delete', 'Delete Roles', 'Delete custom roles', 'role_management', 0, '2025-07-24 00:05:50'),
(13, 'cpu.view', 'View CPUs', 'View CPU inventory', 'inventory', 1, '2025-07-24 00:05:50'),
(14, 'cpu.create', 'Add CPUs', 'Add new CPU components', 'inventory', 0, '2025-07-24 00:05:50'),
(15, 'cpu.edit', 'Edit CPUs', 'Edit CPU component details', 'inventory', 0, '2025-07-24 00:05:50'),
(16, 'cpu.delete', 'Delete CPUs', 'Delete CPU components', 'inventory', 0, '2025-07-24 00:05:50'),
(17, 'ram.view', 'View RAM', 'View RAM inventory', 'inventory', 1, '2025-07-24 00:05:50'),
(18, 'ram.create', 'Add RAM', 'Add new RAM components', 'inventory', 0, '2025-07-24 00:05:50'),
(19, 'ram.edit', 'Edit RAM', 'Edit RAM component details', 'inventory', 0, '2025-07-24 00:05:50'),
(20, 'ram.delete', 'Delete RAM', 'Delete RAM components', 'inventory', 0, '2025-07-24 00:05:50'),
(21, 'storage.view', 'View Storage', 'View storage inventory', 'inventory', 1, '2025-07-24 00:05:50'),
(22, 'storage.create', 'Add Storage', 'Add new storage components', 'inventory', 0, '2025-07-24 00:05:50'),
(23, 'storage.edit', 'Edit Storage', 'Edit storage component details', 'inventory', 0, '2025-07-24 00:05:50'),
(24, 'storage.delete', 'Delete Storage', 'Delete storage components', 'inventory', 0, '2025-07-24 00:05:50'),
(25, 'motherboard.view', 'View Motherboards', 'View motherboard inventory', 'inventory', 1, '2025-07-24 00:05:50'),
(26, 'motherboard.create', 'Add Motherboards', 'Add new motherboard components', 'inventory', 0, '2025-07-24 00:05:50'),
(27, 'motherboard.edit', 'Edit Motherboards', 'Edit motherboard component details', 'inventory', 0, '2025-07-24 00:05:50'),
(28, 'motherboard.delete', 'Delete Motherboards', 'Delete motherboard components', 'inventory', 0, '2025-07-24 00:05:50'),
(29, 'nic.view', 'View NICs', 'View NIC inventory', 'inventory', 1, '2025-07-24 00:05:50'),
(30, 'nic.create', 'Add NICs', 'Add new NIC components', 'inventory', 0, '2025-07-24 00:05:50'),
(31, 'nic.edit', 'Edit NICs', 'Edit NIC component details', 'inventory', 0, '2025-07-24 00:05:50'),
(32, 'nic.delete', 'Delete NICs', 'Delete NIC components', 'inventory', 0, '2025-07-24 00:05:50'),
(33, 'caddy.view', 'View Caddies', 'View caddy inventory', 'inventory', 1, '2025-07-24 00:05:50'),
(34, 'caddy.create', 'Add Caddies', 'Add new caddy components', 'inventory', 0, '2025-07-24 00:05:50'),
(35, 'caddy.edit', 'Edit Caddies', 'Edit caddy component details', 'inventory', 0, '2025-07-24 00:05:50'),
(36, 'caddy.delete', 'Delete Caddies', 'Delete caddy components', 'inventory', 0, '2025-07-24 00:05:50'),
(37, 'dashboard.view', 'View Dashboard', 'Access main dashboard', 'dashboard', 1, '2025-07-24 00:05:50'),
(38, 'reports.view', 'View Reports', 'View inventory reports', 'reports', 0, '2025-07-24 00:05:50'),
(39, 'reports.export', 'Export Reports', 'Export inventory data', 'reports', 0, '2025-07-24 00:05:50'),
(40, 'search.global', 'Global Search', 'Search across all components', 'utilities', 1, '2025-07-24 00:05:50'),
(41, 'search.advanced', 'Advanced Search', 'Advanced search capabilities', 'utilities', 0, '2025-07-24 00:05:50'),
(42, 'system.view_logs', 'View System Logs', 'View system activity logs', 'system', 0, '2025-07-24 00:05:50'),
(43, 'system.manage_settings', 'Manage Settings', 'Manage system settings', 'system', 0, '2025-07-24 00:05:50'),
(44, 'system.backup', 'System Backup', 'Create system backups', 'system', 0, '2025-07-24 00:05:50'),
(45, 'system.maintenance', 'System Maintenance', 'Perform system maintenance', 'system', 0, '2025-07-24 00:05:50'),
(95, 'dashboard.admin', 'Admin Dashboard Access', NULL, 'dashboard', 0, '2025-07-25 01:29:56'),
(97, 'ticket.create', 'Create Ticket', 'Create new tickets and submit for approval', 'ticket', 0, '2025-11-19 21:51:44'),
(98, 'ticket.view_own', 'View Own Tickets', 'View own tickets', 'ticket', 0, '2025-11-19 21:51:44'),
(99, 'ticket.edit_own', 'Edit Own Tickets', 'Edit own draft tickets', 'ticket', 0, '2025-11-19 21:51:44'),
(100, 'ticket.view_all', 'View All Tickets', 'View all tickets in system', 'ticket', 0, '2025-11-19 21:51:44'),
(101, 'ticket.view_assigned', 'View Assigned Tickets', 'View tickets assigned to user', 'ticket', 0, '2025-11-19 21:51:44'),
(102, 'ticket.approve', 'Approve Tickets', 'Approve pending tickets', 'ticket', 0, '2025-11-19 21:51:44'),
(103, 'ticket.reject', 'Reject Tickets', 'Reject pending tickets', 'ticket', 0, '2025-11-19 21:51:44'),
(104, 'ticket.assign', 'Assign Tickets', 'Assign tickets to users', 'ticket', 0, '2025-11-19 21:51:44'),
(105, 'ticket.deploy', 'Deploy Tickets', 'Deploy approved changes and mark as deployed', 'ticket', 0, '2025-11-19 21:51:44'),
(106, 'ticket.complete', 'Complete Tickets', 'Mark deployed tickets as complete', 'ticket', 0, '2025-11-19 21:51:44'),
(107, 'ticket.cancel', 'Cancel Tickets', 'Cancel tickets at any stage', 'ticket', 0, '2025-11-19 21:51:44'),
(108, 'ticket.delete', 'Delete Tickets', 'Delete tickets permanently (admin only)', 'ticket', 0, '2025-11-19 21:51:44'),
(109, 'ticket.manage', 'Manage Tickets', 'Bypass all ticket restrictions (superuser)', 'ticket', 0, '2025-11-19 21:51:44'),
(128, 'roles.assign', 'Assign Roles to Users', NULL, 'user_management', 0, '2025-07-25 01:29:56'),
(133, 'system.settings', 'System Settings', NULL, 'system', 0, '2025-07-25 01:29:56'),
(134, 'system.logs', 'View System Logs', NULL, 'system', 0, '2025-07-25 01:29:56'),
(47431, 'server.view', 'View Server Configurations', 'View server configuration details', 'server_management', 0, '2025-08-02 15:10:03'),
(47432, 'server.create', 'Create Server Configurations', 'Create new server configurations', 'server_management', 0, '2025-08-02 15:10:03'),
(47433, 'server.edit', 'Edit Server Configurations', 'Modify existing server configurations', 'server_management', 0, '2025-08-02 15:10:03'),
(47434, 'server.delete', 'Delete Server Configurations', 'Delete server configurations', 'server_management', 0, '2025-08-02 15:10:03'),
(47435, 'server.view_all', 'View All Server Configurations', 'View server configurations created by other users', 'server_management', 0, '2025-08-02 15:10:03'),
(47436, 'server.delete_all', 'Delete Any Server Configuration', 'Delete server configurations created by other users', 'server_management', 0, '2025-08-02 15:10:03'),
(47437, 'server.view_statistics', 'View Server Statistics', 'View server configuration statistics and reports', 'server_management', 0, '2025-08-02 15:10:03'),
(47438, 'compatibility.check', 'Check Component Compatibility', 'Run compatibility checks between components', 'compatibility', 0, '2025-08-02 15:10:03'),
(47439, 'compatibility.view_statistics', 'View Compatibility Statistics', 'View compatibility check statistics', 'compatibility', 0, '2025-08-02 15:10:03'),
(47440, 'compatibility.manage_rules', 'Manage Compatibility Rules', 'Create and modify compatibility rules', 'compatibility', 0, '2025-08-02 15:10:03'),
(49861, 'server.edit_all', 'Edit All Servers', 'Edit all users server configurations', 'server', 0, '2025-08-20 09:40:51'),
(49862, 'permissions.get_all', 'Get All Permissions', 'View all system permissions', 'role_management', 0, '2025-08-20 09:40:51'),
(49863, 'permissions.manage', 'Manage Permissions', 'Manage system permissions', 'role_management', 0, '2025-08-20 09:40:51'),
(49864, 'roles.update_permissions', 'Update Role Permissions', 'Update permissions for roles', 'role_management', 0, '2025-08-20 09:40:51'),
(49865, 'reports.create', 'Create Reports', 'Create new reports', 'reports', 0, '2025-08-20 09:40:51'),
(49866, 'reports.schedule', 'Schedule Reports', 'Schedule report generation', 'reports', 0, '2025-08-20 09:40:51'),
(49884, 'chassis.view', 'View Chassis', 'View chassis inventory', 'inventory', 1, '2025-10-22 14:33:58'),
(49885, 'chassis.create', 'Add Chassis', 'Add new chassis components', 'inventory', 0, '2025-10-22 14:33:58'),
(49886, 'chassis.edit', 'Edit Chassis', 'Edit chassis component details', 'inventory', 0, '2025-10-22 14:33:58'),
(49887, 'chassis.delete', 'Delete Chassis', 'Delete chassis components', 'inventory', 0, '2025-10-22 14:33:58'),
(49888, 'pciecard.view', 'View PCIe Cards', 'View PCIe card inventory', 'inventory', 1, '2025-10-22 14:33:58'),
(49889, 'pciecard.create', 'Add PCIe Cards', 'Add new PCIe card components', 'inventory', 0, '2025-10-22 14:33:58'),
(49890, 'pciecard.edit', 'Edit PCIe Cards', 'Edit PCIe card component details', 'inventory', 0, '2025-10-22 14:33:58'),
(49891, 'pciecard.delete', 'Delete PCIe Cards', 'Delete PCIe card components', 'inventory', 0, '2025-10-22 14:33:58'),
(49892, 'hbacard.view', 'View HBA Cards', 'View HBA Card inventory', 'inventory', 0, '2025-10-26 11:51:06'),
(49893, 'hbacard.create', 'Create HBA Cards', 'Add new HBA Card components', 'inventory', 0, '2025-10-26 11:51:06'),
(49894, 'hbacard.edit', 'Edit HBA Cards', 'Edit HBA Card component details', 'inventory', 0, '2025-10-26 11:51:06'),
(49895, 'hbacard.delete', 'Delete HBA Cards', 'Delete HBA Card components', 'inventory', 0, '2025-10-26 11:51:06'),
(49896, 'sfp.view', 'View SFP Modules', 'View SFP module inventory', 'inventory', 1, '2025-11-15 09:34:59'),
(49897, 'sfp.create', 'Add SFP Modules', 'Add new SFP modules to inventory', 'inventory', 0, '2025-11-15 09:34:59'),
(49898, 'sfp.edit', 'Edit SFP Modules', 'Edit SFP module details and assignments', 'inventory', 0, '2025-11-15 09:34:59'),
(49899, 'sfp.delete', 'Delete SFP Modules', 'Delete SFP modules from inventory', 'inventory', 0, '2025-11-15 09:34:59');

-- --------------------------------------------------------

--
-- Table structure for table `raminventory`
--

CREATE TABLE `raminventory` (
  `ID` int(11) NOT NULL,
  `UUID` varchar(50) NOT NULL COMMENT 'Links to detailed specs in JSON',
  `SerialNumber` varchar(50) DEFAULT NULL COMMENT 'Manufacturer serial number',
  `Status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=Failed/Decommissioned, 1=Available, 2=In Use',
  `ServerUUID` varchar(36) DEFAULT NULL COMMENT 'UUID of server where RAM is installed, if any',
  `Location` varchar(100) DEFAULT NULL COMMENT 'Physical location like datacenter, warehouse',
  `RackPosition` varchar(20) DEFAULT NULL COMMENT 'Specific rack/shelf position',
  `PurchaseDate` date DEFAULT NULL,
  `InstallationDate` date DEFAULT NULL COMMENT 'When installed in current server',
  `WarrantyEndDate` date DEFAULT NULL,
  `Flag` varchar(50) DEFAULT NULL COMMENT 'Quick status flag or category',
  `Notes` text DEFAULT NULL COMMENT 'Any additional info or history',
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `raminventory`
--

INSERT INTO `raminventory` (`ID`, `UUID`, `SerialNumber`, `Status`, `ServerUUID`, `Location`, `RackPosition`, `PurchaseDate`, `InstallationDate`, `WarrantyEndDate`, `Flag`, `Notes`, `CreatedAt`, `UpdatedAt`) VALUES
(1, '897472c6-7b40-411b-80ef-31a6ca3156ea', 'RAM123456', 1, NULL, 'Datacenter North', 'Rack A3-12', '2023-05-15', '2023-06-01', '2026-05-15', 'Production', '32GB DDR4-3200', '2025-05-11 11:42:52', '2025-10-15 08:38:33'),
(2, 'ef5798f2-fdc4-4e5d-9364-6971995002ea', 'RAM789012', 1, NULL, 'Mumbai', NULL, '2024-01-15', NULL, '2027-01-15', 'Backup', '64GB DDR4-3600', '2025-05-11 11:42:52', '2025-10-15 08:38:21'),
(3, '82827c01-6e89-4d6a-bf2d-e62c929e2080', 'RAM456789', 1, NULL, 'Datacenter South', 'Rack B2-5', '2023-08-20', '2023-09-01', '2026-08-20', 'Production', '32GB DDR4-3200 ECC', '2025-05-11 11:42:52', '2025-10-15 08:38:16'),
(4, 'a1b2c3d4-e5f6-7890-1234-567890abcdef', 'RAM999999', 2, '214100e3-c7cd-4f01-8c27-eca2310b0bbb', '', '', '2025-08-30', '2025-11-07', '2032-10-12', 'Backup', 'RAM - DDR5 64GB 4800MT/s', '2025-08-30 10:08:30', '2025-11-07 07:50:38'),
(6, 'e5f6a7b8-c9d0-1234-5678-90abcdef1234', 'RAM000000', 1, NULL, 'Mumbai', NULL, '2023-09-15', NULL, '2028-09-15', 'Production', 'Kingston ValueRAM DDR4-3200 32GB ECC UDIMM', '2025-09-06 22:17:07', '2025-11-06 10:48:33'),
(7, 'b2c3d4e5-f6a7-8901-2345-67890abcdef1', 'after-fix-ram', 2, 'null', 'Indore', 'null', '2025-10-25', NULL, '2025-10-06', 'Backup', 'DDR5 128GB 4800MT/s', '2025-10-25 07:49:33', '2025-10-25 07:49:33');

-- --------------------------------------------------------

--
-- Table structure for table `refresh_tokens`
--

CREATE TABLE `refresh_tokens` (
  `id` int(11) NOT NULL,
  `user_id` int(6) UNSIGNED NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `display_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_default` tinyint(1) DEFAULT 0,
  `is_system` tinyint(1) DEFAULT 0 COMMENT '1 = system role, cannot be deleted',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`, `display_name`, `description`, `is_default`, `is_system`, `created_at`, `updated_at`) VALUES
(1, 'super_admin', 'Super Administrator', 'Full system access with all permissions', 0, 1, '2025-07-24 00:05:50', '2025-07-24 00:05:50'),
(2, 'admin', 'Administrator', 'Administrative access with most permissions', 0, 1, '2025-07-24 00:05:50', '2025-07-24 00:05:50'),
(3, 'manager', 'Manager', 'Management level access for inventory operations', 0, 1, '2025-07-24 00:05:50', '2025-07-24 00:05:50'),
(4, 'technician', 'Technician', 'Technical staff with component management access', 0, 1, '2025-07-24 00:05:50', '2025-07-24 00:05:50'),
(5, 'viewer', 'Viewer', 'Read-only access to inventory data', 1, 1, '2025-07-24 00:05:50', '2025-07-24 00:05:50'),
(3806, 'media_manager', 'Media Managers', '', 0, 0, '2025-07-29 03:54:58', '2025-07-29 04:27:40');

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `granted` tinyint(1) DEFAULT 1 COMMENT '1 = granted, 0 = denied',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `granted`, `created_at`) VALUES
(31, 1, 4, 1, '2025-08-20 10:01:01'),
(32, 1, 5, 1, '2025-08-20 10:01:01'),
(33, 1, 6, 1, '2025-08-20 10:01:01'),
(34, 1, 7, 1, '2025-08-20 10:01:01'),
(35, 1, 8, 1, '2025-08-20 10:01:01'),
(36, 1, 9, 1, '2025-08-20 10:01:01'),
(37, 1, 10, 1, '2025-08-20 10:01:01'),
(38, 1, 11, 1, '2025-08-20 10:01:01'),
(39, 1, 12, 1, '2025-08-20 10:01:01'),
(40, 1, 14, 1, '2025-08-20 10:01:01'),
(41, 1, 15, 1, '2025-08-20 10:01:01'),
(42, 1, 16, 1, '2025-08-20 10:01:01'),
(43, 1, 18, 1, '2025-08-20 10:01:01'),
(44, 1, 19, 1, '2025-08-20 10:01:01'),
(45, 1, 20, 1, '2025-08-20 10:01:01'),
(46, 1, 22, 1, '2025-08-20 10:01:01'),
(47, 1, 23, 1, '2025-08-20 10:01:01'),
(48, 1, 24, 1, '2025-08-20 10:01:01'),
(49, 1, 26, 1, '2025-08-20 10:01:01'),
(50, 1, 27, 1, '2025-08-20 10:01:01'),
(51, 1, 28, 1, '2025-08-20 10:01:01'),
(52, 1, 30, 1, '2025-08-20 10:01:01'),
(53, 1, 31, 1, '2025-08-20 10:01:01'),
(54, 1, 32, 1, '2025-08-20 10:01:01'),
(55, 1, 34, 1, '2025-08-20 10:01:01'),
(56, 1, 35, 1, '2025-08-20 10:01:01'),
(57, 1, 36, 1, '2025-08-20 10:01:01'),
(58, 1, 38, 1, '2025-08-20 10:01:01'),
(59, 1, 39, 1, '2025-08-20 10:01:01'),
(60, 1, 41, 1, '2025-08-20 10:01:01'),
(61, 1, 42, 1, '2025-08-20 10:01:01'),
(62, 1, 43, 1, '2025-08-20 10:01:01'),
(63, 1, 44, 1, '2025-08-20 10:01:01'),
(64, 1, 45, 1, '2025-08-20 10:01:01'),
(65, 1, 95, 1, '2025-08-20 10:01:01'),
(66, 1, 128, 1, '2025-08-20 10:01:01'),
(67, 1, 133, 1, '2025-08-20 10:01:01'),
(68, 1, 134, 1, '2025-08-20 10:01:01'),
(69, 1, 47431, 1, '2025-08-20 10:01:01'),
(70, 1, 47432, 1, '2025-08-20 10:01:01'),
(71, 1, 47433, 1, '2025-08-20 10:01:01'),
(72, 1, 47434, 1, '2025-08-20 10:01:01'),
(73, 1, 47435, 1, '2025-08-20 10:01:01'),
(74, 1, 47436, 1, '2025-08-20 10:01:01'),
(75, 1, 47437, 1, '2025-08-20 10:01:01'),
(76, 1, 47438, 1, '2025-08-20 10:01:01'),
(77, 1, 47439, 1, '2025-08-20 10:01:01'),
(78, 1, 47440, 1, '2025-08-20 10:01:01'),
(79, 1, 49861, 1, '2025-08-20 10:01:01'),
(80, 1, 49862, 1, '2025-08-20 10:01:01'),
(81, 1, 49863, 1, '2025-08-20 10:01:01'),
(82, 1, 49864, 1, '2025-08-20 10:01:01'),
(83, 1, 49865, 1, '2025-08-20 10:01:01'),
(84, 1, 49866, 1, '2025-08-20 10:01:01'),
(85, 1, 1, 1, '2025-08-20 10:01:01'),
(86, 1, 2, 1, '2025-08-20 10:01:01'),
(87, 1, 3, 1, '2025-08-20 10:01:01'),
(88, 1, 13, 1, '2025-08-20 10:01:01'),
(89, 1, 17, 1, '2025-08-20 10:01:01'),
(90, 1, 21, 1, '2025-08-20 10:01:01'),
(91, 1, 25, 1, '2025-08-20 10:01:01'),
(92, 1, 29, 1, '2025-08-20 10:01:01'),
(93, 1, 33, 1, '2025-08-20 10:01:01'),
(94, 1, 37, 1, '2025-08-20 10:01:01'),
(95, 1, 40, 1, '2025-08-20 10:01:01'),
(97, 1, 49893, 1, '2025-10-26 11:51:14'),
(98, 1, 49895, 1, '2025-10-26 11:51:14'),
(99, 1, 49894, 1, '2025-10-26 11:51:14'),
(100, 1, 49892, 1, '2025-10-26 11:51:14'),
(101, 2, 4, 1, '2025-11-12 19:58:10'),
(102, 2, 5, 1, '2025-11-12 19:58:10'),
(103, 2, 6, 1, '2025-11-12 19:58:10'),
(104, 2, 7, 1, '2025-11-12 19:58:10'),
(105, 2, 8, 1, '2025-11-12 19:58:10'),
(106, 2, 9, 1, '2025-11-12 19:58:10'),
(107, 2, 10, 1, '2025-11-12 19:58:10'),
(108, 2, 11, 1, '2025-11-12 19:58:10'),
(109, 2, 12, 1, '2025-11-12 19:58:10'),
(110, 2, 14, 1, '2025-11-12 19:58:10'),
(111, 2, 15, 1, '2025-11-12 19:58:10'),
(112, 2, 16, 1, '2025-11-12 19:58:10'),
(113, 2, 18, 1, '2025-11-12 19:58:10'),
(114, 2, 19, 1, '2025-11-12 19:58:10'),
(115, 2, 20, 1, '2025-11-12 19:58:10'),
(116, 2, 22, 1, '2025-11-12 19:58:10'),
(117, 2, 23, 1, '2025-11-12 19:58:10'),
(118, 2, 24, 1, '2025-11-12 19:58:10'),
(119, 2, 26, 1, '2025-11-12 19:58:10'),
(120, 2, 27, 1, '2025-11-12 19:58:10'),
(121, 2, 28, 1, '2025-11-12 19:58:10'),
(122, 2, 30, 1, '2025-11-12 19:58:10'),
(123, 2, 31, 1, '2025-11-12 19:58:10'),
(124, 2, 32, 1, '2025-11-12 19:58:10'),
(125, 2, 34, 1, '2025-11-12 19:58:10'),
(126, 2, 35, 1, '2025-11-12 19:58:10'),
(127, 2, 36, 1, '2025-11-12 19:58:10'),
(128, 2, 38, 1, '2025-11-12 19:58:10'),
(129, 2, 39, 1, '2025-11-12 19:58:10'),
(130, 2, 41, 1, '2025-11-12 19:58:10'),
(131, 2, 42, 1, '2025-11-12 19:58:10'),
(132, 2, 43, 1, '2025-11-12 19:58:10'),
(133, 2, 44, 1, '2025-11-12 19:58:10'),
(134, 2, 45, 1, '2025-11-12 19:58:10'),
(135, 2, 95, 1, '2025-11-12 19:58:10'),
(136, 2, 128, 1, '2025-11-12 19:58:10'),
(137, 2, 133, 1, '2025-11-12 19:58:10'),
(138, 2, 134, 1, '2025-11-12 19:58:10'),
(139, 2, 47431, 1, '2025-11-12 19:58:10'),
(140, 2, 47432, 1, '2025-11-12 19:58:10'),
(141, 2, 47433, 1, '2025-11-12 19:58:10'),
(142, 2, 47434, 1, '2025-11-12 19:58:10'),
(143, 2, 47435, 1, '2025-11-12 19:58:10'),
(144, 2, 47436, 1, '2025-11-12 19:58:10'),
(145, 2, 47437, 1, '2025-11-12 19:58:10'),
(146, 2, 47438, 1, '2025-11-12 19:58:10'),
(147, 2, 47439, 1, '2025-11-12 19:58:10'),
(148, 2, 47440, 1, '2025-11-12 19:58:10'),
(149, 2, 49861, 1, '2025-11-12 19:58:10'),
(150, 2, 49862, 1, '2025-11-12 19:58:10'),
(151, 2, 49863, 1, '2025-11-12 19:58:10'),
(152, 2, 49864, 1, '2025-11-12 19:58:10'),
(153, 2, 49865, 1, '2025-11-12 19:58:10'),
(154, 2, 49866, 1, '2025-11-12 19:58:10'),
(155, 2, 49885, 1, '2025-11-12 19:58:10'),
(156, 2, 49886, 1, '2025-11-12 19:58:10'),
(157, 2, 49887, 1, '2025-11-12 19:58:10'),
(158, 2, 49889, 1, '2025-11-12 19:58:10'),
(159, 2, 49890, 1, '2025-11-12 19:58:10'),
(160, 2, 49891, 1, '2025-11-12 19:58:10'),
(161, 2, 49892, 1, '2025-11-12 19:58:10'),
(162, 2, 49893, 1, '2025-11-12 19:58:10'),
(163, 2, 49894, 1, '2025-11-12 19:58:10'),
(164, 2, 49895, 1, '2025-11-12 19:58:10'),
(165, 2, 1, 1, '2025-11-12 19:58:10'),
(166, 2, 2, 1, '2025-11-12 19:58:10'),
(167, 2, 3, 1, '2025-11-12 19:58:10'),
(168, 2, 13, 1, '2025-11-12 19:58:10'),
(169, 2, 17, 1, '2025-11-12 19:58:10'),
(170, 2, 21, 1, '2025-11-12 19:58:10'),
(171, 2, 25, 1, '2025-11-12 19:58:10'),
(172, 2, 29, 1, '2025-11-12 19:58:10'),
(173, 2, 33, 1, '2025-11-12 19:58:10'),
(174, 2, 37, 1, '2025-11-12 19:58:10'),
(175, 2, 40, 1, '2025-11-12 19:58:10'),
(176, 2, 49884, 1, '2025-11-12 19:58:10'),
(177, 2, 49888, 1, '2025-11-12 19:58:10'),
(293, 1, 49896, 1, '2025-11-15 09:34:59'),
(294, 1, 49897, 1, '2025-11-15 09:34:59'),
(295, 1, 49898, 1, '2025-11-15 09:34:59'),
(296, 1, 49899, 1, '2025-11-15 09:34:59'),
(297, 3, 49896, 1, '2025-11-15 09:34:59'),
(298, 3, 49897, 1, '2025-11-15 09:34:59'),
(299, 3, 49898, 1, '2025-11-15 09:34:59'),
(300, 4, 49896, 1, '2025-11-15 09:34:59'),
(301, 4, 49897, 1, '2025-11-15 09:34:59'),
(302, 4, 49898, 1, '2025-11-15 09:34:59'),
(303, 5, 49896, 1, '2025-11-15 09:34:59'),
(320, 2, 49896, 1, '2025-11-15 09:39:46'),
(321, 2, 49898, 1, '2025-11-15 09:39:46'),
(322, 2, 49899, 1, '2025-11-15 09:39:46'),
(333, 2, 49897, 1, '2025-11-15 12:47:39'),
(419, 1, 97, 1, '2025-11-19 21:51:53'),
(420, 1, 98, 1, '2025-11-19 21:51:53'),
(421, 1, 99, 1, '2025-11-19 21:51:53'),
(422, 1, 100, 1, '2025-11-19 21:51:53'),
(423, 1, 101, 1, '2025-11-19 21:51:53'),
(424, 1, 102, 1, '2025-11-19 21:51:53'),
(425, 1, 103, 1, '2025-11-19 21:51:53'),
(426, 1, 104, 1, '2025-11-19 21:51:53'),
(427, 1, 105, 1, '2025-11-19 21:51:53'),
(428, 1, 106, 1, '2025-11-19 21:51:53'),
(429, 1, 107, 1, '2025-11-19 21:51:53'),
(430, 1, 108, 1, '2025-11-19 21:51:53'),
(431, 1, 109, 1, '2025-11-19 21:51:53'),
(432, 2, 97, 1, '2025-11-19 21:52:02'),
(433, 2, 98, 1, '2025-11-19 21:52:02'),
(434, 2, 99, 1, '2025-11-19 21:52:02'),
(435, 2, 100, 1, '2025-11-19 21:52:02'),
(436, 2, 101, 1, '2025-11-19 21:52:02'),
(437, 2, 102, 1, '2025-11-19 21:52:02'),
(438, 2, 103, 1, '2025-11-19 21:52:02'),
(439, 2, 104, 1, '2025-11-19 21:52:02'),
(440, 2, 105, 1, '2025-11-19 21:52:02'),
(441, 2, 106, 1, '2025-11-19 21:52:02'),
(442, 2, 107, 1, '2025-11-19 21:52:02'),
(443, 2, 108, 1, '2025-11-19 21:52:02'),
(444, 2, 109, 1, '2025-11-19 21:52:02');

-- --------------------------------------------------------

--
-- Table structure for table `server_build_templates`
--

CREATE TABLE `server_build_templates` (
  `id` int(11) NOT NULL,
  `template_uuid` varchar(36) NOT NULL,
  `template_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL COMMENT 'Web Server, Database Server, Storage Server, etc.',
  `created_by` int(11) NOT NULL,
  `is_public` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0=Private, 1=Public',
  `use_count` int(11) NOT NULL DEFAULT 0 COMMENT 'Number of times template was used',
  `template_configuration` longtext NOT NULL COMMENT 'JSON configuration template',
  `minimum_requirements` longtext DEFAULT NULL COMMENT 'JSON of minimum hardware requirements',
  `recommended_specs` longtext DEFAULT NULL COMMENT 'JSON of recommended specifications',
  `tags` varchar(500) DEFAULT NULL COMMENT 'Comma-separated tags',
  `estimated_power_consumption` decimal(8,2) DEFAULT NULL,
  `version` varchar(20) NOT NULL DEFAULT '1.0',
  `parent_template_id` int(11) DEFAULT NULL COMMENT 'For template versioning',
  `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0=Draft, 1=Active, 2=Deprecated, 3=Archived',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `server_build_templates`
--

INSERT INTO `server_build_templates` (`id`, `template_uuid`, `template_name`, `description`, `category`, `created_by`, `is_public`, `use_count`, `template_configuration`, `minimum_requirements`, `recommended_specs`, `tags`, `estimated_power_consumption`, `version`, `parent_template_id`, `status`, `approved_by`, `approved_at`, `created_at`, `updated_at`) VALUES
(1, '745c73d1-726e-11f0-9219-309c239ceca6', 'Basic Web Server', 'Standard web server configuration for small to medium websites', 'Web Server', 1, 1, 0, '{\"cpu\": {\"cores\": 4, \"frequency\": \"2.0GHz\"}, \"ram\": {\"capacity\": \"8GB\", \"type\": \"DDR4\"}, \"storage\": {\"capacity\": \"500GB\", \"type\": \"SSD\"}, \"network\": {\"ports\": 2}}', '{\"cpu_cores\": 2, \"ram_gb\": 4, \"storage_gb\": 250}', '{\"cpu_cores\": 8, \"ram_gb\": 16, \"storage_gb\": 1000}', 'web,server,basic,production', NULL, '1.0', NULL, 1, NULL, NULL, '2025-08-06 02:38:37', '2025-08-06 02:38:37'),
(2, '745c7829-726e-11f0-9219-309c239ceca6', 'Database Server', 'High-performance database server with redundant storage', 'Database Server', 1, 1, 0, '{\"cpu\": {\"cores\": 8, \"frequency\": \"3.0GHz\"}, \"ram\": {\"capacity\": \"32GB\", \"type\": \"DDR4\"}, \"storage\": {\"capacity\": \"2TB\", \"type\": \"NVMe SSD\", \"redundancy\": \"RAID1\"}, \"network\": {\"ports\": 4}}', '{\"cpu_cores\": 4, \"ram_gb\": 16, \"storage_gb\": 500}', '{\"cpu_cores\": 16, \"ram_gb\": 64, \"storage_gb\": 4000}', 'database,server,performance,raid', NULL, '1.0', NULL, 1, NULL, NULL, '2025-08-06 02:38:37', '2025-08-06 02:38:37'),
(3, '745c79ae-726e-11f0-9219-309c239ceca6', 'Storage Server', 'Large capacity storage server with multiple drive bays', 'Storage Server', 1, 1, 0, '{\"cpu\": {\"cores\": 4, \"frequency\": \"2.5GHz\"}, \"ram\": {\"capacity\": \"16GB\", \"type\": \"DDR4\"}, \"storage\": {\"capacity\": \"20TB\", \"type\": \"SATA\", \"drives\": 8, \"redundancy\": \"RAID6\"}, \"network\": {\"ports\": 2, \"speed\": \"10Gb\"}}', '{\"cpu_cores\": 2, \"ram_gb\": 8, \"storage_gb\": 2000}', '{\"cpu_cores\": 8, \"ram_gb\": 32, \"storage_gb\": 50000}', 'storage,server,raid,backup', NULL, '1.0', NULL, 1, NULL, NULL, '2025-08-06 02:38:37', '2025-08-06 02:38:37');

-- --------------------------------------------------------

--
-- Table structure for table `server_configurations`
--

CREATE TABLE `server_configurations` (
  `id` int(11) NOT NULL,
  `config_uuid` varchar(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `server_name` varchar(255) NOT NULL COMMENT 'Server name for the configuration',
  `description` text DEFAULT NULL COMMENT 'Description of the server configuration',
  `motherboard_uuid` varchar(50) DEFAULT NULL,
  `chassis_uuid` varchar(50) DEFAULT NULL,
  `ram_configuration` longtext DEFAULT NULL COMMENT 'JSON array of RAM modules',
  `storage_configuration` longtext DEFAULT NULL COMMENT 'JSON array of storage devices',
  `caddy_configuration` longtext DEFAULT NULL COMMENT 'JSON array of caddies',
  `configuration_status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=Draft, 1=Validated, 2=Built, 3=Deployed',
  `power_consumption` int(11) DEFAULT NULL COMMENT 'Estimated power consumption in watts',
  `compatibility_score` decimal(3,2) DEFAULT NULL COMMENT 'Overall compatibility score',
  `validation_results` longtext DEFAULT NULL COMMENT 'JSON validation results',
  `created_by` int(6) UNSIGNED DEFAULT NULL COMMENT 'User who created the configuration',
  `updated_by` int(6) UNSIGNED DEFAULT NULL COMMENT 'User who last updated the configuration',
  `built_date` datetime DEFAULT NULL COMMENT 'When the server was physically built',
  `deployed_date` datetime DEFAULT NULL COMMENT 'When the server was deployed',
  `location` varchar(100) DEFAULT NULL COMMENT 'Physical location of built server',
  `rack_position` varchar(20) DEFAULT NULL COMMENT 'Rack position if deployed',
  `notes` text DEFAULT NULL COMMENT 'Additional notes about the configuration',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `pciecard_configurations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Array of PCIe card configurations with quantities and slot positions' CHECK (json_valid(`pciecard_configurations`)),
  `nic_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'NIC configuration details' CHECK (json_valid(`nic_config`)),
  `cpu_configuration` longtext DEFAULT NULL COMMENT 'JSON array of CPU configurations (1-2 CPUs max)',
  `hbacard_uuid` varchar(255) DEFAULT NULL COMMENT 'UUID of HBA card (maximum 1 per server)',
  `sfp_configuration` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'JSON array of SFP modules with parent NIC assignments and port indices' CHECK (json_valid(`sfp_configuration`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Server configurations and builds';

--
-- Dumping data for table `server_configurations`
--

INSERT INTO `server_configurations` (`id`, `config_uuid`, `server_name`, `description`, `motherboard_uuid`, `chassis_uuid`, `ram_configuration`, `storage_configuration`, `caddy_configuration`, `configuration_status`, `power_consumption`, `compatibility_score`, `validation_results`, `created_by`, `updated_by`, `built_date`, `deployed_date`, `location`, `rack_position`, `notes`, `created_at`, `updated_at`, `pciecard_configurations`, `nic_config`, `cpu_configuration`, `hbacard_uuid`, `sfp_configuration`) VALUES

(30, '8edc0ec7-4921-4261-b7d8-760eb0788551', 'FlexibleOrderTest', 'Testing flexible component addition order', NULL, NULL, NULL, NULL, NULL, 0, 384, 9.99, NULL, 38, NULL, NULL, NULL, '', '', '', '2025-09-23 21:44:22', '2025-11-09 19:52:11', NULL, NULL, '{\"cpus\": [{\"uuid\": \"d3b5f1c2-9f4e-4c2a-8e6b-7a9f3e2d1c4b\", \"quantity\": 1, \"socket\": \"LGA3647\", \"added_at\": \"2025-09-23 21:44:22\"}]}', NULL, NULL),

(31, '34851b18-b600-4415-8c5e-0a1cde1bceed', 'CPUFirstTest', 'Test adding CPU as first component', NULL, NULL, NULL, NULL, NULL, 0, 0, 0.00, NULL, 38, NULL, NULL, NULL, '', '', '', '2025-09-23 21:47:47', '2025-10-15 08:33:13', NULL, NULL, NULL, NULL, NULL),

(67, '214100e3-c7cd-4f01-8c27-eca2310b0bbb', 'Test-Shubham-changes', 'changes', '8c5f2b87-1e5b-4e8c-a1d2-0b1a5e3f4d6c', NULL, '[{\"uuid\":\"a1b2c3d4-e5f6-7890-1234-567890abcdef\",\"quantity\":1,\"added_at\":\"2025-11-07 07:50:38\"}]', '[{\"uuid\":\"a3b4c5d6-e7f8-a9b0-c1d2-e3f4a5b6c7d8\",\"quantity\":1,\"added_at\":\"2025-11-06 12:52:41\"},{\"uuid\":\"b4c5d6e7-f8a9-b0c1-d2e3-f4a5b6c7d8e9\",\"quantity\":1,\"added_at\":\"2025-11-07 13:16:06\"}]', '[{\"uuid\":\"4a8a2c05-e993-4b00-acae-9f036617091c\",\"quantity\":1,\"added_at\":\"2025-11-06 12:52:58\"}]', 3, 970, 9.99, NULL, 38, NULL, NULL, NULL, '', '', '', '2025-11-06 12:49:33', '2025-11-09 19:52:11', NULL, '{\n    \"nics\": [\n        {\n            \"uuid\": \"onboard-8c5f2b87-1\",\n            \"source_type\": \"onboard\",\n            \"parent_motherboard_uuid\": \"8c5f2b87-1e5b-4e8c-a1d2-0b1a5e3f4d6c\",\n            \"onboard_index\": 1,\n            \"status\": \"in_use\",\n            \"replaceable\": true,\n            \"specifications\": {\n                \"controller\": \"Intel X710\",\n                \"ports\": 2,\n                \"speed\": \"10GbE\",\n                \"connector\": \"SFP+\"\n            }\n        }\n    ],\n    \"summary\": {\n        \"total_nics\": 1,\n        \"onboard_nics\": 1,\n        \"component_nics\": 0\n    },\n    \"last_updated\": \"2025-11-07 07:49:44\"\n}', '{\"cpus\": [{\"uuid\": \"545e143b-57b3-419e-86e5-1df6f7aa8fd3\", \"quantity\": 1, \"socket\": \"LGA3647\", \"added_at\": \"2025-11-06 12:49:33\"}]}', NULL, NULL),

(68, 'aae87a84-b4cf-4344-97c1-83a99b0176a2', 'My Production Server', 'Web server for production workloads', NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, 38, NULL, NULL, NULL, '', '', '', '2025-11-09 15:36:25', '2025-11-09 15:36:25', NULL, NULL, NULL, NULL, NULL),

(69, '61303ed1-381f-4774-9604-09a29df0407a', 'My Production Server', 'Web server for production workloads', NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, 38, NULL, NULL, NULL, '', '', '', '2025-11-10 18:16:32', '2025-11-10 18:16:32', NULL, NULL, NULL, NULL, NULL),

(70, 'b8754c5b-f071-4446-bae1-f0fd5afd640f', 'My Production Server', 'Web server for production workloads', '6e4c2a5b-3a8e-4f7d-8b2c-9d1a4e5b6f7c', NULL, NULL, NULL, NULL, 0, 72, NULL, NULL, 38, NULL, NULL, NULL, '', '', '', '2025-11-12 07:52:53', '2025-11-12 13:49:41', NULL, '{\n    \"nics\": [\n        {\n            \"uuid\": \"onboard-6e4c2a5b-1\",\n            \"source_type\": \"onboard\",\n            \"parent_motherboard_uuid\": \"6e4c2a5b-3a8e-4f7d-8b2c-9d1a4e5b6f7c\",\n            \"onboard_index\": 1,\n            \"status\": \"in_use\",\n            \"replaceable\": true,\n            \"specifications\": {\n                \"controller\": \"Intel X710\",\n                \"ports\": 2,\n                \"speed\": \"10GbE\",\n                \"connector\": \"SFP+\"\n            }\n        }\n    ],\n    \"summary\": {\n        \"total_nics\": 1,\n        \"onboard_nics\": 1,\n        \"component_nics\": 0\n    },\n    \"last_updated\": \"2025-11-12 13:49:41\"\n}', NULL, NULL, NULL),

(72, '4841e506-6e58-46d2-ab77-6fa91996561c', 'My Production Server', 'Web server for production workloads', '4f8e6c3d-2b7a-4c9e-8d1b-5e6f7a3d9c8b', NULL, NULL, NULL, NULL, 0, 840, NULL, NULL, 38, NULL, NULL, NULL, '', '', '', '2025-11-13 13:14:07', '2025-11-18 17:50:18', NULL, '{\n    \"nics\": [\n        {\n            \"uuid\": \"onboard-4f8e6c3d-1\",\n            \"source_type\": \"onboard\",\n            \"parent_motherboard_uuid\": \"4f8e6c3d-2b7a-4c9e-8d1b-5e6f7a3d9c8b\",\n            \"onboard_index\": 1,\n            \"status\": \"in_use\",\n            \"replaceable\": true,\n            \"specifications\": {\n                \"controller\": \"Broadcom BCM57414\",\n                \"ports\": 2,\n                \"speed\": \"10GbE\",\n                \"connector\": \"SFP+\"\n            }\n        }\n    ],\n    \"summary\": {\n        \"total_nics\": 1,\n        \"onboard_nics\": 1,\n        \"component_nics\": 0\n    },\n    \"last_updated\": \"2025-11-18 17:50:18\"\n}', '{\"cpus\":[{\"uuid\":\"d3b5f1c2-9f4e-4c2a-8e6b-7a9f3e2d1c4b\",\"quantity\":1,\"socket\":\"LGA3647\",\"added_at\":\"2025-11-13 13:15:31\",\"serial_number\":\"CPU1009854\"},{\"uuid\":\"d3b5f1c2-9f4e-4c2a-8e6b-7a9f3e2d1c4b\",\"quantity\":1,\"socket\":\"LGA3647\",\"added_at\":\"2025-11-13 13:15:46\",\"serial_number\":\"AMD13241551\"}]}', NULL, NULL),

(73, 'bb40c2ab-2443-462b-a4fa-e36306fdfdcf', 'My Production Server', 'Web server for production workloads', NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, 38, NULL, NULL, NULL, '', '', '', '2025-11-19 19:56:15', '2025-11-19 19:56:15', NULL, NULL, NULL, NULL, NULL);


--
-- Triggers `server_configurations`
--
DELIMITER $$
CREATE TRIGGER `tr_server_config_validation` BEFORE UPDATE ON `server_configurations` FOR EACH ROW BEGIN
    -- Auto-update the updated_at timestamp
    SET NEW.updated_at = CURRENT_TIMESTAMP;
    
    -- Log the configuration change if there's a status change
    IF OLD.configuration_status != NEW.configuration_status THEN
        INSERT INTO `compatibility_log` 
        (`operation_type`, `component_uuid_1`, `user_id`, `created_at`) 
        VALUES ('status_change', NEW.config_uuid, NEW.updated_by, CURRENT_TIMESTAMP);
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `server_configuration_history`
--

CREATE TABLE `server_configuration_history` (
  `id` int(11) NOT NULL,
  `config_uuid` varchar(36) NOT NULL,
  `action` varchar(50) NOT NULL COMMENT 'created, updated, component_added, component_removed, validated, etc.',
  `user_id` int(11) NOT NULL,
  `changes` longtext DEFAULT NULL COMMENT 'JSON of changes made',
  `old_values` longtext DEFAULT NULL COMMENT 'JSON of old values',
  `new_values` longtext DEFAULT NULL COMMENT 'JSON of new values',
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `server_deployments`
--

CREATE TABLE `server_deployments` (
  `id` int(11) NOT NULL,
  `deployment_uuid` varchar(36) NOT NULL,
  `config_uuid` varchar(36) NOT NULL,
  `deployment_name` varchar(255) NOT NULL,
  `environment` varchar(50) NOT NULL COMMENT 'production, staging, development, testing',
  `location` varchar(255) DEFAULT NULL COMMENT 'Physical location or datacenter',
  `rack_position` varchar(50) DEFAULT NULL,
  `deployment_status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0=Planned, 1=In Progress, 2=Deployed, 3=Decommissioned',
  `deployed_by` int(11) DEFAULT NULL,
  `deployed_at` timestamp NULL DEFAULT NULL,
  `decommissioned_by` int(11) DEFAULT NULL,
  `decommissioned_at` timestamp NULL DEFAULT NULL,
  `ip_addresses` longtext DEFAULT NULL COMMENT 'JSON array of assigned IP addresses',
  `hostname` varchar(255) DEFAULT NULL,
  `domain` varchar(255) DEFAULT NULL,
  `os_type` varchar(100) DEFAULT NULL,
  `os_version` varchar(100) DEFAULT NULL,
  `installed_software` longtext DEFAULT NULL COMMENT 'JSON array of installed software',
  `monitoring_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `backup_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `last_maintenance` timestamp NULL DEFAULT NULL,
  `next_maintenance` timestamp NULL DEFAULT NULL,
  `maintenance_notes` text DEFAULT NULL,
  `cpu_utilization` decimal(5,2) DEFAULT NULL COMMENT 'Average CPU utilization percentage',
  `memory_utilization` decimal(5,2) DEFAULT NULL COMMENT 'Average memory utilization percentage',
  `storage_utilization` decimal(5,2) DEFAULT NULL COMMENT 'Average storage utilization percentage',
  `uptime_percentage` decimal(5,2) DEFAULT NULL COMMENT 'Uptime percentage',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sfpinventory`
--

CREATE TABLE `sfpinventory` (
  `ID` int(11) NOT NULL,
  `UUID` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Links to detailed specs in JSON',
  `SerialNumber` varchar(50) DEFAULT NULL COMMENT 'Manufacturer serial number',
  `Status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=Failed/Decommissioned, 1=Available, 2=In Use',
  `ServerUUID` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'UUID of server configuration where SFP is used',
  `Location` varchar(100) DEFAULT NULL COMMENT 'Physical location like datacenter, warehouse',
  `RackPosition` varchar(20) DEFAULT NULL COMMENT 'Specific rack/shelf position',
  `PurchaseDate` date DEFAULT NULL,
  `InstallationDate` date DEFAULT NULL COMMENT 'When installed in current server',
  `WarrantyEndDate` date DEFAULT NULL,
  `Flag` varchar(50) DEFAULT NULL COMMENT 'Quick status flag or category',
  `Notes` text DEFAULT NULL COMMENT 'Any additional info or history',
  `ParentNICUUID` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'UUID of NIC card this SFP is installed in',
  `PortIndex` int(11) DEFAULT NULL COMMENT 'Port number on the NIC (1, 2, 3, etc.)',
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='SFP/SFP+ transceiver module inventory';

--
-- Dumping data for table `sfpinventory`
--

INSERT INTO `sfpinventory` (`ID`, `UUID`, `SerialNumber`, `Status`, `ServerUUID`, `Location`, `RackPosition`, `PurchaseDate`, `InstallationDate`, `WarrantyEndDate`, `Flag`, `Notes`, `ParentNICUUID`, `PortIndex`, `CreatedAt`, `UpdatedAt`) VALUES
(1, 'sfp-intel-ftlx8571d3bcl-001', 'SN-INTEL-FTLX8571D3BCL-001', 1, NULL, 'Warehouse A', NULL, NULL, NULL, NULL, NULL, 'Intel FTLX8571D3BCL-IN - SFP+ SR 10Gbps 850nm 300m MMF', NULL, NULL, '2025-11-16 07:52:11', '2025-11-16 07:52:11'),
(2, 'sfp-intel-ftlx1471d3bcl-001', 'SN-INTEL-FTLX1471D3BCL-001', 1, NULL, 'Warehouse A', NULL, NULL, NULL, NULL, NULL, 'Intel FTLX1471D3BCL-IN - SFP+ LR 10Gbps 1310nm 10km SMF', NULL, NULL, '2025-11-16 07:52:11', '2025-11-16 07:52:11'),
(3, 'sfp-cisco-sfp10gsr-001', 'SN-CISCO-SFP10GSR-001', 1, NULL, 'Warehouse A', NULL, NULL, NULL, NULL, NULL, 'Cisco SFP-10G-SR - 10GBASE-SR 10Gbps 850nm 300m MMF', NULL, NULL, '2025-11-16 07:52:11', '2025-11-16 07:52:11'),
(4, 'sfp-cisco-sfp10glr-001', 'SN-CISCO-SFP10GLR-001', 1, NULL, 'Warehouse A', NULL, NULL, NULL, NULL, NULL, 'Cisco SFP-10G-LR - 10GBASE-LR 10Gbps 1310nm 10km SMF', NULL, NULL, '2025-11-16 07:52:11', '2025-11-16 07:52:11'),
(5, 'sfp-cisco-glcsxmmd-001', 'SN-CISCO-GLCSXMMD-001', 1, NULL, 'Warehouse A', NULL, NULL, NULL, NULL, NULL, 'Cisco GLC-SX-MMD - 1000BASE-SX 1Gbps 850nm 550m MMF', NULL, NULL, '2025-11-16 07:52:11', '2025-11-16 07:52:11'),
(6, 'sfp-hpe-j9150a-001', 'SN-HPE-J9150A-001', 1, NULL, 'Warehouse A', NULL, NULL, NULL, NULL, NULL, 'HPE J9150A - X132 10G SFP+ SR 10Gbps 850nm 300m MMF', NULL, NULL, '2025-11-16 07:52:11', '2025-11-16 07:52:11'),
(7, 'sfp-hpe-j9151a-001', 'SN-HPE-J9151A-001', 1, NULL, 'Warehouse A', NULL, NULL, NULL, NULL, NULL, 'HPE J9151A - X132 10G SFP+ LR 10Gbps 1310nm 10km SMF', NULL, NULL, '2025-11-16 07:52:11', '2025-11-16 07:52:11'),
(8, 'sfp-dell-sfp10gsr-001', 'SN-DELL-SFP10GSR-001', 1, NULL, 'Warehouse A', NULL, NULL, NULL, NULL, NULL, 'Dell Dell-SFP-10G-SR - 10GBASE-SR 10Gbps 850nm 300m MMF', NULL, NULL, '2025-11-16 07:52:11', '2025-11-16 07:52:11'),
(9, 'sfp-dell-sfp10glr-001', 'SN-DELL-SFP10GLR-001', 1, NULL, 'Warehouse A', NULL, NULL, NULL, NULL, NULL, 'Dell Dell-SFP-10G-LR - 10GBASE-LR 10Gbps 1310nm 10km SMF', NULL, NULL, '2025-11-16 07:52:11', '2025-11-16 07:52:11'),
(10, 'sfp-mellanox-mma2p00as-001', 'SN-MELLANOX-MMA2P00AS-001', 1, NULL, 'Warehouse A', NULL, NULL, NULL, NULL, NULL, 'Mellanox MMA2P00-AS - 25GbE SFP28 SR 25Gbps 850nm 100m MMF', NULL, NULL, '2025-11-16 07:52:11', '2025-11-16 07:52:11'),
(11, 'sfp-dac-10g-1m-001', 'SN-DAC-10G-1M-001', 1, NULL, 'Warehouse A', NULL, NULL, NULL, NULL, NULL, 'SFP-10G-DAC-1M - 10G SFP+ Direct Attach Copper 1m', NULL, NULL, '2025-11-16 07:52:11', '2025-11-16 07:52:11'),
(12, 'sfp-dac-10g-3m-001', 'SN-DAC-10G-3M-001', 1, NULL, 'Warehouse A', NULL, NULL, NULL, NULL, NULL, 'SFP-10G-DAC-3M - 10G SFP+ Direct Attach Copper 3m', NULL, NULL, '2025-11-16 07:52:11', '2025-11-16 07:52:11'),
(13, 'sfp-dac-10g-5m-001', 'SN-DAC-10G-5M-001', 1, NULL, 'Warehouse A', NULL, NULL, NULL, NULL, NULL, 'SFP-10G-DAC-5M - 10G SFP+ Direct Attach Copper 5m', NULL, NULL, '2025-11-16 07:52:11', '2025-11-16 07:52:11');

-- --------------------------------------------------------

--
-- Table structure for table `storageinventory`
--

CREATE TABLE `storageinventory` (
  `ID` int(11) NOT NULL,
  `UUID` varchar(50) NOT NULL COMMENT 'Links to detailed specs in JSON',
  `SerialNumber` varchar(50) DEFAULT NULL COMMENT 'Manufacturer serial number',
  `Status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=Failed/Decommissioned, 1=Available, 2=In Use',
  `ServerUUID` varchar(36) DEFAULT NULL COMMENT 'UUID of server where storage is installed, if any',
  `Location` varchar(100) DEFAULT NULL COMMENT 'Physical location like datacenter, warehouse',
  `RackPosition` varchar(20) DEFAULT NULL COMMENT 'Specific rack/shelf position',
  `PurchaseDate` date DEFAULT NULL,
  `InstallationDate` date DEFAULT NULL COMMENT 'When installed in current server',
  `WarrantyEndDate` date DEFAULT NULL,
  `Flag` varchar(50) DEFAULT NULL COMMENT 'Quick status flag or category',
  `Notes` text DEFAULT NULL COMMENT 'Any additional info or history',
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `storageinventory`
--

INSERT INTO `storageinventory` (`ID`, `UUID`, `SerialNumber`, `Status`, `ServerUUID`, `Location`, `RackPosition`, `PurchaseDate`, `InstallationDate`, `WarrantyEndDate`, `Flag`, `Notes`, `CreatedAt`, `UpdatedAt`) VALUES
(1, 'ee9b23f6-5960-4691-b411-e81987c12da0', 'SSD123456', 1, NULL, 'Datacenter North', 'Rack A3-12', '2023-05-12', '2023-06-01', '2026-05-12', 'Production', 'Samsung 980 PRO 2TB NVMe', '2025-05-11 11:42:52', '2025-10-15 08:40:03'),
(2, '9ee83441-f1dc-4ac1-82dd-6319b0725737', 'HDD789012', 1, NULL, 'Warehouse East', NULL, '2024-01-05', NULL, '2027-01-05', 'Backup', 'Seagate IronWolf 8TB NAS HDD', '2025-05-11 11:42:52', '2025-10-15 08:39:47'),
(3, 'e383d2a8-6ce7-46af-8ead-73f2f2921545', 'SSD987654', 1, NULL, '', NULL, '2020-08-10', NULL, '2023-08-10', 'Decommissioned', 'WD Black 1TB NVMe - SMART errors detected', '2025-05-11 11:42:52', '2025-10-15 08:38:59'),
(4, '43e1ad0d-cf4a-49c9-a750-b50f73e773f7', 'HDD789098', 1, NULL, '', NULL, '2025-07-30', NULL, '2029-10-25', 'Backup', 'Type: HDD, Capacity: 960GB\n\nAdditional Notes: crucial nvme gen 4 ', '2025-07-27 14:06:01', '2025-10-15 08:38:59'),
(5, 'e1a2b3c4-d5e6-f7a8-b9c0-d1e2f3a4b5c6', 'HDD999999', 1, NULL, '', NULL, '2025-08-30', NULL, '2031-06-30', 'Backup', 'HDD - undefined undefined SATA HDD 8000GB', '2025-08-30 10:57:23', '2025-11-06 12:47:21'),
(6, 'e7f8a9b0-c1d2-e3f4-a5b6-c7d8e9f0a1b2', 'SDD000000', 1, NULL, 'Mumbai', NULL, '2025-09-09', NULL, NULL, 'Available', 'Intel Optane 800GB U.3 PCIe 4.0 SSD, 7500MBps read, 5000MBps write, 1.5W idle, 8W active', '2025-09-09 12:42:56', '2025-11-06 10:48:33'),
(7, 'a3b4c5d6-e7f8-a9b0-c1d2-e3f4a5b6c7d8', 'SSD999999', 2, '214100e3-c7cd-4f01-8c27-eca2310b0bbb', '', '', '2025-10-04', '2025-11-06', NULL, 'Available', 'Brand: Samsung, Series: Data Center, Type: SSD (SATA SSD, 2.5-inch, SATA III), Capacity: 1000GB, Read: 550MBps, Write: 520MBps, Power Idle: 0.5W, Active: 3.2W', '2025-10-04 09:51:34', '2025-11-06 12:52:41'),
(8, 'b4c5d6e7-f8a9-b0c1-d2e3-f4a5b6c7d8e9', 'SSD111111', 2, '214100e3-c7cd-4f01-8c27-eca2310b0bbb', '', '', '2025-10-04', '2025-11-07', NULL, 'Available', 'Type: SSD (M.2 NVMe SSD, M.2 2280, NVMe PCIe 4.0), Capacity: 2000GB, Read: 7000MBps, Write: 5000MBps, Power Idle: 0.5W, Active: 5W', '2025-10-04 10:04:58', '2025-11-07 13:16:06');

-- --------------------------------------------------------

--
-- Table structure for table `tickets`
--

CREATE TABLE `tickets` (
  `id` int(10) UNSIGNED NOT NULL,
  `ticket_number` varchar(20) NOT NULL COMMENT 'Format: TKT-YYYYMMDD-XXXX',
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `status` enum('draft','pending','approved','in_progress','deployed','completed','rejected','cancelled') NOT NULL DEFAULT 'draft',
  `priority` enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `target_server_uuid` varchar(36) DEFAULT NULL COMMENT 'Server configuration UUID this ticket targets',
  `created_by` int(10) UNSIGNED NOT NULL COMMENT 'User ID who created ticket',
  `assigned_to` int(10) UNSIGNED DEFAULT NULL COMMENT 'User ID assigned to handle ticket',
  `rejection_reason` text DEFAULT NULL COMMENT 'Required when status = rejected',
  `deployment_notes` text DEFAULT NULL COMMENT 'Notes added during deployment',
  `completion_notes` text DEFAULT NULL COMMENT 'Notes added when completed',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `submitted_at` timestamp NULL DEFAULT NULL COMMENT 'When status changed from draft to pending',
  `approved_at` timestamp NULL DEFAULT NULL COMMENT 'When status changed to approved',
  `deployed_at` timestamp NULL DEFAULT NULL COMMENT 'When status changed to deployed',
  `completed_at` timestamp NULL DEFAULT NULL COMMENT 'When status changed to completed'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Core ticketing system table';

--
-- Dumping data for table `tickets`
--

INSERT INTO `tickets` (`id`, `ticket_number`, `title`, `description`, `status`, `priority`, `target_server_uuid`, `created_by`, `assigned_to`, `rejection_reason`, `deployment_notes`, `completion_notes`, `created_at`, `updated_at`, `submitted_at`, `approved_at`, `deployed_at`, `completed_at`) VALUES
(1, 'TKT-20251119-0001', 'Setup New Server Configuration', 'Build and test new server configuration with latest components', 'pending', 'high', NULL, 38, NULL, NULL, NULL, NULL, '2025-11-19 21:57:21', '2025-11-19 21:57:25', '2025-11-19 21:57:25', NULL, NULL, NULL),
(2, 'TKT-20251119-0002', 'Update Component Inventory', 'Add new CPUs and RAM modules to inventory', 'pending', 'medium', NULL, 38, NULL, NULL, NULL, NULL, '2025-11-19 21:57:22', '2025-11-19 21:57:26', '2025-11-19 21:57:26', NULL, NULL, NULL),
(3, 'TKT-20251119-0003', 'Configure Network Cards', 'Test and configure new network interface cards', 'pending', 'medium', NULL, 38, NULL, NULL, NULL, NULL, '2025-11-19 21:57:23', '2025-11-19 21:57:27', '2025-11-19 21:57:27', NULL, NULL, NULL),
(4, 'TKT-20251119-0004', 'Setup New Server Configuration', 'Build and test new server configuration with latest components', 'completed', 'high', NULL, 38, NULL, NULL, 'Successfully deployed all changes to staging environment', 'All tasks completed successfully. Ticket closure approved.', '2025-11-19 21:58:54', '2025-11-19 21:59:13', '2025-11-19 21:58:58', '2025-11-19 21:59:02', '2025-11-19 21:59:09', '2025-11-19 21:59:13'),
(5, 'TKT-20251119-0005', 'Update Component Inventory', 'Add new CPUs and RAM modules to inventory', 'completed', 'medium', NULL, 38, NULL, NULL, 'Successfully deployed all changes to staging environment', 'All tasks completed successfully. Ticket closure approved.', '2025-11-19 21:58:55', '2025-11-19 21:59:14', '2025-11-19 21:58:59', '2025-11-19 21:59:03', '2025-11-19 21:59:10', '2025-11-19 21:59:14'),
(6, 'TKT-20251119-0006', 'Configure Network Cards', 'Test and configure new network interface cards', 'completed', 'medium', NULL, 38, NULL, NULL, 'Successfully deployed all changes to staging environment', 'All tasks completed successfully. Ticket closure approved.', '2025-11-19 21:58:57', '2025-11-19 21:59:15', '2025-11-19 21:59:00', '2025-11-19 21:59:04', '2025-11-19 21:59:12', '2025-11-19 21:59:15');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_history`
--

CREATE TABLE `ticket_history` (
  `id` int(10) UNSIGNED NOT NULL,
  `ticket_id` int(10) UNSIGNED NOT NULL,
  `action` varchar(50) NOT NULL COMMENT 'created, submitted, approved, rejected, deployed, completed, etc.',
  `old_value` text DEFAULT NULL COMMENT 'Previous value (JSON for complex data)',
  `new_value` text DEFAULT NULL COMMENT 'New value (JSON for complex data)',
  `changed_by` int(10) UNSIGNED NOT NULL COMMENT 'User ID who made the change',
  `notes` text DEFAULT NULL COMMENT 'Additional notes about the change',
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'IP address of user',
  `user_agent` varchar(255) DEFAULT NULL COMMENT 'Browser/client info',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit trail for all ticket changes';

--
-- Dumping data for table `ticket_history`
--

INSERT INTO `ticket_history` (`id`, `ticket_id`, `action`, `old_value`, `new_value`, `changed_by`, `notes`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 1, 'created', NULL, 'draft', 38, 'Ticket created', '106.215.167.32', NULL, '2025-11-19 21:57:21'),
(2, 2, 'created', NULL, 'draft', 38, 'Ticket created', '106.215.167.32', NULL, '2025-11-19 21:57:22'),
(3, 3, 'created', NULL, 'draft', 38, 'Ticket created', '106.215.167.32', NULL, '2025-11-19 21:57:23'),
(4, 1, 'status_changed', 'draft', 'pending', 38, 'Status changed from draft to pending', '106.215.167.32', NULL, '2025-11-19 21:57:25'),
(5, 2, 'status_changed', 'draft', 'pending', 38, 'Status changed from draft to pending', '106.215.167.32', NULL, '2025-11-19 21:57:26'),
(6, 3, 'status_changed', 'draft', 'pending', 38, 'Status changed from draft to pending', '106.215.167.32', NULL, '2025-11-19 21:57:27'),
(7, 4, 'created', NULL, 'draft', 38, 'Ticket created', '106.215.167.32', NULL, '2025-11-19 21:58:54'),
(8, 5, 'created', NULL, 'draft', 38, 'Ticket created', '106.215.167.32', NULL, '2025-11-19 21:58:55'),
(9, 6, 'created', NULL, 'draft', 38, 'Ticket created', '106.215.167.32', NULL, '2025-11-19 21:58:57'),
(10, 4, 'status_changed', 'draft', 'pending', 38, 'Status changed from draft to pending', '106.215.167.32', NULL, '2025-11-19 21:58:58'),
(11, 5, 'status_changed', 'draft', 'pending', 38, 'Status changed from draft to pending', '106.215.167.32', NULL, '2025-11-19 21:58:59'),
(12, 6, 'status_changed', 'draft', 'pending', 38, 'Status changed from draft to pending', '106.215.167.32', NULL, '2025-11-19 21:59:00'),
(13, 4, 'status_changed', 'pending', 'approved', 38, 'Status changed from pending to approved', '106.215.167.32', NULL, '2025-11-19 21:59:02'),
(14, 5, 'status_changed', 'pending', 'approved', 38, 'Status changed from pending to approved', '106.215.167.32', NULL, '2025-11-19 21:59:03'),
(15, 6, 'status_changed', 'pending', 'approved', 38, 'Status changed from pending to approved', '106.215.167.32', NULL, '2025-11-19 21:59:04'),
(16, 4, 'status_changed', 'approved', 'in_progress', 38, 'Status changed from approved to in_progress', '106.215.167.32', NULL, '2025-11-19 21:59:05'),
(17, 5, 'status_changed', 'approved', 'in_progress', 38, 'Status changed from approved to in_progress', '106.215.167.32', NULL, '2025-11-19 21:59:07'),
(18, 6, 'status_changed', 'approved', 'in_progress', 38, 'Status changed from approved to in_progress', '106.215.167.32', NULL, '2025-11-19 21:59:08'),
(19, 4, 'status_changed', 'in_progress', 'deployed', 38, 'Status changed from in_progress to deployed', '106.215.167.32', NULL, '2025-11-19 21:59:09'),
(20, 5, 'status_changed', 'in_progress', 'deployed', 38, 'Status changed from in_progress to deployed', '106.215.167.32', NULL, '2025-11-19 21:59:10'),
(21, 6, 'status_changed', 'in_progress', 'deployed', 38, 'Status changed from in_progress to deployed', '106.215.167.32', NULL, '2025-11-19 21:59:12'),
(22, 4, 'status_changed', 'deployed', 'completed', 38, 'Status changed from deployed to completed', '106.215.167.32', NULL, '2025-11-19 21:59:13'),
(23, 5, 'status_changed', 'deployed', 'completed', 38, 'Status changed from deployed to completed', '106.215.167.32', NULL, '2025-11-19 21:59:14'),
(24, 6, 'status_changed', 'deployed', 'completed', 38, 'Status changed from deployed to completed', '106.215.167.32', NULL, '2025-11-19 21:59:15');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_items`
--

CREATE TABLE `ticket_items` (
  `id` int(10) UNSIGNED NOT NULL,
  `ticket_id` int(10) UNSIGNED NOT NULL,
  `component_type` enum('cpu','ram','storage','motherboard','nic','caddy','chassis','pciecard','hbacard') NOT NULL,
  `component_uuid` varchar(36) NOT NULL COMMENT 'UUID from All-JSON files',
  `quantity` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `action` enum('add','remove','replace') NOT NULL DEFAULT 'add',
  `component_name` varchar(255) NOT NULL COMMENT 'Component name at time of request',
  `component_specs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Full component specs snapshot' CHECK (json_valid(`component_specs`)),
  `is_validated` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 if UUID validated against JSON files',
  `is_compatible` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 if compatible with target server',
  `compatibility_notes` text DEFAULT NULL COMMENT 'Compatibility check results',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Components requested in tickets';

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(6) UNSIGNED NOT NULL,
  `firstname` varchar(255) DEFAULT NULL,
  `lastname` varchar(255) DEFAULT NULL,
  `username` varchar(30) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(50) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `firstname`, `lastname`, `username`, `password`, `email`, `status`, `created_at`) VALUES
(2, NULL, NULL, 'anshit_231', '$2y$10$49afdS31qberiejobMDGq.2bO.7Apsxn/0NvsdWo.QDHdRqpcyx6W', 'johnmater842002@gmail.com', 'active', '2025-03-24 17:57:43'),
(3, NULL, NULL, 'testuser', '$2y$10$e1YluvO9QmuYJ7MFXkzzW.27HOihwL51ygolzGfF1CucYloqOwUxS', 'test@example.com', 'active', '2025-04-09 07:15:22'),
(4, NULL, NULL, 'anurag', '$2y$10$IaC5Ck4aoiAtkv7q8nSDsurxfDQuH08ycbg3jYaYVe.3cyF8Mx/J6', 'anurag@example.com', 'active', '2025-04-09 10:54:03'),
(5, NULL, NULL, 'admin', '$2y$10$KOv6I6jirzJAoaJKSfRIp.4YRqxunh7o3cLJp3bV2cbXNE1uvHFQq', 'admin@example.com', 'active', '2025-05-08 12:44:51'),
(25, 'shubham', 'gurjar', 'a', '$2y$10$Cc7EaZcYgyUm0qzHTCOSmu.cxdtEm/UxHEfsqZAMiXgIItUKUiT/G', 'a@example.com', 'active', '2025-05-16 08:52:04'),
(26, 'a', 'a', 'aaa', '$2y$10$L3CpwB1bDWzcAsumdfdvv.31pEvl/utXjUsbGNmZ7j/Q0JPqVmjeq', 'patel69@gmail.com', 'active', '2025-05-25 11:36:13'),
(27, 'Admin', 'Test', 'admin_test', '$2y$10$hash_here', 'admin@test.com', 'active', '2025-06-02 17:08:53'),
(28, 'Tech', 'Test', 'tech_test', '$2y$10$hash_here', 'tech@test.com', 'active', '2025-06-02 17:08:53'),
(29, 'Viewer', 'Test', 'viewer_test', '$2y$10$hash_here', 'viewer@test.com', 'active', '2025-06-02 17:08:53'),
(37, 'John', 'Administrator', 'johnadmin', '$2y$10$zqBAUSMszh.D.Bl.pu.RO./wFf64RZqNWYVcW8hFSIKDpytWxhJC2', 'john.admin@company.com', 'active', '2025-06-18 11:03:56'),
(38, 'Super', 'Administrator', 'superadmin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'superadmin@yourcompany.com', 'active', '2025-07-25 07:03:57'),
(39, 'Shubham', 'Gurjar', 'Shubham', '$2y$10$UqRHeAJPqAdzbhg1hwL/l.Q9tKgRGQNVVRqSIteI4nwmcpGwb3hYW', 'shubham@bharatdatacenter.com', 'active', '2025-07-29 03:38:57'),
(42, '', '', 'john_doe', '$2y$10$zeJyD3DB/.tybxCuXUQM8Ogi6XxUJPdQkV0JmDsfX2HIXQOuVxUNS', 'john@example.com', 'active', '2025-10-28 16:27:37');

-- --------------------------------------------------------

--
-- Table structure for table `user_permissions`
--

CREATE TABLE `user_permissions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_permissions`
--

INSERT INTO `user_permissions` (`id`, `user_id`, `permission_id`, `created_at`) VALUES
(1, 38, 49885, '2025-10-22 14:42:56'),
(2, 38, 49887, '2025-10-22 14:42:56'),
(3, 38, 49886, '2025-10-22 14:42:56'),
(4, 38, 49884, '2025-10-22 14:42:56'),
(5, 38, 49889, '2025-10-22 14:42:56'),
(6, 38, 49891, '2025-10-22 14:42:56'),
(7, 38, 49890, '2025-10-22 14:42:56'),
(8, 38, 49888, '2025-10-22 14:42:56');

-- --------------------------------------------------------

--
-- Table structure for table `user_roles`
--

CREATE TABLE `user_roles` (
  `id` int(11) NOT NULL,
  `user_id` int(6) UNSIGNED NOT NULL,
  `role_id` int(11) NOT NULL,
  `assigned_by` int(6) UNSIGNED DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_roles`
--

INSERT INTO `user_roles` (`id`, `user_id`, `role_id`, `assigned_by`, `assigned_at`) VALUES
(2, 25, 5, NULL, '2025-07-25 01:27:40'),
(3, 26, 5, NULL, '2025-07-25 01:27:40'),
(4, 5, 5, NULL, '2025-07-25 01:27:40'),
(5, 27, 5, NULL, '2025-07-25 01:27:40'),
(6, 2, 5, NULL, '2025-07-25 01:27:40'),
(7, 4, 5, NULL, '2025-07-25 01:27:40'),
(8, 28, 5, NULL, '2025-07-25 01:27:40'),
(9, 3, 5, NULL, '2025-07-25 01:27:40'),
(10, 29, 5, NULL, '2025-07-25 01:27:40'),
(20, 39, 5, NULL, '2025-07-29 03:38:57'),
(24, 37, 1, NULL, '2025-08-20 09:38:23'),
(26, 38, 1, NULL, '2025-10-28 19:40:54'),
(28, 5, 2, NULL, '2025-11-12 19:58:10');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `acl_permissions`
--
ALTER TABLE `acl_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `permission_name` (`permission_name`);

--
-- Indexes for table `acl_roles`
--
ALTER TABLE `acl_roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_name` (`role_name`);

--
-- Indexes for table `auth_tokens`
--
ALTER TABLE `auth_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `fk_user` (`user_id`);

--
-- Indexes for table `caddyinventory`
--
ALTER TABLE `caddyinventory`
  ADD PRIMARY KEY (`ID`),
  ADD UNIQUE KEY `SerialNumber` (`SerialNumber`),
  ADD UNIQUE KEY `idx_serial_number` (`SerialNumber`),
  ADD KEY `idx_caddy_status` (`Status`),
  ADD KEY `idx_uuid` (`UUID`);

--
-- Indexes for table `chassisinventory`
--
ALTER TABLE `chassisinventory`
  ADD PRIMARY KEY (`ID`),
  ADD KEY `idx_uuid` (`UUID`);

--
-- Indexes for table `compatibility_log`
--
ALTER TABLE `compatibility_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_session_id` (`session_id`),
  ADD KEY `idx_operation_type` (`operation_type`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `compatibility_rules`
--
ALTER TABLE `compatibility_rules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `rule_name` (`rule_name`),
  ADD KEY `idx_rule_type` (`rule_type`),
  ADD KEY `idx_rule_priority` (`rule_priority`),
  ADD KEY `idx_is_active` (`is_active`);

--
-- Indexes for table `component_compatibility`
--
ALTER TABLE `component_compatibility`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_compatibility` (`component_type_1`,`component_uuid_1`,`component_type_2`,`component_uuid_2`),
  ADD KEY `idx_comp_type_1` (`component_type_1`),
  ADD KEY `idx_comp_type_2` (`component_type_2`),
  ADD KEY `idx_comp_uuid_1` (`component_uuid_1`),
  ADD KEY `idx_comp_uuid_2` (`component_uuid_2`),
  ADD KEY `idx_compatibility_status` (`compatibility_status`);

--
-- Indexes for table `component_specifications`
--
ALTER TABLE `component_specifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_component_spec` (`component_uuid`,`specification_key`),
  ADD KEY `idx_component_uuid` (`component_uuid`),
  ADD KEY `idx_component_type` (`component_type`),
  ADD KEY `idx_specification_key` (`specification_key`),
  ADD KEY `idx_is_searchable` (`is_searchable`),
  ADD KEY `idx_is_comparable` (`is_comparable`),
  ADD KEY `idx_verified_by` (`verified_by`);

--
-- Indexes for table `component_usage_tracking`
--
ALTER TABLE `component_usage_tracking`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_component_uuid` (`component_uuid`),
  ADD KEY `idx_component_type` (`component_type`),
  ADD KEY `idx_config_uuid` (`config_uuid`),
  ADD KEY `idx_deployment_uuid` (`deployment_uuid`),
  ADD KEY `idx_usage_status` (`usage_status`),
  ADD KEY `idx_assigned_by` (`assigned_by`),
  ADD KEY `idx_assigned_at` (`assigned_at`);

--
-- Indexes for table `cpuinventory`
--
ALTER TABLE `cpuinventory`
  ADD PRIMARY KEY (`ID`),
  ADD UNIQUE KEY `SerialNumber` (`SerialNumber`),
  ADD UNIQUE KEY `idx_serial_number` (`SerialNumber`),
  ADD KEY `idx_cpu_status` (`Status`),
  ADD KEY `idx_uuid` (`UUID`);

--
-- Indexes for table `hbacardinventory`
--
ALTER TABLE `hbacardinventory`
  ADD PRIMARY KEY (`ID`),
  ADD KEY `Status` (`Status`),
  ADD KEY `ServerUUID` (`ServerUUID`),
  ADD KEY `idx_uuid` (`UUID`);

--
-- Indexes for table `inventory_log`
--
ALTER TABLE `inventory_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `jwt_blacklist`
--
ALTER TABLE `jwt_blacklist`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `jti` (`jti`),
  ADD KEY `idx_expires_at` (`expires_at`);

--
-- Indexes for table `motherboardinventory`
--
ALTER TABLE `motherboardinventory`
  ADD PRIMARY KEY (`ID`),
  ADD UNIQUE KEY `SerialNumber` (`SerialNumber`),
  ADD UNIQUE KEY `idx_serial_number` (`SerialNumber`),
  ADD KEY `idx_motherboard_status` (`Status`),
  ADD KEY `idx_uuid` (`UUID`);

--
-- Indexes for table `nicinventory`
--
ALTER TABLE `nicinventory`
  ADD PRIMARY KEY (`ID`),
  ADD UNIQUE KEY `SerialNumber` (`SerialNumber`),
  ADD UNIQUE KEY `idx_serial_number` (`SerialNumber`),
  ADD KEY `idx_nic_status` (`Status`),
  ADD KEY `idx_source_type` (`SourceType`),
  ADD KEY `idx_parent_component` (`ParentComponentUUID`),
  ADD KEY `idx_uuid` (`UUID`);

--
-- Indexes for table `pciecardinventory`
--
ALTER TABLE `pciecardinventory`
  ADD PRIMARY KEY (`ID`),
  ADD UNIQUE KEY `SerialNumber` (`SerialNumber`),
  ADD KEY `idx_pciecard_status` (`Status`),
  ADD KEY `idx_uuid` (`UUID`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `idx_permission_name` (`name`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_is_basic` (`is_basic`),
  ADD KEY `idx_permissions_category_basic` (`category`,`is_basic`);

--
-- Indexes for table `raminventory`
--
ALTER TABLE `raminventory`
  ADD PRIMARY KEY (`ID`),
  ADD UNIQUE KEY `SerialNumber` (`SerialNumber`),
  ADD UNIQUE KEY `idx_serial_number` (`SerialNumber`),
  ADD KEY `idx_ram_status` (`Status`),
  ADD KEY `idx_uuid` (`UUID`);

--
-- Indexes for table `refresh_tokens`
--
ALTER TABLE `refresh_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token_hash` (`token_hash`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_expires_at` (`expires_at`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `idx_role_name` (`name`),
  ADD KEY `idx_is_default` (`is_default`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_role_permission` (`role_id`,`permission_id`),
  ADD KEY `idx_role_id` (`role_id`),
  ADD KEY `idx_permission_id` (`permission_id`),
  ADD KEY `idx_role_permissions_lookup` (`role_id`,`permission_id`,`granted`);

--
-- Indexes for table `server_build_templates`
--
ALTER TABLE `server_build_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `template_uuid` (`template_uuid`),
  ADD KEY `idx_template_uuid` (`template_uuid`),
  ADD KEY `idx_created_by` (`created_by`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_is_public` (`is_public`),
  ADD KEY `idx_use_count` (`use_count`),
  ADD KEY `idx_parent_template` (`parent_template_id`);

--
-- Indexes for table `server_configurations`
--
ALTER TABLE `server_configurations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `config_uuid` (`config_uuid`),
  ADD UNIQUE KEY `idx_config_uuid_unique` (`config_uuid`),
  ADD UNIQUE KEY `idx_config_uuid` (`config_uuid`),
  ADD KEY `idx_config_status` (`configuration_status`),
  ADD KEY `idx_created_by` (`created_by`),
  ADD KEY `idx_chassis_uuid` (`chassis_uuid`),
  ADD KEY `idx_is_test` (`is_test`),
  ADD KEY `idx_created_by_is_test` (`created_by`,`is_test`),
  ADD KEY `idx_created_by_date` (`created_by`,`created_at`),
  ADD KEY `idx_status` (`configuration_status`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_hbacard_uuid` (`hbacard_uuid`);

--
-- Indexes for table `server_configuration_history`
--
ALTER TABLE `server_configuration_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_config_uuid` (`config_uuid`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `server_deployments`
--
ALTER TABLE `server_deployments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `deployment_uuid` (`deployment_uuid`),
  ADD KEY `idx_deployment_uuid` (`deployment_uuid`),
  ADD KEY `idx_config_uuid` (`config_uuid`),
  ADD KEY `idx_environment` (`environment`),
  ADD KEY `idx_deployment_status` (`deployment_status`),
  ADD KEY `idx_deployed_by` (`deployed_by`),
  ADD KEY `idx_hostname` (`hostname`),
  ADD KEY `idx_location` (`location`);

--
-- Indexes for table `sfpinventory`
--
ALTER TABLE `sfpinventory`
  ADD PRIMARY KEY (`ID`),
  ADD KEY `idx_uuid` (`UUID`),
  ADD KEY `idx_status` (`Status`),
  ADD KEY `idx_parent_nic` (`ParentNICUUID`),
  ADD KEY `idx_server_uuid` (`ServerUUID`);

--
-- Indexes for table `storageinventory`
--
ALTER TABLE `storageinventory`
  ADD PRIMARY KEY (`ID`),
  ADD UNIQUE KEY `SerialNumber` (`SerialNumber`),
  ADD UNIQUE KEY `idx_serial_number` (`SerialNumber`),
  ADD KEY `idx_storage_status` (`Status`),
  ADD KEY `idx_uuid` (`UUID`);

--
-- Indexes for table `tickets`
--
ALTER TABLE `tickets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ticket_number` (`ticket_number`),
  ADD KEY `idx_ticket_number` (`ticket_number`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_by` (`created_by`),
  ADD KEY `idx_assigned_to` (`assigned_to`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_target_server` (`target_server_uuid`);

--
-- Indexes for table `ticket_history`
--
ALTER TABLE `ticket_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ticket_id` (`ticket_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_changed_by` (`changed_by`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `ticket_items`
--
ALTER TABLE `ticket_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ticket_id` (`ticket_id`),
  ADD KEY `idx_component_type` (`component_type`),
  ADD KEY `idx_component_uuid` (`component_uuid`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_permission` (`user_id`,`permission_id`);

--
-- Indexes for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_role` (`user_id`,`role_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_role_id` (`role_id`),
  ADD KEY `fk_user_roles_assigned_by` (`assigned_by`),
  ADD KEY `idx_user_roles_lookup` (`user_id`,`role_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `acl_permissions`
--
ALTER TABLE `acl_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=110;

--
-- AUTO_INCREMENT for table `acl_roles`
--
ALTER TABLE `acl_roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `auth_tokens`
--
ALTER TABLE `auth_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=413;

--
-- AUTO_INCREMENT for table `caddyinventory`
--
ALTER TABLE `caddyinventory`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `chassisinventory`
--
ALTER TABLE `chassisinventory`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `compatibility_log`
--
ALTER TABLE `compatibility_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `compatibility_rules`
--
ALTER TABLE `compatibility_rules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `component_compatibility`
--
ALTER TABLE `component_compatibility`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `component_specifications`
--
ALTER TABLE `component_specifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `component_usage_tracking`
--
ALTER TABLE `component_usage_tracking`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cpuinventory`
--
ALTER TABLE `cpuinventory`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `hbacardinventory`
--
ALTER TABLE `hbacardinventory`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `inventory_log`
--
ALTER TABLE `inventory_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=110;

--
-- AUTO_INCREMENT for table `jwt_blacklist`
--
ALTER TABLE `jwt_blacklist`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `motherboardinventory`
--
ALTER TABLE `motherboardinventory`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `nicinventory`
--
ALTER TABLE `nicinventory`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=178;

--
-- AUTO_INCREMENT for table `pciecardinventory`
--
ALTER TABLE `pciecardinventory`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=61547;

--
-- AUTO_INCREMENT for table `raminventory`
--
ALTER TABLE `raminventory`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `refresh_tokens`
--
ALTER TABLE `refresh_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4387;

--
-- AUTO_INCREMENT for table `role_permissions`
--
ALTER TABLE `role_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=498;

--
-- AUTO_INCREMENT for table `server_build_templates`
--
ALTER TABLE `server_build_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `server_configurations`
--
ALTER TABLE `server_configurations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=74;

--
-- AUTO_INCREMENT for table `server_configuration_history`
--
ALTER TABLE `server_configuration_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `server_deployments`
--
ALTER TABLE `server_deployments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sfpinventory`
--
ALTER TABLE `sfpinventory`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `storageinventory`
--
ALTER TABLE `storageinventory`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `tickets`
--
ALTER TABLE `tickets`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `ticket_history`
--
ALTER TABLE `ticket_history`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `ticket_items`
--
ALTER TABLE `ticket_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(6) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- AUTO_INCREMENT for table `user_permissions`
--
ALTER TABLE `user_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `user_roles`
--
ALTER TABLE `user_roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `auth_tokens`
--
ALTER TABLE `auth_tokens`
  ADD CONSTRAINT `fk_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `inventory_log`
--
ALTER TABLE `inventory_log`
  ADD CONSTRAINT `inventory_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `refresh_tokens`
--
ALTER TABLE `refresh_tokens`
  ADD CONSTRAINT `fk_refresh_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `server_configurations`
--
ALTER TABLE `server_configurations`
  ADD CONSTRAINT `fk_server_config_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `tickets`
--
ALTER TABLE `tickets`
  ADD CONSTRAINT `tickets_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `tickets_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `ticket_history`
--
ALTER TABLE `ticket_history`
  ADD CONSTRAINT `ticket_history_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ticket_history_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `ticket_items`
--
ALTER TABLE `ticket_items`
  ADD CONSTRAINT `ticket_items_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD CONSTRAINT `fk_user_roles_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
