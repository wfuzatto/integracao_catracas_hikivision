-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Tempo de geração: 22/09/2026 às 20:28
-- Versão do servidor: 10.4.32-MariaDB
-- Versão do PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Banco de dados: `visitor_app`
--

-- --------------------------------------------------------

--
-- Estrutura para tabela `access_levels`
--

CREATE TABLE `access_levels` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `access_levels`
--

INSERT INTO `access_levels` (`id`, `name`, `active`, `created_at`) VALUES
(4, 'ENTRADA ACQUAVALE', 1, '2026-09-21 01:49:28'),
(5, 'SAIDA ACQUAVALE', 1, '2026-09-21 03:07:52'),
(6, 'CATRACAS SABIA', 1, '2026-09-22 02:15:12');

-- --------------------------------------------------------

--
-- Estrutura para tabela `app_settings`
--

CREATE TABLE `app_settings` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `app_settings`
--

INSERT INTO `app_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES
('auto_checkout_enabled', '0', '2026-09-22 16:56:57');

-- --------------------------------------------------------

--
-- Estrutura para tabela `guest_groups`
--

CREATE TABLE `guest_groups` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `guest_groups`
--

INSERT INTO `guest_groups` (`id`, `name`, `active`, `created_at`) VALUES
(1, 'Hospedes Vale', 1, '2026-09-19 21:43:25'),
(2, 'Hóspedes Serra', 1, '2026-09-19 21:43:25'),
(3, 'Day use', 1, '2026-09-19 21:43:25');

-- --------------------------------------------------------

--
-- Estrutura para tabela `reservations`
--

CREATE TABLE `reservations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `first_name` varchar(120) NOT NULL,
  `last_name` varchar(120) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `group_id` int(10) UNSIGNED DEFAULT NULL,
  `access_level_id` int(10) UNSIGNED DEFAULT NULL,
  `entry_at` datetime NOT NULL,
  `exit_at` datetime NOT NULL,
  `document_type` enum('CPF','RG','CNH','OUTRO') DEFAULT NULL,
  `document_number` varchar(80) DEFAULT NULL,
  `gender` enum('F','M','N') DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `qr_token` char(48) NOT NULL,
  `qr_payload` varchar(255) NOT NULL,
  `status` enum('PENDING','SENT','ACTIVE','ERROR','CANCELLED') NOT NULL DEFAULT 'PENDING',
  `visitor_flow_status` enum('REGISTERED','CHECKED_IN') NOT NULL DEFAULT 'REGISTERED',
  `hcp_reference` varchar(190) DEFAULT NULL,
  `hcp_visitor_id` varchar(190) DEFAULT NULL,
  `hcp_appoint_code` varchar(190) DEFAULT NULL,
  `hcp_qr_image_path` varchar(255) DEFAULT NULL,
  `hcp_last_error` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `hcp_registration_id` varchar(190) DEFAULT NULL,
  `hcp_delivery_report` longtext DEFAULT NULL,
  `hcp_assigned_level_id` varchar(190) DEFAULT NULL,
  `hcp_delivery_state` varchar(24) DEFAULT NULL,
  `hcp_delivery_verified_at` datetime DEFAULT NULL,
  `hcp_checkout_state` varchar(24) DEFAULT NULL,
  `hcp_checkout_report` longtext DEFAULT NULL,
  `hcp_checkout_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `reservations`
--

INSERT INTO `reservations` (`id`, `first_name`, `last_name`, `email`, `phone`, `group_id`, `access_level_id`, `entry_at`, `exit_at`, `document_type`, `document_number`, `gender`, `photo_path`, `qr_token`, `qr_payload`, `status`, `visitor_flow_status`, `hcp_reference`, `hcp_visitor_id`, `hcp_appoint_code`, `hcp_qr_image_path`, `hcp_last_error`, `created_at`, `updated_at`, `hcp_registration_id`, `hcp_delivery_report`, `hcp_assigned_level_id`, `hcp_delivery_state`, `hcp_delivery_verified_at`, `hcp_checkout_state`, `hcp_checkout_report`, `hcp_checkout_at`) VALUES
(1, 'Wesley', 'Nascimento', 'wesley@prodatastelecom.com.br', '5535988566222', 3, 4, '2026-09-20 08:00:00', '2026-09-20 23:59:00', 'CPF', '109.670.596-66', 'M', 'uploads/faces/74369670a5fbfdd45699b11317bba24ba3c8.png', '1fa28292cb356f3ef5283568795408acdc85cd33087ead70', 'VALEVISITOR:1fa28292cb356f3ef5283568795408acdc85cd33087ead70', 'ACTIVE', 'REGISTERED', '757064429350158336', '1', '1401', 'uploads/qr/hcp-1-3f7314f4201ac83f.png', NULL, '2026-09-19 22:27:51', '2026-09-21 02:50:04', '757068224998146048', '{\"state\":\"confirmed\",\"segment\":\"ENTRADA ACQUAVALE\",\"levelId\":\"1\",\"source\":\"HikCentral\",\"checkedAt\":\"2026-09-20T23:50:04-03:00\",\"doors\":[{\"id\":\"3\",\"name\":\"192.168.104.12-ISAPI_Door_1\",\"state\":\"confirmed\",\"face\":true,\"credential\":true,\"certificates\":[{\"type\":4,\"status\":0},{\"type\":0,\"status\":0},{\"type\":2,\"status\":0}]},{\"id\":\"14\",\"name\":\"192.168.104.13-ISAPI_Door_1\",\"state\":\"confirmed\",\"face\":true,\"credential\":true,\"certificates\":[{\"type\":4,\"status\":0},{\"type\":0,\"status\":0},{\"type\":2,\"status\":0}]},{\"id\":\"25\",\"name\":\"192.168.104.14-ISAPI_Door_1\",\"state\":\"confirmed\",\"face\":true,\"credential\":true,\"certificates\":[{\"type\":4,\"status\":0},{\"type\":0,\"status\":0},{\"type\":2,\"status\":0}]},{\"id\":\"32\",\"name\":\"192.168.104.15-ISAPI_Door_1\",\"state\":\"confirmed\",\"face\":true,\"credential\":true,\"certificates\":[{\"type\":4,\"status\":0},{\"type\":0,\"status\":0},{\"type\":2,\"status\":0}]}]}', '1', 'confirmed', '2026-09-20 23:50:04', NULL, NULL, NULL),
(2, 'Wesley', 'Nascimento', 'wesley@prodatastelecom.com.br', '5535988566222', 3, 4, '2026-09-21 00:16:00', '2026-09-22 00:16:00', 'CPF', '109.670.596-66', 'M', 'uploads/faces/3144c862d742999293978da9eae542fa9ebd.png', 'd97a8e317b068065d23ffc243a1d2450b25c55277745c4db', 'VALEVISITOR:d97a8e317b068065d23ffc243a1d2450b25c55277745c4db', 'PENDING', 'REGISTERED', NULL, NULL, NULL, NULL, 'HikCentral (60050): The Visitor already appointed', '2026-09-21 03:16:11', '2026-09-21 03:47:06', NULL, NULL, NULL, 'failed', NULL, NULL, NULL, NULL),
(3, 'Wesley', 'Nascimento', 'wesley@prodatastelecom.com.br', '5535988566222', 3, 4, '2026-09-21 00:43:00', '2026-09-22 00:43:00', 'CPF', '109.670.596-66', 'M', 'uploads/faces/9cbcf6701667906b408a17dc42a91b61a797.png', '60c74128d10d01e49466154fa0a4b856731b01f032c02e29', 'VALEVISITOR:60c74128d10d01e49466154fa0a4b856731b01f032c02e29', 'ACTIVE', 'REGISTERED', '757084637586522112', '2', '5455', 'uploads/qr/hcp-3-7c9fc6bc5c8f6089.png', NULL, '2026-09-21 03:43:51', '2026-09-21 03:44:10', NULL, '{\"state\":\"confirmed\",\"segment\":\"ENTRADA ACQUAVALE + SAIDA ACQUAVALE\",\"levelId\":\"1,2\",\"source\":\"HikCentral\",\"checkedAt\":\"2026-09-21T00:44:10-03:00\",\"doors\":[{\"id\":\"3\",\"name\":\"192.168.104.12-ISAPI_Door_1\",\"state\":\"confirmed\",\"face\":true,\"credential\":true,\"certificates\":[{\"type\":4,\"status\":0},{\"type\":0,\"status\":0},{\"type\":2,\"status\":0}]},{\"id\":\"14\",\"name\":\"192.168.104.13-ISAPI_Door_1\",\"state\":\"confirmed\",\"face\":true,\"credential\":true,\"certificates\":[{\"type\":4,\"status\":0},{\"type\":0,\"status\":0},{\"type\":2,\"status\":0}]},{\"id\":\"25\",\"name\":\"192.168.104.14-ISAPI_Door_1\",\"state\":\"confirmed\",\"face\":true,\"credential\":true,\"certificates\":[{\"type\":4,\"status\":0},{\"type\":0,\"status\":0},{\"type\":2,\"status\":0}]},{\"id\":\"32\",\"name\":\"192.168.104.15-ISAPI_Door_1\",\"state\":\"confirmed\",\"face\":true,\"credential\":true,\"certificates\":[{\"type\":4,\"status\":0},{\"type\":0,\"status\":0},{\"type\":2,\"status\":0}]},{\"id\":\"39\",\"name\":\"192.168.104.22-ISAPI_Door_1\",\"state\":\"confirmed\",\"face\":true,\"credential\":true,\"certificates\":[{\"type\":4,\"status\":0},{\"type\":0,\"status\":0},{\"type\":2,\"status\":0}]},{\"id\":\"50\",\"name\":\"192.168.104.23-ISAPI_Door_1\",\"state\":\"confirmed\",\"face\":true,\"credential\":true,\"certificates\":[{\"type\":4,\"status\":0},{\"type\":0,\"status\":0},{\"type\":2,\"status\":0}]},{\"id\":\"78\",\"name\":\"192.168.104.24-ISAPI_Door_1\",\"state\":\"confirmed\",\"face\":true,\"credential\":true,\"certificates\":[{\"type\":4,\"status\":0},{\"type\":0,\"status\":0},{\"type\":2,\"status\":0}]}]}', '1,2', 'confirmed', '2026-09-21 00:44:10', NULL, NULL, NULL),
(4, 'Teste', 'teste', 'teste@teste.com', '123123123123', 1, 6, '2026-09-22 09:00:00', '2026-09-22 17:00:00', 'CPF', '12345678909', 'F', 'uploads/faces/63aed3b8f23718b921f596332523fb560c83.png', '71f351f4c28048b7881e0b25b051cf1666327df59e4bde8e', 'VALEVISITOR:71f351f4c28048b7881e0b25b051cf1666327df59e4bde8e', 'ACTIVE', 'CHECKED_IN', '757605787534098432', '5', '6390', 'uploads/qr/hcp-4-cf3078fc2a921d7d.png', NULL, '2026-09-22 14:00:45', '2026-09-22 14:33:23', '757609092217831424', '{\"state\":\"confirmed\",\"segment\":\"CATRACAS SABIA\",\"levelId\":\"3\",\"source\":\"HikCentral\",\"checkedAt\":\"2026-09-22T11:33:23-03:00\",\"doors\":[{\"id\":\"57\",\"name\":\"192.168.81.177-ISAPI_Door_1\",\"state\":\"confirmed\",\"face\":true,\"credential\":true,\"certificates\":[{\"type\":4,\"status\":0},{\"type\":0,\"status\":0},{\"type\":2,\"status\":0}]},{\"id\":\"64\",\"name\":\"192.168.81.178-ISAPI_Door_1\",\"state\":\"confirmed\",\"face\":true,\"credential\":true,\"certificates\":[{\"type\":4,\"status\":0},{\"type\":0,\"status\":0},{\"type\":2,\"status\":0}]}]}', '3', 'confirmed', '2026-09-22 11:33:23', NULL, NULL, NULL),
(5, 'Teste2', 'teste2', 'test2@teste.com', '32165498779', 2, 6, '2026-09-22 11:34:00', '2026-09-22 11:53:00', 'CPF', '98765432100', 'F', 'uploads/faces/fa6f37aa999e6f10b9414e6e38881a911fcc.jpg', '13cf966c5403ec5ecccccd736315b7a1d77d947767d2e4d6', 'VALEVISITOR:13cf966c5403ec5ecccccd736315b7a1d77d947767d2e4d6', 'ACTIVE', 'CHECKED_IN', '757613662079811584', '6', '7819', 'uploads/qr/hcp-5-cd97bc2c07105685.png', NULL, '2026-09-22 14:34:22', '2026-09-22 14:46:20', '757613662750900224', '{\"state\":\"confirmed\",\"segment\":\"CATRACAS SABIA\",\"levelId\":\"3\",\"source\":\"HikCentral\",\"checkedAt\":\"2026-09-22T11:46:20-03:00\",\"doors\":[{\"id\":\"57\",\"name\":\"192.168.81.177-ISAPI_Door_1\",\"state\":\"confirmed\",\"face\":true,\"credential\":true,\"certificates\":[{\"type\":4,\"status\":0},{\"type\":0,\"status\":0},{\"type\":2,\"status\":0}]},{\"id\":\"64\",\"name\":\"192.168.81.178-ISAPI_Door_1\",\"state\":\"confirmed\",\"face\":true,\"credential\":true,\"certificates\":[{\"type\":4,\"status\":0},{\"type\":0,\"status\":0},{\"type\":2,\"status\":0}]}]}', '3', 'confirmed', '2026-09-22 11:46:20', NULL, NULL, NULL),
(6, 'teste3', 'teste3', 'teste3@vale.com', '35646465465', 1, 6, '2026-09-22 11:54:00', '2026-09-22 11:56:00', 'CPF', '312314123', 'F', 'uploads/faces/7e74ad63e476944a200e96dd8a8088fa990c.jpg', '34174d99befe18e282311fd61e7ec72d86d38fe40ba33a96', 'VALEVISITOR:34174d99befe18e282311fd61e7ec72d86d38fe40ba33a96', 'SENT', 'CHECKED_IN', '757615782971572224', '7', '5197', 'uploads/qr/hcp-6-25f754bee81f071d.png', NULL, '2026-09-22 14:54:26', '2026-09-22 16:31:43', '757615783814627328', '{\"state\":\"queued\",\"segment\":\"CATRACAS SABIA\",\"levelId\":\"3\",\"source\":\"HikCentral\",\"checkedAt\":\"2026-09-22T13:31:43-03:00\",\"doors\":[{\"id\":\"57\",\"name\":\"192.168.81.177-ISAPI_Door_1\",\"state\":\"queued\",\"face\":false,\"credential\":false,\"certificates\":[]},{\"id\":\"64\",\"name\":\"192.168.81.178-ISAPI_Door_1\",\"state\":\"queued\",\"face\":false,\"credential\":false,\"certificates\":[]}]}', '3', 'queued', NULL, 'failed', '{\"state\":\"failed\",\"error\":\"HikCentral (69): UnAuthorized API\"}', '2026-09-22 13:31:30'),
(7, 'teste 5', 'teste 5', 'teste5@vale.com', '12313123', 2, 6, '2026-09-22 13:32:00', '2026-09-22 13:36:00', 'CPF', '56124312', 'F', 'uploads/faces/669f71feea881fed82270a7c42d0f180e718.png', '8518b4ed52f5c48b427847eb6ecdeadd9227318c2de47d7f', 'VALEVISITOR:8518b4ed52f5c48b427847eb6ecdeadd9227318c2de47d7f', 'ACTIVE', 'CHECKED_IN', '757640501447884800', '8', '5545', 'uploads/qr/hcp-7-fd90f00f2eebe19c.png', NULL, '2026-09-22 16:32:49', '2026-09-22 16:55:47', '757640502068641792', '{\"state\":\"confirmed\",\"segment\":\"CATRACAS SABIA\",\"levelId\":\"3\",\"source\":\"HikCentral\",\"checkedAt\":\"2026-09-22T13:32:56-03:00\",\"doors\":[{\"id\":\"57\",\"name\":\"192.168.81.177-ISAPI_Door_1\",\"state\":\"confirmed\",\"face\":true,\"credential\":true,\"certificates\":[{\"type\":4,\"status\":0},{\"type\":0,\"status\":0},{\"type\":2,\"status\":0}]},{\"id\":\"64\",\"name\":\"192.168.81.178-ISAPI_Door_1\",\"state\":\"confirmed\",\"face\":true,\"credential\":true,\"certificates\":[{\"type\":4,\"status\":0},{\"type\":0,\"status\":0},{\"type\":2,\"status\":0}]}]}', '3', 'confirmed', '2026-09-22 13:32:56', 'already_closed', '{\"state\":\"already_closed\",\"visitorStatus\":\"5\",\"statusSource\":\"registration_record\",\"registrationRecordStatus\":\"2\"}', '2026-09-22 13:55:47');

-- --------------------------------------------------------

--
-- Estrutura para tabela `reservation_access_levels`
--

CREATE TABLE `reservation_access_levels` (
  `reservation_id` bigint(20) UNSIGNED NOT NULL,
  `access_level_id` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `reservation_access_levels`
--

INSERT INTO `reservation_access_levels` (`reservation_id`, `access_level_id`) VALUES
(1, 4),
(2, 4),
(2, 5),
(3, 4),
(3, 5),
(4, 6),
(5, 6),
(6, 6),
(7, 6);

--
-- Índices para tabelas despejadas
--

--
-- Índices de tabela `access_levels`
--
ALTER TABLE `access_levels`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Índices de tabela `app_settings`
--
ALTER TABLE `app_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Índices de tabela `guest_groups`
--
ALTER TABLE `guest_groups`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Índices de tabela `reservations`
--
ALTER TABLE `reservations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `qr_token` (`qr_token`),
  ADD KEY `fk_res_group` (`group_id`),
  ADD KEY `fk_res_access` (`access_level_id`),
  ADD KEY `idx_res_dates` (`entry_at`,`exit_at`),
  ADD KEY `idx_res_status` (`status`),
  ADD KEY `idx_res_name` (`last_name`,`first_name`);

--
-- Índices de tabela `reservation_access_levels`
--
ALTER TABLE `reservation_access_levels`
  ADD PRIMARY KEY (`reservation_id`,`access_level_id`),
  ADD KEY `fk_ral_access` (`access_level_id`);

--
-- AUTO_INCREMENT para tabelas despejadas
--

--
-- AUTO_INCREMENT de tabela `access_levels`
--
ALTER TABLE `access_levels`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de tabela `guest_groups`
--
ALTER TABLE `guest_groups`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de tabela `reservations`
--
ALTER TABLE `reservations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- Restrições para tabelas despejadas
--

--
-- Restrições para tabelas `reservations`
--
ALTER TABLE `reservations`
  ADD CONSTRAINT `fk_res_access` FOREIGN KEY (`access_level_id`) REFERENCES `access_levels` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_res_group` FOREIGN KEY (`group_id`) REFERENCES `guest_groups` (`id`) ON DELETE SET NULL;

--
-- Restrições para tabelas `reservation_access_levels`
--
ALTER TABLE `reservation_access_levels`
  ADD CONSTRAINT `fk_ral_access` FOREIGN KEY (`access_level_id`) REFERENCES `access_levels` (`id`),
  ADD CONSTRAINT `fk_ral_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
