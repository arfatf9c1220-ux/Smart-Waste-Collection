-- =============================================================================
--  SMART WASTE COLLECTION OPTIMIZER  ::  DEMO DATA
--  Import AFTER database/schema.sql so the dashboards are populated instantly.
--
--  Demo accounts (all passwords are bcrypt hashes, never plain text):
--    admin@smartwaste.gov    / admin@123     -> Municipal Authority
--    worker1@smartwaste.gov  / worker@123    -> Collection worker (Team Alpha)
--    worker2@smartwaste.gov  / worker@123    -> Collection worker (Team Beta)
--    citizen1@smartwaste.gov / citizen@123   -> Citizen
--    citizen2@smartwaste.gov / citizen@123   -> Citizen
-- =============================================================================

USE `smart_waste_optimizer`;

-- Truncate in dependency order so this file can be re-run.
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE `activity_logs`;
TRUNCATE TABLE `notifications`;
TRUNCATE TABLE `worker_locations`;
TRUNCATE TABLE `collection_plan_stops`;
TRUNCATE TABLE `assignments`;
TRUNCATE TABLE `request_images`;
TRUNCATE TABLE `waste_requests`;
TRUNCATE TABLE `collection_plans`;
TRUNCATE TABLE `hotspots`;
TRUNCATE TABLE `vehicles`;
TRUNCATE TABLE `workers`;
TRUNCATE TABLE `teams`;
TRUNCATE TABLE `citizens`;
TRUNCATE TABLE `users`;
SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- USERS  (1 admin + 8 workers + 10 citizens = 19 accounts)
-- password_hash($plain, PASSWORD_DEFAULT) -> bcrypt $2y$10$...
-- -----------------------------------------------------------------------------
INSERT INTO `users` (`id`,`full_name`,`email`,`password_hash`,`phone`,`role`,`status`,`created_at`) VALUES
(1,'Anita Deshmukh','admin@smartwaste.gov','$2y$10$nXl.0syyOob.99uN7az40eP0MZ.BCC.Dwg5E1aQHdc/nXyIKgacAC','9822001000','admin','active', NOW() - INTERVAL 200 DAY),
(2,'Ravi Deshmukh','worker1@smartwaste.gov','$2y$10$6tcRtvN7vStBxe0WhjJzH.xGDylzQDnGlPRPmIwGo0zooAyj.uaa6','9822002001','worker','active', NOW() - INTERVAL 180 DAY),
(3,'Sunil Pawar','worker2@smartwaste.gov','$2y$10$6tcRtvN7vStBxe0WhjJzH.xGDylzQDnGlPRPmIwGo0zooAyj.uaa6','9822002002','worker','active', NOW() - INTERVAL 175 DAY),
(4,'Imran Shaikh','worker3@smartwaste.gov','$2y$10$6tcRtvN7vStBxe0WhjJzH.xGDylzQDnGlPRPmIwGo0zooAyj.uaa6','9822002003','worker','active', NOW() - INTERVAL 170 DAY),
(5,'Prakash Jadhav','worker4@smartwaste.gov','$2y$10$6tcRtvN7vStBxe0WhjJzH.xGDylzQDnGlPRPmIwGo0zooAyj.uaa6','9822002004','worker','active', NOW() - INTERVAL 165 DAY),
(6,'Sandeep Kamble','worker5@smartwaste.gov','$2y$10$6tcRtvN7vStBxe0WhjJzH.xGDylzQDnGlPRPmIwGo0zooAyj.uaa6','9822002005','worker','active', NOW() - INTERVAL 160 DAY),
(7,'Nitin Gaikwad','worker6@smartwaste.gov','$2y$10$6tcRtvN7vStBxe0WhjJzH.xGDylzQDnGlPRPmIwGo0zooAyj.uaa6','9822002006','worker','active', NOW() - INTERVAL 150 DAY),
(8,'Farida Sayyed','worker7@smartwaste.gov','$2y$10$6tcRtvN7vStBxe0WhjJzH.xGDylzQDnGlPRPmIwGo0zooAyj.uaa6','9822002007','worker','active', NOW() - INTERVAL 140 DAY),
(9,'Mahesh Chavan','worker8@smartwaste.gov','$2y$10$6tcRtvN7vStBxe0WhjJzH.xGDylzQDnGlPRPmIwGo0zooAyj.uaa6','9822002008','worker','active', NOW() - INTERVAL 120 DAY),
(10,'Priya Sharma','citizen1@smartwaste.gov','$2y$10$Zm4t7VvASqqn.hnZQA6ZjOMaonNP9K9ujVqfLFLe3waXOTWBg5ZC2','9900111001','citizen','active', NOW() - INTERVAL 120 DAY),
(11,'Rohit Mehta','citizen2@smartwaste.gov','$2y$10$Zm4t7VvASqqn.hnZQA6ZjOMaonNP9K9ujVqfLFLe3waXOTWBg5ZC2','9900111002','citizen','active', NOW() - INTERVAL 118 DAY),
(12,'Sneha Kulkarni','citizen3@smartwaste.gov','$2y$10$Zm4t7VvASqqn.hnZQA6ZjOMaonNP9K9ujVqfLFLe3waXOTWBg5ZC2','9900111003','citizen','active', NOW() - INTERVAL 110 DAY),
(13,'Amit Joshi','citizen4@smartwaste.gov','$2y$10$Zm4t7VvASqqn.hnZQA6ZjOMaonNP9K9ujVqfLFLe3waXOTWBg5ZC2','9900111004','citizen','active', NOW() - INTERVAL 105 DAY),
(14,'Kavita Rao','citizen5@smartwaste.gov','$2y$10$Zm4t7VvASqqn.hnZQA6ZjOMaonNP9K9ujVqfLFLe3waXOTWBg5ZC2','9900111005','citizen','active', NOW() - INTERVAL 96 DAY),
(15,'Vikram Singh','citizen6@smartwaste.gov','$2y$10$Zm4t7VvASqqn.hnZQA6ZjOMaonNP9K9ujVqfLFLe3waXOTWBg5ZC2','9900111006','citizen','active', NOW() - INTERVAL 88 DAY),
(16,'Meera Nair','citizen7@smartwaste.gov','$2y$10$Zm4t7VvASqqn.hnZQA6ZjOMaonNP9K9ujVqfLFLe3waXOTWBg5ZC2','9900111007','citizen','active', NOW() - INTERVAL 80 DAY),
(17,'Suresh Patil','citizen8@smartwaste.gov','$2y$10$Zm4t7VvASqqn.hnZQA6ZjOMaonNP9K9ujVqfLFLe3waXOTWBg5ZC2','9900111008','citizen','active', NOW() - INTERVAL 70 DAY),
(18,'Anjali Bhatt','citizen9@smartwaste.gov','$2y$10$Zm4t7VvASqqn.hnZQA6ZjOMaonNP9K9ujVqfLFLe3waXOTWBg5ZC2','9900111009','citizen','active', NOW() - INTERVAL 60 DAY),
(19,'Deepak Yadav','citizen10@smartwaste.gov','$2y$10$Zm4t7VvASqqn.hnZQA6ZjOMaonNP9K9ujVqfLFLe3waXOTWBg5ZC2','9900111010','citizen','active', NOW() - INTERVAL 45 DAY);

-- -----------------------------------------------------------------------------
-- CITIZENS  (10)
-- -----------------------------------------------------------------------------
INSERT INTO `citizens` (`id`,`user_id`,`citizen_code`,`address`,`city`,`locality`,`latitude`,`longitude`,`total_reports`) VALUES
(1,10,'CIT-1001','Flat 402, Shivaji Residency, FC Road','Pune','Shivajinagar',18.5308000,73.8472000,2),
(2,11,'CIT-1002','18 Ganesh Colony, Kothrud','Pune','Kothrud',18.5074000,73.8074000,2),
(3,12,'CIT-1003','B-14 Market Yard, Camp Road','Pune','Pune Camp',18.5124000,73.8785000,2),
(4,13,'CIT-1004','Plot 7, Viman Nagar IT Park Road','Pune','Viman Nagar',18.5679000,73.9143000,2),
(5,14,'CIT-1005','Wagholi Farm Road','Pune','Wagholi',18.5790000,73.9800000,1),
(6,15,'CIT-1006','Shop 22, Market Yard Cloth Market','Pune','Pune Camp',18.5105000,73.8795000,2),
(7,16,'CIT-1007','Rajshree Heights, Hadapsar','Pune','Hadapsar',18.5089000,73.9260000,2),
(8,17,'CIT-1008','Green Meadows, Baner Road','Pune','Baner',18.5642000,73.7764000,2),
(9,18,'CIT-1009','Sunrise Apartments, Kharadi','Pune','Kharadi',18.5514000,73.9473000,2),
(10,19,'CIT-1010','Hinjewadi IT Park Phase 2','Pune','Hinjewadi',18.5913000,73.7389000,1);

-- -----------------------------------------------------------------------------
-- TEAMS  (3)  --  waste_types lists drive the compatibility part of the engine
-- -----------------------------------------------------------------------------
INSERT INTO `teams` (`id`,`team_name`,`team_code`,`supervisor`,`phone`,`depot_address`,`latitude`,`longitude`,`waste_types`,`max_load`,`status`) VALUES
(1,'Alpha Sanitation Squad','TEAM-A','Ravi Deshmukh','9822002001','Kothrud Municipal Depot, Paud Road',18.5074000,73.8074000,'general,plastic,organic,other',12,'active'),
(2,'Beta Bulk Collectors','TEAM-B','Sunil Pawar','9822002002','Market Yard Depot, Camp Road',18.5124000,73.8785000,'general,plastic,organic,construction,hazardous',14,'active'),
(3,'Gamma Green-Tech Unit','TEAM-G','Imran Shaikh','9822002003','Viman Nagar Transfer Station',18.5679000,73.9143000,'e_waste,hazardous,electronic',10,'active');

-- -----------------------------------------------------------------------------
-- VEHICLES  (3)
-- -----------------------------------------------------------------------------
INSERT INTO `vehicles` (`id`,`vehicle_number`,`vehicle_type`,`capacity_kg`,`team_id`,`vehicle_status`,`last_service_at`) VALUES
(1,'MH12-AB-1234','tipper',3200,1,'in_service', DATE_SUB(NOW(), INTERVAL 20 DAY)),
(2,'MH12-CD-5678','truck',5000,2,'in_service', DATE_SUB(NOW(), INTERVAL 35 DAY)),
(3,'MH12-EF-9012','e_waste_van',1500,3,'in_service', DATE_SUB(NOW(), INTERVAL 12 DAY));

-- -----------------------------------------------------------------------------
-- WORKERS  (8)
--   availability / status : available, busy, offline
--   current_load / max_load : drives the workload score
-- -----------------------------------------------------------------------------
INSERT INTO `workers`
(`id`,`user_id`,`worker_code`,`team_id`,`vehicle_id`,`phone`,`latitude`,`longitude`,`availability`,`status`,`current_load`,`max_load`,`total_collected`,`rating`,`skills`,`last_seen_at`) VALUES
(1, 2,'WK-001',1,1,'9822002001',18.5091000,73.8123000,'available','available',1,6, 47,4.80,'general,organic,plastic', NOW() - INTERVAL 4 MINUTE),
(2, 3,'WK-002',2,2,'9822002002',18.5142000,73.8741000,'busy',    'busy',    5,8, 62,4.65,'construction,hazardous,bulk', NOW() - INTERVAL 2 MINUTE),
(3, 4,'WK-003',3,3,'9822002003',18.5665000,73.9118000,'available','available',0,5, 38,4.90,'e_waste,electronics,hazardous', NOW() - INTERVAL 1 MINUTE),
(4, 5,'WK-004',1,1,'9822002004',18.5056000,73.8049000,'available','available',2,6, 41,4.55,'general,plastic,organic', NOW() - INTERVAL 9 MINUTE),
(5, 6,'WK-005',2,2,'9822002005',18.5161000,73.8822000,'busy',    'busy',    6,8, 55,4.40,'construction,bulk', NOW() - INTERVAL 6 MINUTE),
(6, 7,'WK-006',3,3,'9822002006',18.5712000,73.9195000,'offline', 'offline', 0,5, 29,4.70,'e_waste,hazardous', NOW() - INTERVAL 1 DAY),
(7, 8,'WK-007',1,1,'9822002007',18.5238000,73.8487000,'available','available',1,6, 33,4.60,'general,plastic', NOW() - INTERVAL 3 MINUTE),
(8, 9,'WK-008',2,2,'9822002008',18.5560000,73.9400000,'available','available',3,8, 51,4.85,'bulk,construction,hazardous', NOW() - INTERVAL 5 MINUTE);

-- -----------------------------------------------------------------------------
-- WASTE REQUESTS  (20)  priority_score / priority_level are refreshed by
-- includes/priority.php at import time (see database/seed_finish.php).
--   * Shivajinagar / FC Road      -> repeated reports  => PREDICTED HOTSPOT
--   * Market Yard / Camp Road     -> repeated reports  => PREDICTED HOTSPOT
--   * Kharadi Transfer Point      -> repeated reports  => PREDICTED HOTSPOT
-- -----------------------------------------------------------------------------
INSERT INTO `waste_requests`
(`id`,`request_code`,`citizen_id`,`user_id`,`waste_type`,`quantity`,`description`,`address`,`landmark`,`latitude`,`longitude`,`status`,`assigned_worker_id`,`assigned_team_id`,`vehicle_id`,`estimated_visit_time`,`collected_at`,`verified_at`,`verified_by`,`image_path`,`before_image`,`after_image`,`collection_lat`,`collection_lng`,`created_at`) VALUES
-- ---------- COMPLETED + VERIFIED (history for analytics) ----------
(1 ,'WR-2026-000001',1,10,'general','medium','Garbage bin overflowing near the society gate, daily overflow.','Flat 402, Shivaji Residency, FC Road','Shivaji Residency Main Gate',18.5308000,73.8472000,'verified',1,1,1, DATE_SUB(NOW(), INTERVAL 12 DAY), DATE_SUB(NOW(), INTERVAL 12 DAY), DATE_SUB(NOW(), INTERVAL 12 DAY), 1, 'uploads/waste/demo-waste-1.png','uploads/before/demo-before-1.png','uploads/after/demo-after-1.png',18.5309000,73.8473000, DATE_SUB(NOW(), INTERVAL 13 DAY)),
(2 ,'WR-2026-000002',3,12,'organic','large','Rotting vegetable waste heap beside the vegetable market, flies everywhere.','Vegetable Market, Camp Road','Market Yard Vegetable Section',18.5124000,73.8785000,'verified',2,2,2, DATE_SUB(NOW(), INTERVAL 11 DAY), DATE_SUB(NOW(), INTERVAL 11 DAY), DATE_SUB(NOW(), INTERVAL 11 DAY), 1, 'uploads/waste/demo-waste-2.png','uploads/before/demo-before-2.png','uploads/after/demo-after-2.png',18.5125000,73.8786000, DATE_SUB(NOW(), INTERVAL 12 DAY)),
(3 ,'WR-2026-000003',6,15,'plastic','medium','Shops throwing plastic bags into the nullah behind the cloth market.','Cloth Market, Market Yard','Market Yard Back Nala',18.5105000,73.8795000,'verified',2,2,2, DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_SUB(NOW(), INTERVAL 10 DAY), 1, 'uploads/waste/demo-waste-3.png','uploads/before/demo-before-3.png','uploads/after/demo-after-3.png',18.5106000,73.8796000, DATE_SUB(NOW(), INTERVAL 11 DAY)),
(4 ,'WR-2026-000004',4,13,'e_waste','small','Old monitors and batteries dumped behind the IT park canteen.','Viman Nagar IT Park, Gate 2','IT Park Canteen Back Lane',18.5679000,73.9143000,'verified',3,3,3, DATE_SUB(NOW(), INTERVAL 9 DAY), DATE_SUB(NOW(), INTERVAL 9 DAY), DATE_SUB(NOW(), INTERVAL 9 DAY), 1, 'uploads/waste/demo-waste-4.png','uploads/before/demo-before-4.png','uploads/after/demo-after-4.png',18.5680000,73.9144000, DATE_SUB(NOW(), INTERVAL 10 DAY)),
(5 ,'WR-2026-000005',9,18,'general','large','Construction debris blocking the internal road of the society.','Sunrise Apartments, Kharadi','Kharadi Transfer Point Road',18.5514000,73.9473000,'verified',8,2,2, DATE_SUB(NOW(), INTERVAL 8 DAY), DATE_SUB(NOW(), INTERVAL 8 DAY), DATE_SUB(NOW(), INTERVAL 8 DAY), 1, 'uploads/waste/demo-waste-5.png','uploads/before/demo-before-5.png','uploads/after/demo-after-5.png',18.5515000,73.9474000, DATE_SUB(NOW(), INTERVAL 9 DAY)),
(6 ,'WR-2026-000006',2,11,'hazardous','small','Used sanitizer bottles and medical waste dumped near the clinic.','Ganesh Colony, Kothrud','Kothrud Clinic Road',18.5074000,73.8074000,'verified',4,1,1, DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_SUB(NOW(), INTERVAL 7 DAY), 1, 'uploads/waste/demo-waste-6.png','uploads/before/demo-before-6.png','uploads/after/demo-after-6.png',18.5075000,73.8075000, DATE_SUB(NOW(), INTERVAL 8 DAY)),
(7 ,'WR-2026-000007',8,17,'plastic','medium','Plastic bottles accumulating near the school gate.','Green Meadows, Baner','Baner School Gate',18.5642000,73.7764000,'verified',7,1,1, DATE_SUB(NOW(), INTERVAL 6 DAY), DATE_SUB(NOW(), INTERVAL 6 DAY), DATE_SUB(NOW(), INTERVAL 6 DAY), 1, 'uploads/waste/demo-waste-7.png','uploads/before/demo-before-7.png','uploads/after/demo-after-7.png',18.5643000,73.7765000, DATE_SUB(NOW(), INTERVAL 7 DAY)),
(8 ,'WR-2026-000008',1,10,'organic','medium','Wet waste pile in the society corner, smells very bad.','Shivaji Residency, FC Road','Shivaji Residency Corner',18.5307000,73.8471000,'verified',1,1,1, DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY), 1, 'uploads/waste/demo-waste-8.png','uploads/before/demo-before-8.png','uploads/after/demo-after-8.png',18.5308000,73.8472000, DATE_SUB(NOW(), INTERVAL 6 DAY)),
(9 ,'WR-2026-000009',3,12,'general','medium','Wet garbage not cleared for four days in the lane.','Market Yard, Camp Road','Market Yard Main Lane',18.5123000,73.8784000,'verified',2,2,2, DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY), 1, 'uploads/waste/demo-waste-9.png','uploads/before/demo-before-9.png','uploads/after/demo-after-9.png',18.5124000,73.8785000, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(10,'WR-2026-000010',9,18,'plastic','large','Plastic waste dumped at the Kharadi transfer point, very visible.','Kharadi Transfer Point','Kharadi Transfer Point Gate',18.5513000,73.9472000,'verified',8,2,2, DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY), 1, 'uploads/waste/demo-waste-10.png','uploads/before/demo-before-10.png','uploads/after/demo-after-10.png',18.5514000,73.9473000, DATE_SUB(NOW(), INTERVAL 4 DAY)),
-- ---------- COLLECTED, WAITING FOR ADMIN VERIFICATION ----------
(11,'WR-2026-000011',6,15,'hazardous','medium','Broken fluorescent tubes and batteries dumped behind the market shed.','Market Yard Loading Shed','Market Yard Shed Side',18.5110000,73.8790000,'collected',5,2,2, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 5 HOUR), NULL, NULL, 'uploads/waste/demo-waste-11.png','uploads/before/demo-before-11.png','uploads/after/demo-after-11.png',18.5111000,73.8791000, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(12,'WR-2026-000012',7,16,'organic','large','Food waste from the wedding hall dumped on the road shoulder.','Rajshree Heights, Hadapsar','Hadapsar Wedding Hall Road',18.5089000,73.9260000,'collected',3,3,3, DATE_SUB(NOW(), INTERVAL 20 HOUR), DATE_SUB(NOW(), INTERVAL 2 HOUR), NULL, NULL, 'uploads/waste/demo-waste-12.png','uploads/before/demo-before-12.png','uploads/after/demo-after-12.png',18.5090000,73.9261000, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(13,'WR-2026-000013',4,13,'e_waste','medium','Fridges and washing machines dumped near the transformer.','Viman Nagar IT Park','Viman Nagar Transformer Lane',18.5675000,73.9138000,'collected',3,3,3, DATE_SUB(NOW(), INTERVAL 16 HOUR), DATE_SUB(NOW(), INTERVAL 90 MINUTE), NULL, NULL, 'uploads/waste/demo-waste-13.png','uploads/before/demo-before-13.png','uploads/after/demo-after-13.png',18.5676000,73.9139000, DATE_SUB(NOW(), INTERVAL 3 DAY)),
-- ---------- IN PROGRESS / ASSIGNED / SCHEDULED ----------
(14,'WR-2026-000014',3,12,'organic','bulk','Entire vegetable market waste is piled up, needs a full tipper today.','Vegetable Market, Camp Road','Market Yard Vegetable Section',18.5125000,73.8786000,'in_progress',2,2,2, DATE_SUB(NOW(), INTERVAL 2 HOUR), NULL, NULL, NULL, 'uploads/waste/demo-waste-14.png','uploads/before/demo-before-14.png',NULL,18.5125000,73.8786000, DATE_SUB(NOW(), INTERVAL 30 HOUR)),
(15,'WR-2026-000015',1,10,'hazardous','medium','Chemical drums from a workshop dumped near the school gate.','Shivaji Residency, FC Road','FC Road School Junction',18.5310000,73.8474000,'assigned',1,1,1, DATE_ADD(NOW(), INTERVAL 3 HOUR), NULL, NULL, NULL, 'uploads/waste/demo-waste-15.png',NULL,NULL,18.5310000,73.8474000, DATE_SUB(NOW(), INTERVAL 16 HOUR)),
(16,'WR-2026-000016',9,18,'general','large','Debris and garbage mix blocking the transfer point entry.','Kharadi Transfer Point','Kharadi Transfer Point Entry',18.5515000,73.9475000,'scheduled',8,2,2, DATE_ADD(NOW(), INTERVAL 5 HOUR), NULL, NULL, NULL, 'uploads/waste/demo-waste-16.png',NULL,NULL,18.5515000,73.9475000, DATE_SUB(NOW(), INTERVAL 12 HOUR)),
-- ---------- FRESH PENDING / PRIORITISED (these get planned in the demo) ----------
(17,'WR-2026-000017',10,19,'plastic','small','Plastic packets collected near the nala, blocking water flow.','Hinjewadi IT Park Phase 2','Nala near IT Gate 3',18.5913000,73.7389000,'pending',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'uploads/waste/demo-waste-17.png',NULL,NULL,18.5913000,73.7389000, DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(18,'WR-2026-000018',5,14,'organic','small','Garden waste dumped near the road corner, not collected for days.','Wagholi Farm Road','Corner of the Farm Road',18.5790000,73.9800000,'pending',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'uploads/waste/demo-waste-18.png',NULL,NULL,18.5790000,73.9800000, DATE_SUB(NOW(), INTERVAL 7 HOUR)),
(19,'WR-2026-000019',2,11,'construction','large','Broken wall debris from road digging left on the footpath.','Ganesh Colony, Kothrud','Kothrud Road Bridge',18.5073000,73.8073000,'prioritized',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'uploads/waste/demo-waste-19.png',NULL,NULL,18.5073000,73.8073000, DATE_SUB(NOW(), INTERVAL 40 HOUR)),
(20,'WR-2026-000020',8,17,'e_waste','medium','Old laptop and mobile batteries dumped near the society temple.','Green Meadows, Baner','Baner Society Temple',18.5644000,73.7766000,'prioritized',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'uploads/waste/demo-waste-20.png',NULL,NULL,18.5644000,73.7766000, DATE_SUB(NOW(), INTERVAL 28 HOUR));

-- -----------------------------------------------------------------------------
-- REQUEST_IMAGES  (mirror of the columns above so the UI can show galleries)
-- -----------------------------------------------------------------------------
INSERT INTO `request_images` (`request_id`,`image_type`,`file_path`,`mime_type`,`file_size`,`uploaded_by`) VALUES
(1,'waste','uploads/waste/demo-waste-1.png','image/png',184320,10),
(1,'before','uploads/before/demo-before-1.png','image/png',165100,2),
(1,'after','uploads/after/demo-after-1.png','image/png',142800,2),
(2,'waste','uploads/waste/demo-waste-2.png','image/png',201450,12),
(2,'before','uploads/before/demo-before-2.png','image/png',198300,3),
(2,'after','uploads/after/demo-after-2.png','image/png',176200,3),
(3,'waste','uploads/waste/demo-waste-3.png','image/png',175900,15),
(3,'before','uploads/before/demo-before-3.png','image/png',171200,3),
(3,'after','uploads/after/demo-after-3.png','image/png',160400,3),
(4,'waste','uploads/waste/demo-waste-4.png','image/png',190300,13),
(4,'before','uploads/before/demo-before-4.png','image/png',188100,4),
(4,'after','uploads/after/demo-after-4.png','image/png',175600,4),
(5,'waste','uploads/waste/demo-waste-5.png','image/png',215600,18),
(5,'before','uploads/before/demo-before-5.png','image/png',212300,9),
(5,'after','uploads/after/demo-after-5.png','image/png',198700,9),
(6,'waste','uploads/waste/demo-waste-6.png','image/png',166700,11),
(6,'before','uploads/before/demo-before-6.png','image/png',164200,5),
(6,'after','uploads/after/demo-after-6.png','image/png',151900,5),
(7,'waste','uploads/waste/demo-waste-7.png','image/png',158300,17),
(7,'before','uploads/before/demo-before-7.png','image/png',156400,8),
(7,'after','uploads/after/demo-after-7.png','image/png',144800,8),
(8,'waste','uploads/waste/demo-waste-8.png','image/png',172900,10),
(8,'before','uploads/before/demo-before-8.png','image/png',170500,2),
(8,'after','uploads/after/demo-after-8.png','image/png',158300,2),
(9,'waste','uploads/waste/demo-waste-9.png','image/png',169800,12),
(9,'before','uploads/before/demo-before-9.png','image/png',167400,3),
(9,'after','uploads/after/demo-after-9.png','image/png',155100,3),
(10,'waste','uploads/waste/demo-waste-10.png','image/png',183400,18),
(10,'before','uploads/before/demo-before-10.png','image/png',181200,9),
(10,'after','uploads/after/demo-after-10.png','image/png',168900,9),
(11,'waste','uploads/waste/demo-waste-11.png','image/png',195700,15),
(11,'before','uploads/before/demo-before-11.png','image/png',193500,5),
(11,'after','uploads/after/demo-after-11.png','image/png',180200,5),
(12,'waste','uploads/waste/demo-waste-12.png','image/png',205300,16),
(12,'before','uploads/before/demo-before-12.png','image/png',203100,4),
(12,'after','uploads/after/demo-after-12.png','image/png',189400,4),
(13,'waste','uploads/waste/demo-waste-13.png','image/png',188600,13),
(13,'before','uploads/before/demo-before-13.png','image/png',186400,4),
(13,'after','uploads/after/demo-after-13.png','image/png',174800,4),
(14,'waste','uploads/waste/demo-waste-14.png','image/png',224500,12),
(14,'before','uploads/before/demo-before-14.png','image/png',222000,3),
(15,'waste','uploads/waste/demo-waste-15.png','image/png',177800,10),
(16,'waste','uploads/waste/demo-waste-16.png','image/png',212900,18),
(17,'waste','uploads/waste/demo-waste-17.png','image/png',152400,19),
(18,'waste','uploads/waste/demo-waste-18.png','image/png',148700,14),
(19,'waste','uploads/waste/demo-waste-19.png','image/png',219700,11),
(20,'waste','uploads/waste/demo-waste-20.png','image/png',181500,17);

-- -----------------------------------------------------------------------------
-- ASSIGNMENTS  (audit trail for the requests that already have a worker)
-- -----------------------------------------------------------------------------
INSERT INTO `assignments` (`request_id`,`worker_id`,`team_id`,`vehicle_id`,`assigned_by`,`distance_km`,`match_score`,`reason`,`status`,`assigned_at`,`accepted_at`,`completed_at`) VALUES
(1,1,1,1,1, 2.40,88.50,'Recommended: closest available team with spare capacity','completed', DATE_SUB(NOW(), INTERVAL 13 DAY), DATE_SUB(NOW(), INTERVAL 12 DAY), DATE_SUB(NOW(), INTERVAL 12 DAY)),
(2,2,2,2,1, 1.10,92.00,'Recommended: bulk load, tipper required','completed', DATE_SUB(NOW(), INTERVAL 12 DAY), DATE_SUB(NOW(), INTERVAL 11 DAY), DATE_SUB(NOW(), INTERVAL 11 DAY)),
(3,2,2,2,1, 0.60,90.20,'Recommended: same team already in Market Yard','completed', DATE_SUB(NOW(), INTERVAL 11 DAY), DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_SUB(NOW(), INTERVAL 10 DAY)),
(4,3,3,3,1, 3.20,86.40,'Recommended: only Green-Tech unit handles E-Waste','completed', DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_SUB(NOW(), INTERVAL 9 DAY), DATE_SUB(NOW(), INTERVAL 9 DAY)),
(5,8,2,2,1, 7.80,71.30,'Manual: heavy debris needs the 5t truck','completed', DATE_SUB(NOW(), INTERVAL 9 DAY), DATE_SUB(NOW(), INTERVAL 8 DAY), DATE_SUB(NOW(), INTERVAL 8 DAY)),
(6,4,1,1,1, 4.90,79.60,'Recommended: nearest available Alpha squad member','completed', DATE_SUB(NOW(), INTERVAL 8 DAY), DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_SUB(NOW(), INTERVAL 7 DAY)),
(7,7,1,1,1, 9.60,68.20,'Recommended: school area, low priority batch','completed', DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_SUB(NOW(), INTERVAL 6 DAY), DATE_SUB(NOW(), INTERVAL 6 DAY)),
(8,1,1,1,1, 0.30,94.10,'Recommended: same gate, worker already on site','completed', DATE_SUB(NOW(), INTERVAL 6 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY)),
(9,2,2,2,1, 0.90,91.30,'Recommended: repeated Market Yard hotspot','completed', DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY)),
(10,8,2,2,1, 6.20,74.80,'Recommended: large plastic load','completed', DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY)),
(11,5,2,2,1, 1.40,83.60,'Recommended: hazardous handling certified team','completed', DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 5 HOUR)),
(12,3,3,3,1, 5.30,77.20,'Recommended: organic bulk with Green-Tech van','completed', DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 20 HOUR), DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(13,3,3,3,1, 0.40,95.00,'Recommended: E-Waste specialist on the same lane','completed', DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 16 HOUR), DATE_SUB(NOW(), INTERVAL 90 MINUTE)),
(14,2,2,2,1, 0.50,93.20,'Recommended: bulk vegetable load, tipper required','on_the_way', DATE_SUB(NOW(), INTERVAL 30 HOUR), DATE_SUB(NOW(), INTERVAL 28 HOUR), NULL),
(15,1,1,1,1, 2.60,89.10,'Recommended: hazardous drums near a school junction','accepted', DATE_SUB(NOW(), INTERVAL 16 HOUR), DATE_SUB(NOW(), INTERVAL 15 HOUR), NULL),
(16,8,2,2,1, 6.50,76.80,'Recommended: assigned during evening plan','assigned', DATE_SUB(NOW(), INTERVAL 12 HOUR), NULL, NULL);

-- -----------------------------------------------------------------------------
-- COLLECTION PLANS  (1 completed, 1 active) with their stops
-- -----------------------------------------------------------------------------
INSERT INTO `collection_plans`
(`id`,`plan_code`,`team_id`,`vehicle_id`,`worker_id`,`plan_date`,`status`,`total_stops`,`total_distance_km`,`estimated_duration_min`,`notes`,`started_at`,`completed_at`,`created_by`,`created_at`) VALUES
(1,'CP-260310-001',2,2,2, DATE_SUB(CURDATE(), INTERVAL 10 DAY),'completed', 4, 6.80, 96,'Morning Market Yard sweep', DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_SUB(NOW(), INTERVAL 10 DAY), 1, DATE_SUB(NOW(), INTERVAL 11 DAY)),
(2,'CP-260312-002',2,2,2, CURDATE(),'active', 3, 3.10, 58,'Market Yard + Kharadi priority run', DATE_SUB(NOW(), INTERVAL 2 HOUR), NULL, 1, DATE_SUB(NOW(), INTERVAL 4 HOUR));

INSERT INTO `collection_plan_stops`
(`plan_id`,`request_id`,`stop_number`,`sequence_order`,`eta`,`distance_from_prev_km`,`travel_minutes`,`priority_score`,`status`,`arrived_at`,`completed_at`) VALUES
(1,2,1,1, DATE_SUB(NOW(), INTERVAL 10 DAY) + INTERVAL 9 HOUR + INTERVAL 10 MINUTE, 0.40, 3, 78,'collected', DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_SUB(NOW(), INTERVAL 10 DAY)),
(1,3,2,2, DATE_SUB(NOW(), INTERVAL 10 DAY) + INTERVAL 9 HOUR + INTERVAL 40 MINUTE, 0.60, 5, 66,'collected', DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_SUB(NOW(), INTERVAL 10 DAY)),
(1,9,3,3, DATE_SUB(NOW(), INTERVAL 10 DAY) + INTERVAL 9 HOUR + INTERVAL 70 MINUTE, 0.90, 7, 61,'collected', DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_SUB(NOW(), INTERVAL 10 DAY)),
(1,11,4,4, DATE_SUB(NOW(), INTERVAL 10 DAY) + INTERVAL 10 HOUR + INTERVAL 5 MINUTE, 2.50, 12, 71,'collected', DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_SUB(NOW(), INTERVAL 10 DAY)),
(2,14,1,1, DATE_SUB(NOW(), INTERVAL 2 HOUR), 0.50, 4, 84,'in_progress', DATE_SUB(NOW(), INTERVAL 100 MINUTE), NULL),
(2,11,2,2, DATE_SUB(NOW(), INTERVAL 40 MINUTE), 1.20, 6, 71,'collected', DATE_SUB(NOW(), INTERVAL 45 MINUTE), DATE_SUB(NOW(), INTERVAL 5 HOUR)),
(2,13,3,3, DATE_SUB(NOW(), INTERVAL 15 MINUTE), 1.40, 7, 74,'collected', DATE_SUB(NOW(), INTERVAL 30 MINUTE), DATE_SUB(NOW(), INTERVAL 90 MINUTE));

-- Link requests 14/16 to plan 2 and set their plan_stop_number.
UPDATE `waste_requests` SET `collection_plan_id` = 2, `plan_stop_number` = 1 WHERE `id` = 14;
UPDATE `waste_requests` SET `collection_plan_id` = 2, `plan_stop_number` = 2 WHERE `id` = 11;
UPDATE `waste_requests` SET `collection_plan_id` = 2, `plan_stop_number` = 3 WHERE `id` = 13;

-- -----------------------------------------------------------------------------
-- WORKER LOCATIONS  (a small GPS breadcrumb per worker)
-- -----------------------------------------------------------------------------
INSERT INTO `worker_locations` (`worker_id`,`latitude`,`longitude`,`accuracy_m`,`speed_kph`,`heading`,`status`,`source`,`recorded_at`) VALUES
(1,18.5085000,73.8105000,12.00,18.50,45.00,'available','gps', DATE_SUB(NOW(), INTERVAL 22 MINUTE)),
(1,18.5091000,73.8123000, 8.00, 0.00, 0.00,'available','gps', DATE_SUB(NOW(), INTERVAL 4 MINUTE)),
(2,18.5124000,73.8758000,10.00,24.00,90.00,'busy','gps',     DATE_SUB(NOW(), INTERVAL 16 MINUTE)),
(2,18.5142000,73.8741000, 7.00, 0.00, 0.00,'busy','gps',     DATE_SUB(NOW(), INTERVAL 2 MINUTE)),
(3,18.5665000,73.9118000,15.00,32.00,180.00,'available','gps', DATE_SUB(NOW(), INTERVAL 1 MINUTE)),
(4,18.5056000,73.8049000,11.00, 0.00, 0.00,'available','gps', DATE_SUB(NOW(), INTERVAL 9 MINUTE)),
(5,18.5161000,73.8822000,13.00,20.00,270.00,'busy','gps',     DATE_SUB(NOW(), INTERVAL 6 MINUTE)),
(7,18.5238000,73.8487000, 9.00, 0.00, 0.00,'available','gps', DATE_SUB(NOW(), INTERVAL 3 MINUTE)),
(8,18.5560000,73.9400000,10.00,26.00,300.00,'available','gps', DATE_SUB(NOW(), INTERVAL 5 MINUTE));

-- -----------------------------------------------------------------------------
-- NOTIFICATIONS  (a welcome feed for each role)
-- -----------------------------------------------------------------------------
INSERT INTO `notifications` (`user_id`,`title`,`message`,`type`,`link`,`is_read`) VALUES
(1,'Hotspot engine ready','3 predicted hotspots detected from the last 30 days of reports.','warning','/admin/hotspots.php',0),
(1,'3 collections awaiting verification','Requests #11, #12 and #13 have before/after photos.','info','/admin/verify.php',0),
(2,'Mission assigned','Plan CP-260312-002 has 3 stops today.','plan','/worker/mission.php',0),
(2,'Urgent pickup near your route','Request #15 (Hazardous, Priority High) is assigned to you.','warning','/worker/mission.php',0),
(3,'New stop on your active plan','Stop 1 - Request #14 (Organic Bulk, Priority 84).','plan','/worker/mission.php',0),
(10,'Your report is being processed','Request #1 was collected and verified. Thank you!','success','/citizen/track.php?id=1',1),
(10,'Update on request #15','Our team has been assigned and will visit today.','info','/citizen/track.php?id=15',0),
(12,'Update on request #14','Collection in progress at Market Yard.','info','/citizen/track.php?id=14',0);

-- -----------------------------------------------------------------------------
-- ACTIVITY LOG  (a believable audit trail)
-- -----------------------------------------------------------------------------
INSERT INTO `activity_logs` (`user_id`,`actor_role`,`action`,`entity_type`,`entity_id`,`description`,`created_at`) VALUES
(1,'admin','plan_created','collection_plan',1,'Plan CP-260310-001 created with 4 stops for worker #2', DATE_SUB(NOW(), INTERVAL 11 DAY)),
(1,'admin','assign','request',14,'Request #14 assigned to Ravi Deshmukh (Team Alpha)', DATE_SUB(NOW(), INTERVAL 30 HOUR)),
(1,'admin','plan_created','collection_plan',2,'Plan CP-260312-002 created with 3 stops for worker #2', DATE_SUB(NOW(), INTERVAL 4 HOUR)),
(1,'admin','verify','request',10,'Request #10 verified after before/after review', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(1,'admin','verify','request',9, 'Request #9 verified after before/after review',  DATE_SUB(NOW(), INTERVAL 4 DAY)),
(1,'admin','hotspot_recompute','hotspot',NULL,'Hotspot engine rebuilt 3 cluster(s) over 30 days', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(2,'worker','collect','request',13,'Worker collected request #13 with before/after proof', DATE_SUB(NOW(), INTERVAL 90 MINUTE)),
(2,'worker','collect','request',11,'Worker collected request #11 with before/after proof', DATE_SUB(NOW(), INTERVAL 5 HOUR)),
(2,'worker','plan_start','collection_plan',2,'Plan CP-260312-002 started by Ravi Deshmukh', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(3,'worker','collect','request',12,'Worker collected request #12 with before/after proof', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(19,'citizen','create_request','waste_request',20,'Citizen Priya Sharma reported E-Waste at Green Meadows, Baner', DATE_SUB(NOW(), INTERVAL 28 HOUR)),
(11,'citizen','create_request','waste_request',19,'Citizen Rohit Mehta reported Construction Waste at Ganesh Colony', DATE_SUB(NOW(), INTERVAL 40 HOUR)),
(19,'citizen','create_request','waste_request',17,'Citizen Deepak Yadav reported Plastic at Hinjewadi IT Park Phase 2', DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(14,'citizen','create_request','waste_request',18,'Citizen Kavita Rao reported Organic Waste at Wagholi Farm Road', DATE_SUB(NOW(), INTERVAL 7 HOUR));

-- -----------------------------------------------------------------------------
-- SYNTHETIC BACK-FILL: makes the "requests per day" analytics chart meaningful
-- Older verified requests spread over the last 30 days.
-- -----------------------------------------------------------------------------
INSERT INTO `waste_requests`
(`request_code`,`citizen_id`,`user_id`,`waste_type`,`quantity`,`description`,`address`,`landmark`,`latitude`,`longitude`,`status`,`assigned_worker_id`,`assigned_team_id`,`vehicle_id`,`estimated_visit_time`,`collected_at`,`verified_at`,`verified_by`,`created_at`) VALUES
('WR-2026-000101',1,10,'general','small','Daily door-to-door waste not picked up.','Shivaji Residency, FC Road','Shivaji Residency Main Gate',18.5308000,73.8472000,'verified',1,1,1, DATE_SUB(NOW(), INTERVAL 26 DAY), DATE_SUB(NOW(), INTERVAL 26 DAY), DATE_SUB(NOW(), INTERVAL 26 DAY), 1, DATE_SUB(NOW(), INTERVAL 27 DAY)),
('WR-2026-000102',1,10,'plastic','small','Plastic wrappers in the society garden.','Shivaji Residency, FC Road','Shivaji Residency Garden',18.5309000,73.8471000,'verified',1,1,1, DATE_SUB(NOW(), INTERVAL 25 DAY), DATE_SUB(NOW(), INTERVAL 25 DAY), DATE_SUB(NOW(), INTERVAL 25 DAY), 1, DATE_SUB(NOW(), INTERVAL 26 DAY)),
('WR-2026-000103',3,12,'organic','medium','Market waste again blocking the entry.','Vegetable Market, Camp Road','Market Yard Vegetable Section',18.5124000,73.8785000,'verified',2,2,2, DATE_SUB(NOW(), INTERVAL 24 DAY), DATE_SUB(NOW(), INTERVAL 24 DAY), DATE_SUB(NOW(), INTERVAL 24 DAY), 1, DATE_SUB(NOW(), INTERVAL 25 DAY)),
('WR-2026-000104',6,15,'plastic','small','Carry bags dumped near the weighbridge.','Cloth Market, Market Yard','Market Yard Weighbridge',18.5104000,73.8794000,'verified',2,2,2, DATE_SUB(NOW(), INTERVAL 23 DAY), DATE_SUB(NOW(), INTERVAL 23 DAY), DATE_SUB(NOW(), INTERVAL 23 DAY), 1, DATE_SUB(NOW(), INTERVAL 24 DAY)),
('WR-2026-000105',9,18,'general','medium','Garbage truck missed the transfer point lane.','Kharadi Transfer Point','Kharadi Transfer Point Gate',18.5514000,73.9473000,'verified',8,2,2, DATE_SUB(NOW(), INTERVAL 21 DAY), DATE_SUB(NOW(), INTERVAL 21 DAY), DATE_SUB(NOW(), INTERVAL 21 DAY), 1, DATE_SUB(NOW(), INTERVAL 22 DAY)),
('WR-2026-000106',4,13,'e_waste','small','Cables and adapters thrown behind the Canteen.','Viman Nagar IT Park','IT Park Canteen Back Lane',18.5680000,73.9144000,'verified',3,3,3, DATE_SUB(NOW(), INTERVAL 20 DAY), DATE_SUB(NOW(), INTERVAL 20 DAY), DATE_SUB(NOW(), INTERVAL 20 DAY), 1, DATE_SUB(NOW(), INTERVAL 21 DAY)),
('WR-2026-000107',2,11,'organic','medium','Wet waste near the clinic gate.','Ganesh Colony, Kothrud','Kothrud Clinic Road',18.5075000,73.8074000,'verified',4,1,1, DATE_SUB(NOW(), INTERVAL 19 DAY), DATE_SUB(NOW(), INTERVAL 19 DAY), DATE_SUB(NOW(), INTERVAL 19 DAY), 1, DATE_SUB(NOW(), INTERVAL 20 DAY)),
('WR-2026-000108',10,19,'plastic','small','Plastics blocking the nala at the crossing.','Nirmal Nagar, Wakad','Wakad Nala Crossing',18.5975000,73.7625000,'verified',7,1,1, DATE_SUB(NOW(), INTERVAL 18 DAY), DATE_SUB(NOW(), INTERVAL 18 DAY), DATE_SUB(NOW(), INTERVAL 18 DAY), 1, DATE_SUB(NOW(), INTERVAL 19 DAY)),
('WR-2026-000109',5,14,'general','small','Leaf litter and dust from the temple road.','Snehalaya, Aundh','Aundh Temple Gate',18.5590000,73.8080000,'verified',4,1,1, DATE_SUB(NOW(), INTERVAL 17 DAY), DATE_SUB(NOW(), INTERVAL 17 DAY), DATE_SUB(NOW(), INTERVAL 17 DAY), 1, DATE_SUB(NOW(), INTERVAL 18 DAY)),
('WR-2026-000110',8,17,'plastic','small','Bottles near the school gate again.','Green Meadows, Baner','Baner School Gate',18.5642000,73.7764000,'verified',7,1,1, DATE_SUB(NOW(), INTERVAL 16 DAY), DATE_SUB(NOW(), INTERVAL 16 DAY), DATE_SUB(NOW(), INTERVAL 16 DAY), 1, DATE_SUB(NOW(), INTERVAL 17 DAY)),
('WR-2026-000111',3,12,'organic','medium','Rotting stock thrown behind the shed.','Market Yard, Camp Road','Market Yard Shed Side',18.5110000,73.8790000,'verified',2,2,2, DATE_SUB(NOW(), INTERVAL 15 DAY), DATE_SUB(NOW(), INTERVAL 15 DAY), DATE_SUB(NOW(), INTERVAL 15 DAY), 1, DATE_SUB(NOW(), INTERVAL 16 DAY)),
('WR-2026-000112',9,18,'construction','small','Small rubble pile at the society corner.','Sunrise Apartments, Kharadi','Kharadi Transfer Point Road',18.5515000,73.9474000,'verified',8,2,2, DATE_SUB(NOW(), INTERVAL 14 DAY), DATE_SUB(NOW(), INTERVAL 14 DAY), DATE_SUB(NOW(), INTERVAL 14 DAY), 1, DATE_SUB(NOW(), INTERVAL 15 DAY)),
('WR-2026-000113',6,15,'hazardous','small','Old paint cans dumped behind the shed.','Market Yard Loading Shed','Market Yard Shed Side',18.5111000,73.8791000,'verified',2,2,2, DATE_SUB(NOW(), INTERVAL 13 DAY), DATE_SUB(NOW(), INTERVAL 13 DAY), DATE_SUB(NOW(), INTERVAL 13 DAY), 1, DATE_SUB(NOW(), INTERVAL 14 DAY)),
('WR-2026-000114',1,10,'general','small','Gate dustbin overflowing once more.','Shivaji Residency, FC Road','Shivaji Residency Main Gate',18.5308000,73.8472000,'verified',1,1,1, DATE_SUB(NOW(), INTERVAL 12 DAY), DATE_SUB(NOW(), INTERVAL 12 DAY), DATE_SUB(NOW(), INTERVAL 12 DAY), 1, DATE_SUB(NOW(), INTERVAL 13 DAY)),
('WR-2026-000115',4,13,'e_waste','small','Printer cartridges dumped near the transformer.','Viman Nagar IT Park','Viman Nagar Transformer Lane',18.5675000,73.9138000,'verified',3,3,3, DATE_SUB(NOW(), INTERVAL 11 DAY), DATE_SUB(NOW(), INTERVAL 11 DAY), DATE_SUB(NOW(), INTERVAL 11 DAY), 1, DATE_SUB(NOW(), INTERVAL 12 DAY)),
('WR-2026-000116',2,11,'general','medium','Roadside garbage after the fair.','Ganesh Colony, Kothrud','Kothrud Road Bridge',18.5073000,73.8073000,'verified',4,1,1, DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_SUB(NOW(), INTERVAL 10 DAY), 1, DATE_SUB(NOW(), INTERVAL 11 DAY)),
('WR-2026-000117',7,16,'organic','small','Food waste from the mess hall.','Rajshree Heights, Hadapsar','Hadapsar Wedding Hall Road',18.5089000,73.9260000,'verified',3,3,3, DATE_SUB(NOW(), INTERVAL 9 DAY), DATE_SUB(NOW(), INTERVAL 9 DAY), DATE_SUB(NOW(), INTERVAL 9 DAY), 1, DATE_SUB(NOW(), INTERVAL 10 DAY)),
('WR-2026-000118',5,14,'plastic','small','Garden plastic sheeting dumped in the lane.','Snehalaya, Aundh','Aundh Temple Gate',18.5591000,73.8081000,'verified',4,1,1, DATE_SUB(NOW(), INTERVAL 8 DAY), DATE_SUB(NOW(), INTERVAL 8 DAY), DATE_SUB(NOW(), INTERVAL 8 DAY), 1, DATE_SUB(NOW(), INTERVAL 9 DAY)),
('WR-2026-000119',9,18,'general','medium','Overflowing bin at the transfer point.','Kharadi Transfer Point','Kharadi Transfer Point Gate',18.5513000,73.9472000,'verified',8,2,2, DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_SUB(NOW(), INTERVAL 7 DAY), 1, DATE_SUB(NOW(), INTERVAL 8 DAY)),
('WR-2026-000120',8,17,'e_waste','small','Broken charger dumped near the temple.','Green Meadows, Baner','Baner Society Temple',18.5644000,73.7766000,'verified',7,1,1, DATE_SUB(NOW(), INTERVAL 6 DAY), DATE_SUB(NOW(), INTERVAL 6 DAY), DATE_SUB(NOW(), INTERVAL 6 DAY), 1, DATE_SUB(NOW(), INTERVAL 7 DAY)),
('WR-2026-000121',3,12,'organic','large','Weekend market waste pile.','Vegetable Market, Camp Road','Market Yard Vegetable Section',18.5125000,73.8786000,'verified',2,2,2, DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY), 1, DATE_SUB(NOW(), INTERVAL 6 DAY)),
('WR-2026-000122',10,19,'general','small','Corner garbage after rain.','Nirmal Nagar, Wakad','Wakad Nala Crossing',18.5974000,73.7624000,'verified',7,1,1, DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY), 1, DATE_SUB(NOW(), INTERVAL 5 DAY)),
('WR-2026-000123',1,10,'organic','small','Garden leaves swept into the gate.','Shivaji Residency, FC Road','Shivaji Residency Garden',18.5309000,73.8471000,'verified',1,1,1, DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY), 1, DATE_SUB(NOW(), INTERVAL 4 DAY)),
('WR-2026-000124',6,15,'plastic','medium','Shops dumping bags behind the shed.','Market Yard Loading Shed','Market Yard Shed Side',18.5111000,73.8791000,'verified',2,2,2, DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY), 1, DATE_SUB(NOW(), INTERVAL 3 DAY)),
('WR-2026-000125',4,13,'general','small','Canteen waste not collected.','Viman Nagar IT Park','IT Park Canteen Back Lane',18.5680000,73.9144000,'verified',3,3,3, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), 1, DATE_SUB(NOW(), INTERVAL 2 DAY));

-- -----------------------------------------------------------------------------
-- Final housekeeping: keep the request codes unique and sequential.
-- -----------------------------------------------------------------------------
SET @i := 200;
UPDATE `waste_requests` SET `request_code` = CONCAT('WR-2026-', LPAD(@i := @i + 1, 6, '0'))
 WHERE `id` > 20;

-- -----------------------------------------------------------------------------
-- MUMBAI DEMO LOCALIZATION
-- These are illustrative records for Greater Mumbai, not official civic data.
-- -----------------------------------------------------------------------------
UPDATE `citizens` SET `city` = 'Mumbai',
	`address` = CASE `id`
		WHEN 1 THEN 'Shivaji Park, Dadar West'
		WHEN 2 THEN 'Lokhandwala, Andheri West'
		WHEN 3 THEN 'Byculla Market'
		WHEN 4 THEN 'Hiranandani Gardens, Powai'
		WHEN 5 THEN 'Mulund West'
		WHEN 6 THEN 'Chembur Market'
		WHEN 7 THEN 'Ghatkopar East'
		WHEN 8 THEN 'Borivali West'
		WHEN 9 THEN 'Bhandup West'
		ELSE 'Dahisar East'
	END,
	`locality` = CASE `id`
		WHEN 1 THEN 'Dadar West'
		WHEN 2 THEN 'Andheri West'
		WHEN 3 THEN 'Byculla'
		WHEN 4 THEN 'Powai'
		WHEN 5 THEN 'Mulund West'
		WHEN 6 THEN 'Chembur'
		WHEN 7 THEN 'Ghatkopar East'
		WHEN 8 THEN 'Borivali West'
		WHEN 9 THEN 'Bhandup West'
		ELSE 'Dahisar East'
	END,
	`latitude` = CASE `id`
		WHEN 1 THEN 19.0176000 WHEN 2 THEN 19.1197000 WHEN 3 THEN 18.9766000
		WHEN 4 THEN 19.1176000 WHEN 5 THEN 19.1726000 WHEN 6 THEN 19.0620000
		WHEN 7 THEN 19.0865000 WHEN 8 THEN 19.2307000 WHEN 9 THEN 19.1510000
		ELSE 19.2500000
	END,
	`longitude` = CASE `id`
		WHEN 1 THEN 72.8427000 WHEN 2 THEN 72.8464000 WHEN 3 THEN 72.8328000
		WHEN 4 THEN 72.9060000 WHEN 5 THEN 72.9565000 WHEN 6 THEN 72.9005000
		WHEN 7 THEN 72.9081000 WHEN 8 THEN 72.8567000 WHEN 9 THEN 72.9370000
		ELSE 72.8590000
	END;

UPDATE `teams` SET
	`depot_address` = CASE `id`
		WHEN 1 THEN 'Andheri West Municipal Depot, Mumbai'
		WHEN 2 THEN 'Byculla Transfer Depot, Mumbai'
		ELSE 'Powai Waste Collection Depot, Mumbai'
	END,
	`latitude` = CASE `id` WHEN 1 THEN 19.1197000 WHEN 2 THEN 18.9766000 ELSE 19.1176000 END,
	`longitude` = CASE `id` WHEN 1 THEN 72.8464000 WHEN 2 THEN 72.8328000 ELSE 72.9060000 END;

UPDATE `vehicles` SET `vehicle_number` = CONCAT('MH01-', SUBSTRING(`vehicle_number`, 6));

UPDATE `workers` SET
	`latitude` = CASE `id`
		WHEN 1 THEN 19.1197000 WHEN 2 THEN 18.9766000 WHEN 3 THEN 19.1176000
		WHEN 4 THEN 19.1197000 WHEN 5 THEN 18.9766000 WHEN 6 THEN 19.1176000
		WHEN 7 THEN 19.0176000 ELSE 19.1510000
	END,
	`longitude` = CASE `id`
		WHEN 1 THEN 72.8464000 WHEN 2 THEN 72.8328000 WHEN 3 THEN 72.9060000
		WHEN 4 THEN 72.8464000 WHEN 5 THEN 72.8328000 WHEN 6 THEN 72.9060000
		WHEN 7 THEN 72.8427000 ELSE 72.9370000
	END;

UPDATE `waste_requests` r
JOIN `citizens` c ON c.`id` = r.`citizen_id`
SET r.`address` = CONCAT(c.`address`, ', Mumbai'),
		r.`landmark` = c.`locality`,
		r.`latitude` = c.`latitude`,
		r.`longitude` = c.`longitude`,
		r.`collection_lat` = CASE WHEN r.`collection_lat` IS NULL THEN NULL ELSE c.`latitude` END,
		r.`collection_lng` = CASE WHEN r.`collection_lng` IS NULL THEN NULL ELSE c.`longitude` END;

UPDATE `worker_locations` l
JOIN `workers` w ON w.`id` = l.`worker_id`
SET l.`latitude` = w.`latitude`, l.`longitude` = w.`longitude`;

UPDATE `waste_requests`
SET `description` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
		`description`, 'Shivaji Residency', 'Shivaji Park'), 'FC Road', 'Ranade Road'),
		'Kothrud', 'Andheri West'), 'Market Yard', 'Byculla Market'), 'Camp Road', 'Byculla'),
		'Viman Nagar', 'Powai'), 'Wagholi', 'Mulund'), 'Hadapsar', 'Ghatkopar'),
	'Baner', 'Borivali West'), 'Kharadi', 'Bhandup West'), 'Hinjewadi', 'Dahisar'),
	'Wakad', 'Kandivali'), 'Aundh', 'Malad');

UPDATE `collection_plans`
SET `notes` = REPLACE(REPLACE(`notes`, 'Market Yard', 'Byculla Market'), 'Kharadi', 'Bhandup West');

UPDATE `notifications`
SET `title` = REPLACE(`title`, 'Market Yard', 'Byculla Market'),
	`message` = REPLACE(REPLACE(`message`, 'Market Yard', 'Byculla Market'), 'Kharadi', 'Bhandup West');

UPDATE `activity_logs`
SET `description` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
	`description`, 'Market Yard', 'Byculla Market'), 'Baner', 'Borivali West'),
	'Kothrud', 'Andheri West'), 'Hinjewadi', 'Dahisar'), 'Wagholi', 'Mulund'),
	'Wakad', 'Kandivali'), 'Aundh', 'Malad');
