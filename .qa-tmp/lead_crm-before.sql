-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: lead_crm
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `lead_crm`
--

/*!40000 DROP DATABASE IF EXISTS `lead_crm`*/;

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `lead_crm` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci */;

USE `lead_crm`;

--
-- Table structure for table `alerts`
--

DROP TABLE IF EXISTS `alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `alerts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `lead_id` bigint(20) unsigned DEFAULT NULL,
  `rule_id` bigint(20) unsigned DEFAULT NULL,
  `type` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` text DEFAULT NULL,
  `severity` varchar(255) NOT NULL DEFAULT 'info',
  `read_at` datetime DEFAULT NULL,
  `action_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `alerts_lead_id_foreign` (`lead_id`),
  KEY `alerts_rule_id_foreign` (`rule_id`),
  KEY `alerts_user_id_read_at_created_at_index` (`user_id`,`read_at`,`created_at`),
  KEY `alerts_user_id_lead_id_type_created_at_index` (`user_id`,`lead_id`,`type`,`created_at`),
  CONSTRAINT `alerts_lead_id_foreign` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alerts_rule_id_foreign` FOREIGN KEY (`rule_id`) REFERENCES `automation_rules` (`id`) ON DELETE SET NULL,
  CONSTRAINT `alerts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `alerts`
--

LOCK TABLES `alerts` WRITE;
/*!40000 ALTER TABLE `alerts` DISABLE KEYS */;
INSERT INTO `alerts` VALUES (1,2,1,NULL,'lead_stuck','Bhavesh Bhatt has been in Fresh for 40 days','Nothing has moved this lead on since it reached Fresh. Move it forward, or mark it lost so it stops counting as open.','warning',NULL,'http://127.0.0.1:8000/leads?search=9846654579','2026-09-08 07:19:05'),(2,3,3,NULL,'lead_stuck','Pooja Vaghela has been in Site visit done for 15 days','Nothing has moved this lead on since it reached Site visit done. Move it forward, or mark it lost so it stops counting as open.','warning',NULL,'http://127.0.0.1:8000/leads?search=9875015699','2026-09-08 07:19:05'),(3,2,4,NULL,'lead_stuck','Tejas Mehta has been in Not connected for 29 days','Nothing has moved this lead on since it reached Not connected. Move it forward, or mark it lost so it stops counting as open.','warning',NULL,'http://127.0.0.1:8000/leads?search=9231633213','2026-09-08 07:19:05'),(4,4,6,NULL,'lead_stuck','Kavita Rana has been in Site visit done for 14 days','Nothing has moved this lead on since it reached Site visit done. Move it forward, or mark it lost so it stops counting as open.','warning',NULL,'http://127.0.0.1:8000/leads?search=9943316969','2026-09-08 07:19:05'),(5,2,7,NULL,'lead_stuck','Roshni Shah has been in Details shared for 15 days','Nothing has moved this lead on since it reached Details shared. Move it forward, or mark it lost so it stops counting as open.','warning',NULL,'http://127.0.0.1:8000/leads?search=9493180452','2026-09-08 07:19:05'),(6,2,10,NULL,'lead_stuck','Pooja Mehta has been in Connected for 21 days','Nothing has moved this lead on since it reached Connected. Move it forward, or mark it lost so it stops counting as open.','warning',NULL,'http://127.0.0.1:8000/leads?search=9170403084','2026-09-08 07:19:05'),(7,4,14,NULL,'lead_stuck','Sanjay Modi has been in Site visit scheduled for 17 days','Nothing has moved this lead on since it reached Site visit scheduled. Move it forward, or mark it lost so it stops counting as open.','warning',NULL,'http://127.0.0.1:8000/leads?search=9415732668','2026-09-08 07:19:05'),(8,4,17,NULL,'lead_stuck','Rekha Mehta has been in Site visit done for 17 days','Nothing has moved this lead on since it reached Site visit done. Move it forward, or mark it lost so it stops counting as open.','warning',NULL,'http://127.0.0.1:8000/leads?search=9545452557','2026-09-08 07:19:05'),(9,2,18,NULL,'lead_stuck','Vipul Bhatt has been in Connected for 36 days','Nothing has moved this lead on since it reached Connected. Move it forward, or mark it lost so it stops counting as open.','warning',NULL,'http://127.0.0.1:8000/leads?search=9922950339','2026-09-08 07:19:05'),(10,2,19,NULL,'lead_stuck','Nidhi Chauhan has been in Not connected for 10 days','Nothing has moved this lead on since it reached Not connected. Move it forward, or mark it lost so it stops counting as open.','warning',NULL,'http://127.0.0.1:8000/leads?search=9888255850','2026-09-08 07:19:05'),(11,4,21,NULL,'lead_stuck','Bhavesh Modi has been in In discussion for 12 days','Nothing has moved this lead on since it reached In discussion. Move it forward, or mark it lost so it stops counting as open.','warning',NULL,'http://127.0.0.1:8000/leads?search=9204441616','2026-09-08 07:19:05'),(12,2,24,NULL,'lead_stuck','Ronak Rana has been in Fresh for 24 days','Nothing has moved this lead on since it reached Fresh. Move it forward, or mark it lost so it stops counting as open.','warning',NULL,'http://127.0.0.1:8000/leads?search=9323752076','2026-09-08 07:19:05'),(13,2,25,NULL,'lead_stuck','Bhavesh Bhatt has been in Not connected for 26 days','Nothing has moved this lead on since it reached Not connected. Move it forward, or mark it lost so it stops counting as open.','warning',NULL,'http://127.0.0.1:8000/leads?search=9894984125','2026-09-08 07:19:05'),(14,2,28,NULL,'lead_stuck','Ronak Solanki has been in Details shared for 15 days','Nothing has moved this lead on since it reached Details shared. Move it forward, or mark it lost so it stops counting as open.','warning',NULL,'http://127.0.0.1:8000/leads?search=9165077908','2026-09-08 07:19:05'),(15,2,32,NULL,'lead_stuck','Bhavesh Trivedi has been in Details shared for 17 days','Nothing has moved this lead on since it reached Details shared. Move it forward, or mark it lost so it stops counting as open.','warning',NULL,'http://127.0.0.1:8000/leads?search=9542706247','2026-09-08 07:19:05'),(16,2,34,NULL,'lead_stuck','Nidhi Joshi has been in Not connected for 13 days','Nothing has moved this lead on since it reached Not connected. Move it forward, or mark it lost so it stops counting as open.','warning',NULL,'http://127.0.0.1:8000/leads?search=9613021384','2026-09-08 07:19:05'),(17,3,36,NULL,'lead_stuck','Dhruv Bhatt has been in Site visit done for 9 days','Nothing has moved this lead on since it reached Site visit done. Move it forward, or mark it lost so it stops counting as open.','warning',NULL,'http://127.0.0.1:8000/leads?search=9672346727','2026-09-08 07:19:05'),(18,2,38,NULL,'lead_stuck','Vipul Patel has been in Fresh for 46 days','Nothing has moved this lead on since it reached Fresh. Move it forward, or mark it lost so it stops counting as open.','warning',NULL,'http://127.0.0.1:8000/leads?search=9143197376','2026-09-08 07:19:05'),(19,2,39,NULL,'lead_stuck','Vipul Bhatt has been in Connected for 20 days','Nothing has moved this lead on since it reached Connected. Move it forward, or mark it lost so it stops counting as open.','warning',NULL,'http://127.0.0.1:8000/leads?search=9765146297','2026-09-08 07:19:05'),(20,2,40,NULL,'lead_stuck','Nilesh Joshi has been in Details shared for 22 days','Nothing has moved this lead on since it reached Details shared. Move it forward, or mark it lost so it stops counting as open.','warning',NULL,'http://127.0.0.1:8000/leads?search=9477506851','2026-09-08 07:19:05'),(21,2,44,NULL,'lead_stuck','Vipul Modi has been in Not connected for 23 days','Nothing has moved this lead on since it reached Not connected. Move it forward, or mark it lost so it stops counting as open.','warning',NULL,'http://127.0.0.1:8000/leads?search=9195320877','2026-09-08 07:19:05'),(22,4,46,NULL,'lead_stuck','Sanjay Mehta has been in Site visit scheduled for 8 days','Nothing has moved this lead on since it reached Site visit scheduled. Move it forward, or mark it lost so it stops counting as open.','warning',NULL,'http://127.0.0.1:8000/leads?search=9383626227','2026-09-08 07:19:05'),(23,3,47,NULL,'lead_stuck','Ankita Joshi has been in In discussion for 13 days','Nothing has moved this lead on since it reached In discussion. Move it forward, or mark it lost so it stops counting as open.','warning',NULL,'http://127.0.0.1:8000/leads?search=9681021784','2026-09-08 07:19:05'),(24,4,51,NULL,'lead_stuck','Hardik Modi has been in Site visit scheduled for 13 days','Nothing has moved this lead on since it reached Site visit scheduled. Move it forward, or mark it lost so it stops counting as open.','warning',NULL,'http://127.0.0.1:8000/leads?search=9681325264','2026-09-08 07:19:05'),(25,2,52,NULL,'lead_stuck','Sanjay Trivedi has been in Details shared for 16 days','Nothing has moved this lead on since it reached Details shared. Move it forward, or mark it lost so it stops counting as open.','warning',NULL,'http://127.0.0.1:8000/leads?search=9206310019','2026-09-08 07:19:05'),(26,2,53,NULL,'lead_stuck','Kavita Modi has been in Not connected for 28 days','Nothing has moved this lead on since it reached Not connected. Move it forward, or mark it lost so it stops counting as open.','warning',NULL,'http://127.0.0.1:8000/leads?search=9205611455','2026-09-08 07:19:05'),(27,2,55,NULL,'lead_stuck','Tejas Rana has been in Fresh for 43 days','Nothing has moved this lead on since it reached Fresh. Move it forward, or mark it lost so it stops counting as open.','warning',NULL,'http://127.0.0.1:8000/leads?search=9307639086','2026-09-08 07:19:05');
/*!40000 ALTER TABLE `alerts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `automation_logs`
--

DROP TABLE IF EXISTS `automation_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `automation_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `rule_id` bigint(20) unsigned DEFAULT NULL,
  `lead_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `result` varchar(255) NOT NULL,
  `error` text DEFAULT NULL,
  `fired_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `automation_logs_rule_id_lead_id_fired_at_index` (`rule_id`,`lead_id`,`fired_at`),
  KEY `automation_logs_fired_at_index` (`fired_at`),
  KEY `automation_logs_lead_id_fired_at_index` (`lead_id`,`fired_at`),
  CONSTRAINT `automation_logs_lead_id_foreign` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE SET NULL,
  CONSTRAINT `automation_logs_rule_id_foreign` FOREIGN KEY (`rule_id`) REFERENCES `automation_rules` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `automation_logs`
--

LOCK TABLES `automation_logs` WRITE;
/*!40000 ALTER TABLE `automation_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `automation_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `automation_rules`
--

DROP TABLE IF EXISTS `automation_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `automation_rules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `trigger` varchar(255) NOT NULL,
  `trigger_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`trigger_config`)),
  `conditions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`conditions`)),
  `actions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`actions`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `last_fired_at` datetime DEFAULT NULL,
  `fire_count` int(10) unsigned NOT NULL DEFAULT 0,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `automation_rules_created_by_foreign` (`created_by`),
  KEY `automation_rules_trigger_is_active_index` (`trigger`,`is_active`),
  CONSTRAINT `automation_rules_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `automation_rules`
--

LOCK TABLES `automation_rules` WRITE;
/*!40000 ALTER TABLE `automation_rules` DISABLE KEYS */;
INSERT INTO `automation_rules` VALUES (1,'Facebook leads to the telecaller desk','Leads that come in from a Facebook ad are shared out evenly among the telecallers, and each one gets a call booked in an hour\'s time. Turn this on if you are running ads and want them picked up the same day rather than whenever somebody notices them.','lead_created','[]','[{\"field\":\"source\",\"value\":\"facebook\"}]','[{\"type\":\"assign_round_robin\",\"role\":\"telecaller\"},{\"type\":\"create_follow_up\",\"hours\":1,\"todo_type\":\"call\",\"remarks\":\"New Facebook lead \\u2014 first call.\"}]',0,NULL,0,1,'2026-09-08 07:02:14','2026-09-08 07:02:14'),(2,'Broker leads straight to sales','A lead that comes through a broker is usually further along than a cold enquiry, so it goes to a salesperson rather than a telecaller, with a call booked for two hours\' time.','lead_created','[]','[{\"field\":\"source\",\"value\":\"broker\"}]','[{\"type\":\"assign_round_robin\",\"role\":\"salesperson\"},{\"type\":\"create_follow_up\",\"hours\":2,\"todo_type\":\"call\",\"remarks\":\"Broker lead \\u2014 call and confirm what the broker has already told them.\"}]',0,NULL,0,1,'2026-09-08 07:02:14','2026-09-08 07:02:14'),(3,'Thank a walk-in','Somebody who walked into the site office gets a WhatsApp welcome ready to send. The message is not sent on its own — it waits in the Queue tab for you to open and send it.','lead_created','[]','[{\"field\":\"source\",\"value\":\"walk_in\"}]','[{\"type\":\"queue_whatsapp\",\"template_id\":1}]',0,NULL,0,1,'2026-09-08 07:02:14','2026-09-08 07:02:14'),(4,'Chase ignored leads','If a follow-up is three days past its date and still open, the person it belongs to is told about it. This is the one most offices switch on first — it is the cheapest way to stop leads going quiet.','follow_up_overdue','{\"days\":3}','[]','[{\"type\":\"raise_alert\",\"recipient\":\"lead_owner\",\"severity\":\"warning\",\"title\":\"{lead_name} has been waiting three days\",\"body\":\"The follow-up on this lead is overdue. Call them today or move the date.\"}]',0,NULL,0,1,'2026-09-08 07:02:14','2026-09-08 07:02:14'),(5,'Flag stuck negotiations','A lead that has been sitting In discussion for a week is going cold. Everybody with an admin login is told, because at that point it usually needs a senior person to step in on price or possession.','stage_idle','{\"stage\":\"in_discussion\",\"days\":7}','[]','[{\"type\":\"raise_alert\",\"recipient\":\"admins\",\"severity\":\"warning\",\"title\":\"{lead_name} has been in discussion for a week\",\"body\":\"Nothing has moved on {project} since the negotiation started. Worth a call from a senior person.\"}]',0,NULL,0,1,'2026-09-08 07:02:14','2026-09-08 07:02:14'),(6,'Nudge after a site visit','The day after somebody visits the site is when they decide. This books a call for 24 hours later and puts a thank-you message in the queue.','stage_changed','{\"stage\":\"site_visit_done\"}','[]','[{\"type\":\"create_follow_up\",\"hours\":24,\"todo_type\":\"call\",\"remarks\":\"Day-after call. Ask what they thought of the sample flat.\"},{\"type\":\"queue_whatsapp\",\"template_id\":4}]',0,NULL,0,1,'2026-09-08 07:02:14','2026-09-08 07:02:14'),(7,'Confirm a booked site visit','When a site visit is scheduled, a confirmation message with the ID and parking details is put in the queue. Cuts down on people not turning up.','stage_changed','{\"stage\":\"site_visit_scheduled\"}','[]','[{\"type\":\"queue_whatsapp\",\"template_id\":3}]',0,NULL,0,1,'2026-09-08 07:02:14','2026-09-08 07:02:14'),(8,'Tell everyone about a booking','A booking is good news and everybody should see it. Raises an alert for all admins and queues the confirmation message for the customer.','stage_changed','{\"stage\":\"booking_done\"}','[]','[{\"type\":\"raise_alert\",\"recipient\":\"admins\",\"severity\":\"info\",\"title\":\"Booking done \\u2014 {lead_name} at {project}\",\"body\":\"Closed by {owner_name}.\"},{\"type\":\"queue_whatsapp\",\"template_id\":5}]',0,NULL,0,1,'2026-09-08 07:02:14','2026-09-08 07:02:14');
/*!40000 ALTER TABLE `automation_rules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache_locks`
--

LOCK TABLES `cache_locks` WRITE;
/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `channel_partners`
--

DROP TABLE IF EXISTS `channel_partners`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `channel_partners` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `name_key` varchar(191) DEFAULT NULL,
  `type` varchar(255) NOT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `phone` varchar(255) NOT NULL,
  `alt_phone` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `channel_partners_name_key_type_unique` (`name_key`,`type`),
  KEY `channel_partners_type_is_active_index` (`type`,`is_active`),
  KEY `channel_partners_parent_id_index` (`parent_id`),
  CONSTRAINT `channel_partners_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `channel_partners` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `channel_partners`
--

LOCK TABLES `channel_partners` WRITE;
/*!40000 ALTER TABLE `channel_partners` DISABLE KEYS */;
INSERT INTO `channel_partners` VALUES (1,'Shreeji Realty','shreeji realty','firm',NULL,'Nita Shah','9456252005',NULL,'shreejirealty@example.com','Ring Road, Surat',1,'2026-09-07 03:03:12','2026-09-07 03:03:12',NULL),(2,'Anand Properties','anand properties','firm',NULL,'Bhavin Rana','9967345664',NULL,'anandproperties@example.com','Ring Road, Surat',1,'2026-09-07 03:03:12','2026-09-07 03:03:12',NULL),(3,'Ravi Kumar','ravi kumar','broker',1,NULL,'9951110187',NULL,NULL,NULL,1,'2026-09-07 03:03:12','2026-09-07 03:03:12',NULL),(4,'Sunil Vaghela','sunil vaghela','broker',1,NULL,'9139231172',NULL,NULL,NULL,1,'2026-09-07 03:03:12','2026-09-07 03:03:12',NULL),(5,'Ravi Bhatt','ravi bhatt','broker',2,NULL,'9950961958',NULL,NULL,NULL,1,'2026-09-07 03:03:12','2026-09-07 03:03:12',NULL),(6,'Kiran Modi','kiran modi','broker',NULL,NULL,'9186358248',NULL,NULL,NULL,1,'2026-09-07 03:03:12','2026-09-07 03:03:12',NULL),(7,'Hetal Solanki','hetal solanki','broker',NULL,NULL,'9399335621',NULL,NULL,NULL,1,'2026-09-07 03:03:12','2026-09-07 03:03:12',NULL);
/*!40000 ALTER TABLE `channel_partners` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` varchar(255) NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `integration_events`
--

DROP TABLE IF EXISTS `integration_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `integration_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(255) NOT NULL,
  `result` varchar(255) NOT NULL,
  `external_id` varchar(255) DEFAULT NULL,
  `lead_id` bigint(20) unsigned DEFAULT NULL,
  `message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `integration_events_lead_id_foreign` (`lead_id`),
  KEY `integration_events_provider_created_at_index` (`provider`,`created_at`),
  CONSTRAINT `integration_events_lead_id_foreign` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `integration_events`
--

LOCK TABLES `integration_events` WRITE;
/*!40000 ALTER TABLE `integration_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `integration_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `integrations`
--

DROP TABLE IF EXISTS `integrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `integrations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(255) NOT NULL,
  `settings` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `last_received_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `integrations_provider_unique` (`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `integrations`
--

LOCK TABLES `integrations` WRITE;
/*!40000 ALTER TABLE `integrations` DISABLE KEYS */;
/*!40000 ALTER TABLE `integrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_batches`
--

LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` smallint(5) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leads`
--

DROP TABLE IF EXISTS `leads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leads` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(255) NOT NULL,
  `middle_name` varchar(255) DEFAULT NULL,
  `last_name` varchar(255) NOT NULL,
  `mobile_number` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `project_id` bigint(20) unsigned NOT NULL,
  `source` varchar(255) NOT NULL,
  `external_id` varchar(255) DEFAULT NULL,
  `broker_name` varchar(255) DEFAULT NULL,
  `channel_partner_id` bigint(20) unsigned DEFAULT NULL,
  `stage` varchar(255) NOT NULL DEFAULT 'fresh',
  `stage_changed_at` datetime DEFAULT NULL,
  `not_connected_count` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `assigned_to` bigint(20) unsigned DEFAULT NULL,
  `assigned_role` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `requirement` varchar(255) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `booked_unit` varchar(255) DEFAULT NULL,
  `booking_date` date DEFAULT NULL,
  `last_activity_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `leads_mobile_number_project_id_unique` (`mobile_number`,`project_id`),
  UNIQUE KEY `leads_external_id_unique` (`external_id`),
  KEY `leads_project_id_foreign` (`project_id`),
  KEY `leads_created_by_foreign` (`created_by`),
  KEY `leads_assigned_to_stage_index` (`assigned_to`,`stage`),
  KEY `leads_stage_stage_changed_at_index` (`stage`,`stage_changed_at`),
  KEY `leads_source_created_at_index` (`source`,`created_at`),
  KEY `leads_created_at_index` (`created_at`),
  KEY `leads_channel_partner_id_created_at_index` (`channel_partner_id`,`created_at`),
  CONSTRAINT `leads_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leads_channel_partner_id_foreign` FOREIGN KEY (`channel_partner_id`) REFERENCES `channel_partners` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leads_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leads_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=56 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leads`
--

LOCK TABLES `leads` WRITE;
/*!40000 ALTER TABLE `leads` DISABLE KEYS */;
INSERT INTO `leads` VALUES (1,'Bhavesh',NULL,'Bhatt','9846654579','lead1@example.com',2,'facebook',NULL,NULL,NULL,'fresh','2026-07-29 13:07:00',0,2,'telecaller',1,NULL,NULL,NULL,NULL,NULL,'2026-07-29 07:37:00','2026-09-07 03:03:12',NULL),(2,'Ronak',NULL,'Chauhan','9844384849','lead2@example.com',3,'whatsapp',NULL,NULL,NULL,'not_connected','2026-09-04 16:56:00',2,2,'telecaller',1,NULL,NULL,NULL,NULL,NULL,'2026-08-30 04:13:00','2026-09-07 03:03:12',NULL),(3,'Pooja',NULL,'Vaghela','9875015699','lead3@example.com',2,'facebook',NULL,NULL,NULL,'site_visit_done','2026-08-24 09:04:00',0,3,'salesperson',1,NULL,NULL,NULL,NULL,NULL,'2026-06-29 05:39:00','2026-09-07 03:03:12',NULL),(4,'Tejas',NULL,'Mehta','9231633213','lead4@example.com',2,'broker',NULL,'Shreeji Realty',NULL,'not_connected','2026-08-10 01:52:00',1,2,'telecaller',1,NULL,NULL,NULL,NULL,NULL,'2026-07-12 13:40:00','2026-09-07 03:03:13',NULL),(5,'Sanjay',NULL,'Vaghela','9930353036','lead5@example.com',2,'hoarding',NULL,NULL,NULL,'lost','2026-08-30 22:57:00',0,3,'salesperson',1,NULL,'budget',NULL,NULL,NULL,'2026-07-17 07:50:00','2026-09-07 03:03:13',NULL),(6,'Kavita',NULL,'Rana','9943316969','lead6@example.com',2,'referral',NULL,NULL,NULL,'site_visit_done','2026-08-24 13:37:00',0,4,'salesperson',1,NULL,NULL,NULL,NULL,NULL,'2026-06-30 04:24:00','2026-09-07 03:03:13',NULL),(7,'Roshni',NULL,'Shah','9493180452','lead7@example.com',1,'referral',NULL,NULL,NULL,'details_shared','2026-08-24 03:38:00',0,2,'telecaller',1,NULL,NULL,NULL,NULL,NULL,'2026-07-26 12:17:00','2026-09-07 03:03:13',NULL),(8,'Sanjay',NULL,'Bhatt','9243870105','lead8@example.com',1,'broker',NULL,'Shreeji Realty',NULL,'booking_done','2026-09-03 10:10:00',0,3,'salesperson',1,NULL,NULL,'A-518',NULL,NULL,'2026-08-10 14:25:00','2026-09-07 03:03:13',NULL),(9,'Sanjay',NULL,'Patel','9318642730','lead9@example.com',2,'instagram',NULL,NULL,NULL,'lost','2026-09-02 12:28:00',0,4,'salesperson',1,NULL,'budget',NULL,NULL,NULL,'2026-08-04 06:25:00','2026-09-07 03:03:13',NULL),(10,'Pooja',NULL,'Mehta','9170403084','lead10@example.com',3,'incoming_call',NULL,NULL,NULL,'connected','2026-08-17 23:08:00',0,2,'telecaller',1,NULL,NULL,NULL,NULL,NULL,'2026-07-28 08:13:00','2026-09-07 03:03:13',NULL),(11,'Rekha',NULL,'Solanki','9550826171','lead11@example.com',3,'facebook',NULL,NULL,NULL,'lost','2026-08-19 21:35:00',0,3,'salesperson',1,NULL,'budget',NULL,NULL,NULL,'2026-06-25 07:10:00','2026-09-07 03:03:13',NULL),(12,'Nilesh',NULL,'Modi','9954542595','lead12@example.com',2,'facebook',NULL,NULL,NULL,'lost','2026-08-22 01:13:00',0,2,'telecaller',1,NULL,'budget',NULL,NULL,NULL,'2026-07-20 05:03:00','2026-09-07 03:03:13',NULL),(13,'Meera',NULL,'Vaghela','9646685582','lead13@example.com',1,'whatsapp',NULL,NULL,NULL,'lost','2026-08-25 15:05:00',0,3,'salesperson',1,NULL,'budget',NULL,NULL,NULL,'2026-07-18 05:12:00','2026-09-07 03:03:13',NULL),(14,'Sanjay',NULL,'Modi','9415732668','lead14@example.com',1,'whatsapp',NULL,NULL,NULL,'site_visit_scheduled','2026-08-21 15:31:00',0,4,'salesperson',1,NULL,NULL,NULL,NULL,NULL,'2026-07-02 06:55:00','2026-09-07 03:03:13',NULL),(15,'Dhruv',NULL,'Trivedi','9415785142','lead15@example.com',2,'walk_in',NULL,NULL,NULL,'site_visit_done','2026-09-03 14:11:00',0,3,'salesperson',1,NULL,NULL,NULL,NULL,NULL,'2026-08-19 07:15:00','2026-09-07 03:03:13',NULL),(16,'Pooja',NULL,'Bhatt','9944918570','lead16@example.com',3,'referral',NULL,NULL,NULL,'details_shared','2026-09-01 18:27:00',0,2,'telecaller',1,NULL,NULL,NULL,NULL,NULL,'2026-08-21 08:44:00','2026-09-07 03:03:13',NULL),(17,'Rekha',NULL,'Mehta','9545452557','lead17@example.com',2,'referral',NULL,NULL,NULL,'site_visit_done','2026-08-22 04:08:00',0,4,'salesperson',1,NULL,NULL,NULL,NULL,NULL,'2026-06-18 04:59:00','2026-09-07 03:03:13',NULL),(18,'Vipul',NULL,'Bhatt','9922950339','lead18@example.com',1,'facebook',NULL,NULL,NULL,'connected','2026-08-03 01:36:00',0,2,'telecaller',1,NULL,NULL,NULL,NULL,NULL,'2026-06-28 13:08:00','2026-09-07 03:03:13',NULL),(19,'Nidhi',NULL,'Chauhan','9888255850','lead19@example.com',1,'whatsapp',NULL,NULL,NULL,'not_connected','2026-08-29 09:13:00',1,2,'telecaller',1,NULL,NULL,NULL,NULL,NULL,'2026-08-20 04:23:00','2026-09-07 03:03:13',NULL),(20,'Ankita',NULL,'Shah','9146054664','lead20@example.com',2,'broker',NULL,'Shreeji Realty',NULL,'site_visit_done','2026-09-05 05:53:00',0,3,'salesperson',1,NULL,NULL,NULL,NULL,NULL,'2026-08-27 13:42:00','2026-09-07 03:03:13',NULL),(21,'Bhavesh',NULL,'Modi','9204441616','lead21@example.com',3,'incoming_call',NULL,NULL,NULL,'in_discussion','2026-08-26 22:07:00',0,4,'salesperson',1,NULL,NULL,NULL,NULL,NULL,'2026-06-30 12:29:00','2026-09-07 03:03:13',NULL),(22,'Tejas',NULL,'Bhatt','9140932374','lead22@example.com',3,'instagram',NULL,NULL,NULL,'connected','2026-09-06 12:32:00',0,2,'telecaller',1,NULL,NULL,NULL,NULL,NULL,'2026-09-05 11:00:00','2026-09-07 03:03:13',NULL),(23,'Rekha',NULL,'Vaghela','9573939540','lead23@example.com',1,'referral',NULL,NULL,NULL,'lost','2026-09-03 05:26:00',0,3,'salesperson',1,NULL,'budget',NULL,NULL,NULL,'2026-08-09 05:17:00','2026-09-07 03:03:13',NULL),(24,'Ronak',NULL,'Rana','9323752076','lead24@example.com',2,'hoarding',NULL,NULL,NULL,'fresh','2026-08-15 11:36:00',0,2,'telecaller',1,NULL,NULL,NULL,NULL,NULL,'2026-08-15 06:06:00','2026-09-07 03:03:13',NULL),(25,'Bhavesh',NULL,'Bhatt','9894984125','lead25@example.com',3,'instagram',NULL,NULL,NULL,'not_connected','2026-08-12 17:57:00',2,2,'telecaller',1,NULL,NULL,NULL,NULL,NULL,'2026-06-22 07:15:00','2026-09-07 03:03:13',NULL),(26,'Jignesh',NULL,'Patel','9901732488','lead26@example.com',1,'incoming_call',NULL,NULL,NULL,'in_discussion','2026-09-03 00:47:00',0,4,'salesperson',1,NULL,NULL,NULL,NULL,NULL,'2026-08-12 04:24:00','2026-09-07 03:03:13',NULL),(27,'Manish\n',NULL,'Rana','9928730746','lead27@example.com',1,'referral',NULL,NULL,NULL,'in_discussion','2026-09-03 09:39:00',0,4,'salesperson',1,NULL,NULL,NULL,NULL,NULL,'2026-08-14 09:40:00','2026-09-07 03:03:13',NULL),(28,'Ronak',NULL,'Solanki','9165077908','lead28@example.com',2,'facebook',NULL,NULL,NULL,'details_shared','2026-08-23 17:40:00',0,2,'telecaller',1,NULL,NULL,NULL,NULL,NULL,'2026-07-25 06:23:00','2026-09-07 03:03:13',NULL),(29,'Pooja',NULL,'Patel','9695499871','lead29@example.com',3,'facebook',NULL,NULL,NULL,'booking_done','2026-08-28 13:11:00',0,3,'salesperson',1,NULL,NULL,'A-225',NULL,NULL,'2026-06-30 11:30:00','2026-09-07 03:03:13',NULL),(30,'Tejas',NULL,'Desai','9153703865','lead30@example.com',1,'hoarding',NULL,NULL,NULL,'in_discussion','2026-09-01 22:03:00',0,3,'salesperson',1,NULL,NULL,NULL,NULL,NULL,'2026-08-05 12:01:00','2026-09-07 03:03:13',NULL),(31,'Jignesh',NULL,'Vaghela','9520664070','lead31@example.com',1,'instagram',NULL,NULL,NULL,'site_visit_done','2026-09-02 00:48:00',0,3,'salesperson',1,NULL,NULL,NULL,NULL,NULL,'2026-08-11 12:20:00','2026-09-07 03:03:13',NULL),(32,'Bhavesh',NULL,'Trivedi','9542706247','lead32@example.com',2,'instagram',NULL,NULL,NULL,'details_shared','2026-08-21 17:03:00',0,2,'telecaller',1,NULL,NULL,NULL,NULL,NULL,'2026-07-19 04:33:00','2026-09-07 03:03:13',NULL),(33,'Manish\n',NULL,'Bhatt','9559457711','lead33@example.com',1,'facebook',NULL,NULL,NULL,'lost','2026-08-27 03:23:00',0,3,'salesperson',1,NULL,'budget',NULL,NULL,NULL,'2026-07-24 06:23:00','2026-09-07 03:03:13',NULL),(34,'Nidhi',NULL,'Joshi','9613021384','lead34@example.com',1,'walk_in',NULL,NULL,NULL,'not_connected','2026-08-25 22:57:00',1,2,'telecaller',1,NULL,NULL,NULL,NULL,NULL,'2026-08-13 07:51:00','2026-09-07 03:03:13',NULL),(35,'Pooja',NULL,'Shah','9614966833','lead35@example.com',3,'facebook',NULL,NULL,NULL,'lost','2026-08-14 17:31:00',0,2,'telecaller',1,NULL,'budget',NULL,NULL,NULL,'2026-06-28 05:58:00','2026-09-07 03:03:13',NULL),(36,'Dhruv',NULL,'Bhatt','9672346727','lead36@example.com',3,'whatsapp',NULL,NULL,NULL,'site_visit_done','2026-08-29 23:08:00',0,3,'salesperson',1,NULL,NULL,NULL,NULL,NULL,'2026-07-27 03:58:00','2026-09-07 03:03:13',NULL),(37,'Ronak',NULL,'Chauhan','9640266698','lead37@example.com',2,'facebook',NULL,NULL,NULL,'booking_done','2026-08-27 12:26:00',0,4,'salesperson',1,NULL,NULL,'A-336',NULL,NULL,'2026-06-23 06:11:00','2026-09-07 03:03:13',NULL),(38,'Vipul',NULL,'Patel','9143197376','lead38@example.com',1,'facebook',NULL,NULL,NULL,'fresh','2026-07-23 19:10:00',0,2,'telecaller',1,NULL,NULL,NULL,NULL,NULL,'2026-07-23 13:40:00','2026-09-07 03:03:13',NULL),(39,'Vipul',NULL,'Bhatt','9765146297','lead39@example.com',2,'incoming_call',NULL,NULL,NULL,'connected','2026-08-19 01:05:00',0,2,'telecaller',1,NULL,NULL,NULL,NULL,NULL,'2026-07-30 12:07:00','2026-09-07 03:03:13',NULL),(40,'Nilesh',NULL,'Joshi','9477506851','lead40@example.com',1,'walk_in',NULL,NULL,NULL,'details_shared','2026-08-17 01:44:00',0,2,'telecaller',1,NULL,NULL,NULL,NULL,NULL,'2026-07-05 06:35:00','2026-09-07 03:03:13',NULL),(41,'Rekha',NULL,'Modi','9972510170','lead41@example.com',2,'broker',NULL,NULL,1,'lost','2026-09-04 03:51:00',0,4,'salesperson',1,NULL,'budget',NULL,NULL,NULL,'2026-08-25 08:14:00','2026-09-07 03:03:13',NULL),(42,'Roshni',NULL,'Shah','9682459685','lead42@example.com',1,'referral',NULL,NULL,NULL,'lost','2026-09-04 18:13:00',0,2,'telecaller',1,NULL,'budget',NULL,NULL,NULL,'2026-08-30 08:04:00','2026-09-07 03:03:13',NULL),(43,'Vipul',NULL,'Patel','9552139140','lead43@example.com',2,'incoming_call',NULL,NULL,NULL,'booking_done','2026-08-31 19:30:00',0,3,'salesperson',1,NULL,NULL,'A-403',NULL,NULL,'2026-07-23 07:42:00','2026-09-07 03:03:13',NULL),(44,'Vipul',NULL,'Modi','9195320877','lead44@example.com',3,'hoarding',NULL,NULL,NULL,'not_connected','2026-08-15 21:09:00',1,2,'telecaller',1,NULL,NULL,NULL,NULL,NULL,'2026-07-24 04:14:00','2026-09-07 03:03:13',NULL),(45,'Hardik',NULL,'Chauhan','9844505160','lead45@example.com',3,'broker',NULL,NULL,6,'lost','2026-09-01 20:26:00',0,4,'salesperson',1,NULL,'budget',NULL,NULL,NULL,'2026-07-30 14:17:00','2026-09-07 03:03:13',NULL),(46,'Sanjay',NULL,'Mehta','9383626227','lead46@example.com',3,'whatsapp',NULL,NULL,NULL,'site_visit_scheduled','2026-08-31 10:35:00',0,4,'salesperson',1,NULL,NULL,NULL,NULL,NULL,'2026-08-10 11:09:00','2026-09-07 03:03:13',NULL),(47,'Ankita',NULL,'Joshi','9681021784','lead47@example.com',2,'facebook',NULL,NULL,NULL,'in_discussion','2026-08-25 21:30:00',0,3,'salesperson',1,NULL,NULL,NULL,NULL,NULL,'2026-06-24 08:46:00','2026-09-07 03:03:13',NULL),(48,'Pooja',NULL,'Rana','9664482100','lead48@example.com',2,'incoming_call',NULL,NULL,NULL,'lost','2026-08-29 16:45:00',0,3,'salesperson',1,NULL,'budget',NULL,NULL,NULL,'2026-08-03 11:50:00','2026-09-07 03:03:13',NULL),(49,'Nidhi',NULL,'Desai','9972109148','lead49@example.com',3,'facebook',NULL,NULL,NULL,'lost','2026-09-02 10:58:00',0,2,'telecaller',1,NULL,'budget',NULL,NULL,NULL,'2026-08-23 10:19:00','2026-09-07 03:03:13',NULL),(50,'Bhavesh',NULL,'Solanki','9736603540','lead50@example.com',1,'broker',NULL,NULL,5,'in_discussion','2026-09-01 21:08:00',0,3,'salesperson',1,NULL,NULL,NULL,NULL,NULL,'2026-08-05 06:31:00','2026-09-07 03:03:13',NULL),(51,'Hardik',NULL,'Modi','9681325264','lead51@example.com',3,'referral',NULL,NULL,NULL,'site_visit_scheduled','2026-08-26 09:14:00',0,4,'salesperson',1,NULL,NULL,NULL,NULL,NULL,'2026-07-21 05:45:00','2026-09-07 03:03:13',NULL),(52,'Sanjay',NULL,'Trivedi','9206310019','lead52@example.com',1,'facebook',NULL,NULL,NULL,'details_shared','2026-08-23 08:49:00',0,2,'telecaller',1,NULL,NULL,NULL,NULL,NULL,'2026-07-24 03:52:00','2026-09-07 03:03:13',NULL),(53,'Kavita',NULL,'Modi','9205611455','lead53@example.com',2,'facebook',NULL,NULL,NULL,'not_connected','2026-08-10 19:52:00',2,2,'telecaller',1,NULL,NULL,NULL,NULL,NULL,'2026-06-16 13:00:00','2026-09-07 03:03:13',NULL),(54,'Nilesh',NULL,'Desai','9512643478','lead54@example.com',1,'facebook',NULL,NULL,NULL,'lost','2026-08-26 15:59:00',0,3,'salesperson',1,NULL,'budget',NULL,NULL,NULL,'2026-06-17 07:02:00','2026-09-07 03:03:13',NULL),(55,'Tejas',NULL,'Rana','9307639086','lead55@example.com',1,'walk_in',NULL,NULL,NULL,'fresh','2026-07-27 10:35:00',0,2,'telecaller',1,NULL,NULL,NULL,NULL,NULL,'2026-07-27 05:05:00','2026-09-07 03:03:13',NULL);
/*!40000 ALTER TABLE `leads` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `message_logs`
--

DROP TABLE IF EXISTS `message_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `message_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `lead_id` bigint(20) unsigned NOT NULL,
  `template_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `rule_id` bigint(20) unsigned DEFAULT NULL,
  `mode` varchar(255) NOT NULL DEFAULT 'click',
  `to_number` varchar(255) DEFAULT NULL,
  `body` text NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'queued',
  `error` text DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `message_logs_template_id_foreign` (`template_id`),
  KEY `message_logs_user_id_foreign` (`user_id`),
  KEY `message_logs_rule_id_foreign` (`rule_id`),
  KEY `message_logs_status_created_at_index` (`status`,`created_at`),
  KEY `message_logs_lead_id_created_at_index` (`lead_id`,`created_at`),
  CONSTRAINT `message_logs_lead_id_foreign` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `message_logs_rule_id_foreign` FOREIGN KEY (`rule_id`) REFERENCES `automation_rules` (`id`) ON DELETE SET NULL,
  CONSTRAINT `message_logs_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `message_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `message_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `message_logs`
--

LOCK TABLES `message_logs` WRITE;
/*!40000 ALTER TABLE `message_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `message_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `message_templates`
--

DROP TABLE IF EXISTS `message_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `message_templates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `category` varchar(255) NOT NULL DEFAULT 'utility',
  `body` text NOT NULL,
  `placeholder_map` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`placeholder_map`)),
  `meta_template_name` varchar(255) DEFAULT NULL,
  `approval_status` varchar(255) NOT NULL DEFAULT 'draft',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `message_templates_is_active_category_index` (`is_active`,`category`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `message_templates`
--

LOCK TABLES `message_templates` WRITE;
/*!40000 ALTER TABLE `message_templates` DISABLE KEYS */;
INSERT INTO `message_templates` VALUES (1,'Welcome — new enquiry','utility','Namaste {first_name}, thank you for your interest in {project}.\n\nI am {owner_name} from our sales team. I will call you shortly to understand what you are looking for.\n\nYou can reach me any time on {owner_phone}.','[\"first_name\",\"project\",\"owner_name\",\"owner_phone\"]',NULL,'draft',1,'2026-09-08 07:02:14','2026-09-08 07:02:14'),(2,'Brochure follow-up','utility','Hello {first_name}, I have shared the brochure and floor plans for {project} with you.\n\nDo have a look at the layouts and let me know which configuration suits you best. Happy to arrange a site visit at your convenience.\n\n— {owner_name}, {owner_phone}','[\"first_name\",\"project\",\"owner_name\",\"owner_phone\"]',NULL,'draft',1,'2026-09-08 07:02:14','2026-09-08 07:02:14'),(3,'Site visit confirmation','utility','Hello {first_name}, your site visit to {project} is confirmed.\n\nPlease carry a photo ID for entry. Parking is available at the site office.\n\nI will meet you there — {owner_name}, {owner_phone}. Call me if you need directions or want to change the time.','[\"first_name\",\"project\",\"owner_name\",\"owner_phone\"]',NULL,'draft',1,'2026-09-08 07:02:14','2026-09-08 07:02:14'),(4,'Thank you after a site visit','utility','Thank you for visiting {project} today, {first_name}.\n\nI hope you liked the sample flat and the amenities. If you have any questions about the payment plan, possession timeline or bank approvals, just message me here.\n\n— {owner_name}','[\"project\",\"first_name\",\"owner_name\"]',NULL,'draft',1,'2026-09-08 07:02:14','2026-09-08 07:29:16'),(5,'Booking confirmation','utility','Congratulations {lead_name}! Your booking at {project} is confirmed.\n\nOur team will share the allotment letter and the payment schedule shortly. Welcome to the {project} family.\n\n— {owner_name}, {owner_phone}','[\"lead_name\",\"project\",\"owner_name\",\"owner_phone\"]',NULL,'draft',1,'2026-09-08 07:02:14','2026-09-08 07:02:14'),(6,'Festive greeting','marketing','Wishing you and your family a very happy Diwali, {first_name}!\n\nFrom all of us at {project}. May the new year bring you your own new home.\n\n— {owner_name}','[\"first_name\",\"project\",\"owner_name\"]',NULL,'draft',1,'2026-09-08 07:02:14','2026-09-08 07:02:14');
/*!40000 ALTER TABLE `message_templates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'0001_01_01_000000_create_users_table',1),(2,'0001_01_01_000001_create_cache_table',1),(3,'0001_01_01_000002_create_jobs_table',1),(4,'2026_09_01_053249_add_crm_fields_to_users_table',1),(5,'2026_09_01_053250_create_projects_table',1),(6,'2026_09_01_053251_create_leads_table',1),(7,'2026_09_01_053252_create_todos_table',1),(8,'2026_09_06_090000_add_permissions_and_soft_deletes_to_users_table',1),(9,'2026_09_07_090000_create_channel_partners_table',1),(10,'2026_09_07_090001_add_channel_partner_id_to_leads_table',1),(11,'2026_09_07_090002_add_name_key_to_channel_partners_table',1),(12,'2026_09_07_100000_create_integrations_table',2),(13,'2026_09_07_100001_create_integration_events_table',2),(14,'2026_09_07_100002_add_external_id_to_leads_table',2),(15,'2026_09_08_100000_create_automation_rules_table',3),(16,'2026_09_08_100001_create_message_templates_table',3),(17,'2026_09_08_100002_create_alerts_table',3),(18,'2026_09_08_100003_create_automation_logs_table',3),(19,'2026_09_08_100004_create_message_logs_table',3),(20,'2026_09_08_110000_add_description_and_soft_deletes_to_projects_table',4);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `projects`
--

DROP TABLE IF EXISTS `projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `projects` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `type` varchar(255) NOT NULL DEFAULT 'residential',
  `description` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `projects_created_by_foreign` (`created_by`),
  KEY `projects_is_active_index` (`is_active`),
  CONSTRAINT `projects_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `projects`
--

LOCK TABLES `projects` WRITE;
/*!40000 ALTER TABLE `projects` DISABLE KEYS */;
INSERT INTO `projects` VALUES (1,'Skyline Residency','Vesu, Surat','residential',NULL,1,1,'2026-09-07 03:03:12','2026-09-07 03:03:12',NULL),(2,'Green Court','Pal, Surat','residential',NULL,1,1,'2026-09-07 03:03:12','2026-09-08 07:40:09',NULL),(3,'Orion Business Hub','Adajan, Surat','residential',NULL,1,1,'2026-09-07 03:03:12','2026-09-08 07:40:11',NULL);
/*!40000 ALTER TABLE `projects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
INSERT INTO `sessions` VALUES ('9UBsf7uUmidJmOzU3iEhUpYg0s90jr5zUUbAaxPl',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','eyJfdG9rZW4iOiIybnVySkhyM2tvWWZHWHBFRGRRbFBXVk1rdWVvSHo4WFQxaGJ0U3hqIiwibG9naW5fd2ViXzU5YmEzNmFkZGMyYjJmOTQwMTU4MGYwMTRjN2Y1OGVhNGUzMDk4OWQiOjEsImZpbHRlcnMiOnsidG9kb3MiOltdLCJkYXNoYm9hcmQiOltdLCJjaGFubmVsLXBhcnRuZXJzIjpbXSwicHJvamVjdHMiOltdfSwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2xvY2FsaG9zdDo4MDAwXC9wcm9qZWN0cyIsInJvdXRlIjoicHJvamVjdHMuaW5kZXgifSwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119LCJkYXNoYm9hcmQiOnsiZGlnZXN0X3NlZW4iOnRydWV9fQ==',1788853454),('AsHnbvxX3anbJv6jkJ5ckTUJyac80dL0tYBxU3rF',NULL,'127.0.0.1','Symfony','eyJfdG9rZW4iOiJjZllYYWRwcFVtSnEzZXpXVWxVQkZRUE9HOEZFc1IyR3VZOVQzbGJ5IiwidXJsIjp7ImludGVuZGVkIjoiaHR0cDpcL1wvbG9jYWxob3N0XC9hdXRvbWF0aW9uIn0sIl9wcmV2aW91cyI6eyJ1cmwiOiJodHRwOlwvXC9sb2NhbGhvc3RcL2F1dG9tYXRpb24iLCJyb3V0ZSI6ImF1dG9tYXRpb24uaW5kZXgifSwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119fQ==',1788852253),('Hg2GdhskaOSmAa5rBnhXxFCjLhKvgoyLPTueHNmk',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36','eyJfdG9rZW4iOiJJN0djbGowOXZXVndhNEJaM091cktEcnZRMmFQMExORTExV21nMTNIIiwibG9naW5fd2ViXzU5YmEzNmFkZGMyYjJmOTQwMTU4MGYwMTRjN2Y1OGVhNGUzMDk4OWQiOjEsIl9wcmV2aW91cyI6eyJ1cmwiOiJodHRwOlwvXC9sb2NhbGhvc3Q6ODAwMFwvdG9kb3MiLCJyb3V0ZSI6InRvZG9zLmluZGV4In0sIl9mbGFzaCI6eyJvbGQiOltdLCJuZXciOltdfSwiZmlsdGVycyI6eyJkYXNoYm9hcmQiOltdLCJ0b2RvcyI6W119LCJkYXNoYm9hcmQiOnsiZGlnZXN0X3NlZW4iOnRydWV9fQ==',1788840255);
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `todos`
--

DROP TABLE IF EXISTS `todos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `todos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `lead_id` bigint(20) unsigned NOT NULL,
  `assigned_to` bigint(20) unsigned NOT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `scheduled_at` datetime NOT NULL,
  `type` varchar(255) NOT NULL DEFAULT 'call',
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `outcome_stage` varchar(255) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `completed_by` bigint(20) unsigned DEFAULT NULL,
  `rescheduled_from_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `todos_created_by_foreign` (`created_by`),
  KEY `todos_completed_by_foreign` (`completed_by`),
  KEY `todos_rescheduled_from_id_foreign` (`rescheduled_from_id`),
  KEY `todos_assigned_to_status_scheduled_at_index` (`assigned_to`,`status`,`scheduled_at`),
  KEY `todos_outcome_stage_completed_at_index` (`outcome_stage`,`completed_at`),
  KEY `todos_lead_id_status_index` (`lead_id`,`status`),
  CONSTRAINT `todos_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `todos_completed_by_foreign` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `todos_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `todos_lead_id_foreign` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `todos_rescheduled_from_id_foreign` FOREIGN KEY (`rescheduled_from_id`) REFERENCES `todos` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=208 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `todos`
--

LOCK TABLES `todos` WRITE;
/*!40000 ALTER TABLE `todos` DISABLE KEYS */;
INSERT INTO `todos` VALUES (1,1,2,1,'2026-09-05 04:33:12','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:12','2026-09-07 03:03:12'),(2,2,2,1,'2026-09-02 00:20:00','call','completed','Call logged during seeding.','not_connected','2026-09-02 01:20:00',2,NULL,'2026-09-07 03:03:12','2026-09-07 03:03:12'),(3,2,2,1,'2026-09-04 11:56:00','call','completed','Call logged during seeding.','not_connected','2026-09-04 16:56:00',2,NULL,'2026-09-07 03:03:12','2026-09-07 03:03:12'),(4,2,2,1,'2026-09-12 08:33:12','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:12','2026-09-07 03:03:12'),(5,3,2,1,'2026-07-13 09:38:00','call','completed','Call logged during seeding.','connected','2026-07-13 10:38:00',2,NULL,'2026-09-07 03:03:12','2026-09-07 03:03:12'),(6,3,2,1,'2026-07-27 05:07:00','call','completed','Call logged during seeding.','details_shared','2026-07-27 10:07:00',2,NULL,'2026-09-07 03:03:12','2026-09-07 03:03:12'),(7,3,3,1,'2026-08-10 06:35:00','site_visit','completed','Call logged during seeding.','site_visit_scheduled','2026-08-10 09:35:00',3,NULL,'2026-09-07 03:03:12','2026-09-07 03:03:12'),(8,3,3,1,'2026-08-24 08:04:00','call','completed','Call logged during seeding.','site_visit_done','2026-08-24 09:04:00',3,NULL,'2026-09-07 03:03:12','2026-09-07 03:03:12'),(9,3,3,1,'2026-09-07 07:33:12','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:12','2026-09-07 03:03:12'),(10,4,2,1,'2026-08-10 00:52:00','call','completed','Call logged during seeding.','not_connected','2026-08-10 01:52:00',2,NULL,'2026-09-07 03:03:12','2026-09-07 03:03:12'),(11,4,2,1,'2026-09-09 04:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(12,5,2,1,'2026-07-24 18:56:00','call','completed','Call logged during seeding.','connected','2026-07-24 22:56:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(13,5,2,1,'2026-08-01 05:32:00','call','completed','Call logged during seeding.','details_shared','2026-08-01 08:32:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(14,5,3,1,'2026-08-08 17:08:00','site_visit','completed','Call logged during seeding.','site_visit_scheduled','2026-08-08 18:08:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(15,5,3,1,'2026-08-15 23:45:00','call','completed','Call logged during seeding.','site_visit_done','2026-08-16 03:45:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(16,5,3,1,'2026-08-23 11:21:00','call','completed','Call logged during seeding.','in_discussion','2026-08-23 13:21:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(17,5,3,1,'2026-08-30 20:57:00','call','completed','Call logged during seeding.','lost','2026-08-30 22:57:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(18,6,2,1,'2026-07-14 01:50:00','call','completed','Call logged during seeding.','connected','2026-07-14 04:50:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(19,6,2,1,'2026-07-27 22:46:00','call','completed','Call logged during seeding.','details_shared','2026-07-27 23:46:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(20,6,4,1,'2026-08-10 12:41:00','site_visit','completed','Call logged during seeding.','site_visit_scheduled','2026-08-10 18:41:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(21,6,4,1,'2026-08-24 07:37:00','call','completed','Call logged during seeding.','site_visit_done','2026-08-24 13:37:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(22,6,4,1,'2026-09-08 04:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(23,7,2,1,'2026-08-09 18:42:00','call','completed','Call logged during seeding.','connected','2026-08-09 22:42:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(24,7,2,1,'2026-08-23 23:38:00','call','completed','Call logged during seeding.','details_shared','2026-08-24 03:38:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(25,7,2,1,'2026-09-06 23:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(26,8,2,1,'2026-08-14 12:18:00','call','completed','Call logged during seeding.','connected','2026-08-14 18:18:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(27,8,2,1,'2026-08-18 12:40:00','call','completed','Call logged during seeding.','details_shared','2026-08-18 16:40:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(28,8,3,1,'2026-08-22 09:03:00','site_visit','completed','Call logged during seeding.','site_visit_scheduled','2026-08-22 15:03:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(29,8,3,1,'2026-08-26 12:25:00','call','completed','Call logged during seeding.','site_visit_done','2026-08-26 13:25:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(30,8,3,1,'2026-08-30 06:48:00','call','completed','Call logged during seeding.','in_discussion','2026-08-30 11:48:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(31,8,3,1,'2026-09-03 05:10:00','call','completed','Call logged during seeding.','booking_done','2026-09-03 10:10:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(32,9,2,1,'2026-08-09 03:00:00','call','completed','Call logged during seeding.','connected','2026-08-09 08:00:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(33,9,2,1,'2026-08-13 23:06:00','call','completed','Call logged during seeding.','details_shared','2026-08-14 04:06:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(34,9,4,1,'2026-08-18 22:11:00','site_visit','completed','Call logged during seeding.','site_visit_scheduled','2026-08-19 00:11:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(35,9,4,1,'2026-08-23 19:17:00','call','completed','Call logged during seeding.','site_visit_done','2026-08-23 20:17:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(36,9,4,1,'2026-08-28 12:22:00','call','completed','Call logged during seeding.','in_discussion','2026-08-28 16:22:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(37,9,4,1,'2026-09-02 09:28:00','call','completed','Call logged during seeding.','lost','2026-09-02 12:28:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(38,10,2,1,'2026-08-17 18:08:00','call','completed','Call logged during seeding.','connected','2026-08-17 23:08:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(39,10,2,1,'2026-09-07 07:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(40,11,2,1,'2026-07-13 21:38:00','call','completed','Call logged during seeding.','connected','2026-07-13 23:38:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(41,11,2,1,'2026-08-01 06:37:00','call','completed','Call logged during seeding.','details_shared','2026-08-01 10:37:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(42,11,3,1,'2026-08-19 19:35:00','call','completed','Call logged during seeding.','lost','2026-08-19 21:35:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(43,12,2,1,'2026-08-05 15:53:00','call','completed','Call logged during seeding.','not_connected','2026-08-05 17:53:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(44,12,2,1,'2026-08-21 21:13:00','call','completed','Call logged during seeding.','lost','2026-08-22 01:13:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(45,13,2,1,'2026-07-31 00:10:00','call','completed','Call logged during seeding.','connected','2026-07-31 04:10:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(46,13,2,1,'2026-08-12 15:38:00','call','completed','Call logged during seeding.','details_shared','2026-08-12 21:38:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(47,13,3,1,'2026-08-25 11:05:00','call','completed','Call logged during seeding.','lost','2026-08-25 15:05:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(48,14,2,1,'2026-07-19 00:27:00','call','completed','Call logged during seeding.','connected','2026-07-19 05:27:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(49,14,2,1,'2026-08-04 20:29:00','call','completed','Call logged during seeding.','details_shared','2026-08-04 22:29:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(50,14,4,1,'2026-08-21 14:31:00','site_visit','completed','Call logged during seeding.','site_visit_scheduled','2026-08-21 15:31:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(51,14,4,1,'2026-09-07 14:33:13','site_visit','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(52,15,2,1,'2026-08-23 01:07:00','call','completed','Call logged during seeding.','connected','2026-08-23 07:07:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(53,15,2,1,'2026-08-26 22:28:00','call','completed','Call logged during seeding.','details_shared','2026-08-27 01:28:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(54,15,3,1,'2026-08-30 13:50:00','site_visit','completed','Call logged during seeding.','site_visit_scheduled','2026-08-30 19:50:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(55,15,3,1,'2026-09-03 13:11:00','call','completed','Call logged during seeding.','site_visit_done','2026-09-03 14:11:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(56,15,3,1,'2026-09-06 06:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(57,16,2,1,'2026-08-27 01:20:00','call','completed','Call logged during seeding.','connected','2026-08-27 04:20:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(58,16,2,1,'2026-09-01 16:27:00','call','completed','Call logged during seeding.','details_shared','2026-09-01 18:27:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(59,16,2,1,'2026-09-10 06:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(60,17,2,1,'2026-07-04 12:54:00','call','completed','Call logged during seeding.','connected','2026-07-04 14:54:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(61,17,2,1,'2026-07-20 15:19:00','call','completed','Call logged during seeding.','details_shared','2026-07-20 19:19:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(62,17,4,1,'2026-08-05 19:43:00','site_visit','completed','Call logged during seeding.','site_visit_scheduled','2026-08-05 23:43:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(63,17,4,1,'2026-08-22 02:08:00','call','completed','Call logged during seeding.','site_visit_done','2026-08-22 04:08:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(64,17,4,1,'2026-09-07 07:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(65,18,2,1,'2026-08-02 20:36:00','call','completed','Call logged during seeding.','connected','2026-08-03 01:36:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(66,18,2,1,'2026-09-12 08:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(67,19,2,1,'2026-08-29 03:13:00','call','completed','Call logged during seeding.','not_connected','2026-08-29 09:13:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(68,19,2,1,'2026-09-12 08:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(69,20,2,1,'2026-08-29 19:52:00','call','completed','Call logged during seeding.','connected','2026-08-29 21:52:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(70,20,2,1,'2026-08-31 21:32:00','call','completed','Call logged during seeding.','details_shared','2026-09-01 00:32:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(71,20,3,1,'2026-09-02 22:13:00','site_visit','completed','Call logged during seeding.','site_visit_scheduled','2026-09-03 03:13:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(72,20,3,1,'2026-09-04 23:53:00','call','completed','Call logged during seeding.','site_visit_done','2026-09-05 05:53:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(73,20,3,1,'2026-09-06 06:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(74,21,2,1,'2026-07-12 00:25:00','call','completed','Call logged during seeding.','connected','2026-07-12 04:25:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(75,21,2,1,'2026-07-23 09:50:00','call','completed','Call logged during seeding.','details_shared','2026-07-23 14:50:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(76,21,4,1,'2026-08-03 20:16:00','site_visit','completed','Call logged during seeding.','site_visit_scheduled','2026-08-04 01:16:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(77,21,4,1,'2026-08-15 05:42:00','call','completed','Call logged during seeding.','site_visit_done','2026-08-15 11:42:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(78,21,4,1,'2026-08-26 16:07:00','call','completed','Call logged during seeding.','in_discussion','2026-08-26 22:07:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(79,21,4,1,'2026-09-06 23:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(80,22,2,1,'2026-09-06 08:32:00','call','completed','Call logged during seeding.','connected','2026-09-06 12:32:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(81,22,2,1,'2026-09-10 06:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(82,23,2,1,'2026-08-13 10:54:00','call','completed','Call logged during seeding.','connected','2026-08-13 13:54:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(83,23,2,1,'2026-08-17 12:00:00','call','completed','Call logged during seeding.','details_shared','2026-08-17 17:00:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(84,23,3,1,'2026-08-21 15:07:00','site_visit','completed','Call logged during seeding.','site_visit_scheduled','2026-08-21 20:07:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(85,23,3,1,'2026-08-25 20:13:00','call','completed','Call logged during seeding.','site_visit_done','2026-08-25 23:13:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(86,23,3,1,'2026-08-29 23:20:00','call','completed','Call logged during seeding.','in_discussion','2026-08-30 02:20:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(87,23,3,1,'2026-09-03 00:26:00','call','completed','Call logged during seeding.','lost','2026-09-03 05:26:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(88,24,2,1,'2026-09-07 05:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(89,25,2,1,'2026-07-17 21:21:00','call','completed','Call logged during seeding.','not_connected','2026-07-18 03:21:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(90,25,2,1,'2026-08-12 14:57:00','call','completed','Call logged during seeding.','not_connected','2026-08-12 17:57:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(91,25,2,1,'2026-09-07 10:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(92,26,2,1,'2026-08-16 14:41:00','call','completed','Call logged during seeding.','connected','2026-08-16 17:41:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(93,26,2,1,'2026-08-20 19:27:00','call','completed','Call logged during seeding.','details_shared','2026-08-21 01:27:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(94,26,4,1,'2026-08-25 08:14:00','site_visit','completed','Call logged during seeding.','site_visit_scheduled','2026-08-25 09:14:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(95,26,4,1,'2026-08-29 11:00:00','call','completed','Call logged during seeding.','site_visit_done','2026-08-29 17:00:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(96,26,4,1,'2026-09-02 22:47:00','call','completed','Call logged during seeding.','in_discussion','2026-09-03 00:47:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(97,26,4,1,'2026-09-07 07:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(98,27,2,1,'2026-08-18 13:04:00','call','completed','Call logged during seeding.','connected','2026-08-18 14:04:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(99,27,2,1,'2026-08-22 06:58:00','call','completed','Call logged during seeding.','details_shared','2026-08-22 12:58:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(100,27,4,1,'2026-08-26 09:52:00','site_visit','completed','Call logged during seeding.','site_visit_scheduled','2026-08-26 11:52:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(101,27,4,1,'2026-08-30 04:45:00','call','completed','Call logged during seeding.','site_visit_done','2026-08-30 10:45:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(102,27,4,1,'2026-09-03 06:39:00','call','completed','Call logged during seeding.','in_discussion','2026-09-03 09:39:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(103,27,4,1,'2026-09-07 10:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(104,28,2,1,'2026-08-09 00:46:00','call','completed','Call logged during seeding.','connected','2026-08-09 02:46:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(105,28,2,1,'2026-08-23 11:40:00','call','completed','Call logged during seeding.','details_shared','2026-08-23 17:40:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(106,28,2,1,'2026-09-06 23:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(107,29,2,1,'2026-07-10 11:22:00','call','completed','Call logged during seeding.','connected','2026-07-10 12:22:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(108,29,2,1,'2026-07-20 02:44:00','call','completed','Call logged during seeding.','details_shared','2026-07-20 07:44:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(109,29,3,1,'2026-07-29 23:06:00','site_visit','completed','Call logged during seeding.','site_visit_scheduled','2026-07-30 03:06:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(110,29,3,1,'2026-08-08 18:27:00','call','completed','Call logged during seeding.','site_visit_done','2026-08-08 22:27:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(111,29,3,1,'2026-08-18 11:49:00','call','completed','Call logged during seeding.','in_discussion','2026-08-18 17:49:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(112,29,3,1,'2026-08-28 08:11:00','call','completed','Call logged during seeding.','booking_done','2026-08-28 13:11:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(113,30,2,1,'2026-08-11 01:01:00','call','completed','Call logged during seeding.','connected','2026-08-11 04:01:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(114,30,2,1,'2026-08-16 10:32:00','call','completed','Call logged during seeding.','details_shared','2026-08-16 14:32:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(115,30,3,1,'2026-08-21 19:02:00','site_visit','completed','Call logged during seeding.','site_visit_scheduled','2026-08-22 01:02:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(116,30,3,1,'2026-08-27 08:32:00','call','completed','Call logged during seeding.','site_visit_done','2026-08-27 11:32:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(117,30,3,1,'2026-09-01 17:03:00','call','completed','Call logged during seeding.','in_discussion','2026-09-01 22:03:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(118,30,3,1,'2026-09-09 04:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(119,31,2,1,'2026-08-16 21:35:00','call','completed','Call logged during seeding.','connected','2026-08-17 01:35:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(120,31,2,1,'2026-08-22 03:19:00','call','completed','Call logged during seeding.','details_shared','2026-08-22 09:19:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(121,31,3,1,'2026-08-27 14:04:00','site_visit','completed','Call logged during seeding.','site_visit_scheduled','2026-08-27 17:04:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(122,31,3,1,'2026-09-01 19:48:00','call','completed','Call logged during seeding.','site_visit_done','2026-09-02 00:48:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(123,31,3,1,'2026-09-07 14:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(124,32,2,1,'2026-08-04 19:33:00','call','completed','Call logged during seeding.','connected','2026-08-05 01:33:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(125,32,2,1,'2026-08-21 16:03:00','call','completed','Call logged during seeding.','details_shared','2026-08-21 17:03:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(126,32,2,1,'2026-09-08 04:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(127,33,2,1,'2026-08-04 11:03:00','call','completed','Call logged during seeding.','connected','2026-08-04 17:03:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(128,33,2,1,'2026-08-15 17:13:00','call','completed','Call logged during seeding.','details_shared','2026-08-15 22:13:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(129,33,3,1,'2026-08-27 01:23:00','call','completed','Call logged during seeding.','lost','2026-08-27 03:23:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(130,34,2,1,'2026-08-25 19:57:00','call','completed','Call logged during seeding.','not_connected','2026-08-25 22:57:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(131,34,2,1,'2026-09-06 23:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(132,35,2,1,'2026-07-22 01:30:00','call','completed','Call logged during seeding.','not_connected','2026-07-22 02:30:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(133,35,2,1,'2026-08-14 16:31:00','call','completed','Call logged during seeding.','lost','2026-08-14 17:31:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(134,36,2,1,'2026-08-04 14:53:00','call','completed','Call logged during seeding.','connected','2026-08-04 18:53:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(135,36,2,1,'2026-08-13 00:18:00','call','completed','Call logged during seeding.','details_shared','2026-08-13 04:18:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(136,36,3,1,'2026-08-21 09:43:00','site_visit','completed','Call logged during seeding.','site_visit_scheduled','2026-08-21 13:43:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(137,36,3,1,'2026-08-29 20:08:00','call','completed','Call logged during seeding.','site_visit_done','2026-08-29 23:08:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(138,36,3,1,'2026-09-08 04:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(139,37,2,1,'2026-07-04 03:48:00','call','completed','Call logged during seeding.','connected','2026-07-04 07:48:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(140,37,2,1,'2026-07-14 21:56:00','call','completed','Call logged during seeding.','details_shared','2026-07-15 03:56:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(141,37,4,1,'2026-07-25 19:03:00','site_visit','completed','Call logged during seeding.','site_visit_scheduled','2026-07-26 00:03:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(142,37,4,1,'2026-08-05 14:11:00','call','completed','Call logged during seeding.','site_visit_done','2026-08-05 20:11:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(143,37,4,1,'2026-08-16 12:18:00','call','completed','Call logged during seeding.','in_discussion','2026-08-16 16:18:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(144,37,4,1,'2026-08-27 11:26:00','call','completed','Call logged during seeding.','booking_done','2026-08-27 12:26:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(145,38,2,1,'2026-09-08 04:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(146,39,2,1,'2026-08-18 19:05:00','call','completed','Call logged during seeding.','connected','2026-08-19 01:05:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(147,39,2,1,'2026-09-12 08:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(148,40,2,1,'2026-07-26 15:54:00','call','completed','Call logged during seeding.','connected','2026-07-26 18:54:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(149,40,2,1,'2026-08-17 00:44:00','call','completed','Call logged during seeding.','details_shared','2026-08-17 01:44:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(150,40,2,1,'2026-09-06 23:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(151,41,2,1,'2026-08-28 17:26:00','call','completed','Call logged during seeding.','connected','2026-08-28 18:26:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(152,41,2,1,'2026-08-31 20:09:00','call','completed','Call logged during seeding.','details_shared','2026-08-31 23:09:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(153,41,4,1,'2026-09-03 22:51:00','call','completed','Call logged during seeding.','lost','2026-09-04 03:51:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(154,42,2,1,'2026-09-02 01:54:00','call','completed','Call logged during seeding.','not_connected','2026-09-02 03:54:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(155,42,2,1,'2026-09-04 17:13:00','call','completed','Call logged during seeding.','lost','2026-09-04 18:13:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(156,43,2,1,'2026-07-29 20:15:00','call','completed','Call logged during seeding.','connected','2026-07-30 02:15:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(157,43,2,1,'2026-08-05 14:18:00','call','completed','Call logged during seeding.','details_shared','2026-08-05 15:18:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(158,43,3,1,'2026-08-12 03:21:00','site_visit','completed','Call logged during seeding.','site_visit_scheduled','2026-08-12 04:21:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(159,43,3,1,'2026-08-18 14:24:00','call','completed','Call logged during seeding.','site_visit_done','2026-08-18 17:24:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(160,43,3,1,'2026-08-25 01:27:00','call','completed','Call logged during seeding.','in_discussion','2026-08-25 06:27:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(161,43,3,1,'2026-08-31 18:30:00','call','completed','Call logged during seeding.','booking_done','2026-08-31 19:30:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(162,44,2,1,'2026-08-15 17:09:00','call','completed','Call logged during seeding.','not_connected','2026-08-15 21:09:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(163,44,2,1,'2026-09-08 04:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(164,45,2,1,'2026-08-05 03:54:00','call','completed','Call logged during seeding.','connected','2026-08-05 07:54:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(165,45,2,1,'2026-08-10 16:00:00','call','completed','Call logged during seeding.','details_shared','2026-08-10 20:00:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(166,45,4,1,'2026-08-16 05:07:00','site_visit','completed','Call logged during seeding.','site_visit_scheduled','2026-08-16 08:07:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(167,45,4,1,'2026-08-21 17:13:00','call','completed','Call logged during seeding.','site_visit_done','2026-08-21 20:13:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(168,45,4,1,'2026-08-27 02:20:00','call','completed','Call logged during seeding.','in_discussion','2026-08-27 08:20:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(169,45,4,1,'2026-09-01 17:26:00','call','completed','Call logged during seeding.','lost','2026-09-01 20:26:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(170,46,2,1,'2026-08-17 10:38:00','call','completed','Call logged during seeding.','connected','2026-08-17 14:38:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(171,46,2,1,'2026-08-24 09:36:00','call','completed','Call logged during seeding.','details_shared','2026-08-24 12:36:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(172,46,4,1,'2026-08-31 05:35:00','site_visit','completed','Call logged during seeding.','site_visit_scheduled','2026-08-31 10:35:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(173,46,4,1,'2026-09-05 04:33:13','site_visit','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(174,47,2,1,'2026-07-06 23:19:00','call','completed','Call logged during seeding.','connected','2026-07-07 01:19:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(175,47,2,1,'2026-07-19 10:22:00','call','completed','Call logged during seeding.','details_shared','2026-07-19 12:22:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(176,47,3,1,'2026-07-31 19:25:00','site_visit','completed','Call logged during seeding.','site_visit_scheduled','2026-07-31 23:25:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(177,47,3,1,'2026-08-13 06:27:00','call','completed','Call logged during seeding.','site_visit_done','2026-08-13 10:27:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(178,47,3,1,'2026-08-25 15:30:00','call','completed','Call logged during seeding.','in_discussion','2026-08-25 21:30:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(179,47,3,1,'2026-09-07 05:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(180,48,2,1,'2026-08-12 04:08:00','call','completed','Call logged during seeding.','connected','2026-08-12 09:08:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(181,48,2,1,'2026-08-20 23:57:00','call','completed','Call logged during seeding.','details_shared','2026-08-21 00:57:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(182,48,3,1,'2026-08-29 12:45:00','call','completed','Call logged during seeding.','lost','2026-08-29 16:45:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(183,49,2,1,'2026-08-28 11:24:00','call','completed','Call logged during seeding.','not_connected','2026-08-28 13:24:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(184,49,2,1,'2026-09-02 05:58:00','call','completed','Call logged during seeding.','lost','2026-09-02 10:58:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(185,50,2,1,'2026-08-10 17:26:00','call','completed','Call logged during seeding.','connected','2026-08-10 23:26:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(186,50,2,1,'2026-08-16 04:52:00','call','completed','Call logged during seeding.','details_shared','2026-08-16 10:52:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(187,50,3,1,'2026-08-21 17:17:00','site_visit','completed','Call logged during seeding.','site_visit_scheduled','2026-08-21 22:17:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(188,50,3,1,'2026-08-27 04:42:00','call','completed','Call logged during seeding.','site_visit_done','2026-08-27 09:42:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(189,50,3,1,'2026-09-01 16:08:00','call','completed','Call logged during seeding.','in_discussion','2026-09-01 21:08:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(190,50,3,1,'2026-09-05 04:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(191,51,2,1,'2026-08-02 06:35:00','call','completed','Call logged during seeding.','connected','2026-08-02 10:35:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(192,51,2,1,'2026-08-14 03:54:00','call','completed','Call logged during seeding.','details_shared','2026-08-14 09:54:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(193,51,4,1,'2026-08-26 05:14:00','site_visit','completed','Call logged during seeding.','site_visit_scheduled','2026-08-26 09:14:00',4,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(194,51,4,1,'2026-09-07 07:33:13','site_visit','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(195,52,2,1,'2026-08-08 04:06:00','call','completed','Call logged during seeding.','connected','2026-08-08 09:06:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(196,52,2,1,'2026-08-23 07:49:00','call','completed','Call logged during seeding.','details_shared','2026-08-23 08:49:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(197,52,2,1,'2026-09-06 23:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(198,53,2,1,'2026-07-14 06:11:00','call','completed','Call logged during seeding.','not_connected','2026-07-14 07:11:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(199,53,2,1,'2026-08-10 16:52:00','call','completed','Call logged during seeding.','not_connected','2026-08-10 19:52:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(200,53,2,1,'2026-09-07 07:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(201,54,2,1,'2026-06-29 04:06:00','call','completed','Call logged during seeding.','connected','2026-06-29 05:06:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(202,54,2,1,'2026-07-10 15:41:00','call','completed','Call logged during seeding.','details_shared','2026-07-10 21:41:00',2,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(203,54,3,1,'2026-07-22 08:15:00','site_visit','completed','Call logged during seeding.','site_visit_scheduled','2026-07-22 14:15:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(204,54,3,1,'2026-08-03 01:50:00','call','completed','Call logged during seeding.','site_visit_done','2026-08-03 06:50:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(205,54,3,1,'2026-08-14 21:24:00','call','completed','Call logged during seeding.','in_discussion','2026-08-14 23:24:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(206,54,3,1,'2026-08-26 14:59:00','call','completed','Call logged during seeding.','lost','2026-08-26 15:59:00',3,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13'),(207,55,2,1,'2026-09-07 05:33:13','call','pending',NULL,NULL,NULL,NULL,NULL,'2026-09-07 03:03:13','2026-09-07 03:03:13');
/*!40000 ALTER TABLE `todos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(255) DEFAULT NULL,
  `last_name` varchar(255) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `mobile_number` varchar(255) DEFAULT NULL,
  `role` varchar(255) NOT NULL DEFAULT 'salesperson',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`permissions`)),
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_mobile_number_unique` (`mobile_number`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Rajesh','Mehta','admin@crm.test','9820000001','admin',1,NULL,NULL,'$2y$12$wHx7j0SUDEA40klikbdOdO.zx46qIErzQvPvgmRIMg6embsCwaYYO','KLa4nAslyXIBnUV9mrraRlfw7htCW8xPlPpOJOgF8c8VLhOGazRG7LScfVhz','2026-09-07 03:03:12','2026-09-07 03:03:12',NULL),(2,'Priya','Shah','tele@crm.test','9820000002','telecaller',1,NULL,NULL,'$2y$12$sIjN6nOBzy6pvyUAj4hryuWXZNbcKcicds087B9Nqpee7ucVALTcm',NULL,'2026-09-07 03:03:12','2026-09-07 03:03:12',NULL),(3,'Amit','Patel','sales@crm.test','9820000003','salesperson',1,NULL,NULL,'$2y$12$BLC3z9FIf8fwA4KWs82d3uAErQSN1ip.cBzTxeNNtyxWBECz9RrD2',NULL,'2026-09-07 03:03:12','2026-09-07 04:39:00',NULL),(4,'Nisha','Desai','sales2@crm.test','9820000004','salesperson',1,NULL,NULL,'$2y$12$i6OOTQRqozf2HHSji4W4TO11K6u8qN/UHOgti0HRkJdAXRGPaPDA6',NULL,'2026-09-07 03:03:12','2026-09-07 03:03:12',NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'lead_crm'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-08 13:20:55
