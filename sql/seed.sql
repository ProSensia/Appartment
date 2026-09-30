-- ===========================================================================
--  FlatMate — DEMO DATA SEED
-- ---------------------------------------------------------------------------
--  Run AFTER  sql/schema.sql
--      mysql -u root -p flatmate_db < sql/seed.sql
--
--  Everything is generated relative to CURDATE(), so the dashboard always
--  looks "current" whenever you run it.
--
--  ---------------------------------------------------------------------------
--  DEMO CREDENTIALS   (all accounts share these login details)
--  ---------------------------------------------------------------------------
--    ADMIN      aisha@flatmate.test     / admin123
--    RESIDENT   rakib@flatmate.test     / password123
--    RESIDENT   nabila@flatmate.test    / password123
--    RESIDENT   tanha@flatmate.test     / password123
--    RESIDENT   sabbir@flatmate.test    / password123
--    RESIDENT   maliha@flatmate.test    / password123
--
--  Every resident password is bcrypt ($2y$, cost 10). Logins are also
--  available by magic link (see /api/index.php?action=auth.magic_request).
-- ===========================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Select the database before running, or uncomment and set it to match
-- config/config.php. Leaving this out is why an import can succeed with
-- "0 rows affected" and leave the app querying an empty schema.
-- USE `flatmate_db`;

SET @ws := DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY);  -- Monday 00:00 of this week

-- ===========================================================================
-- 1. APARTMENT
-- ===========================================================================
INSERT INTO `apartments`
  (`id`,`name`,`address_line`,`city`,`currency_code`,`currency_symbol`,`week_starts_on`,`meal_deadline_hr`,`settings`)
VALUES
  (1,'Sunrise Apartments — Block C','House 42, Road 11, Banani','Dhaka','BDT','৳',1,10,
   JSON_OBJECT('gracePeriodHours',6,'voteThreshold',1,'requirePhotoProof',false));

-- ===========================================================================
-- 2. ROOMS  (6 residents, 3 rooms × 2)
-- ===========================================================================
INSERT INTO `rooms` (`id`,`apartment_id`,`code`,`name`,`floor`,`capacity`,`rent_amount`,`notes`) VALUES
  (1,1,'101','Room 101 — North',1,2,12000.00,'Corner room, extra sunlight'),
  (2,1,'102','Room 102 — Middle',1,2,11000.00,'Adjacent to kitchen'),
  (3,1,'103','Room 103 — South',1,2,11000.00,'Balcony access'),
  (4,1,'104','Room 104 — Pool View',2,2,14000.00,'Higher rent, top floor'),
  (5,1,'105','Room 105 — East',2,2,12000.00,'Currently vacant'),
  (6,1,'106','Room 106 — West',2,2,12000.00,'Currently vacant');

-- ===========================================================================
-- 3. DUTY GROUPS  (the "6 residents / 2 washrooms" split)
-- ===========================================================================
INSERT INTO `duty_groups` (`id`,`apartment_id`,`name`,`slug`,`description`,`color`,`sort_order`) VALUES
  (1,1,'Washroom A','washroom-a','Users of the north-side shared washroom. Rotates 3-person cycle.','#0ea5e9',1),
  (2,1,'Washroom B','washroom-b','Users of the south-side shared washroom. Rotates 3-person cycle.','#f97316',2);

-- ===========================================================================
-- 4. USERS
--    admin / Aisha also lives in the flat so the ledger has 6 participants.
--    Rotation membership (duty_group_id) defines the washroom cohorts.
-- ===========================================================================
--  password hashes (bcrypt cost 10):
--    admin123     -> $2y$10$DU6X/G1jYk9Z/5OFFbcRAev5EqANqVDxJQ5G7CsEWvwUork.xSgCy
--    password123  -> $2y$10$GP3UszQVEyNPxNur3og.2u6k9j7vkD64.OImFADIF2kBuiM6uhq9W
INSERT INTO `users`
  (`id`,`apartment_id`,`participant_code`,`full_name`,`email`,`phone`,`password_hash`,`role`,`status`,
   `room_id`,`duty_group_id`,`avatar_color`,`bio`,`favourite_food`,`joined_on`)
VALUES
  (1,1,'FM-2X4K9P','Aisha Rahman','aisha@flatmate.test','+8801711000001',
   '$2y$10$DU6X/G1jYk9Z/5OFFbcRAev5EqANqVDxJQ5G7CsEWvwUork.xSgCy','admin','active',
   1,1,'#6366f1','House admin · pays the utility bills on time','Kacchi biryani',DATE_SUB(CURDATE(), INTERVAL 420 DAY)),

  (2,1,'FM-7QRT2M','Rakib Hasan','rakib@flatmate.test','+8801711000002',
   '$2y$10$GP3UszQVEyNPxNur3og.2u6k9j7vkD64.OImFADIF2kBuiM6uhq9W','resident','active',
   1,1,'#ec4899','Gym at 6am, asleep by 11','Chicken curry',DATE_SUB(CURDATE(), INTERVAL 410 DAY)),

  (3,1,'FM-5NVK8C','Nabila Islam','nabila@flatmate.test','+8801711000003',
   '$2y$10$GP3UszQVEyNPxNur3og.2u6k9j7vkD64.OImFADIF2kBuiM6uhq9W','resident','active',
   2,1,'#14b8a6','Final-year student, cooks most dinners','Fish curry',DATE_SUB(CURDATE(), INTERVAL 300 DAY)),

  (4,1,'FM-3WDJ6L','Tanha Ahmed','tanha@flatmate.test','+8801711000004',
   '$2y$10$GP3UszQVEyNPxNur3og.2u6k9j7vkD64.OImFADIF2kBuiM6uhq9W','resident','active',
   2,2,'#f59e0b','Cafeteria shift until 2pm','Khichuri',DATE_SUB(CURDATE(), INTERVAL 260 DAY)),

  (5,1,'FM-9PLC3H','Sabbir Rahman','sabbir@flatmate.test','+8801711000005',
   '$2y$10$GP3UszQVEyNPxNur3og.2u6k9j7vkD64.OImFADIF2kBuiM6uhq9W','resident','active',
   3,2,'#8b5cf6','Night shifts — sleeps late, keeps washroom B spotless','Beef bhaji',DATE_SUB(CURDATE(), INTERVAL 190 DAY)),

  (6,1,'FM-6BHF4Z','Maliha Karim','maliha@flatmate.test','+8801711000006',
   '$2y$10$GP3UszQVEyNPxNur3og.2u6k9j7vkD64.OImFADIF2kBuiM6uhq9W','resident','active',
   3,2,'#ef4444','Vegetarian, opts out of some meat dishes','Mixed vegetables',DATE_SUB(CURDATE(), INTERVAL 150 DAY)),

  -- A pending invitee so the onboarding screen has something to show
  (7,1,'FM-1QTZ7M','Imtiaz Chowdhury','imtiaz@flatmate.test','+8801711000007',
   NULL,'resident','invited',
   NULL,2,'#0ea5e9','Interested in Room 104 from next month',NULL,NULL);

-- A couple of historical residents to prove the offboarded state works
INSERT INTO `users`
  (`id`,`apartment_id`,`participant_code`,`full_name`,`email`,`phone`,`password_hash`,`role`,`status`,
   `room_id`,`duty_group_id`,`avatar_color`,`joined_on`,`offboarded_on`)
VALUES
  (8,1,'FM-0ZZZ9X','Farhan Alam (left)','farhan@flatmate.test','+8801711000008',
   NULL,'resident','offboarded',4,2,'#64748b',DATE_SUB(CURDATE(), INTERVAL 700 DAY),DATE_SUB(CURDATE(), INTERVAL 60 DAY));

-- ===========================================================================
-- 5. MAGIC LINKS (one already-used, one live for the demo account)
--    token_hash = sha256('flatmate-demo-magic-2026')
-- ===========================================================================
SET @mt := SHA2('flatmate-demo-magic-2026', 256);
INSERT INTO `magic_links` (`user_id`,`token_hash`,`purpose`,`expires_at`,`used_at`) VALUES
  (1, @mt, 'login', DATE_ADD(NOW(), INTERVAL 1 DAY), NULL);

-- ===========================================================================
-- 6. INVITES
-- ===========================================================================
INSERT INTO `invites`
  (`id`,`apartment_id`,`email`,`full_name`,`role`,`room_id`,`duty_group_id`,`token_hash`,`status`,`invited_by`,`expires_at`)
VALUES
  (1,1,'imtiaz@flatmate.test','Imtiaz Chowdhury','resident',4,2,SHA2('invite-imtiaz-2026',256),'pending',1,DATE_ADD(NOW(), INTERVAL 6 DAY)),
  (2,1,'newcomer@flatmate.test','Prospective Tenant','resident',5,NULL,SHA2('invite-newcomer-2026',256),'pending',1,DATE_ADD(NOW(), INTERVAL 13 DAY)),
  (3,1,'rakib@flatmate.test','Rakib Hasan','resident',1,1,SHA2('invite-used-2026',256),'accepted',1,DATE_SUB(NOW(), INTERVAL 400 DAY));

-- ===========================================================================
-- 7. MEAL PLANS  (this week + next week)
-- ===========================================================================
INSERT INTO `meal_plans` (`id`,`apartment_id`,`week_start`,`status`,`notes`) VALUES
  (1,1,@ws,'draft','Plan the weekend, settle the votes by Thursday 10pm.'),
  (2,1,DATE_ADD(@ws, INTERVAL 7 DAY),'draft','Next week — meal prep on Sunday.');

-- 21 slots per plan (7 days x 3 meal types)
INSERT INTO `meals` (`meal_plan_id`,`day_of_week`,`meal_type`)
SELECT p.`id`, d.dow, t.meal_type
FROM (SELECT `id` FROM `meal_plans` WHERE `apartment_id` = 1) p
CROSS JOIN (SELECT 1 dow UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
           UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7) d
CROSS JOIN (SELECT 'breakfast' meal_type UNION ALL SELECT 'lunch' UNION ALL SELECT 'dinner') t;

-- Fill the menu for THIS week (plan 1)
UPDATE `meals` m
JOIN `meal_plans` p ON p.`id` = m.`meal_plan_id`
SET m.`menu_title` = CASE m.`meal_type`
      WHEN 'breakfast' THEN ELT(m.`day_of_week`,
        'Paratha & Eggs','Oatmeal & Banana','Eggs & Toast','Rice & Dal',
        'Bread & Omelette','Idli & Sambar','Pancakes')
      WHEN 'lunch'     THEN ELT(m.`day_of_week`,
        'Chicken Biryani','Khichuri','Fried Rice','Leftovers',
        'Fish Curry & Rice','Veg Fried Rice','Instant Noodles')
      ELSE                  ELT(m.`day_of_week`,
        'Chicken Curry & Rice','Khichuri','Beef Bhaji & Rice','Dal & Rice',
        'Chicken Biryani','Mixed Veg Curry','Grilled Chicken')
    END,
    m.`cook_user_id` = ELT(m.`day_of_week`, 3,3,6,6,2,2,5)
WHERE p.`id` = 1;

-- Pre-fill next week with a lighter draft menu
UPDATE `meals` m
SET m.`menu_title` = CASE m.`meal_type`
      WHEN 'breakfast' THEN ELT(m.`day_of_week`,
        'Paratha & Eggs','Oatmeal & Banana','Eggs & Toast','Rice & Dal',
        'Bread & Omelette','Idli & Sambar','Pancakes')
      WHEN 'lunch'     THEN 'Leftovers'
      ELSE 'Dal & Rice'
    END
WHERE m.`meal_plan_id` = 2;

-- Leave three slots OPEN so the voting UI has something live to show
UPDATE `meals` m
SET m.`menu_title` = NULL, m.`locked` = 0
WHERE m.`meal_plan_id` = 1
  AND ( (m.`day_of_week` = 5 AND m.`meal_type` = 'dinner')
     OR (m.`day_of_week` = 6 AND m.`meal_type` = 'lunch')
     OR (m.`day_of_week` = 7 AND m.`meal_type` = 'dinner') );

-- ===========================================================================
-- 8. MEAL PARTICIPANTS — opt-in / opt-out for THIS week
--    Everyone starts as "eating"; a handful opt out below.
--    NOTE: chores never read this table. Opting out of food does NOT
--          remove you from the cleaning rotation.
-- ===========================================================================
INSERT INTO `meal_participants` (`meal_id`,`user_id`,`status`)
SELECT m.`id`, u.`id`, 'eating'
FROM `meals` m
JOIN `meal_plans` p ON p.`id` = m.`meal_plan_id`
CROSS JOIN (SELECT `id` FROM `users` WHERE `apartment_id` = 1 AND `status` = 'active') u
WHERE p.`id` = 1;

-- Sabbir (night shifts) skips dinner Tue + Thu
UPDATE `meal_participants` mp
JOIN `meals` m ON m.`id` = mp.`meal_id`
SET mp.`status` = 'opting_out', mp.`responded_at` = NOW()
WHERE m.`meal_plan_id` = 1 AND mp.`user_id` = 5
  AND m.`meal_type` = 'dinner' AND m.`day_of_week` IN (2,4);

-- Maliha (vegetarian-leaning) skips Friday dinner
UPDATE `meal_participants` mp
JOIN `meals` m ON m.`id` = mp.`meal_id`
SET mp.`status` = 'opting_out', mp.`responded_at` = NOW()
WHERE m.`meal_plan_id` = 1 AND mp.`user_id` = 6
  AND m.`meal_type` = 'dinner' AND m.`day_of_week` = 5;

-- Rakib skips Wednesday breakfast (early gym + class)
UPDATE `meal_participants` mp
JOIN `meals` m ON m.`id` = mp.`meal_id`
SET mp.`status` = 'opting_out', mp.`responded_at` = NOW()
WHERE m.`meal_plan_id` = 1 AND mp.`user_id` = 2
  AND m.`meal_type` = 'breakfast' AND m.`day_of_week` = 3;

-- Next week: only partial responses so the "coverage" badge lights up
INSERT INTO `meal_participants` (`meal_id`,`user_id`,`status`)
SELECT m.`id`, u.`id`, IF(u.`id` = 6, 'opting_out', 'eating')
FROM `meals` m
CROSS JOIN (SELECT `id` FROM `users` WHERE `apartment_id` = 1 AND `status` = 'active' AND `id` <> 5) u
WHERE m.`meal_plan_id` = 2;

-- ===========================================================================
-- 9. MEAL SUGGESTIONS + VOTES  (on the three open slots)
-- ===========================================================================
INSERT INTO `meal_suggestions` (`meal_id`,`user_id`,`title`,`notes`,`estimated_cost`,`status`)
SELECT m.`id`, s.uid, s.title, s.notes, s.cost, s.st
FROM (
  SELECT 'Fri dinner' AS slot, 5 AS dow, 'dinner' AS mt
) x
JOIN `meals` m ON m.`meal_plan_id` = 1 AND m.`day_of_week` = x.dow AND m.`meal_type` = x.mt
CROSS JOIN (
  SELECT 3 AS uid, 'Kacchi Biryani'    AS title, 'Marinate overnight, cook at 6pm'   AS notes, 1450.00 AS cost, 'open'     AS st
  UNION ALL SELECT 5,'Chicken Fried Rice','Uses leftover rice from Thursday'        ,  620.00,'open'
  UNION ALL SELECT 1,'Beef Kala Bhuna'    ,'Slow fry, serves 6 well'                , 1350.00,'open'
  UNION ALL SELECT 4,'Vegetable Stew + Rice','Light, everyone can eat it'           ,  380.00,'open'
) s;

INSERT INTO `meal_suggestions` (`meal_id`,`user_id`,`title`,`notes`,`estimated_cost`,`status`)
SELECT m.`id`, s.uid, s.title, s.notes, s.cost, s.st
FROM (SELECT 6 AS dow, 'lunch' AS mt) x
JOIN `meals` m ON m.`meal_plan_id` = 1 AND m.`day_of_week` = x.dow AND m.`meal_type` = x.mt
CROSS JOIN (
  SELECT 6 AS uid, 'Chicken Roast & Pulao' AS title,'Order from Tekka on Thursday' AS notes, 1800.00 AS cost,'open' AS st
  UNION ALL SELECT 2,'Burger & Fries'        ,'Cheap but nobody volunteers to cook', 900.00,'open'
  UNION ALL SELECT 3,'Fried Rice + Egg'     ,'Quick 30-minute cook'            , 480.00,'open'
) s;

INSERT INTO `meal_suggestions` (`meal_id`,`user_id`,`title`,`notes`,`estimated_cost`,`status`)
SELECT m.`id`, s.uid, s.title, s.notes, s.cost, s.st
FROM (SELECT 7 AS dow, 'dinner' AS mt) x
JOIN `meals` m ON m.`meal_plan_id` = 1 AND m.`day_of_week` = x.dow AND m.`meal_type` = x.mt
CROSS JOIN (
  SELECT 2 AS uid, 'Grilled Chicken Platter' AS title,'Charcoal already in the store room' AS notes, 1250.00 AS cost,'open' AS st
  UNION ALL SELECT 5,'Khichuri + Soft Egg'   ,'One pot, everyone helps'              ,  520.00,'open'
  UNION ALL SELECT 1,'Chinese Fried Rice'    ,'Leftovers + soy sauce'                ,  450.00,'open'
) s;

-- Votes (user_id can vote on suggestions in the same slot, incl. their own)
INSERT INTO `suggestion_votes` (`suggestion_id`,`user_id`,`vote`)
SELECT s.`id`, v.uid, v.vote
FROM `meal_suggestions` s
JOIN `meals` m ON m.`id` = s.`meal_id`
CROSS JOIN (
  -- Kacchi Biryani (Fri dinner)
  SELECT 'Kacchi Biryani'    AS title, 1 AS uid,  1 AS vote UNION ALL
  SELECT 'Kacchi Biryani',    2,  1 UNION ALL
  SELECT 'Kacchi Biryani',    3,  1 UNION ALL
  SELECT 'Kacchi Biryani',    4,  1 UNION ALL
  SELECT 'Kacchi Biryani',    5,  1 UNION ALL
  SELECT 'Kacchi Biryani',    6,  1 UNION ALL
  -- Beef Kala Bhuna
  SELECT 'Beef Kala Bhuna',    1,  1 UNION ALL
  SELECT 'Beef Kala Bhuna',    3, -1 UNION ALL
  SELECT 'Beef Kala Bhuna',    5,  1 UNION ALL
  -- Chicken Fried Rice
  SELECT 'Chicken Fried Rice', 2,  1 UNION ALL
  SELECT 'Chicken Fried Rice', 4,  1 UNION ALL
  -- Vegetable Stew
  SELECT 'Vegetable Stew + Rice', 4,  1 UNION ALL
  SELECT 'Vegetable Stew + Rice', 6,  1 UNION ALL
  SELECT 'Vegetable Stew + Rice', 1, -1 UNION ALL
  -- Sat lunch
  SELECT 'Chicken Roast & Pulao', 1,  1 UNION ALL
  SELECT 'Chicken Roast & Pulao', 2,  1 UNION ALL
  SELECT 'Chicken Roast & Pulao', 3,  1 UNION ALL
  SELECT 'Chicken Roast & Pulao', 4,  1 UNION ALL
  SELECT 'Chicken Roast & Pulao', 5,  1 UNION ALL
  SELECT 'Chicken Roast & Pulao', 6, -1 UNION ALL
  SELECT 'Burger & Fries',        4, -1 UNION ALL
  SELECT 'Burger & Fries',        6, -1 UNION ALL
  SELECT 'Fried Rice + Egg',     2,  1 UNION ALL
  -- Sun dinner
  SELECT 'Grilled Chicken Platter', 1,  1 UNION ALL
  SELECT 'Grilled Chicken Platter', 2,  1 UNION ALL
  SELECT 'Grilled Chicken Platter', 3,  1 UNION ALL
  SELECT 'Grilled Chicken Platter', 4,  1 UNION ALL
  SELECT 'Grilled Chicken Platter', 6,  1 UNION ALL
  SELECT 'Grilled Chicken Platter', 5,  1 UNION ALL
  SELECT 'Khichuri + Soft Egg',      1,  1 UNION ALL
  SELECT 'Khichuri + Soft Egg',      3,  1 UNION ALL
  SELECT 'Khichuri + Soft Egg',      6,  1 UNION ALL
  SELECT 'Chinese Fried Rice',       4, -1 UNION ALL
  SELECT 'Chinese Fried Rice',       6, -1
) v
WHERE s.`title` = v.title;

-- Mark the current winner on the Friday dinner slot
UPDATE `meal_suggestions` s
JOIN `meals` m ON m.`id` = s.`meal_id`
SET s.`is_winner` = 1, s.`status` = 'chosen'
WHERE m.`meal_plan_id` = 1 AND m.`day_of_week` = 5 AND m.`meal_type` = 'dinner'
  AND s.`title` = 'Kacchi Biryani';

-- ===========================================================================
-- 10. EXPENSE CATEGORIES
-- ===========================================================================
INSERT INTO `expense_categories` (`id`,`apartment_id`,`name`,`slug`,`icon`,`is_meal_related`,`sort_order`) VALUES
  (1,1,'Grocery & Vegetables','grocery','bi-basket2',1,1),
  (2,1,'Meat & Fish','meat-fish','bi-egg-fried',1,2),
  (3,1,'Dining Out','dining-out','bi-cup-hot',1,3),
  (4,1,'Household Supplies','household','bi-house-gear',0,4),
  (5,1,'Cleaning Products','cleaning','bi-droplet-half',0,5),
  (6,1,'Utilities (Gas/EB/AC)','utilities','bi-lightning-charge',0,6),
  (7,1,'Internet & TV','internet-tv','bi-wifi',0,7),
  (8,1,'Maintenance & Repairs','maintenance','bi-tools',0,8);

-- ===========================================================================
-- 11. EXPENSES + SPLITS
--     Amounts are chosen so every split divides EXACTLY into 2 decimals —
--     no rounding drift, sum(share_amount) == expenses.amount for every row.
--     Paid offsets are 0 (today) … 33 days back.
-- ===========================================================================
-- `id` is left to AUTO_INCREMENT so the 20 rows get 1..20 in the order below.
-- Later sections key off those ids (e.g. activity_log entity_id 16 = EX-2026-000133),
-- so the insert order is load-bearing — do not reorder this block.
INSERT INTO `expenses`
  (`apartment_id`,`reference_no`,`title`,`description`,`amount`,`paid_by_user_id`,`category_id`,
   `split_type`,`split_meta`,`expense_date`,`is_meal_related`,`created_by`)
SELECT 1, e.ref, e.title, e.descr, e.amt, e.payer, e.cat, e.stype, e.meta,
       DATE_SUB(CURDATE(), INTERVAL e.off DAY), e.meal, e.payer
FROM (
  SELECT 'EX-2026-000118' AS ref, 'Weekly grocery run'    AS title, 'Shwapno — rice, dal, oil, sugar, tea' AS descr,
         4200.00 AS amt, 2 AS payer, 1 AS cat, 'equal' AS stype, CAST(NULL AS CHAR(40)) AS meta,
         33 AS off, 1 AS meal
  UNION ALL SELECT 'EX-2026-000119','Chicken & fresh vegetables','Sunday market haul',1650.00,3,2,'meal_based',
         '{"source":"optin","window":"2026-01-01..2026-01-31"}',31,1
  UNION ALL SELECT 'EX-2026-000120','Rice 25kg (2 bags)','Minimax order, split delivery fee too',2400.00,5,1,'equal',
         NULL,29,1
  UNION ALL SELECT 'EX-2026-000121','Cleaning supplies','Dish soap, floor cleaner, scrub pads, gloves',780.00,4,5,'equal',
         NULL,27,0
  UNION ALL SELECT 'EX-2026-000122','Fiber internet — January','BTRC line, 40 Mbps shared',3600.00,1,7,'equal',
         NULL,25,0
  UNION ALL SELECT 'EX-2026-000123','Gas cylinder refill','12.5 kg cylinder',1440.00,6,6,'equal',
         NULL,24,0
  UNION ALL SELECT 'EX-2026-000124','Weekend BBQ groceries','Corn, beef, peppers, charcoal, buns',2100.00,2,2,'meal_based',
         '{"source":"optin","window":"sat-sun"}',22,1
  UNION ALL SELECT 'EX-2026-000125','Dishwasher tabs & scrub pads','Monthly restock',450.00,3,4,'equal',
         NULL,20,0
  UNION ALL SELECT 'EX-2026-000126','Water filter cartridges','3-stage filter, all taps',1200.00,1,4,'equal',
         NULL,18,0
  UNION ALL SELECT 'EX-2026-000127','Electricity bill — December','Desco prepaid recharge',900.00,4,6,'equal',
         NULL,16,0
  UNION ALL SELECT 'EX-2026-000128','Laundry service (2 users)','Sabbir & Maliha only had 12 shirts each',720.00,6,4,'selective',
         '{"selected":[5,6]}',14,0
  UNION ALL SELECT 'EX-2026-000129','Fish curry ingredients','Rui fish, mustard oil, gourds',1300.00,5,2,'meal_based',
         '{"source":"optin","window":"thu-sat"}',12,1
  UNION ALL SELECT 'EX-2026-000130','Pest control — quarterly','Spraying all 3 rooms + kitchen',1800.00,1,8,'equal',
         NULL,10,0
  UNION ALL SELECT 'EX-2026-000131','Breakfast supplies','Bread, eggs (2 dozen), milk powder',960.00,4,1,'meal_based',
         '{"source":"optin","window":"mon-fri-am"}',8,1
  UNION ALL SELECT 'EX-2026-000132','Cooking oil 5L','Soybean oil',1380.00,2,1,'equal',
         NULL,7,1
  UNION ALL SELECT 'EX-2026-000133','Bathroom restock','Soap, shampoo, toilet paper',600.00,3,4,'shares',
         '{"shares":{"1":2,"2":2,"3":1,"4":1,"5":1,"6":1}}',5,0
  UNION ALL SELECT 'EX-2026-000134','Wardrobe rail repair','Broken rail in Room 101 — shared cost',1500.00,1,8,'equal',
         NULL,4,0
  UNION ALL SELECT 'EX-2026-000135','Fruits & juice','Mango, guava, mixed juice',500.00,6,1,'meal_based',
         '{"source":"optin","window":"week"}',2,1
  UNION ALL SELECT 'EX-2026-000136','Gas refill (top-up)','Urgent top-up before cooking night',720.00,3,6,'equal',
         NULL,1,0
  UNION ALL SELECT 'EX-2026-000137','Basmati rice 5kg','For the biryani night',1150.00,5,1,'meal_based',
         '{"source":"optin","window":"fri-dinner"}',0,1
) e;

-- ---- Splits: "equal" (all 6 residents) --------------------------------------
INSERT INTO `expense_splits` (`expense_id`,`user_id`,`share_amount`,`weight`)
SELECT e.`id`, u.`id`, e.`amount` / 6, 1.0000
FROM `expenses` e
CROSS JOIN (SELECT `id` FROM `users` WHERE `apartment_id`=1 AND `status`='active' AND `id` <= 6) u
WHERE e.`split_type` = 'equal';

-- ---- Splits: "selective" (laundry = users 5 and 6) -------------------------
INSERT INTO `expense_splits` (`expense_id`,`user_id`,`share_amount`,`weight`)
SELECT e.`id`, s.`uid`, e.`amount` / 2, 1.0000
FROM `expenses` e
CROSS JOIN (SELECT 5 `uid` UNION ALL SELECT 6) s
WHERE e.`reference_no` = 'EX-2026-000128';

-- ---- Splits: "shares" (weighted: 2/2/1/1/1/1 of 8) -------------------------
INSERT INTO `expense_splits` (`expense_id`,`user_id`,`share_amount`,`weight`)
SELECT e.`id`, s.`uid`, ROUND(e.`amount` * s.w / 8, 2), s.w
FROM `expenses` e
CROSS JOIN (
  SELECT 1 `uid`, 2.0 w UNION ALL SELECT 2,2.0 UNION ALL SELECT 3,1.0
  UNION ALL SELECT 4,1.0     UNION ALL SELECT 5,1.0 UNION ALL SELECT 6,1.0
) s
WHERE e.`reference_no` = 'EX-2026-000133';

-- ---- Splits: "meal_based" (only residents who opted in) ---------------------
--      n = number of eaters for that meal window, so amount/n divides exactly.
INSERT INTO `expense_splits` (`expense_id`,`user_id`,`share_amount`,`weight`)
SELECT e.`id`, x.`uid`, ROUND(e.`amount` / x.`n`, 2), 1.0000
FROM (
  -- Chicken & fresh vegetables  : 4 eaters
  SELECT 'EX-2026-000119' AS ref, 4 AS n, 1 AS `uid` UNION ALL
  SELECT 'EX-2026-000119', 4, 2 UNION ALL
  SELECT 'EX-2026-000119', 4, 3 UNION ALL
  SELECT 'EX-2026-000119', 4, 4 UNION ALL
  -- Weekend BBQ groceries      : 5 eaters
  SELECT 'EX-2026-000124', 5, 2 UNION ALL
  SELECT 'EX-2026-000124', 5, 3 UNION ALL
  SELECT 'EX-2026-000124', 5, 4 UNION ALL
  SELECT 'EX-2026-000124', 5, 5 UNION ALL
  SELECT 'EX-2026-000124', 5, 6 UNION ALL
  -- Fish curry ingredients     : 5 eaters
  SELECT 'EX-2026-000129', 5, 2 UNION ALL
  SELECT 'EX-2026-000129', 5, 3 UNION ALL
  SELECT 'EX-2026-000129', 5, 4 UNION ALL
  SELECT 'EX-2026-000129', 5, 5 UNION ALL
  SELECT 'EX-2026-000129', 5, 6 UNION ALL
  -- Breakfast supplies         : 4 eaters
  SELECT 'EX-2026-000131', 4, 1 UNION ALL
  SELECT 'EX-2026-000131', 4, 2 UNION ALL
  SELECT 'EX-2026-000131', 4, 3 UNION ALL
  SELECT 'EX-2026-000131', 4, 4 UNION ALL
  -- Fruits & juice             : 5 eaters
  SELECT 'EX-2026-000135', 5, 2 UNION ALL
  SELECT 'EX-2026-000135', 5, 3 UNION ALL
  SELECT 'EX-2026-000135', 5, 4 UNION ALL
  SELECT 'EX-2026-000135', 5, 5 UNION ALL
  SELECT 'EX-2026-000135', 5, 6 UNION ALL
  -- Basmati rice (biryani night): 5 eaters
  SELECT 'EX-2026-000137', 5, 1 UNION ALL
  SELECT 'EX-2026-000137', 5, 2 UNION ALL
  SELECT 'EX-2026-000137', 5, 3 UNION ALL
  SELECT 'EX-2026-000137', 5, 4 UNION ALL
  SELECT 'EX-2026-000137', 5, 5
) x
JOIN `expenses` e ON e.`reference_no` = x.`ref`;

-- ===========================================================================
-- 12. SETTLEMENTS — logged cash / mobile payouts
-- ===========================================================================
INSERT INTO `settlements` (`apartment_id`,`from_user_id`,`to_user_id`,`amount`,`method`,`reference`,`note`,`settled_at`,`created_by`)
VALUES
  (1, 5, 2, 400.00,'cash',   'bkash-771203',  'Settled in person at the gate',            DATE_SUB(CURDATE(), INTERVAL 21 DAY), 1),
  (1, 6, 1, 250.00,'bkash', 'NID-88230114',  'Half of the pest control share',            DATE_SUB(CURDATE(), INTERVAL 11 DAY), 1),
  (1, 4, 3, 175.00,'cash',   NULL,            'For the fresh vegetables run',              DATE_SUB(CURDATE(), INTERVAL  6 DAY), 1),
  (1, 2, 1, 300.00,'bank',   'BEFTN-44120',   'Utilities contribution',                    DATE_SUB(CURDATE(), INTERVAL  3 DAY), 1);

-- ===========================================================================
-- 13. CHORE AREAS
--     scope='common' → rotates across ALL active residents
--     scope='group'  → rotates strictly inside duty_group_id
--     weekday_mask: bit0=Mon 1, Tue 2, Wed 4, Thu 8, Fri 16, Sat 32, Sun 64
-- ===========================================================================
INSERT INTO `chore_areas`
  (`id`,`apartment_id`,`name`,`slug`,`scope`,`room_id`,`duty_group_id`,`icon`,`frequency`,`weekday_mask`,`rotation_offset`,`points`,`description`)
VALUES
  (1,1,'Hall Sweeping','hall-sweeping','common',NULL,NULL,'bi-stars','daily',127,0,10,
      'Sweep + mop the main hall and staircase landing. Daily, all residents.'),
  (2,1,'Trash Disposal','trash-disposal','common',NULL,NULL,'bi-trash3','daily',127,2,10,
      'Tie bags, take to the bin, wipe the bin lid, replace the liner.'),
  (3,1,'Kitchen Deep Clean','kitchen-deep-clean','common',NULL,NULL,'bi-fire','weekly',32,1,30,
      'Every Saturday: degrease the hob, clean the chimney, wipe all cabinets.'),
  (4,1,'Washroom A','washroom-a','group',NULL,1,'bi-droplet','daily',127,0,15,
      'Washroom A users only — 3-person strict cycle. Mandatory even when you skip meals.'),
  (5,1,'Washroom B','washroom-b','group',NULL,2,'bi-droplet-half','daily',127,1,15,
      'Washroom B users only — 3-person strict cycle. Mandatory even when you skip meals.'),
  (6,1,'Room 102 Internal','room-102-internal','room',2,NULL,'bi-door-open','weekly',64,0,20,
      'Sunday: the two residents in Room 102 deep-clean their own room + attached bath.');

-- ===========================================================================
-- 14. CHORE TASKS — generate the rotation
--     Window: 14 days back → 21 days forward (36 days, 6x6 cross join).
--     Position formula mirrors src/DutyScheduler.php exactly:
--       daily  → MOD(DATEDIFF(date,'1970-01-01') + offset, n)
--       weekly → MOD(FLOOR(DATEDIFF(date,'1970-01-01')/7) + offset, n)
--     Past days are marked done (every 9th is 'skipped'); today/future pending.
-- ===========================================================================
INSERT INTO `chore_tasks` (`chore_area_id`,`assigned_user_id`,`task_date`,`status`,`completed_at`,`notes`)
SELECT
  l.`area_id`,
  (SELECT u2.`id` FROM `users` u2
     WHERE u2.`status` = 'active'
       AND ( (l.`scope` = 'group' AND u2.`duty_group_id` = l.`group_id`)
          OR (l.`scope` = 'room'  AND u2.`room_id`       = l.`room_id`)
          OR  l.`scope` = 'common' )
     ORDER BY u2.`id`
     LIMIT 1 OFFSET l.`pos`),
  l.`task_date`,
  CASE
    WHEN l.`task_date` <  CURDATE() THEN IF(MOD(l.`seq`, 9) = 0, 'skipped', 'done')
    WHEN l.`task_date` =  CURDATE() THEN 'pending'
    ELSE 'pending'
  END,
  CASE
    WHEN l.`task_date` < CURDATE() AND MOD(l.`seq`, 9) <> 0
      THEN TIMESTAMP(l.`task_date`, '18:30:00')
    ELSE NULL
  END,
  CASE
    WHEN l.`task_date` < CURDATE() AND MOD(l.`seq`, 9) = 0
      THEN 'Out of town / could not do it — reported in the group chat'
    ELSE NULL
  END
FROM (
  SELECT
    (g1.n * 6 + g2.n)                       AS `seq`,
    a.`id`                                   AS `area_id`,
    a.`scope`,
    a.`duty_group_id`                        AS `group_id`,
    a.`room_id`,
    DATE_ADD(DATE_SUB(@ws, INTERVAL 14 DAY), INTERVAL (g1.n * 6 + g2.n) DAY) AS `task_date`,
    MOD(
      IF(a.`frequency` = 'weekly',
         FLOOR(DATEDIFF(DATE_ADD(DATE_SUB(@ws, INTERVAL 14 DAY), INTERVAL (g1.n*6+g2.n) DAY), '1970-01-01') / 7),
         DATEDIFF(DATE_ADD(DATE_SUB(@ws, INTERVAL 14 DAY), INTERVAL (g1.n*6+g2.n) DAY), '1970-01-01'))
      + a.`rotation_offset`,
      GREATEST((SELECT COUNT(*) FROM `users` u3
                  WHERE u3.`status` = 'active'
                    AND ( (a.`scope` = 'group' AND u3.`duty_group_id` = a.`duty_group_id`)
                       OR (a.`scope` = 'room'  AND u3.`room_id`       = a.`room_id`)
                       OR  a.`scope` = 'common' )), 1)
    ) AS `pos`
  FROM `chore_areas` a
  CROSS JOIN (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5) g1
  CROSS JOIN (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5) g2
  WHERE a.`is_active` = 1
    AND ( (1 << WEEKDAY(DATE_ADD(DATE_SUB(@ws, INTERVAL 14 DAY), INTERVAL (g1.n*6+g2.n) DAY)))
         & a.`weekday_mask`) > 0
) l;

-- Stagger completion timestamps so the "recently done" feed looks organic
UPDATE `chore_tasks` SET `completed_by` = `assigned_user_id`
WHERE `status` = 'done';

UPDATE `chore_tasks` SET `verified_by` = 1, `verified_at` = `completed_at`
WHERE `status` = 'done' AND `assigned_user_id` <> 1;

-- Mark one of *today's* chores as already completed for a nicer dashboard
UPDATE `chore_tasks`
SET `status` = 'done', `completed_at` = NOW(), `completed_by` = `assigned_user_id`
WHERE `task_date` = CURDATE() AND `assigned_user_id` IS NOT NULL
ORDER BY `id` LIMIT 1;

-- ===========================================================================
-- 15. OFFBOARDING CHECKLIST
--     Farhan (id 8) left 60 days ago but the FINANCE item is still open —
--     that is the admin's "budget dispute" queue in action.
-- ===========================================================================
INSERT INTO `offboarding_tasks` (`apartment_id`,`user_id`,`label`,`category`,`is_done`,`is_blocking`,`completed_at`,`sort_order`) VALUES
  (1,8,'Confirm move-out date with the owner','admin',1,1,DATE_SUB(NOW(), INTERVAL 62 DAY),1),
  (1,8,'Settle the outstanding shared balance to ৳0.00','finance',0,1,NULL,2),
  (1,8,'Return the room key + gate fob','property',1,1,DATE_SUB(NOW(), INTERVAL 60 DAY),3),
  (1,8,'Clear personal items from the shared fridge / shelf','property',1,0,DATE_SUB(NOW(), INTERVAL 60 DAY),4),
  (1,8,'Final reading of the electricity + gas meter','admin',1,1,DATE_SUB(NOW(), INTERVAL 60 DAY),5),
  (1,8,'Transfer Wi-Fi / subscription ownership','admin',1,0,DATE_SUB(NOW(), INTERVAL 60 DAY),6);

-- ===========================================================================
-- 16. NOTICE BOARD
-- ===========================================================================
INSERT INTO `announcements`
  (`id`,`apartment_id`,`user_id`,`title`,`body`,`category`,`audience`,`is_pinned`,`pinned_until`,`view_count`,`created_at`)
VALUES
  (1,1,1,'Water supply cut on Thursday 9am–2pm',
      'Munshi Water will dig on Banani Road 11, so expect no water from 9am until roughly 2pm on Thursday. Store drinking water in the buckets tonight. Showers will be short. — Aisha',
      'maintenance','everyone',1,DATE_ADD(CURDATE(), INTERVAL 7 DAY),9, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
  (2,1,3,'Weekend grocery list is up',
      'I pinned the list on the kitchen door. Add anything you need before Thursday 8pm so Rakib can do one big run instead of three small ones. Please add your own items under your name.',
      'general','everyone',0,NULL,4, DATE_SUB(NOW(), INTERVAL 1 DAY)),
  (3,1,1,'Reminder: rent is due on the 5th',
      'Pay your room rent directly to the owner and then mark it done in your own tracker. The shared ledger only covers household costs — rent stays private.',
      'billing','everyone',1,DATE_ADD(CURDATE(), INTERVAL 10 DAY),6, DATE_SUB(NOW(), INTERVAL 2 DAY)),
  (4,1,5,'AC filter needs cleaning',
      'The unit in Room 101 is blowing warm air again. Clean or replace the filter — I left a spare in the store shelf.',
      'maintenance','everyone',0,NULL,2, DATE_SUB(NOW(), INTERVAL 4 DAY)),
  (5,1,4,'Farewell dinner for Imtiaz on Sunday',
      'He is moving into Room 104 on the 1st. Everyone eats at 8pm, I am ordering from the biryani place. Please opt in on the meal planner so I order the right amount.',
      'event','everyone',0,NULL,3, DATE_SUB(NOW(), INTERVAL 6 HOUR));

INSERT INTO `announcement_reads` (`announcement_id`,`user_id`,`read_at`) VALUES
  (1,1,NOW()),(1,2,NOW()),(1,3,NOW()),(1,5,NOW()),
  (2,1,NOW()),(2,2,NOW()),(2,3,NOW()),
  (3,1,NOW()),(3,2,NOW()),
  (4,1,NOW()),
  (5,1,NOW());

-- ===========================================================================
-- 17. REMINDERS
-- ===========================================================================
INSERT INTO `reminders` (`apartment_id`,`user_id`,`type`,`title`,`body`,`severity`,`ref_table`,`ref_id`,`due_at`) VALUES
  (1,1,'chore','You are on duty today','Check the Task Dashboard for your assigned chores and mark them done before 9pm.',  'warning','chore_tasks',NULL, TIMESTAMP(CURDATE(),'21:00:00')),
  (1,1,'announcement','2 new notices on the board','Water cut and the farewell dinner need a read.','info','announcements',NULL,NULL),
  (1,3,'vote','Your vote is needed','Friday dinner still has 4 open suggestions.','info','meal_suggestions',NULL, DATE_ADD(CURDATE(), INTERVAL 1 DAY)),
  (1,2,'balance','You are owed money','You are a creditor on the shared ledger. Review who owes whom.','info','expenses',NULL,NULL),
  (1,4,'meal_optin','Opt in for next week','Your meal responses for next week are incomplete.','warning','meal_plans',2, DATE_ADD(CURDATE(), INTERVAL 2 DAY)),
  (1,1,'system','Offboarding still blocked','Farhan Alam left 60 days ago with an unsettled balance. Close it or waive it.','danger','offboarding_tasks',NULL,NULL),
  (1,1,'system','Pending invite','Imtiaz Chowdhury has not accepted the Room 104 invite yet.','info','invites',1,DATE_ADD(NOW(), INTERVAL 6 DAY));

-- ===========================================================================
-- 18. ACTIVITY LOG
-- ===========================================================================
INSERT INTO `activity_log` (`apartment_id`,`user_id`,`action`,`entity`,`entity_id`,`summary`,`meta`,`created_at`) VALUES
  (1,2,'expense.created','expenses',1,'Rakib logged "Weekly grocery run" — ৳4,200.00 split equally','{"split":"equal","heads":6}', DATE_SUB(NOW(), INTERVAL 33 DAY)),
  (1,5,'chore.completed','chore_tasks',1,'Sabbir completed "Washroom B"','{"area":"Washroom B"}', DATE_SUB(NOW(), INTERVAL 1 DAY)),
  (1,3,'meal.suggested','meal_suggestions',1,'Nabila suggested "Kacchi Biryani" for Friday dinner','{"estimated_cost":1450}', DATE_SUB(NOW(), INTERVAL 5 HOUR)),
  (1,6,'settle.paid','settlements',2,'Maliha paid Aisha ৳250.00 via bKash','{"method":"bkash"}', DATE_SUB(NOW(), INTERVAL 11 DAY)),
  (1,1,'resident.invited','invites',1,'Aisha invited imtiaz@flatmate.test for Room 104','{"room":"104","group":"Washroom B"}', DATE_SUB(NOW(), INTERVAL 3 DAY)),
  (1,4,'announcement.posted','announcements',5,'Tanha posted "Farewell dinner for Imtiaz on Sunday"','{"category":"event"}', DATE_SUB(NOW(), INTERVAL 6 HOUR)),
  (1,1,'expense.disputed','expenses',16,'Aisha flagged "Bathroom restock" — shares disputed','{"note":"weighting unclear"}', DATE_SUB(NOW(), INTERVAL 2 HOUR));

-- One dispute demo row on a real expense
UPDATE `expenses` SET `is_disputed` = 1, `dispute_note` = 'Aisha: I think 2/8 weighting is unfair for 2 people. Rakib: I use more than average. Needs a vote.'
WHERE `reference_no` = 'EX-2026-000133';

SET FOREIGN_KEY_CHECKS = 1;

-- ===========================================================================
--  VERIFICATION QUERIES — run these after seeding to sanity-check.
-- ===========================================================================
SELECT '--- Balance sheet (Net = Paid - Owed - Sent + Received) ---' AS ``;
SELECT `full_name`, `participant_code`, `room_code`, `duty_group`,
       ROUND(`total_paid`,2)   AS `paid`,
       ROUND(`total_owed`,2)   AS `owed`,
       ROUND(`net_balance`,2)  AS `net`
FROM `vw_balance_sheet`
WHERE `status` = 'active'
ORDER BY `net_balance` DESC;

SELECT '--- Split integrity (must be 0 for every row) ---' AS ``;
SELECT e.`reference_no`, e.`amount`,
       SUM(es.`share_amount`) AS `sum_splits`,
       ROUND(SUM(es.`share_amount`) - e.`amount`, 2) AS `drift`
FROM `expenses` e
JOIN `expense_splits` es ON es.`expense_id` = e.`id`
GROUP BY e.`id`, e.`reference_no`, e.`amount`
HAVING ABS(SUM(es.`share_amount`) - e.`amount`) > 0.001;

SELECT '--- Next 7 days of the rotation ---' AS ``;
SELECT `task_date`, `area_name`, `scope`, `assignee_name`, `status`
FROM `vw_today_chores`
WHERE `task_date` BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
ORDER BY `task_date`, `area_name`;

SELECT '--- Upcoming chores per resident (fairness check) ---' AS ``;
SELECT u.`full_name`,
       SUM(t.`status` = 'pending')                          AS `pending_7d`,
       SUM(t.`status` IN ('done','verified'))               AS `done_7d`,
       SUM(t.`status` = 'skipped')                          AS `skipped_7d`
FROM `chore_tasks` t
JOIN `users` u ON u.`id` = t.`assigned_user_id`
WHERE t.`task_date` BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
GROUP BY u.`id`, u.`full_name`
ORDER BY u.`id`;

-- ===========================================================================
--  END OF SEED
-- ===========================================================================
