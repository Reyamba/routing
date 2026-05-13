-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 13, 2026 at 09:31 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `routing`
--

-- --------------------------------------------------------

--
-- Table structure for table `documents`
--

CREATE TABLE `documents` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `status` varchar(64) NOT NULL DEFAULT 'Pending',
  `route_state` varchar(32) NOT NULL DEFAULT 'Pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `receiver_name` varchar(255) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `sender_name` varchar(255) DEFAULT NULL,
  `received_at` datetime DEFAULT NULL,
  `doc_name` varchar(255) DEFAULT NULL,
  `control_no` varchar(255) DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `file_size` bigint(20) DEFAULT NULL,
  `type` varchar(10) DEFAULT NULL,
  `owner` varchar(255) DEFAULT NULL,
  `date_created` datetime DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `date_time_receiving` datetime DEFAULT NULL,
  `time_stamp_received` datetime DEFAULT NULL,
  `time_record_in_logbook` datetime DEFAULT NULL,
  `date_time_filed` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `document_history`
--

CREATE TABLE `document_history` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `action` varchar(64) NOT NULL,
  `actor` varchar(255) NOT NULL,
  `from_state` varchar(32) DEFAULT NULL,
  `to_state` varchar(32) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `email`, `reset_token`, `reset_expires`) VALUES
(4, 'staff', '$2y$10$b6ztneyUXHfKBm5i7oWUq.whyy5B37yZBulKuzznIRD7h2Ab7hAri', 'naldozarey29@gmail.com', NULL, NULL),
(5, 'admin', '$2y$10$r3ql2QwJWIZEcTnFdqV/cehuNgaFdfAQzOi8khbL1jwIt5z/Icq96', 'adsd@gmial.com', NULL, NULL),
(7, 'joseph', '$2y$10$nShb6CsdHkfJys7hcrT7C.w.EgsXGANzd8zmG0tz4RoLKEn0tVaIm', 'joseph@gmail.com', '0f03492495f51bc4811afa490d8f676fc9b3f83a36cac7b842683b1638e471d5', '2026-05-11 06:48:47'),
(8, 'piolo', '$2y$10$IqVmVXV8eXoHNP2GhmosjeAyTwS9H.tBXtz./ZaNeaHAgO4nfG8Ya', 'piolo@gmail.com', NULL, NULL),
(9, 'm.maestrado', '$2y$10$qubYKakJGK6Wg18eqNS7AuqrCVIJhZxD6mJAemBEpzsvsN1XJtUSG', 'm.maestrado.psa@gmail.com', NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `document_history`
--
ALTER TABLE `document_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_id` (`document_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `documents`
--
ALTER TABLE `documents`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=155;

--
-- AUTO_INCREMENT for table `document_history`
--
ALTER TABLE `document_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=255;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
